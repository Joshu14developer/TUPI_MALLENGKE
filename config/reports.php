<?php
/* ============================================================
   VENDOR REPORTS
   ------------------------------------------------------------
   A vendor reports another vendor to the market office; the office
   works through the queue on admin_reports.php.

   What this file deliberately does NOT do: change anything about the
   reported vendor. Suspending or banning an account is a separate
   decision with its own flow — a report is evidence, not a penalty,
   so filing one never touches the vendor's status, permit or login.

   Shape of the table:
     reporter_vendor_id   who filed it   } both vendors, so both are
     reported_vendor_id   who it is about } real foreign keys
     status               Open → Reviewed | Dismissed
     admin_note           why the office closed it that way

   Include this file anywhere a $conn (mysqli) is available.
   ============================================================ */

/* ============================================================
   REASONS
   ------------------------------------------------------------
   A fixed list, so the office can triage by kind and a vendor
   cannot file an empty or absurd report.
   ============================================================ */

function report_reasons() {
    return [
        'misconduct'  => ['label' => 'Misconduct at the stall',   'icon' => 'fa-user-slash',    'color' => 'red'],
        'overpricing' => ['label' => 'Overpricing / cheating',    'icon' => 'fa-tag',            'color' => 'amber'],
        'false_info'  => ['label' => 'False or misleading info', 'icon' => 'fa-comment-dots',   'color' => 'blue'],
        'dirty_stall' => ['label' => 'Dirty or unsafe stall',     'icon' => 'fa-broom',          'color' => 'sky'],
        'no_show'     => ['label' => 'Absent from assigned stall','icon' => 'fa-door-closed',    'color' => 'purple'],
        'harassment'  => ['label' => 'Harassment or abuse',       'icon' => 'fa-hand-fist',      'color' => 'red'],
        'other'       => ['label' => 'Something else',            'icon' => 'fa-circle-question', 'color' => 'green'],
    ];
}

/** How the office closes a report. */
function report_status_meta() {
    return [
        'Open'      => ['label' => 'Needs review', 'icon' => 'fa-inbox',         'color' => 'amber'],
        'Reviewed'  => ['label' => 'Reviewed',     'icon' => 'fa-clipboard-check','color' => 'green'],
        'Dismissed' => ['label' => 'Dismissed',    'icon' => 'fa-box-archive',   'color' => 'muted'],
    ];
}

/* ============================================================
   FILING A REPORT
   ============================================================ */

/**
 * The vendor behind this session, or null when nobody is signed in.
 * The reporter is taken from the session, never from the request, so
 * nobody can file a report as somebody else.
 *
 * @return array|null ['id' => int, 'name' => string, 'stall' => string]
 */
function report_reporter($conn) {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) return null;

    if (!$stmt = $conn->prepare(
        "SELECT id, first_name, last_name, stall_number, status
           FROM vendors WHERE user_id = ? LIMIT 1"
    )) return null;

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) return null;

    return [
        'id'    => (int)$v['id'],
        'name'  => trim($v['first_name'] . ' ' . $v['last_name']) ?: 'Vendor #' . (int)$v['id'],
        'stall' => (string)$v['stall_number'],
        'status'=> (string)$v['status'],
    ];
}

/**
 * File one report about another vendor.
 *
 * @param mysqli $conn
 * @param array  $reporter  report_reporter()
 * @param int    $vendorId  the vendor being reported
 * @param string $reason    key of report_reasons()
 * @param string $details   what happened, in the reporter's words
 * @return array ['ok' => bool, 'id' => int, 'error' => string]
 */
