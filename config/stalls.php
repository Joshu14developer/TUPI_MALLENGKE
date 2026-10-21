<?php
/* ============================================================
   STALLS
   ------------------------------------------------------------
   The market layout and everything that turns it into assignments.

   Three rules hold the whole feature together:

     1. A stall number belongs to exactly one vendor. That is carried by
        a UNIQUE key on vendors.stall_number, so it is the database's
        answer, not ours.
     2. A vendor never assigns a stall to themselves. They file a
        request (stall_apply) and the market office decides
        (stall_assign). Until then their stall_number is NULL, which is
        a normal state — several vendors can be without one, and the
        maps simply show them as not yet placed.
     3. The office may also move a vendor who already holds a stall.
        That is the same call, so it goes through the same transaction
        and leaves the same record behind.

   The layout lives here, once, so the vendor map, the customer map and
   the office map cannot drift apart.
   ============================================================ */

/**
 * Every section and the stalls in it, in walking order.
 *
 * @return array section label => list of stall codes
 */
function stall_layout() {
    return [
        'Meat'             => ['A-01','A-02','A-03','A-04','A-05','A-06','A-07','A-08','A-09','A-10','A-11','A-12'],
        'Fish & Seafood'   => ['B-01','B-02','B-03','B-04','B-05','B-06','B-07','B-08'],
        'Vegetables'       => ['C-01','C-02','C-03','C-04','C-05','C-06','C-07','C-08','C-09','C-10'],
        'Fruits'           => ['D-01','D-02','D-03','D-04','D-05','D-06'],
        'Rice & Grains'    => ['E-01','E-02','E-03','E-04','E-05','E-06','E-07','E-08'],
        'Dry Goods'        => ['F-01','F-02','F-03','F-04','F-05','F-06','F-07','F-08','F-09','F-10'],
        'Bread & Pastries' => ['G-01','G-02','G-03','G-04','G-05','G-06'],
        'Processed Foods'  => ['H-01','H-02','H-03','H-04','H-05','H-06','H-07','H-08'],
        'Others'           => ['I-01','I-02','I-03','I-04'],
    ];
}

/** The two halves of the floor plan, as the maps draw them. */
function stall_wings() {
    return [
        [
            ['section' => 'Meat',             'letter' => 'A'],
            ['section' => 'Vegetables',       'letter' => 'C'],
            ['section' => 'Rice & Grains',    'letter' => 'E'],
            ['section' => 'Bread & Pastries', 'letter' => 'G'],
            ['section' => 'Others',           'letter' => 'I'],
        ],
        [
            ['section' => 'Fish & Seafood', 'letter' => 'B'],
            ['section' => 'Fruits',         'letter' => 'D'],
            ['section' => 'Dry Goods',      'letter' => 'F'],
            ['section' => 'Processed Foods','letter' => 'H'],
        ],
    ];
}

/** Which section a stall code belongs to, or '' when it is not a real one. */
function stall_section($stall) {
    $needle = strtoupper(trim((string)$stall));
    if ($needle === '') return '';
    foreach (stall_layout() as $section => $stalls) {
        if (in_array($needle, $stalls, true)) return $section;
    }
    return '';
}

/** Is this a stall the market actually has? */
function stall_exists($stall) {
    return stall_section($stall) !== '';
}

/**
 * Who holds each stall, keyed by upper-case stall code.
 *
 * Vendors without a stall are simply absent from the map — that is the
 * state the office is trying to fix, so it must not read as occupied.
 *
 * @return array code => ['id','name','business','section','status','permit','payment','stall',
 *                         'contact','products','years']
 */
function stall_occupancy($conn) {
    $out = [];
    if (!$res = $conn->query(
        "SELECT id, stall_number, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name,
                business_name, market_section, status, permit_status, payment_status,
                contact_number, product_type, years_in_business
           FROM vendors
          WHERE stall_number IS NOT NULL AND stall_number <> ''"
    )) return $out;

    while ($row = $res->fetch_assoc()) {
        $code = strtoupper(trim((string)$row['stall_number']));
        if ($code === '') continue;
        $out[$code] = [
            'id'       => (int)$row['id'],
            'name'     => trim((string)$row['name']) ?: 'Unnamed vendor',
            'business' => (string)($row['business_name'] ?? ''),
            'section'  => (string)($row['market_section'] ?? ''),
            'status'   => (string)($row['status'] ?? ''),
            'permit'   => (string)($row['permit_status'] ?? ''),
            'payment'  => (string)($row['payment_status'] ?? ''),
            'stall'    => (string)$row['stall_number'],
            'contact'  => (string)($row['contact_number'] ?? ''),
            'products' => (string)($row['product_type'] ?? ''),
            'years'    => (int)($row['years_in_business'] ?? 0),
        ];
    }
    return $out;
}

