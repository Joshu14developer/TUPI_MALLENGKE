<?php
/* ============================================================
   MALLENGKE · DATABASE LAYER
   ------------------------------------------------------------
   Connection + self-healing schema.

   The schema below is the single source of truth. On every request
   we only pay for one cheap lookup (schema_meta); the DDL runs once
   per version bump, so an existing install converges to the target
   schema without any manual SQL.

   Design rules
     · Referential integrity is enforced by the database, not just
       by the PHP forms — every child column points at its parent.
     · Every foreign key is served by an index that STARTS with its
       child column. Where a real lookup shares that column, the two
       are declared as ONE composite index instead of two structures
       InnoDB has to keep in step on every write.
     · Deletes cascade along ownership (a conversation dies with the
       vendor it belongs to), while bookkeeping columns that must
       survive (who approved a swap) are SET NULL.
     · Composite indexes are shaped after the queries that actually
       run (inbox feeds, conversation lists, approval queues).
     · Uniqueness rules the app already enforces in PHP are mirrored
       here as real UNIQUE keys, so a race cannot slip past.
     · vendor_conversations is normalised as an unordered pair:
       vendor_a_id < vendor_b_id, guaranteed by a CHECK.
   ============================================================ */

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/config.php';
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$conn->query(
    "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`"
    . " DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);

$conn->select_db(DB_NAME);

/* Bump this whenever the schema below changes; every install will
   walk itself forward to it on the next request.
   15: every foreign key and CHECK rule is declared in the table
       definitions itself, and the relationship pass is re-run so an
       install stuck at 14 with missing keys repairs itself.
   16: profile photos for vendors, customers and admin accounts. */
const MOLLENGKE_SCHEMA_VERSION = 16;

/* ============================================================
   TINY DDL HELPERS
   Every helper is idempotent, so the migration can run any number
   of times and on partially-migrated databases.
   ============================================================ */

/** Run DDL, collecting (not swallowing) failures. */
function mk_exec($conn, $sql, &$warn) {
    try {
        if ($conn->query($sql)) return true;
    } catch (Throwable $e) {
        $warn[] = $sql . ' → ' . $e->getMessage();
        return false;
    }
    $warn[] = $sql . ' → ' . $conn->error;
    return false;
}

/**
 * Fire-and-forget statement (data cleanup, seeds). Never throws, so a
 * migration problem degrades into a warning instead of a blank page.
 */
function mk_run($conn, $sql) {
    try { return (bool)$conn->query($sql); } catch (Throwable $e) { return false; }
}

function mk_index_exists($conn, $table, $name) {
    try {
        $s = $conn->query("SELECT 1 FROM information_schema.STATISTICS
                            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '$table' AND INDEX_NAME = '$name' LIMIT 1");
        return $s && $s->num_rows > 0;
    } catch (Throwable $e) { return false; }
}

function mk_index_columns($conn, $table, $name) {
    $cols = [];
    try {
        if (!$s = $conn->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS
                                 WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '$table' AND INDEX_NAME = '$name'
                                 ORDER BY SEQ_IN_INDEX")) return $cols;
        while ($r = $s->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
    } catch (Throwable $e) { return []; }
    return $cols;
}

/**
 * Create an index only when it is missing or defined on different
 * columns — an index on the same column twice is pure write overhead.
 *
 * When the existing index is the one a foreign key is standing on, the
 * naive DROP/ADD pair cannot work: MySQL refuses to drop an index a
 * foreign key needs, then refuses the duplicate name. That failure is
 * silent on the first half and fatal-looking on the second, and it
 * leaves the table permanently on the narrow index.
 */
function mk_index($conn, $table, $name, array $cols, $unique, &$warn) {
    $want = implode(',', $cols);
    if (mk_index_exists($conn, $table, $name)) {
        if (mk_index_columns($conn, $table, $name) === $cols) return true;
        if (in_array($name, mk_fk_backed_indexes($conn, $table), true)) {
            return mk_reindex_fk_backed($conn, $table, $name, $cols, $unique, $warn);
        }
        mk_exec($conn, "ALTER TABLE `$table` DROP INDEX `$name`", $warn);
    }
    $kind = $unique ? 'UNIQUE KEY' : 'KEY';
    return mk_exec($conn, "ALTER TABLE `$table` ADD $kind `$name` ($want)", $warn);
}

/**
 * The single-column foreign keys that would break if this index
 * disappeared — i.e. every key whose child column sits anywhere in the
 * index — with enough detail to put each one back exactly as it was.
 *
 * Composite keys are excluded: this helper only ever declares
 * single-column keys, so anything wider is someone else's to manage.
 */
function mk_fks_on_index($conn, $table, $indexName) {
    $out = [];
    try {
        $s = $conn->query(
            "SELECT DISTINCT k.CONSTRAINT_NAME, k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
               FROM information_schema.STATISTICS st
               JOIN information_schema.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_SCHEMA = st.TABLE_SCHEMA
                AND k.TABLE_NAME      = st.TABLE_NAME
                AND k.COLUMN_NAME     = st.COLUMN_NAME
                AND k.REFERENCED_TABLE_NAME IS NOT NULL
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
                AND r.TABLE_NAME        = k.TABLE_NAME
              WHERE st.TABLE_SCHEMA = '" . DB_NAME . "'
                AND st.TABLE_NAME  = '$table'
                AND st.INDEX_NAME  = '$indexName'
                AND k.CONSTRAINT_NAME IN (
                      SELECT k2.CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE k2
                       WHERE k2.CONSTRAINT_SCHEMA = '" . DB_NAME . "'
                         AND k2.TABLE_NAME = '$table'
                       GROUP BY k2.CONSTRAINT_NAME HAVING COUNT(*) = 1)"
        );
        if ($s) while ($r = $s->fetch_assoc()) $out[] = $r;
    } catch (Throwable $e) { return []; }
    return $out;
}

/**
 * Re-declare an index whose name is held by a narrower index that a
 * foreign key happens to be standing on.
 *
 * InnoDB is happy to use a *wider* index for a foreign key as long as
 * the required column is still its leftmost one, so the fix is to lift
 * the key off the old index, drop it, and put it straight back onto the
 * wider structure. Every helper here is idempotent, so a failure part
 * way through is repaired by the next migration run rather than
 * leaving the table in a state nothing will ever revisit.
 */
function mk_reindex_fk_backed($conn, $table, $name, array $cols, $unique, &$warn) {
    $lifted = mk_fks_on_index($conn, $table, $name);
    if (!$lifted) return true;   /* raced with another fix; nothing to lift */

    foreach ($lifted as $fk) {
        if (!mk_exec($conn, "ALTER TABLE `$table` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`", $warn)) {
            return false;       /* never drop what we cannot put back */
        }
    }

    mk_drop_index($conn, $table, $name, $warn);

    $kind = $unique ? 'UNIQUE KEY' : 'KEY';
    $ok = mk_exec($conn, "ALTER TABLE `$table` ADD $kind `$name` (" . implode(',', $cols) . ")", $warn);

    foreach ($lifted as $fk) {
        mk_fk($conn, $table, $fk['CONSTRAINT_NAME'], $fk['COLUMN_NAME'],
              $fk['REFERENCED_TABLE_NAME'], $fk['REFERENCED_COLUMN_NAME'],
              $fk['DELETE_RULE'], $warn);
    }
    return $ok;
}

/**
 * The definition of an existing foreign key: which column it hangs on,
 * where it points, and what happens to the child row on delete/update.
 * One row per column, so a composite key comes back as several.
 *
 * This deliberately REPLACES an "does this constraint name exist?" check.
 * A name proves nothing about what the key actually does, and treating it
 * as proof is how a drifted key survives every future migration.
 */
function mk_fk_def($conn, $table, $name) {
    $rows = [];
    try {
        $s = $conn->query(
            "SELECT r.DELETE_RULE, r.UPDATE_RULE, k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
               FROM information_schema.REFERENTIAL_CONSTRAINTS r
               JOIN information_schema.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
                AND k.CONSTRAINT_NAME   = r.CONSTRAINT_NAME
                AND k.TABLE_NAME        = r.TABLE_NAME
              WHERE r.CONSTRAINT_SCHEMA = '" . DB_NAME . "'
                AND r.TABLE_NAME  = '$table'
                AND r.CONSTRAINT_NAME = '$name'
              ORDER BY k.ORDINAL_POSITION"
        );
        if ($s) while ($r = $s->fetch_assoc()) $rows[] = $r;
    } catch (Throwable $e) { return []; }
    return $rows;
}

/**
 * Point a child column at its parent.
 *
 * Existence alone is not enough: a key left over from an older release
 * can sit on the wrong column, or carry a narrower ON UPDATE rule, and
 * a plain "is it there?" check reports success forever. So an existing
 * constraint is compared against the target and rebuilt when it drifted.
 *
 * Composite keys are left alone — this helper only ever declares
 * single-column keys, so anything with more than one row belongs to
 * something else.
 */
function mk_fk($conn, $table, $name, $col, $refTable, $refCol, $onDelete, &$warn) {
    $cur = mk_fk_def($conn, $table, $name);

    if ($cur) {
        if (count($cur) !== 1) return true;   /* not ours to manage */
        $c = $cur[0];
        if ($c['COLUMN_NAME'] === $col
            && $c['REFERENCED_TABLE_NAME'] === $refTable
            && $c['REFERENCED_COLUMN_NAME'] === $refCol
            && strcasecmp($c['DELETE_RULE'], $onDelete) === 0
            && strcasecmp($c['UPDATE_RULE'], 'CASCADE') === 0) {
            return true;
        }
        /* The key exists, so referential integrity already holds and the
           child rows are safe to re-validate against the rebuilt key. */
        if (!mk_exec($conn, "ALTER TABLE `$table` DROP FOREIGN KEY `$name`", $warn)) return false;
    }

    return mk_exec($conn,
        "ALTER TABLE `$table` ADD CONSTRAINT `$name`
            FOREIGN KEY (`$col`) REFERENCES `$refTable` (`$refCol`)
            ON DELETE $onDelete ON UPDATE CASCADE", $warn);
}

function mk_check_exists($conn, $table, $name) {
    try {
        $s = $conn->query("SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                            WHERE CONSTRAINT_SCHEMA = '" . DB_NAME . "'
                              AND TABLE_NAME = '$table' AND CONSTRAINT_NAME = '$name' LIMIT 1");
        return $s && $s->num_rows > 0;
    } catch (Throwable $e) { return false; }
}

function mk_check($conn, $table, $name, $expr, &$warn) {
    if (mk_check_exists($conn, $table, $name)) return true;
    return mk_exec($conn, "ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expr)", $warn);
}

/**
 * Indexes a foreign key on this table depends on.
 *
 * InnoDB satisfies a single-column foreign key with any index that
 * STARTS with the child column, so this returns every index whose
 * leading column is a foreign key column. That is deliberately a
 * superset of the one index InnoDB actually picked — over-reporting is
 * the safe direction, since it only means a drop is handled carefully.
 *
 * Portability note: MySQL exposes INDEX_NAME on KEY_COLUMN_USAGE but
 * MariaDB does not, so the index is derived from STATISTICS instead.
 * Querying INDEX_NAME straight off KEY_COLUMN_USAGE silently throws on
 * MariaDB and makes this return nothing at all, which is exactly how a
 * foreign-key-backed index ends up un-deletable and un-widenable.
 */
function mk_fk_backed_indexes($conn, $table) {
    $out = [];
    try {
        $s = $conn->query(
            "SELECT DISTINCT st.INDEX_NAME
               FROM information_schema.STATISTICS st
               JOIN information_schema.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_SCHEMA = st.TABLE_SCHEMA
                AND k.TABLE_NAME      = st.TABLE_NAME
                AND k.COLUMN_NAME     = st.COLUMN_NAME
              WHERE st.TABLE_SCHEMA = '" . DB_NAME . "'
                AND st.TABLE_NAME  = '$table'
                AND st.SEQ_IN_INDEX = 1
                AND k.REFERENCED_TABLE_NAME IS NOT NULL"
        );
        if ($s) while ($r = $s->fetch_assoc()) $out[] = $r['INDEX_NAME'];
    } catch (Throwable $e) { return []; }
    return $out;
}

/**
 * Collapse indexes that cover the exact same columns.
 *
 * An older release (or a hand-tuned table) can end up with two
 * structures such as `uniq_pair` and `uniq_conv_pair`; InnoDB then
 * pays twice on every write for no benefit. FK-backed indexes are
 * never touched.
 */
function mk_dedupe_indexes($conn, $table, array $wanted, &$warn) {
    $protected = mk_fk_backed_indexes($conn, $table);

    $all = [];
    try {
        $s = $conn->query("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '$table'
                            ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        if (!$s) return;
        while ($r = $s->fetch_assoc()) {
            $all[$r['INDEX_NAME']]['cols']  = ($all[$r['INDEX_NAME']]['cols'] ?? []);
            $all[$r['INDEX_NAME']]['cols'][] = $r['COLUMN_NAME'];
            $all[$r['INDEX_NAME']]['unique'] = ((int)$r['NON_UNIQUE'] === 0);
        }
    } catch (Throwable $e) { return; }

    $bySig = [];
    foreach ($all as $name => $meta) {
        if ($name === 'PRIMARY') continue;
        $bySig[implode(',', $meta['cols'])][] = $name;
    }

    foreach ($bySig as $sig => $names) {
        if (count($names) < 2) continue;

        /* Keep the declared name when present, else the unique index,
           else whatever a foreign key is standing on. */
        $winner = null;
        foreach ($names as $n) if (isset($wanted[$n])) { $winner = $n; break; }
        if ($winner === null) foreach ($names as $n) if ($all[$n]['unique']) { $winner = $n; break; }
        if ($winner === null) foreach ($names as $n) if (in_array($n, $protected, true)) { $winner = $n; break; }
        if ($winner === null) $winner = $names[0];

        foreach ($names as $n) {
            if ($n === $winner) continue;
            if (in_array($n, $protected, true)) {
                /* The declared index would duplicate one a FK needs:
                   keep the FK's, skip creating ours. */
                unset($wanted[$n]);
                continue;
            }
            mk_drop_index($conn, $table, $n, $warn);
        }
    }
}

/**
 * Reconcile a table's indexes with the declared design.
 *
 * @param array $defs table => [index name => '!col, col']
 *                    a leading '!' marks a UNIQUE index
 */
function mk_optimize_indexes($conn, array $defs, &$warn) {
    foreach ($defs as $table => $spec) {
        $wanted = [];
        foreach ($spec as $name => $def) {
            $unique = str_starts_with($def, '!');
            $cols   = array_map('trim', explode(',', $unique ? substr($def, 1) : $def));
            mk_index($conn, $table, $name, $cols, $unique, $warn);
            $wanted[$name] = $cols;
        }
        mk_dedupe_indexes($conn, $table, $wanted, $warn);
    }
}

function mk_column_exists($conn, $table, $col) {
    try {
        $s = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        return $s && $s->num_rows > 0;
    } catch (Throwable $e) { return false; }
}

function mk_column_info($conn, $table, $col) {
    try {
        $s = $conn->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                             FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = '" . DB_NAME . "'
                              AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col'");
        $r = $s ? $s->fetch_assoc() : null;
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Re-declare a column when an older install drifted from the target
 * type, nullability or default, so the shape converges without a data
 * copy.
 *
 * The default is compared as carefully as the type. A column can hold
 * the right type and the right nullability and still be wrong — a
 * NOT NULL column with no DEFAULT makes an INSERT that omits the column
 * fail outright — and a check that only looked at type and nullability
 * would call that converged.
 *
 * @param string $spec e.g. "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
 */
function mk_align_column($conn, $table, $col, $spec, &$warn) {
    $cur = mk_column_info($conn, $table, $col);
    if (!$cur) return true; /* column itself is missing: mk_column handles it */

    $type = trim(preg_replace('/\s+(NOT\s+NULL|NULL)\b.*$/i', '', $spec));
    $nullable = preg_match('/\bNOT\s+NULL\b/i', $spec) ? 'NO' : 'YES';

    if (strcasecmp($cur['COLUMN_TYPE'], $type) === 0
        && $cur['IS_NULLABLE'] === $nullable
        && mk_same_default(mk_spec_default($spec), $cur['COLUMN_DEFAULT'])) {
        return true;
    }
    return mk_exec($conn, "ALTER TABLE `$table` MODIFY `$col` $spec", $warn);
}

/** The default an abbreviated column spec declares, or null for none. */
function mk_spec_default($spec) {
    if (!preg_match('/\bDEFAULT\s+(.+?)\s*$/is', $spec, $m)) return null;
    return trim($m[1]);
}

/**
 * Compare a declared default against what the server reports.
 *
 * MariaDB quotes string and ENUM defaults in COLUMN_DEFAULT ('Pending')
 * and normalises CURRENT_TIMESTAMP() to CURRENT_TIMESTAMP, so both
 * sides are reduced to a lowercase, unparenthesised form first. A column
 * with no declared default and one declared DEFAULT NULL are the same
 * thing and compare equal.
 */
function mk_same_default($specDefault, $liveDefault) {
    $norm = function ($v) {
        if ($v === null) return null;
        $v = strtolower(trim((string)$v));
        $v = preg_replace('/^current_timestamp\s*\(\s*\)$/', 'current_timestamp', $v);
        return $v;
    };
    $a = $norm($specDefault);
    $b = $norm($liveDefault);
    return $a === $b;
}

function mk_column($conn, $table, $col, $def, &$warn) {
    if (mk_column_exists($conn, $table, $col)) return true;
    return mk_exec($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def", $warn);
}

function mk_table($conn, $table, $body, &$warn) {
    return mk_exec($conn,
        "CREATE TABLE IF NOT EXISTS `$table` ($body) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        $warn);
}

/** Drop an index the new design no longer needs. */
function mk_drop_index($conn, $table, $name, &$warn) {
    if (!mk_index_exists($conn, $table, $name)) return true;
    return mk_exec($conn, "ALTER TABLE `$table` DROP INDEX `$name`", $warn);
}

/** Bring a table onto the project-wide collation. */
function mk_collation($conn, $table, &$warn) {
    try {
        $s = $conn->query("SELECT TABLE_COLLATION FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '$table'");
        $now = $s ? ($s->fetch_assoc()['TABLE_COLLATION'] ?? '') : '';
    } catch (Throwable $e) { return true; }
    if ($now === '' || $now === 'utf8mb4_unicode_ci') return true;
    return mk_exec($conn, "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", $warn);
}

/* ============================================================
   TARGET SCHEMA
   ------------------------------------------------------------
   Declaration order = dependency order, so a fresh install gets
   every foreign key without a second pass.
   ============================================================ */

/* ---------- users (vendor login accounts) ---------- */
mk_table($conn, 'users', "
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username    VARCHAR(50) NOT NULL,
    email       VARCHAR(190) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    remember_token VARCHAR(64) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_username (username),
    UNIQUE KEY uniq_users_email (email),
    KEY idx_users_remember (remember_token)
", $warn);

/* ---------- admin_users (market office staff) ---------- */
mk_table($conn, 'admin_users', "
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(60) NOT NULL,
    email         VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    role          ENUM('superadmin','admin','moderator') NOT NULL DEFAULT 'admin',
    avatar_color  VARCHAR(20) NOT NULL DEFAULT '#16a34a',
    avatar_path   VARCHAR(255) DEFAULT NULL,
    last_login    DATETIME DEFAULT NULL,
    last_ip       VARCHAR(45) DEFAULT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_username (username),
    UNIQUE KEY uniq_admin_email (email),
    KEY idx_admin_role (role),
    KEY idx_admin_active (is_active)
", $warn);

/* ---------- vendors ---------- */
mk_table($conn, 'vendors', "
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED DEFAULT NULL,
    first_name    VARCHAR(80) NOT NULL,
    middle_name   VARCHAR(80) DEFAULT NULL,
    last_name     VARCHAR(80) NOT NULL,
    birthdate     DATE NOT NULL,
    gender        ENUM('Male','Female','Other') NOT NULL,
    civil_status  ENUM('Single','Married','Widowed','Separated') NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email         VARCHAR(190) DEFAULT NULL,
    avatar_path   VARCHAR(255) DEFAULT NULL,
    address       VARCHAR(255) NOT NULL,
    business_name VARCHAR(120) DEFAULT NULL,
    stall_number  VARCHAR(30) DEFAULT NULL,
    market_section VARCHAR(60) NOT NULL,
    product_type  VARCHAR(150) NOT NULL,
    years_in_business INT UNSIGNED NOT NULL DEFAULT 0,
    id_type       VARCHAR(60) NOT NULL,
    id_number     VARCHAR(60) NOT NULL,
    emergency_name VARCHAR(120) NOT NULL,
    emergency_relationship VARCHAR(60) NOT NULL,
    emergency_contact VARCHAR(20) NOT NULL,
    status        ENUM('Pending','Approved','Rejected','Suspended') NOT NULL DEFAULT 'Pending',
    access_blocked TINYINT(1) NOT NULL DEFAULT 0,
    access_kind   ENUM('none','suspended','removed') NOT NULL DEFAULT 'none',
    access_note  VARCHAR(500) DEFAULT NULL,
    access_by    VARCHAR(120) DEFAULT NULL,
    access_at    DATETIME DEFAULT NULL,
    remarks       VARCHAR(255) DEFAULT NULL,
    qr_token      VARCHAR(64) DEFAULT NULL,
    permit_status ENUM('Valid','Pending','Expired','Suspended') NOT NULL DEFAULT 'Pending',
    permit_expiry DATE DEFAULT NULL,
    payment_status ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
    payment_due   DATE DEFAULT NULL,
    last_payment_date DATE DEFAULT NULL,
    stall_status  ENUM('Occupied','Available') NOT NULL DEFAULT 'Occupied',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vendors_qr (qr_token),
    UNIQUE KEY uniq_vendors_stall (stall_number),
    KEY idx_vendors_user (user_id),
    KEY idx_vendors_status (status),
    KEY idx_vendors_section_stall (market_section, stall_number),
    KEY idx_vendors_permit (permit_status, permit_expiry),
    KEY idx_vendors_payment (payment_status, payment_due),
    CONSTRAINT fk_vendors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_vendors_stall CHECK (CHAR_LENGTH(TRIM(stall_number)) > 0),
    CONSTRAINT chk_vendors_section CHECK (CHAR_LENGTH(TRIM(market_section)) > 0)
", $warn);

/* ---------- customers ---------- */
mk_table($conn, 'customers', "
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name  VARCHAR(80) NOT NULL,
    last_name   VARCHAR(80) NOT NULL,
    email       VARCHAR(190) NOT NULL,
    phone       VARCHAR(20) DEFAULT NULL,
    address     VARCHAR(255) DEFAULT NULL,
    avatar_path VARCHAR(255) DEFAULT NULL,
    username    VARCHAR(50) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    remember_token VARCHAR(64) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login  DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_customers_username (username),
    UNIQUE KEY uniq_customers_email (email),
    KEY idx_customers_remember (remember_token)
", $warn);

/* ---------- vendor_conversations (vendor ↔ vendor) ----------
   Stored as a canonical pair: vendor_a_id is always the lower id,
   which is what makes uniq_vc_pair a real uniqueness guarantee. */
mk_table($conn, 'vendor_conversations', "
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_a_id   INT UNSIGNED NOT NULL,
    vendor_b_id   INT UNSIGNED NOT NULL,
    last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_preview VARCHAR(160) DEFAULT NULL,
    unread_a      INT UNSIGNED NOT NULL DEFAULT 0,
    unread_b      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vc_pair (vendor_a_id, vendor_b_id),
    KEY idx_vc_a (vendor_a_id, last_message_at),
    KEY idx_vc_b (vendor_b_id, last_message_at),
    CONSTRAINT fk_vc_a FOREIGN KEY (vendor_a_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vc_b FOREIGN KEY (vendor_b_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_vc_order CHECK (vendor_a_id < vendor_b_id)
", $warn);

/* ---------- vendor_messages (vendor ↔ vendor) ---------- */
mk_table($conn, 'vendor_messages', "
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    sender_vendor_id INT UNSIGNED NOT NULL,
    body            TEXT NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vm_conv (conversation_id, id),
    KEY idx_vm_unread (conversation_id, sender_vendor_id, is_read),
    CONSTRAINT fk_vm_conv FOREIGN KEY (conversation_id) REFERENCES vendor_conversations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vm_sender FOREIGN KEY (sender_vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_vm_body CHECK (CHAR_LENGTH(TRIM(body)) > 0)
", $warn);

/* ---------- announcements ----------
   The market-wide bulletin board. One row per announcement; the
   per-vendor copies live in `notifications` and point back here
   through announcement_id, so deleting a notice takes its fan-out
   with it (ON DELETE CASCADE) instead of orphaning feeds. */
mk_table($conn, 'announcements', "
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title            VARCHAR(180) NOT NULL,
    body             TEXT NOT NULL,
    category         VARCHAR(40) NOT NULL DEFAULT 'announcement',
    audience         ENUM('all','section') NOT NULL DEFAULT 'all',
    section          VARCHAR(60) DEFAULT NULL,
    is_pinned        TINYINT(1) NOT NULL DEFAULT 0,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    published_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at       DATETIME DEFAULT NULL,
    recipient_count  INT UNSIGNED NOT NULL DEFAULT 0,
    read_count       INT UNSIGNED NOT NULL DEFAULT 0,
    sms_sent         INT UNSIGNED NOT NULL DEFAULT 0,
    sms_failed       INT UNSIGNED NOT NULL DEFAULT 0,
    sms_skipped      INT UNSIGNED NOT NULL DEFAULT 0,
    sms_sent_at      DATETIME DEFAULT NULL,
    created_by       INT UNSIGNED DEFAULT NULL,
    created_by_name  VARCHAR(160) NOT NULL DEFAULT 'Market Office',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ann_feed (is_active, is_pinned, published_at),
    KEY idx_ann_expiry (is_active, expires_at),
    KEY idx_ann_audience (audience, section),
    KEY idx_ann_author (created_by),
    CONSTRAINT fk_ann_admin FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_ann_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)
", $warn);

/* ---------- notifications ---------- */
mk_table($conn, 'notifications', "
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_type    ENUM('admin','vendor','customer','system') NOT NULL DEFAULT 'system',
    sender_id      INT UNSIGNED DEFAULT NULL,
    sender_name    VARCHAR(160) NOT NULL DEFAULT 'System',
    recipient_type ENUM('admin','vendor','customer') NOT NULL DEFAULT 'vendor',
    recipient_id   INT UNSIGNED NOT NULL DEFAULT 0,
    parent_id      INT UNSIGNED DEFAULT NULL,
    announcement_id INT UNSIGNED DEFAULT NULL,
    category       VARCHAR(40) NOT NULL DEFAULT 'general',
    title          VARCHAR(180) NOT NULL DEFAULT '',
    body           TEXT DEFAULT NULL,
    link           VARCHAR(255) DEFAULT NULL,
    is_read        TINYINT(1) NOT NULL DEFAULT 0,
    read_at        DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_inbox (recipient_type, recipient_id, is_read, id),
    KEY idx_notif_feed (recipient_type, recipient_id, created_at, id),
    KEY idx_notif_sender (sender_type, sender_id),
    KEY idx_notif_parent (parent_id),
    KEY idx_notif_announcement (announcement_id, is_read),
    KEY idx_notif_category (category),
    CONSTRAINT fk_notif_parent FOREIGN KEY (parent_id) REFERENCES notifications (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notif_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_notif_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)
", $warn);

/* ---------- admin_activity_log ---------- */
mk_table($conn, 'admin_activity_log', "
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id    INT UNSIGNED NOT NULL,
    action      VARCHAR(80) NOT NULL,
    target      VARCHAR(120) DEFAULT NULL,
    details     TEXT DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_log_admin (admin_id, created_at),
    KEY idx_log_action (action, created_at),
    KEY idx_log_created (created_at),
    CONSTRAINT fk_log_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE CASCADE ON UPDATE CASCADE
", $warn);

/* ---------- stall_swap_requests ----------
   The stall numbers are snapshotted at request time on purpose: the
   row stays a faithful audit record even after the vendors trade
   stalls. Live assignments always come from vendors.stall_number. */
mk_table($conn, 'stall_swap_requests', "
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_vendor_id INT UNSIGNED NOT NULL,
    requester_stall   VARCHAR(30) NOT NULL,
    requester_section VARCHAR(60) DEFAULT NULL,
    target_vendor_id  INT UNSIGNED NOT NULL,
    target_stall      VARCHAR(30) NOT NULL,
    target_section    VARCHAR(60) DEFAULT NULL,
    reason            VARCHAR(500) DEFAULT NULL,
    conversation_id   INT UNSIGNED DEFAULT NULL,
    status            ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    decided_by        INT UNSIGNED DEFAULT NULL,
    decided_by_name   VARCHAR(160) DEFAULT NULL,
    decision_note     VARCHAR(500) DEFAULT NULL,
    decided_at        DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_swap_queue (status, id),
    KEY idx_swap_req (requester_vendor_id, status, id),
    KEY idx_swap_tgt (target_vendor_id, status, id),
    KEY idx_swap_pair (requester_vendor_id, target_vendor_id, status),
    CONSTRAINT fk_swap_requester FOREIGN KEY (requester_vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_swap_target FOREIGN KEY (target_vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_swap_conv FOREIGN KEY (conversation_id) REFERENCES vendor_conversations (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_swap_admin FOREIGN KEY (decided_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_swap_distinct CHECK (requester_vendor_id <> target_vendor_id),
    CONSTRAINT chk_swap_decided CHECK (status = 'Pending' OR decided_at IS NOT NULL)
", $warn);

/* ---------- stall_applications (vendor asks the office for a stall) ----------
   A stall is not assigned by the vendor. They ask for one, the market
   office reads the queue on its own stall map and decides — approving
   writes vendors.stall_number, declining closes the request with a
   reason. requested_stall is NULL when the vendor has no preference and
   will take whatever the office offers. */
mk_table($conn, 'stall_applications', "
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_id      INT UNSIGNED NOT NULL,
    requested_stall VARCHAR(30) DEFAULT NULL,
    request_note   VARCHAR(500) DEFAULT NULL,
    status         ENUM('Pending','Assigned','Declined') NOT NULL DEFAULT 'Pending',
    assigned_stall VARCHAR(30) DEFAULT NULL,
    decision_note  VARCHAR(500) DEFAULT NULL,
    decided_by     INT UNSIGNED DEFAULT NULL,
    decided_by_name VARCHAR(120) DEFAULT NULL,
    decided_at     DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sa_vendor (vendor_id, status),
    KEY idx_sa_status (status, created_at),
    KEY idx_sa_decider (decided_by),
    CONSTRAINT fk_sa_vendor FOREIGN KEY (vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sa_admin FOREIGN KEY (decided_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_sa_decided CHECK (status = 'Pending' OR decided_at IS NOT NULL),
    CONSTRAINT chk_sa_assigned CHECK (status <> 'Assigned' OR assigned_stall IS NOT NULL)
", $warn);

/* ---------- vendor_reports (vendor → vendor, read by the office) ----------
   A vendor reports another vendor to the market office. Both ends are
   vendors, so both are real foreign keys: the report disappears with
   either party. The office reviews the queue and closes each report;
   nothing about the reported vendor's account changes here. */
mk_table($conn, 'vendor_reports', "
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter_vendor_id INT UNSIGNED NOT NULL,
    reported_vendor_id INT UNSIGNED NOT NULL,
    reason            VARCHAR(60) NOT NULL,
    details           TEXT NOT NULL,
    status            ENUM('Open','Reviewed','Dismissed') NOT NULL DEFAULT 'Open',
    admin_note        VARCHAR(500) DEFAULT NULL,
    reviewed_by       INT UNSIGNED DEFAULT NULL,
    reviewed_by_name  VARCHAR(120) DEFAULT NULL,
    reviewed_at       DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vr_status (status, created_at),
    KEY idx_vr_reporter (reporter_vendor_id, status),
    KEY idx_vr_reported (reported_vendor_id, status),
    CONSTRAINT fk_vr_reporter FOREIGN KEY (reporter_vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vr_reported FOREIGN KEY (reported_vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vr_reviewer FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_vr_distinct CHECK (reporter_vendor_id <> reported_vendor_id),
    CONSTRAINT chk_vr_details CHECK (CHAR_LENGTH(TRIM(details)) > 0)
", $warn);

/* ---------- admin_messages (vendor <-> market office) ----------
   vendor_conversations model vendor<->vendor. The office is neither, so
   it gets its own thread: one conversation per vendor holding both
   directions, which is how an office inbox is read anyway.
   stall_requested rides along because this thread exists to ask for a
   stall, and the office should not have to guess which one was meant. */
mk_table($conn, 'admin_messages', "
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_id        INT UNSIGNED NOT NULL,
    admin_id         INT UNSIGNED DEFAULT NULL,
    direction        ENUM('vendor','admin') NOT NULL DEFAULT 'vendor',
    body             TEXT NOT NULL,
    stall_requested  VARCHAR(30) DEFAULT NULL,
    is_read          TINYINT(1) NOT NULL DEFAULT 0,
    read_at          DATETIME DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_am_vendor (vendor_id, created_at),
    KEY idx_am_inbox  (direction, is_read, created_at),
    CONSTRAINT fk_am_vendor FOREIGN KEY (vendor_id) REFERENCES vendors (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_am_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_am_body CHECK (CHAR_LENGTH(TRIM(body)) > 0)
", $warn);

/* ============================================================
   BRING AN EXISTING INSTALL UP TO THE TARGET SHAPE
   Runs only when schema_meta is behind.
   ============================================================ */
function mallengke_migrate($conn) {
    $warn = [];

    /* -- 0. retire the customer <-> vendor chat --
       The chat was removed from the product in schema 14. Drop the
       dependent table first so the foreign key releases cleanly. */
    $conn->query("DROP TABLE IF EXISTS messages");
    $conn->query("DROP TABLE IF EXISTS conversations");

    /* -- 1. one collation for the whole database -- */
    foreach (['users', 'admin_users', 'vendors', 'customers',
              'vendor_conversations', 'vendor_messages', 'notifications', 'announcements',
              'admin_activity_log', 'stall_swap_requests', 'stall_applications',
              'vendor_reports', 'admin_messages'] as $t) {
        mk_collation($conn, $t, $warn);
    }

    /* -- 2. columns that older releases may be missing -- */
    mk_column($conn, 'users', 'remember_token', 'VARCHAR(64) DEFAULT NULL', $warn);
    mk_column($conn, 'customers', 'remember_token', 'VARCHAR(64) DEFAULT NULL', $warn);
    mk_column($conn, 'customers', 'avatar_path', 'VARCHAR(255) DEFAULT NULL', $warn);
    mk_column($conn, 'admin_users', 'avatar_path', 'VARCHAR(255) DEFAULT NULL', $warn);

    $vendorCols = [
        'avatar_path'       => 'VARCHAR(255) DEFAULT NULL',
        'qr_token'          => 'VARCHAR(64) DEFAULT NULL',
        'permit_status'     => "ENUM('Valid','Pending','Expired','Suspended') NOT NULL DEFAULT 'Pending'",
        'permit_expiry'     => 'DATE DEFAULT NULL',
        'payment_status'    => "ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid'",
        'payment_due'       => 'DATE DEFAULT NULL',
        'last_payment_date' => 'DATE DEFAULT NULL',
        'stall_status'      => "ENUM('Occupied','Available') NOT NULL DEFAULT 'Occupied'",
    ];
    foreach ($vendorCols as $c => $def) mk_column($conn, 'vendors', $c, $def, $warn);

    /* Access control: why a vendor cannot sign in, who decided, and
       when. access_note is the description they read on the blocked
       screen. */
    $vendorAccessCols = [
        'access_blocked' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'access_kind'    => "ENUM('none','suspended','removed') NOT NULL DEFAULT 'none'",
        'access_note'    => 'VARCHAR(500) DEFAULT NULL',
        'access_by'      => 'VARCHAR(120) DEFAULT NULL',
        'access_at'      => 'DATETIME DEFAULT NULL',
    ];
    foreach ($vendorAccessCols as $c => $def) mk_column($conn, 'vendors', $c, $def, $warn);

    $notifCols = [
        'sender_type'    => "ENUM('admin','vendor','customer','system') NOT NULL DEFAULT 'system'",
        'sender_id'      => 'INT UNSIGNED DEFAULT NULL',
        'sender_name'    => "VARCHAR(160) NOT NULL DEFAULT 'System'",
        'recipient_type' => "ENUM('admin','vendor','customer') NOT NULL DEFAULT 'vendor'",
        'recipient_id'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'parent_id'      => 'INT UNSIGNED DEFAULT NULL',
        'announcement_id'=> 'INT UNSIGNED DEFAULT NULL',
        'category'       => "VARCHAR(40) NOT NULL DEFAULT 'general'",
        'title'          => "VARCHAR(180) NOT NULL DEFAULT ''",
        'body'           => 'TEXT DEFAULT NULL',
        'link'           => 'VARCHAR(255) DEFAULT NULL',
        'is_read'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'read_at'        => 'DATETIME DEFAULT NULL',
    ];
    foreach ($notifCols as $c => $def) mk_column($conn, 'notifications', $c, $def, $warn);

    $annCols = [
        'category'        => "VARCHAR(40) NOT NULL DEFAULT 'announcement'",
        'audience'        => "ENUM('all','section') NOT NULL DEFAULT 'all'",
        'section'         => 'VARCHAR(60) DEFAULT NULL',
        'is_pinned'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_active'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'published_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'expires_at'      => 'DATETIME DEFAULT NULL',
        'recipient_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'read_count'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'sms_sent'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'sms_failed'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'sms_skipped'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'sms_sent_at'     => 'DATETIME DEFAULT NULL',
        'created_by'      => 'INT UNSIGNED DEFAULT NULL',
        'created_by_name' => "VARCHAR(160) NOT NULL DEFAULT 'Market Office'",
    ];
    foreach ($annCols as $c => $def) mk_column($conn, 'announcements', $c, $def, $warn);

    $swapCols = [
        'requester_stall'  => "VARCHAR(30) NOT NULL DEFAULT ''",
        'requester_section'=> 'VARCHAR(60) DEFAULT NULL',
        'target_stall'     => "VARCHAR(30) NOT NULL DEFAULT ''",
        'target_section'   => 'VARCHAR(60) DEFAULT NULL',
        'reason'           => 'VARCHAR(500) DEFAULT NULL',
        'conversation_id'  => 'INT UNSIGNED DEFAULT NULL',
        'decided_by'       => 'INT UNSIGNED DEFAULT NULL',
        'decided_by_name'  => 'VARCHAR(160) DEFAULT NULL',
        'decision_note'    => 'VARCHAR(500) DEFAULT NULL',
        'decided_at'       => 'DATETIME DEFAULT NULL',
    ];
    foreach ($swapCols as $c => $def) mk_column($conn, 'stall_swap_requests', $c, $def, $warn);

    /* -- 2b. converge drifted column definitions -- */
    mk_align_column($conn, 'vendor_conversations', 'last_message_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $warn);
    mk_align_column($conn, 'vendor_conversations', 'last_message_preview', 'VARCHAR(160) DEFAULT NULL', $warn);
    mk_align_column($conn, 'vendors', 'stall_status', "ENUM('Occupied','Available') NOT NULL DEFAULT 'Occupied'", $warn);
    /* Only the office assigns a stall now, so a vendor can be without
       one. The UNIQUE key still holds — MySQL allows any number of NULLs
       and keeps one vendor per real stall number. */
    mk_align_column($conn, 'vendors', 'stall_number', 'VARCHAR(30) DEFAULT NULL', $warn);
    /* Widening the ENUM keeps every existing row, so an install created
       before suspensions existed simply gains the one new state. */
    mk_align_column($conn, 'vendors', 'status', "ENUM('Pending','Approved','Rejected','Suspended') NOT NULL DEFAULT 'Pending'", $warn);
    mk_align_column($conn, 'vendor_messages', 'is_read', 'TINYINT(1) NOT NULL DEFAULT 0', $warn);

    /* NOT NULL columns that had lost their default: the type and the
       nullability were already correct, so nothing noticed until an
       INSERT that omitted the column hit them under strict mode. */
    mk_align_column($conn, 'notifications', 'sender_type',
        "ENUM('admin','vendor','customer','system') NOT NULL DEFAULT 'system'", $warn);
    mk_align_column($conn, 'notifications', 'sender_name',
        "VARCHAR(160) NOT NULL DEFAULT 'System'", $warn);
    mk_align_column($conn, 'notifications', 'recipient_type',
        "ENUM('admin','vendor','customer') NOT NULL DEFAULT 'vendor'", $warn);
    mk_align_column($conn, 'notifications', 'recipient_id', 'INT UNSIGNED NOT NULL DEFAULT 0', $warn);
    mk_align_column($conn, 'stall_swap_requests', 'requester_stall', "VARCHAR(30) NOT NULL DEFAULT ''", $warn);
    mk_align_column($conn, 'stall_swap_requests', 'target_stall',    "VARCHAR(30) NOT NULL DEFAULT ''", $warn);
    mk_align_column($conn, 'admin_users', 'avatar_color', "VARCHAR(20) NOT NULL DEFAULT '#16a34a'", $warn);

    /* -- 3. clean the data so the constraints below can hold -- */

    /* Conversation lists order by last_message_at; NULL would sort
       them into a separate group, so anchor them to creation time. */
    mk_run($conn, "UPDATE vendor_conversations SET last_message_at = created_at WHERE last_message_at IS NULL");

    /* Orphaned rows: a foreign key can only be added once they are gone. */
    mk_run($conn, "DELETE vm FROM vendor_messages vm LEFT JOIN vendor_conversations vc ON vc.id = vm.conversation_id WHERE vc.id IS NULL");
    mk_run($conn, "DELETE vc FROM vendor_conversations vc LEFT JOIN vendors a ON a.id = vc.vendor_a_id LEFT JOIN vendors b ON b.id = vc.vendor_b_id WHERE a.id IS NULL OR b.id IS NULL");
    mk_run($conn, "DELETE vm FROM vendor_messages vm LEFT JOIN vendors v ON v.id = vm.sender_vendor_id WHERE v.id IS NULL");
    /* MariaDB refuses a subquery on the table being updated, so the
       self-join does the orphan check. */
    mk_run($conn, "UPDATE notifications n LEFT JOIN notifications p ON p.id = n.parent_id
                    SET n.parent_id = NULL WHERE n.parent_id IS NOT NULL AND p.id IS NULL");
    mk_run($conn, "UPDATE notifications n LEFT JOIN announcements a ON a.id = n.announcement_id
                    SET n.announcement_id = NULL WHERE n.announcement_id IS NOT NULL AND a.id IS NULL");

    /* Restore the canonical pair order the application relies on.
       The self-join reads both sides from one snapshot, and the
       unread counters travel with the side they belong to. */
    mk_run($conn, "DELETE FROM vendor_conversations WHERE vendor_a_id = vendor_b_id");
    mk_run($conn, "UPDATE vendor_conversations vc
                     JOIN vendor_conversations t ON t.id = vc.id
                    SET vc.vendor_a_id = t.vendor_b_id,
                        vc.vendor_b_id = t.vendor_a_id,
                        vc.unread_a   = t.unread_b,
                        vc.unread_b   = t.unread_a
                  WHERE vc.vendor_a_id > vc.vendor_b_id");
    /* ...and keep the message senders inside their own conversation. */
    mk_run($conn, "DELETE vm FROM vendor_messages vm JOIN vendor_conversations vc ON vc.id = vm.conversation_id
                    WHERE vm.sender_vendor_id NOT IN (vc.vendor_a_id, vc.vendor_b_id)");

    mk_run($conn, "UPDATE stall_swap_requests SET conversation_id = NULL WHERE conversation_id IS NOT NULL
                    AND conversation_id NOT IN (SELECT id FROM vendor_conversations)");
    mk_run($conn, "UPDATE stall_swap_requests SET decided_by = NULL WHERE decided_by IS NOT NULL
                    AND decided_by NOT IN (SELECT id FROM admin_users)");
    mk_run($conn, "DELETE FROM stall_swap_requests WHERE requester_vendor_id = target_vendor_id");
    mk_run($conn, "DELETE FROM stall_swap_requests WHERE requester_vendor_id NOT IN (SELECT id FROM vendors)
                    OR target_vendor_id NOT IN (SELECT id FROM vendors)");
    mk_run($conn, "UPDATE stall_swap_requests SET decided_at = NOW() WHERE status <> 'Pending' AND decided_at IS NULL");
    mk_run($conn, "DELETE FROM admin_activity_log WHERE admin_id NOT IN (SELECT id FROM admin_users)");

    /* Stall requests follow the same housekeeping: an orphaned request
       cannot keep a foreign key, and a closed one needs its timestamp. */
    mk_run($conn, "DELETE FROM stall_applications WHERE vendor_id NOT IN (SELECT id FROM vendors)");
    mk_run($conn, "UPDATE announcements SET created_by = NULL WHERE created_by IS NOT NULL
                    AND created_by NOT IN (SELECT id FROM admin_users)");
    mk_run($conn, "UPDATE stall_applications SET decided_by = NULL WHERE decided_by IS NOT NULL
                    AND decided_by NOT IN (SELECT id FROM admin_users)");
    mk_run($conn, "UPDATE stall_applications SET decided_at = NOW() WHERE status <> 'Pending' AND decided_at IS NULL");

    /* Office message threads follow the same rules: an orphan cannot keep
       a foreign key, and a read message needs its timestamp. */
    mk_run($conn, "DELETE FROM admin_messages WHERE vendor_id NOT IN (SELECT id FROM vendors)");
    mk_run($conn, "UPDATE admin_messages SET admin_id = NULL WHERE admin_id IS NOT NULL
                    AND admin_id NOT IN (SELECT id FROM admin_users)");
    mk_run($conn, "UPDATE admin_messages SET read_at = NOW() WHERE is_read = 1 AND read_at IS NULL");
    mk_run($conn, "UPDATE stall_applications SET assigned_stall = NULL WHERE status = 'Declined'");
    /* One open request per vendor, oldest kept — the queue the office
       reads has to have a single row per applicant. */
    mk_run($conn, "DELETE sa FROM stall_applications sa
                     JOIN stall_applications keep
                       ON keep.vendor_id = sa.vendor_id
                      AND keep.status = 'Pending'
                      AND (keep.created_at < sa.created_at
                           OR (keep.created_at = sa.created_at AND keep.id < sa.id))
                    WHERE sa.status = 'Pending'");

    /* vendors.stall_status used to mean "this row exists", so it read
       Occupied for everybody. It is now derived from the stall the
       office actually gave them. */
    mk_run($conn, "UPDATE vendors SET stall_status = 'Available'
                    WHERE (stall_number IS NULL OR stall_number = '') AND stall_status <> 'Available'");
    mk_run($conn, "UPDATE vendors SET stall_status = 'Occupied'
                    WHERE stall_number IS NOT NULL AND stall_number <> '' AND stall_status <> 'Occupied'");

    /* A stall can only be held by one vendor. If legacy rows violate
       that, keep the migration alive and report it instead of
       silently dropping vendor records. NULL (no stall yet) is the
       normal state now and is never a duplicate. */
    $dupStalls = [];
    try {
        if ($s = $conn->query(
            "SELECT stall_number, COUNT(*) c FROM vendors
              WHERE stall_number IS NOT NULL AND stall_number <> ''
              GROUP BY stall_number HAVING c > 1"
        )) {
            $dupStalls = $s->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Throwable $e) { $dupStalls = []; }
    $uniqueStall = !$dupStalls;

    /* -- 4. indexes shaped after the real queries --
       A leading '!' marks a UNIQUE index. Composite order mirrors the
       WHERE / ORDER BY of the queries that use it. */
    $targetIndexes = [
        'users' => [
            'uniq_users_username' => '!username',
            'uniq_users_email'    => '!email',
            'idx_users_remember'  => 'remember_token',
        ],
        'admin_users' => [
            'uniq_admin_username' => '!username',
            'uniq_admin_email'    => '!email',
            'idx_admin_role'      => 'role',
            'idx_admin_active'    => 'is_active',
        ],
        'vendors' => [
            'uniq_vendors_qr'           => '!qr_token',
            'uniq_vendors_stall'        => '!stall_number',
            'idx_vendors_user'          => 'user_id',
            'idx_vendors_status'        => 'status',
            'idx_vendors_section_stall' => 'market_section, stall_number',
            'idx_vendors_permit'        => 'permit_status, permit_expiry',
            'idx_vendors_payment'       => 'payment_status, payment_due',
        ],
        'customers' => [
            'uniq_customers_username' => '!username',
            'uniq_customers_email'    => '!email',
            'idx_customers_remember'  => 'remember_token',
        ],
        'vendor_conversations' => [
            'uniq_vc_pair' => '!vendor_a_id, vendor_b_id',
            'idx_vc_a'     => 'vendor_a_id, last_message_at',
            'idx_vc_b'     => 'vendor_b_id, last_message_at',
        ],
        'vendor_messages' => [
            'idx_vm_conv'   => 'conversation_id, id',
            'idx_vm_unread' => 'conversation_id, sender_vendor_id, is_read',
        ],
        'notifications' => [
            'idx_notif_inbox'       => 'recipient_type, recipient_id, is_read, id',
            'idx_notif_feed'        => 'recipient_type, recipient_id, created_at, id',
            'idx_notif_sender'      => 'sender_type, sender_id',
            'idx_notif_parent'      => 'parent_id',
            'idx_notif_announcement'=> 'announcement_id, is_read',
            'idx_notif_category'    => 'category',
        ],
        'announcements' => [
            'idx_ann_feed'     => 'is_active, is_pinned, published_at',
            'idx_ann_expiry'   => 'is_active, expires_at',
            'idx_ann_audience' => 'audience, section',
            'idx_ann_author'   => 'created_by',
        ],
        'admin_activity_log' => [
            'idx_log_admin'   => 'admin_id, created_at',
            'idx_log_action'  => 'action, created_at',
            'idx_log_created' => 'created_at',
        ],
        'stall_swap_requests' => [
            'idx_swap_queue' => 'status, id',
            'idx_swap_req'   => 'requester_vendor_id, status, id',
            'idx_swap_tgt'   => 'target_vendor_id, status, id',
            'idx_swap_pair'  => 'requester_vendor_id, target_vendor_id, status',
        ],
        'vendor_reports' => [
            'idx_vr_status'   => 'status, created_at',
            'idx_vr_reporter' => 'reporter_vendor_id, status',
            'idx_vr_reported' => 'reported_vendor_id, status',
        ],
        'stall_applications' => [
            'idx_sa_status'  => 'status, created_at',
            /* Left-prefixed by vendor_id so fk_sa_vendor is served by this
               same index — one structure covers both the foreign key and
               the "this vendor's requests, newest first" lookup. */
            'idx_sa_vendor'  => 'vendor_id, status',
            'idx_sa_decider' => 'decided_by',
        ],
        'admin_messages' => [
            'idx_am_vendor' => 'vendor_id, created_at',
            'idx_am_inbox'  => 'direction, is_read, created_at',
        ],
    ];

    if ($uniqueStall) {
        mk_optimize_indexes($conn, $targetIndexes, $warn);
    } else {
        /* Duplicate stalls are a data problem, not a schema one — keep
           the index but report it instead of forcing a UNIQUE. */
        $warn[] = 'vendors.stall_number has ' . count($dupStalls) . ' duplicate value(s) — UNIQUE not applied';
        unset($targetIndexes['vendors']['uniq_vendors_stall']);
        mk_optimize_indexes($conn, $targetIndexes, $warn);
    }

    /* -- 5. drop indexes the new design supersedes --
       (mk_optimize_indexes only collapses duplicates; these have no
       replacement at all and would just slow writes down.) */
    mk_drop_index($conn, 'vendor_messages', 'idx_vm_read', $warn);
    mk_drop_index($conn, 'vendor_messages', 'idx_vm_sender', $warn);
    mk_drop_index($conn, 'vendor_messages', 'idx_vm_created', $warn);
    mk_drop_index($conn, 'vendor_conversations', 'idx_vc_last', $warn);
    mk_drop_index($conn, 'stall_swap_requests', 'idx_swap_status', $warn);
    mk_drop_index($conn, 'stall_swap_requests', 'idx_swap_requester', $warn);
    mk_drop_index($conn, 'stall_swap_requests', 'idx_swap_target', $warn);
    mk_drop_index($conn, 'stall_swap_requests', 'idx_swap_created', $warn);

    /* -- 6. foreign keys: every child column points at its parent -- */
    mk_fk($conn, 'vendors', 'fk_vendors_user', 'user_id', 'users', 'id', 'SET NULL', $warn);
    mk_fk($conn, 'stall_applications', 'fk_sa_vendor', 'vendor_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'stall_applications', 'fk_sa_admin', 'decided_by', 'admin_users', 'id', 'SET NULL', $warn);
    mk_fk($conn, 'admin_messages', 'fk_am_vendor', 'vendor_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'admin_messages', 'fk_am_admin', 'admin_id', 'admin_users', 'id', 'SET NULL', $warn);

    mk_fk($conn, 'vendor_conversations', 'fk_vc_a', 'vendor_a_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'vendor_conversations', 'fk_vc_b', 'vendor_b_id', 'vendors', 'id', 'CASCADE', $warn);

    mk_fk($conn, 'vendor_messages', 'fk_vm_conv', 'conversation_id', 'vendor_conversations', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'vendor_messages', 'fk_vm_sender', 'sender_vendor_id', 'vendors', 'id', 'CASCADE', $warn);

    mk_fk($conn, 'notifications', 'fk_notif_parent', 'parent_id', 'notifications', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'notifications', 'fk_notif_announcement', 'announcement_id', 'announcements', 'id', 'CASCADE', $warn);

    /* The office staff who authored a notice and the staff member who
       decided a stall request. Both keep a *_by_name snapshot, so losing
       the key on account deletion must not lose the row that records it. */
    mk_fk($conn, 'announcements', 'fk_ann_admin', 'created_by', 'admin_users', 'id', 'SET NULL', $warn);

    mk_fk($conn, 'admin_activity_log', 'fk_log_admin', 'admin_id', 'admin_users', 'id', 'CASCADE', $warn);

    mk_fk($conn, 'stall_swap_requests', 'fk_swap_requester', 'requester_vendor_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'stall_swap_requests', 'fk_swap_target', 'target_vendor_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'stall_swap_requests', 'fk_swap_conv', 'conversation_id', 'vendor_conversations', 'id', 'SET NULL', $warn);
    mk_fk($conn, 'stall_swap_requests', 'fk_swap_admin', 'decided_by', 'admin_users', 'id', 'SET NULL', $warn);

    mk_fk($conn, 'vendor_reports', 'fk_vr_reporter', 'reporter_vendor_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'vendor_reports', 'fk_vr_reported', 'reported_vendor_id', 'vendors', 'id', 'CASCADE', $warn);
    mk_fk($conn, 'vendor_reports', 'fk_vr_reviewer', 'reviewed_by', 'admin_users', 'id', 'SET NULL', $warn);

    /* -- 7. column-level rules -- */
    mk_check($conn, 'vendors', 'chk_vendors_stall', "CHAR_LENGTH(TRIM(stall_number)) > 0", $warn);
    mk_check($conn, 'vendors', 'chk_vendors_section', "CHAR_LENGTH(TRIM(market_section)) > 0", $warn);
    mk_check($conn, 'vendor_messages', 'chk_vm_body', 'CHAR_LENGTH(TRIM(body)) > 0', $warn);
    mk_check($conn, 'vendor_conversations', 'chk_vc_order', 'vendor_a_id < vendor_b_id', $warn);
    mk_check($conn, 'notifications', 'chk_notif_title', "CHAR_LENGTH(TRIM(title)) > 0", $warn);
    mk_check($conn, 'announcements', 'chk_ann_title', "CHAR_LENGTH(TRIM(title)) > 0", $warn);
    mk_check($conn, 'stall_swap_requests', 'chk_swap_distinct', 'requester_vendor_id <> target_vendor_id', $warn);
    mk_check($conn, 'stall_swap_requests', 'chk_swap_decided', "status = 'Pending' OR decided_at IS NOT NULL", $warn);
    mk_check($conn, 'vendor_reports', 'chk_vr_distinct', 'reporter_vendor_id <> reported_vendor_id', $warn);
    mk_check($conn, 'stall_applications', 'chk_sa_decided', "status = 'Pending' OR decided_at IS NOT NULL", $warn);
    mk_check($conn, 'stall_applications', 'chk_sa_assigned', "status <> 'Assigned' OR assigned_stall IS NOT NULL", $warn);
    mk_check($conn, 'admin_messages', 'chk_am_body', 'CHAR_LENGTH(TRIM(body)) > 0', $warn);

    return $warn;
}

/* ============================================================
   VERSION GATE
   One tiny SELECT on a normal request; the DDL only runs when the
   stored version is behind MOLLENGKE_SCHEMA_VERSION.
   ============================================================ */
$applied = null;
$notifTableWasMissing = false;
try {
    $s = $conn->query("SELECT meta_value FROM schema_meta WHERE meta_key = 'schema_version' LIMIT 1");
    if ($s->num_rows > 0) $applied = (int)($s->fetch_assoc()['meta_value'] ?? 0);
} catch (Throwable $e) {
    $applied = null; /* no version marker yet → migrate on this request */
}

if ($applied === null) {
    mk_run($conn,
        "CREATE TABLE IF NOT EXISTS schema_meta (
            meta_key   VARCHAR(64) NOT NULL,
            meta_value TEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (meta_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Only relevant while migrating: did this install ever have the
       notifications table? (decides the one-time welcome note) */
    try {
        $t = $conn->query("SHOW TABLES LIKE 'notifications'");
        $notifTableWasMissing = !$t || $t->num_rows === 0;
    } catch (Throwable $e) {
        $notifTableWasMissing = true;
    }
}

if ($applied !== MOLLENGKE_SCHEMA_VERSION) {
    $migrationWarn = mallengke_migrate($conn);

    $report = 'schema_version=' . MOLLENGKE_SCHEMA_VERSION
            . ' applied_at=' . date('c')
            . ($migrationWarn ? ' warnings=' . count($migrationWarn) : ' warnings=0');
    if ($migrationWarn) $report .= ' || ' . implode(' || ', array_slice($migrationWarn, 0, 10));

    mk_run($conn,
        "INSERT INTO schema_meta (meta_key, meta_value) VALUES ('schema_version', '" . (int)MOLLENGKE_SCHEMA_VERSION . "')
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
    );
    mk_run($conn,
        "INSERT INTO schema_meta (meta_key, meta_value) VALUES ('last_migration', '" . $conn->real_escape_string($report) . "')
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
    );
}

/* ============================================================
   SEED DATA  (first run only)
   ============================================================ */
function mallengke_seed($conn, $notifTableWasMissing) {
    if ($notifTableWasMissing) {
        if ($res = $conn->query(
            "SELECT v.id, v.first_name
               FROM vendors v
              WHERE v.user_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n
                     WHERE n.recipient_type = 'vendor' AND n.recipient_id = v.id
                )"
        )) {
            $welcome = $conn->prepare(
                "INSERT INTO notifications
                    (sender_type, sender_id, sender_name, recipient_type, recipient_id, category, title, body, link)
                 VALUES ('system', NULL, 'Market Office', 'vendor', ?, 'system', 'Welcome to Mallengke', ?, 'dashboard.php')"
            );
            if ($welcome) {
                while ($r = $res->fetch_assoc()) {
                    $name = trim((string)($r['first_name'] ?? ''));
                    $msg  = 'Hi ' . ($name !== '' ? $name : 'Vendor') . '! Your vendor account is registered. '
                          . 'The market office will send updates about your permit, payments and stall here.';
                    $welcome->bind_param('is', $r['id'], $msg);
                    $welcome->execute();
                }
                $welcome->close();
            }
        }
    }

    if ((int)($conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] ?? 0) === 0) {
        $adminPass = password_hash('password123', PASSWORD_DEFAULT);
        $demoPass  = password_hash('demo123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
        if ($stmt) {
            $u = 'admin'; $e = 'admin@mollengke.local'; $stmt->bind_param('sss', $u, $e, $adminPass); $stmt->execute();
            $u = 'demo';  $e = 'demo@mollengke.local';  $stmt->bind_param('sss', $u, $e, $demoPass);  $stmt->execute();
            $stmt->close();
        }
    }

    if ((int)($conn->query('SELECT COUNT(*) AS total FROM admin_users')->fetch_assoc()['total'] ?? 0) === 0) {
        $defaultAdmins = [
            ['admin',     'admin@mallengke.local',     'Admin@123', 'Market Administrator', 'superadmin', '#16a34a'],
            ['moderator', 'moderator@mallengke.local', 'Mod@123',   'Market Moderator',      'moderator', '#0ea5e9'],
        ];
        $stmt = $conn->prepare(
            "INSERT INTO admin_users (username, email, password_hash, full_name, role, avatar_color)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            foreach ($defaultAdmins as $a) {
                $hash = password_hash($a[2], PASSWORD_DEFAULT);
                $stmt->bind_param('ssssss', $a[0], $a[1], $hash, $a[3], $a[4], $a[5]);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

try {
    mallengke_seed($conn, !empty($notifTableWasMissing));
} catch (Throwable $e) {
    /* Seeding is best-effort: never let it break a page load. */
}

$conn->set_charset('utf8mb4');
return $conn;