function report_create($conn, array $reporter, $vendorId, $reason, $details) {
    $out = ['ok' => false, 'id' => 0, 'error' => ''];

    $vendorId = (int)$vendorId;
    $reason   = trim((string)$reason);
    $details  = trim((string)$details);

    if ((int)($reporter['id'] ?? 0) <= 0) {
        $out['error'] = 'Please sign in before reporting a vendor.';
        return $out;
    }
    if ($vendorId <= 0) {
        $out['error'] = 'Pick the vendor you want to report.';
        return $out;
    }
    if ((int)$reporter['id'] === $vendorId) {
        $out['error'] = 'You cannot report your own stall.';
        return $out;
    }

    $reasons = report_reasons();
    if (!isset($reasons[$reason])) {
        $out['error'] = 'Choose what you are reporting.';
        return $out;
    }
    if (mb_strlen($details) < 10) {
        $out['error'] = 'Please describe what happened (at least 10 characters).';
        return $out;
    }
    if (mb_strlen($details) > 1500) $details = mb_substr($details, 0, 1500);

    /* The stall has to exist and be trading: a pending application or
       a stall already closed cannot be reported. */
    if (!$stmt = $conn->prepare(
        "SELECT id, first_name, last_name, business_name, stall_number, status
           FROM vendors WHERE id = ? LIMIT 1"
    )) { $out['error'] = 'Could not look up that vendor.'; return $out; }

    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$vendor)                    { $out['error'] = 'That vendor no longer exists.'; return $out; }
    if ($vendor['status'] === 'Pending') { $out['error'] = 'That stall is not trading yet.'; return $out; }
    if ($vendor['status'] === 'Rejected') { $out['error'] = 'That stall is not trading.'; return $out; }

    /* One open report per pair is enough — a second copy tells the
       office nothing new, and stops a stall being flooded. */
    if ($stmt = $conn->prepare(
        "SELECT id FROM vendor_reports
          WHERE reporter_vendor_id = ? AND reported_vendor_id = ? AND status = 'Open'
          LIMIT 1"
    )) {
        $rid = (int)$reporter['id'];
        $stmt->bind_param('ii', $rid, $vendorId);
        $stmt->execute();
        $dupe = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
        if ($dupe > 0) {
            $out['error'] = 'You already have an open report about this vendor (#' . $dupe . ').';
            return $out;
        }
    }

    if (!$stmt = $conn->prepare(
        "INSERT INTO vendor_reports (reporter_vendor_id, reported_vendor_id, reason, details)
         VALUES (?, ?, ?, ?)"
    )) { $out['error'] = 'Could not save the report.'; return $out; }

    $rid = (int)$reporter['id'];
    $stmt->bind_param('iiss', $rid, $vendorId, $reason, $details);
    if (!$stmt->execute()) {
        $out['error'] = 'Could not save the report: ' . $stmt->error;
        $stmt->close();
        return $out;
    }
    $stmt->close();

    $out['ok'] = true;
    $out['id'] = (int)$conn->insert_id;
    return $out;
}

/** How many reports the office still has to look at (sidebar badge). */
function report_open_count($conn) {
    if (!$res = $conn->query("SELECT COUNT(*) AS c FROM vendor_reports WHERE status = 'Open'")) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/** Counts for the admin stat row. */
function report_counts($conn) {
    $out = ['open' => 0, 'reviewed' => 0, 'dismissed' => 0, 'total' => 0];

    if ($res = $conn->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'Open'), 0)      AS open_cnt,
                COALESCE(SUM(status = 'Reviewed'), 0)  AS reviewed,
                COALESCE(SUM(status = 'Dismissed'), 0) AS dismissed
           FROM vendor_reports"
    )) {
        $row = $res->fetch_assoc() ?: [];
        $out['total']     = (int)($row['total'] ?? 0);
        $out['open']      = (int)($row['open_cnt'] ?? 0);
        $out['reviewed']  = (int)($row['reviewed'] ?? 0);
        $out['dismissed'] = (int)($row['dismissed'] ?? 0);
    }
    return $out;
}

/* ============================================================
   THE OFFICE'S QUEUE
   ============================================================ */

/**
 * The queue, newest first, with both vendors resolved.
 *
 * @param string $filter 'open' | 'reviewed' | 'dismissed' | 'all'
 * @param string $search free text over names, stalls and the details
 */