/** Totals for the strip counters every map shows. */
function stall_counts($conn) {
    $layout = stall_layout();
    $held   = stall_occupancy($conn);

    $total = 0;
    foreach ($layout as $stalls) $total += count($stalls);

    $occupied = 0;
    foreach ($layout as $stalls) {
        foreach ($stalls as $s) {
            if (isset($held[strtoupper($s)])) $occupied++;
        }
    }

    return [
        'total'     => $total,
        'occupied'  => $occupied,
        'available' => max(0, $total - $occupied),
    ];
}

/** A vendor's own row, by their session, or null. */
function stall_vendor_from_session($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, first_name, last_name, stall_number, market_section, status, access_kind,
                business_name, product_type, years_in_business
           FROM vendors WHERE user_id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $userId = (int)$userId;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ============================================================
   THE VENDOR SIDE: asking for a stall
   ============================================================ */

/** How the office can answer a request. */
function stall_application_meta() {
    return [
        'Pending'  => ['label' => 'Waiting for the office', 'icon' => 'fa-hourglass-half', 'color' => 'amber'],
        'Assigned' => ['label' => 'Stall assigned',        'icon' => 'fa-circle-check',   'color' => 'green'],
        'Declined' => ['label' => 'Not granted',           'icon' => 'fa-circle-xmark',   'color' => 'red'],
    ];
}

/**
 * File a request for a stall.
 *
 * $requestedStall may be '' for "I will take whatever is free". A
 * vendor who already holds a stall may still ask to be moved; the
 * office decides. One open request per vendor — asking again replaces
 * the old answer instead of stacking up in the queue.
 *
 * @return array ['ok' => bool, 'error' => string, 'id' => int]
 */
function stall_apply($conn, $vendorId, $requestedStall = '', $note = '') {
    $out = ['ok' => false, 'error' => '', 'id' => 0];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'No vendor account to apply with.'; return $out; }

    if (!$stmt = $conn->prepare("SELECT id FROM vendors WHERE id = ? LIMIT 1")) {
        $out['error'] = 'Could not check your account.';
        return $out;
    }
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($exists <= 0) { $out['error'] = 'That vendor account no longer exists.'; return $out; }

    /* A preference has to be a stall the market actually has. */
    $requestedStall = strtoupper(trim((string)$requestedStall));
    if ($requestedStall !== '' && !stall_exists($requestedStall)) {
        $out['error'] = 'Stall ' . $requestedStall . ' is not part of this market.';
        return $out;
    }

    $note = trim((string)$note);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    /* Asking again replaces the earlier request rather than queueing
       a second one for the same person. */
    if ($del = $conn->prepare("DELETE FROM stall_applications WHERE vendor_id = ? AND status = 'Pending'")) {
        $del->bind_param('i', $vendorId);
        $del->execute();
        $del->close();
    }

    $stmt = $conn->prepare(
        "INSERT INTO stall_applications (vendor_id, requested_stall, request_note)
         VALUES (?, ?, ?)"
    );
    if (!$stmt) { $out['error'] = 'Could not file your request.'; return $out; }

    $wanted = $requestedStall !== '' ? $requestedStall : null;
    $stmt->bind_param('iss', $vendorId, $wanted, $note);
    $ok = $stmt->execute();
    $out['id'] = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok) { $out['error'] = 'Could not file your request.'; return $out; }

    /* Asking means they have nowhere to trade yet. Registration calls
       this too, so the stall_status flag can never disagree with the
       stall they actually hold. */
    if ($u = $conn->prepare(
        "UPDATE vendors SET stall_status = 'Available'
          WHERE id = ? AND (stall_number IS NULL OR stall_number = '')"
    )) {
        $u->bind_param('i', $vendorId);
        $u->execute();
        $u->close();
    }

    $out['ok'] = true;
    return $out;
}