function report_list($conn, $filter = 'open', $search = '') {
    $where = [];
    $types = '';
    $vals  = [];

    $map = [
        'open'      => "r.status = 'Open'",
        'reviewed'  => "r.status = 'Reviewed'",
        'dismissed' => "r.status = 'Dismissed'",
    ];
    if (isset($map[$filter])) $where[] = $map[$filter];

    $search = trim((string)$search);
    if ($search !== '') {
        $where[] = "(rv.first_name LIKE ? OR rv.last_name LIKE ? OR rv.business_name LIKE ?
                     OR rv.stall_number LIKE ? OR tv.first_name LIKE ? OR tv.last_name LIKE ?
                     OR tv.business_name LIKE ? OR tv.stall_number LIKE ? OR r.details LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 9; $i++) $vals[] = $like;
        $types .= str_repeat('s', 9);
    }

    $sql = "SELECT r.*,
                   TRIM(CONCAT_WS(' ', rv.first_name, rv.middle_name, rv.last_name)) AS reporter_name,
                   rv.business_name AS reporter_biz, rv.stall_number AS reporter_stall,
                   rv.market_section AS reporter_section, rv.status AS reporter_status,
                   TRIM(CONCAT_WS(' ', tv.first_name, tv.middle_name, tv.last_name)) AS target_name,
                   tv.business_name AS target_biz, tv.stall_number AS target_stall,
                   tv.market_section AS target_section, tv.status AS target_status,
                   tv.access_blocked AS target_blocked, tv.access_kind AS target_access_kind,
                   tv.access_note AS target_access_note,
                   tv.access_by AS target_access_by, tv.access_at AS target_access_at,
                   (SELECT COUNT(*) FROM vendor_reports x
                     WHERE x.reported_vendor_id = r.reported_vendor_id AND x.status = 'Open') AS open_against
              FROM vendor_reports r
              JOIN vendors rv ON rv.id = r.reporter_vendor_id
              JOIN vendors tv ON tv.id = r.reported_vendor_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 200';

    $rows = [];
    if (!$stmt = $conn->prepare($sql)) return $rows;
    if ($vals) $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    return $rows;
}

/* ============================================================
   ACCESS CONTROL
   ------------------------------------------------------------
   The office can take a vendor out of the application. Two levers:

     Suspend  → access_kind = 'suspended'. Nothing is destroyed, so
                Restore puts them back exactly as they were.
     Remove access → access_kind = 'removed'. The credential is
                destroyed, so the account can never be used again —
                but the vendor row, their stall, payments and whole
                message history stay, and the login form can still
                recognise the username to show them the reason.

   A removed login is deliberately a one-way door: restoring it would
   mean handing the password back. vendor_reissue_login() is the only
   way back, and the office sets the new password themselves — from the
   reports queue or from the vendor's own record.
   ============================================================ */

/**
 * What sits in users.password once the office has removed a login.
 *
 * It is not a password hash, so password_verify() can never match it
 * against anything anybody types: the account is inert, not merely
 * locked, and the row itself is kept so the vendor's history stays.
 */
define('MK_REMOVED_PASSWORD', 'removed-by-market-office');

/**
 * Has this login been destroyed by the office?
 *
 * @param mysqli $conn
 * @return bool
 */
function vendor_credential_gone($conn, $userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return false;

    $hash = null;
    if ($stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $hash = $stmt->get_result()->fetch_assoc()['password'] ?? null;
        $stmt->close();
    }
    return $hash !== null && (string)$hash === MK_REMOVED_PASSWORD;
}

/**
 * Why a vendor cannot sign in, or null when they can.
 *
 * @return array|null ['kind' => 'removed'|'suspended',
 *                     'name' => string, 'stall' => string,
 *                     'description' => string]
 */
function vendor_access_block($conn, $vendorId) {
    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) return null;

    $row = null;
    if ($stmt = $conn->prepare(
        "SELECT first_name, last_name, stall_number, user_id, status,
                access_blocked, access_kind, access_note
           FROM vendors WHERE id = ? LIMIT 1"
    )) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$row) return null;

    $name  = trim($row['first_name'] . ' ' . $row['last_name']) ?: 'Vendor';
    $stall = (string)$row['stall_number'];

    $kind = (string)($row['access_kind'] ?? 'none');
    if ($kind === 'none' && ((int)($row['access_blocked'] ?? 0) === 1 || $row['status'] === 'Suspended')) {
        $kind = 'suspended';   /* a row written before access_kind existed */
    }

    /* A credential that has been destroyed is a removal, even if the
       kind column somehow still says otherwise. */
    if ($kind !== 'removed' && (int)($row['user_id'] ?? 0) > 0
        && vendor_credential_gone($conn, (int)$row['user_id'])) {
        $kind = 'removed';
    }

    if ($kind !== 'suspended' && $kind !== 'removed') return null;

    $note = trim((string)($row['access_note'] ?? ''));
    if ($note === '') {
        $note = $kind === 'removed'
            ? 'Your login account for this stall has been removed by the market office. '
              . 'Please visit the market office if you think this is a mistake.'
            : 'Your stall access has been suspended by the market office.';
    }

    return [
        'kind'        => $kind,
        'name'        => $name,
        'stall'       => $stall,
        'description' => $note,
    ];
}

/**
 * Block a vendor. The description is what they read on the suspended
 * screen, so a blank one gets a plain default rather than nothing.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function vendor_suspend($conn, $vendorId, $description = '', $adminName = 'Market Office') {
    $out = ['ok' => false, 'error' => ''];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }

    $description = trim((string)$description);
    if (mb_strlen($description) > 500) $description = mb_substr($description, 0, 500);
    if ($description === '') {
        $out['error'] = 'Write a short description — the vendor reads it when they try to sign in.';
        return $out;
    }

    if (!$stmt = $conn->prepare(
        "UPDATE vendors
            SET status = 'Suspended', access_blocked = 1, access_kind = 'suspended',
                access_note = ?, access_by = ?, access_at = NOW()
          WHERE id = ?"
    )) { $out['error'] = 'Could not suspend that vendor.'; return $out; }

    $by = (string)$adminName;
    $stmt->bind_param('ssi', $description, $by, $vendorId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { $out['error'] = 'Could not suspend that vendor.'; return $out; }

    $out['ok'] = true;
    return $out;
}

/**
 * Let a suspended vendor back in. The note is kept on the record as
 * the reason they were reinstated.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function vendor_restore($conn, $vendorId, $note = '', $adminName = 'Market Office') {
    $out = ['ok' => false, 'error' => ''];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }

    $note = trim((string)$note);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    $row = null;
    if ($stmt = $conn->prepare("SELECT status, access_kind FROM vendors WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$row) { $out['error'] = 'That vendor no longer exists.'; return $out; }

    $status = (string)($row['status'] ?? '');
    if ($status === 'Pending') { $out['error'] = 'That vendor has no account to restore yet.'; return $out; }
    if ($status === 'Rejected') { $out['error'] = 'That application was rejected — approve it first.'; return $out; }

    /* Their password was destroyed. Nothing to unlock — the office
       has to issue a new one from the vendor's record. */
    if ((string)($row['access_kind'] ?? '') === 'removed') {
        $out['error'] = 'That login was removed, so there is no password to restore. '
                      . 'Issue a new password on the vendor\'s record to bring them back.';
        return $out;
    }

    if (!$stmt = $conn->prepare(
        "UPDATE vendors
            SET status = 'Approved', access_blocked = 0, access_kind = 'none',
                access_note = ?, access_by = ?, access_at = NOW()
          WHERE id = ?"
    )) { $out['error'] = 'Could not restore that vendor.'; return $out; }

    $by = (string)$adminName;
    $stmt->bind_param('ssi', $note, $by, $vendorId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { $out['error'] = 'Could not restore that vendor.'; return $out; }

    $out['ok'] = true;
    return $out;
}