/** A vendor's open request, or their most recent one, or null. */
function stall_application_for_vendor($conn, $vendorId) {
    $stmt = $conn->prepare(
        "SELECT * FROM stall_applications
          WHERE vendor_id = ?
          ORDER BY (status = 'Pending') DESC, created_at DESC, id DESC
          LIMIT 1"
    );
    if (!$stmt) return null;
    $vendorId = (int)$vendorId;
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ============================================================
   THE OFFICE SIDE: reading the queue and deciding
   ============================================================ */

/**
 * The request queue, newest first, with the applicant joined in.
 *
 * @param string $filter 'pending' | 'assigned' | 'declined' | 'all'
 */
function stall_applications_list($conn, $filter = 'pending', $search = '') {
    $where = [];
    $params = [];
    $types  = '';

    if ($filter === 'pending')       $where[] = "sa.status = 'Pending'";
    elseif ($filter === 'assigned')  $where[] = "sa.status = 'Assigned'";
    elseif ($filter === 'declined')  $where[] = "sa.status = 'Declined'";

    $search = trim((string)$search);
    if ($search !== '') {
        $where[] = "(v.first_name LIKE ? OR v.last_name LIKE ? OR v.business_name LIKE ?"
                 . " OR sa.requested_stall LIKE ? OR sa.request_note LIKE ? OR v.stall_number LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 6; $i++) { $params[] = $like; $types .= 's'; }
    }

    $sql = "SELECT sa.*,
                   v.first_name, v.middle_name, v.last_name, v.business_name,
                   v.stall_number AS current_stall, v.market_section AS current_section,
                   v.status AS vendor_status, v.permit_status, v.payment_status,
                   v.access_kind
              FROM stall_applications sa
              JOIN vendors v ON v.id = sa.vendor_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY (sa.status = 'Pending') DESC, sa.created_at DESC, sa.id DESC";

    $rows = [];
    if ($stmt = $conn->prepare($sql)) {
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    }
    return $rows;
}

/** Counts per status, for the queue tabs. */
function stall_application_counts($conn) {
    $out = ['pending' => 0, 'assigned' => 0, 'declined' => 0, 'all' => 0];
    if (!$res = $conn->query(
        "SELECT status, COUNT(*) c FROM stall_applications GROUP BY status"
    )) return $out;
    while ($r = $res->fetch_assoc()) {
        $out['all'] += (int)$r['c'];
        $key = strtolower((string)$r['status']);
        if (isset($out[$key])) $out[$key] += (int)$r['c'];
    }
    return $out;
}

/** The badge number for the sidebar: requests waiting on the office. */
function stall_pending_count($conn) {
    if (!$res = $conn->query("SELECT COUNT(*) c FROM stall_applications WHERE status = 'Pending'")) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/** Vendors the office has not placed yet — they need a stall. */
function stall_unassigned($conn) {
    $rows = [];
    if (!$res = $conn->query(
        "SELECT id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name,
                business_name, market_section, product_type, status, created_at
           FROM vendors
          WHERE stall_number IS NULL OR stall_number = ''
          ORDER BY created_at ASC"
    )) return $rows;
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

/** How many vendors still have nowhere to trade. */
function stall_unassigned_count($conn) {
    if (!$res = $conn->query(
        "SELECT COUNT(*) c FROM vendors WHERE stall_number IS NULL OR stall_number = ''"
    )) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/**
 * Give a vendor a stall. This is the only thing in the application that
 * writes vendors.stall_number.
 *
 * Works for a vendor who has none and for one who already holds a stall
 * (a move). The UNIQUE key means a swap cannot be done one statement at
 * a time, so the row is parked on a temporary code inside a transaction
 * and the real value is written after — the temporary code can never
 * escape, because anything failing rolls the whole thing back.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function stall_assign($conn, $vendorId, $stallNumber, $adminId = 0, $adminName = 'Market Office', $note = '') {
    $out = ['ok' => false, 'error' => '', 'moved' => false];

    $vendorId = (int)$vendorId;
    $stallNumber = strtoupper(trim((string)$stallNumber));

    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }
    if ($stallNumber === '') { $out['error'] = 'Pick a stall first.'; return $out; }

    $section = stall_section($stallNumber);
    if ($section === '') { $out['error'] = 'Stall ' . $stallNumber . ' is not part of this market.'; return $out; }

    $vendor = null;
    if ($stmt = $conn->prepare("SELECT id, stall_number FROM vendors WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $vendor = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$vendor) { $out['error'] = 'That vendor no longer exists.'; return $out; }

    $fromStall = strtoupper(trim((string)($vendor['stall_number'] ?? '')));
    if ($fromStall === $stallNumber) {
        /* Already there. Succeeding is right, but 'changed' is not — the
           caller must not notify or log a move that did not happen. */
        $out['ok']    = true;
        $out['moved'] = false;
        return $out;
    }

    /* Somebody else is in it — the office has to move them out first,
       and doing that by hand is safer than silently overwriting. */
    $holder = null;
    if ($stmt = $conn->prepare(
        "SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name
           FROM vendors WHERE stall_number = ? AND id <> ? LIMIT 1"
    )) {
        $stmt->bind_param('si', $stallNumber, $vendorId);
        $stmt->execute();
        $holder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($holder) {
        $out['error'] = 'Stall ' . $stallNumber . ' is held by '
                      . (trim((string)$holder['name']) ?: 'another vendor')
                      . '. Move or release them first.';
        return $out;
    }

    $note = trim((string)$note);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    if (!$conn->query('START TRANSACTION')) { $out['error'] = 'Could not start the change.'; return $out; }

    if ($fromStall !== '') {
        $park = '~assign~' . $vendorId;
        if (!$u = $conn->prepare("UPDATE vendors SET stall_number = ? WHERE id = ?")) {
            $conn->query('ROLLBACK'); $out['error'] = 'Could not move the vendor off their old stall.'; return $out;
        }
        $u->bind_param('si', $park, $vendorId);
        $ok = $u->execute();
        $u->close();
        if (!$ok) { $conn->query('ROLLBACK'); $out['error'] = 'Could not move the vendor off their old stall.'; return $out; }
    }

    if (!$u = $conn->prepare(
        "UPDATE vendors SET stall_number = ?, market_section = ?, stall_status = 'Occupied', updated_at = NOW()
          WHERE id = ?"
    )) {
        $conn->query('ROLLBACK'); $out['error'] = 'Could not write the stall.'; return $out;
    }
    $u->bind_param('ssi', $stallNumber, $section, $vendorId);
    $ok = $u->execute();
    $u->close();
    if (!$ok) { $conn->query('ROLLBACK'); $out['error'] = 'Could not write the stall.'; return $out; }

    /* Answer the request this assignment settles, if there is one. */
    $aid = (int)$adminId;
    $by  = (string)$adminName;
    if (!$u = $conn->prepare(
        "UPDATE stall_applications
            SET status = 'Assigned', assigned_stall = ?, decision_note = ?,
                decided_by = ?, decided_by_name = ?, decided_at = NOW()
          WHERE vendor_id = ? AND status = 'Pending'"
    )) {
        $conn->query('ROLLBACK'); $out['error'] = 'Could not record the decision.'; return $out;
    }
    $decision = $note !== '' ? $note : null;
    $decidedBy = $aid > 0 ? $aid : null;
    /* Order matters: stall(s), decision(s), decided_by(i), by(s), vendor(i) */
    $u->bind_param('ssisi', $stallNumber, $decision, $decidedBy, $by, $vendorId);
    $ok = $u->execute();
    $u->close();
    if (!$ok) { $conn->query('ROLLBACK'); $out['error'] = 'Could not record the decision.'; return $out; }

    if (!$conn->query('COMMIT')) { $conn->query('ROLLBACK'); $out['error'] = 'Could not save the change.'; return $out; }

    $out['ok']    = true;
    $out['moved'] = true;
    return $out;
}

/**
 * Take a stall away without giving another one — the vendor goes back
 * to "not placed" and can apply again.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function stall_release($conn, $vendorId, $adminId = 0, $adminName = 'Market Office', $note = '') {
    $out = ['ok' => false, 'error' => ''];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }

    $note = trim((string)$note);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    $stmt = $conn->prepare("SELECT stall_number FROM vendors WHERE id = ? LIMIT 1");
    if (!$stmt) { $out['error'] = 'Could not check that vendor.'; return $out; }
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $was = (string)($stmt->get_result()->fetch_assoc()['stall_number'] ?? '');
    $stmt->close();

    if (trim($was) === '') { $out['ok'] = true; return $out; }

    if (!$stmt = $conn->prepare(
        "UPDATE vendors SET stall_number = NULL, stall_status = 'Available', updated_at = NOW() WHERE id = ?"
    )) { $out['error'] = 'Could not release that stall.'; return $out; }
    $stmt->bind_param('i', $vendorId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { $out['error'] = 'Could not release that stall.'; return $out; }

    $aid = (int)$adminId;
    $by  = (string)$adminName;
    if ($stmt = $conn->prepare(
        "INSERT INTO stall_applications (vendor_id, requested_stall, request_note, status, decision_note, decided_by, decided_by_name, decided_at)
         VALUES (?, NULL, ?, 'Declined', ?, ?, ?, NOW())"
    )) {
        $message = $note !== ''
            ? $note
            : 'Stall ' . strtoupper($was) . ' was released by the market office. Please apply again.';
        $decidedBy = $aid > 0 ? $aid : null;
    $stmt->bind_param('issis', $vendorId, $message, $message, $decidedBy, $by);
        $stmt->execute();
        $stmt->close();
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Close a request without granting it. The note is what the vendor
 * reads, so a blank one is refused.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function stall_decline($conn, $applicationId, $adminId = 0, $adminName = 'Market Office', $note = '') {
    $out = ['ok' => false, 'error' => ''];

    $applicationId = (int)$applicationId;
    if ($applicationId <= 0) { $out['error'] = 'Pick a request first.'; return $out; }

    $note = trim((string)$note);
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);
    if ($note === '') {
        $out['error'] = 'Write a short reason — the vendor reads it on their stall page.';
        return $out;
    }

    $stmt = $conn->prepare(
        "UPDATE stall_applications
            SET status = 'Declined', decision_note = ?, decided_by = ?, decided_by_name = ?, decided_at = NOW()
          WHERE id = ? AND status = 'Pending'"
    );
    if (!$stmt) { $out['error'] = 'Could not record that decision.'; return $out; }

    $aid = (int)$adminId;
    $by  = (string)$adminName;
    $decidedBy = $aid > 0 ? $aid : null;
    /* Order matters: decision(s), decided_by(i), by(s), id(i) */
    $stmt->bind_param('sisi', $note, $decidedBy, $by, $applicationId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!$ok) { $out['error'] = 'Could not record that decision.'; return $out; }
    if ($affected < 1) { $out['error'] = 'That request was already answered.'; return $out; }

    $out['ok'] = true;
    return $out;
}

/**
 * Tell a vendor where they stand on their own stall.
 *
 * One place decides the wording, so the vendor map, the dashboard and
 * the notifications cannot tell three different stories.
 *
 * @param array|null $vendor    the vendor row
 * @param array|null $app       their latest request
 * @return array ['state' => string, 'title' => string, 'body' => string, 'tone' => string]
 */
function stall_status_for_vendor($vendor, $app = null) {
    $stall = strtoupper(trim((string)($vendor['stall_number'] ?? '')));
    $declined = ($app && $app['status'] === 'Declined')
        ? trim((string)($app['decision_note'] ?? ''))
        : '';

    if ($stall !== '') {
        /* They still trade somewhere, but a refused move is worth
           telling them about — otherwise the office's answer to their
           request would go unread. */
        return [
            'state' => $declined !== '' ? 'placed_declined' : 'placed',
            'title' => 'You trade at Stall ' . $stall,
            'body'  => $declined !== ''
                ? 'Your last request was not granted: ' . $declined
                  . ' You can apply again from this page.'
                : '',
            'tone'  => $declined !== '' ? 'red' : 'green',
        ];
    }

    if ($app && $app['status'] === 'Pending') {
        $wanted = strtoupper(trim((string)($app['requested_stall'] ?? '')));
        return [
            'state' => 'waiting',
            'title' => $wanted !== ''
                ? 'Waiting for the office — you asked for Stall ' . $wanted
                : 'Waiting for the office to offer you a stall',
            'body'  => 'You have no stall yet. The market office assigns stalls, so you will see it here as soon as they decide.',
            'tone'  => 'amber',
        ];
    }

    if ($app && $app['status'] === 'Declined') {
        $reason = trim((string)($app['decision_note'] ?? ''));
        return [
            'state' => 'declined',
            'title' => 'Your last request was not granted',
            'body'  => ($reason !== '' ? $reason . ' ' : 'The market office did not grant that request. ')
                     . 'You can apply again from your stall map.',
            'tone'  => 'red',
        ];
    }

    return [
        'state' => 'none',
        'title' => 'You do not have a stall yet',
        'body'  => 'Only the market office can assign a stall. Ask for one below and they will decide.',
        'tone'  => 'amber',
    ];
}