/**
 * Remove a vendor's login for good.
 *
 * The password is replaced with a value no input can ever match and
 * the "remember me" token is dropped, so the account cannot be signed
 * into again by anyone, including the vendor. Everything else is left
 * alone: the vendor row, their stall, permit, payments, vendor chats
 * and every notification stay exactly where they are, and the login
 * form can still recognise the username so the vendor reads why.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function vendor_remove_access($conn, $vendorId, $reason = '') {
    $out = ['ok' => false, 'error' => ''];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }

    $userId = 0;
    if ($stmt = $conn->prepare("SELECT user_id FROM vendors WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $userId = (int)($stmt->get_result()->fetch_assoc()['user_id'] ?? 0);
        $stmt->close();
    }
    if ($userId <= 0) { $out['error'] = 'That vendor has no login account to remove.'; return $out; }
    if (vendor_credential_gone($conn, $userId)) {
        $out['error'] = 'That login has already been removed.';
        return $out;
    }

    /* Destroy the credential and the cookie token in one go. */
    if (!$stmt = $conn->prepare(
        "UPDATE users SET password = ?, remember_token = NULL WHERE id = ?"
    )) { $out['error'] = 'Could not remove that vendor\'s login.'; return $out; }

    $gone = MK_REMOVED_PASSWORD;
    $stmt->bind_param('si', $gone, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { $out['error'] = 'Could not remove that vendor\'s login.'; return $out; }

    $note = trim((string)$reason);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);
    if ($note === '') $note = 'Your login account has been removed by the market office.';

    if ($stmt = $conn->prepare(
        "UPDATE vendors
            SET access_blocked = 1, access_kind = 'removed',
                access_note = ?, access_by = 'Market office', access_at = NOW()
          WHERE id = ?"
    )) {
        $stmt->bind_param('si', $note, $vendorId);
        $stmt->execute();
        $stmt->close();
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Give a removed login a new password.
 *
 * This is the only way back from a removal, and it is deliberately an
 * explicit act: the office types the new password, nobody else can.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function vendor_reissue_login($conn, $vendorId, $newPassword, $adminName = 'Market Office') {
    $out = ['ok' => false, 'error' => ''];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }

    $newPassword = (string)$newPassword;
    if (mb_strlen($newPassword) < 8) {
        $out['error'] = 'The new password needs at least 8 characters.';
        return $out;
    }

    $userId = 0;
    if ($stmt = $conn->prepare("SELECT user_id FROM vendors WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $userId = (int)($stmt->get_result()->fetch_assoc()['user_id'] ?? 0);
        $stmt->close();
    }
    if ($userId <= 0) { $out['error'] = 'That vendor has no login to re-issue.'; return $out; }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!$hash) { $out['error'] = 'Could not create the new password.'; return $out; }

    if (!$stmt = $conn->prepare(
        "UPDATE users SET password = ?, remember_token = NULL WHERE id = ?"
    )) { $out['error'] = 'Could not re-issue that login.'; return $out; }
    $stmt->bind_param('si', $hash, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { $out['error'] = 'Could not re-issue that login.'; return $out; }

    $by = (string)$adminName;
    if ($stmt = $conn->prepare(
        "UPDATE vendors
            SET status = 'Approved', access_blocked = 0, access_kind = 'none',
                access_note = 'Login re-issued by the market office.',
                access_by = ?, access_at = NOW()
          WHERE id = ?"
    )) {
        $stmt->bind_param('si', $by, $vendorId);
        $stmt->execute();
        $stmt->close();
    }

    $out['ok'] = true;
    return $out;
}

/** How many vendors the office has currently blocked (stat + badge). */
function vendor_blocked_count($conn) {
    if (!$res = $conn->query(
        "SELECT COUNT(*) AS c FROM vendors WHERE access_blocked = 1 OR status = 'Suspended'"
    )) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/* ============================================================
   CLOSING A REPORT
   ============================================================ */

/**
 * The office marks a report reviewed or dismisses it. The note is the
 * audit trail; the reported vendor's account is not touched.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function report_close($conn, $reportId, $status, $note = '', $adminName = 'Market Office') {
    $out = ['ok' => false, 'error' => ''];

    $reportId = (int)$reportId;
    $status   = in_array($status, ['Reviewed', 'Dismissed'], true) ? $status : '';
    if ($reportId <= 0 || $status === '') {
        $out['error'] = 'That is not a valid action.';
        return $out;
    }

    $note = trim((string)$note);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    if (!$stmt = $conn->prepare(
        "SELECT id, reported_vendor_id, status FROM vendor_reports WHERE id = ? LIMIT 1"
    )) { $out['error'] = 'Could not load that report.'; return $out; }

    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) { $out['error'] = 'That report no longer exists.'; return $out; }
    if ($report['status'] !== 'Open') {
        $out['error'] = 'That report was already closed — nothing changed.';
        return $out;
    }

    if (!$stmt = $conn->prepare(
        "UPDATE vendor_reports
            SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW()
          WHERE id = ? AND status = 'Open'"
    )) { $out['error'] = 'Could not record the decision.'; return $out; }

    $adminId = (int)($_SESSION['admin_id'] ?? 0) ?: null;
    $by      = (string)$adminName;
    $stmt->bind_param('ssssi', $status, $note, $adminId, $by, $reportId);
    if (!$stmt->execute()) {
        $out['error'] = 'Could not record the decision: ' . $stmt->error;
        $stmt->close();
        return $out;
    }
    $stmt->close();

    $out['ok'] = true;
    return $out;
}