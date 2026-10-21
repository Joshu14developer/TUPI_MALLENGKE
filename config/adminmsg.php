<?php
/* ============================================================
   VENDOR <-> MARKET OFFICE MESSAGES
   ------------------------------------------------------------
   The second way to get a stall. The first is stall_apply(), which
   files a structured request the office reads on the stall map. This
   is the other half: a plain conversation, so a vendor who wants to
   explain their business, or ask a question the form does not cover,
   has somewhere to say so.

   Why its own table and not one of the existing conversation tables:
   vendor_conversations model vendor <-> vendor. The office is neither,
   and the shape it actually needs is simpler than either: one thread
   per vendor, both directions, newest last.

   The default message text lives here too, so the vendor, the office
   and the tests all read the same sentence rather than three copies of
   it drifting apart.
   ============================================================ */

define('AM_MAX_BODY', 2000);

/* It writes a notification when the office replies, and it checks a
   stall against the real layout, so both have to be here rather than
   left to whichever page happened to include this first. */
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/stalls.php';

/**
 * The message the vendor can send the office about getting a stall,
 * written from their own record and whatever stall they selected.
 *
 * This is what the vendor sees pre-filled in the box. They are free to
 * rewrite all of it — it is a starting point, not a fixed message.
 *
 * @param array       $vendor vendor row
 * @param string      $stall  the stall they picked in the selector ('' if any)
 * @return string
 */
function am_default_text($vendor, $stall = '') {
    $first = trim(explode(' ', trim((string)($vendor['first_name'] ?? '')))[0] ?? '');
    $name  = $first !== ''
        ? $first . ' ' . trim((string)($vendor['last_name'] ?? ''))
        : (trim((string)($vendor['last_name'] ?? '')) ?: 'Vendor');
    $biz   = trim((string)($vendor['business_name'] ?? '')) ?: 'my business';
    $sell  = trim((string)($vendor['product_type'] ?? '')) ?: 'my products';
    $sec   = trim((string)($vendor['market_section'] ?? ''));

    $stall = strtoupper(trim((string)$stall));
    if ($stall !== '') {
        $want = 'I would like to get Stall ' . $stall
              . ($sec !== '' ? ' in the ' . $sec . ' section' : '')
              . ' for my stall at Mallengke Market.';
    } else {
        $want = 'I would like to get a stall for my business at Mallengke Market. '
              . 'I am open to whichever stall is available.';
    }

    return "Hello Market Office.\n\n"
         . "My name is {$name} and I run {$biz}. "
         . "{$want}\n\n"
         . "I sell {$sell}. "
         . "Please let me know which stall is available for me, and what I need to do "
         . "to start trading.\n\n"
         . "Thank you.";
}

/**
 * Vendor -> office. The stall they picked is stored alongside so the
 * office does not have to read it out of the prose.
 *
 * @return array ['ok' => bool, 'error' => string, 'id' => int]
 */
function am_send_from_vendor($conn, $vendorId, $body, $stall = '') {
    $out = ['ok' => false, 'error' => '', 'id' => 0];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'No vendor account to send from.'; return $out; }

    if (!$stmt = $conn->prepare("SELECT id FROM vendors WHERE id = ? LIMIT 1")) {
        $out['error'] = 'Could not check your account.';
        return $out;
    }
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($exists <= 0) { $out['error'] = 'That vendor account no longer exists.'; return $out; }

    $body = trim((string)$body);
    if ($body === '') {
        $out['error'] = 'Write a message to the market office first.';
        return $out;
    }
    if (mb_strlen($body) > AM_MAX_BODY) {
        $out['error'] = 'That message is too long — please keep it under ' . AM_MAX_BODY . ' characters.';
        return $out;
    }

    /* A stall is only recorded when it is one the market actually has;
       "any available" stays NULL. */
    $stall = strtoupper(trim((string)$stall));
    $want  = ($stall !== '' && stall_exists($stall)) ? $stall : null;

    $stmt = $conn->prepare(
        "INSERT INTO admin_messages (vendor_id, direction, body, stall_requested)
         VALUES (?, 'vendor', ?, ?)"
    );
    if (!$stmt) { $out['error'] = 'Could not send your message.'; return $out; }

    $stmt->bind_param('iss', $vendorId, $body, $want);
    $ok = $stmt->execute();
    $out['id'] = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok) { $out['error'] = 'Could not send your message.'; return $out; }
    $out['ok'] = true;
    return $out;
}

/**
 * Office -> vendor.
 *
 * @return array ['ok' => bool, 'error' => string, 'id' => int]
 */
function am_reply_from_admin($conn, $vendorId, $adminId, $adminName, $body) {
    $out = ['ok' => false, 'error' => '', 'id' => 0];

    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) { $out['error'] = 'Pick a vendor first.'; return $out; }

    $body = trim((string)$body);
    if ($body === '') { $out['error'] = 'Write a reply first.'; return $out; }
    if (mb_strlen($body) > AM_MAX_BODY) {
        $out['error'] = 'That reply is too long — please keep it under ' . AM_MAX_BODY . ' characters.';
        return $out;
    }

    $aid = (int)$adminId;
    $by  = (string)$adminName;

    $stmt = $conn->prepare(
        "INSERT INTO admin_messages (vendor_id, admin_id, direction, body)
         VALUES (?, ?, 'admin', ?)"
    );
    if (!$stmt) { $out['error'] = 'Could not send the reply.'; return $out; }

    $aid = (int)$adminId;
    $by  = (string)$adminName;
    $who = $aid > 0 ? $aid : null;
    $stmt->bind_param('sis', $vendorId, $who, $body);
    $ok = $stmt->execute();
    $out['id'] = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok) { $out['error'] = 'Could not send the reply.'; return $out; }

    /* The vendor sees replies in their inbox, so they are told even if
       they never open the stall map again. */
    notify_vendor($conn, $vendorId, 'stall',
        'A message from the market office',
        mb_substr($body, 0, 400),
        'php/stall_map.php#officeThread',
        $aid > 0 ? $aid : null,
        $by);

    $out['ok'] = true;
    return $out;
}

/**
 * One vendor's whole thread, oldest first so it reads as a conversation.
 *
 * @param int $limit 0 for the whole thread
 */
function am_thread($conn, $vendorId, $limit = 0) {
    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) return [];

    $sql = "SELECT m.*, a.full_name AS admin_name
              FROM admin_messages m
              LEFT JOIN admin_users a ON a.id = m.admin_id
             WHERE m.vendor_id = ?
             ORDER BY m.created_at ASC, m.id ASC";
    if ((int)$limit > 0) $sql .= ' LIMIT ' . (int)$limit;

    $rows = [];
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    }
    return $rows;
}

/**
 * The office inbox: one row per vendor, the latest message, plus how
 * many are waiting to be read.
 *
 * The latest row per vendor is picked in PHP rather than SQL so the
 * query stays a single index scan on (vendor_id, created_at) — the
 * thread sizes here are small and it keeps the grouping readable.
 */
function am_inbox($conn) {
    $threads = [];
    if ($res = $conn->query(
        "SELECT m.id, m.vendor_id, m.direction, m.body, m.stall_requested,
                m.is_read, m.created_at,
                v.first_name, v.middle_name, v.last_name, v.business_name,
                v.stall_number, v.market_section
           FROM admin_messages m
           JOIN vendors v ON v.id = m.vendor_id
          ORDER BY m.vendor_id ASC, m.created_at ASC, m.id ASC"
    )) {
        while ($r = $res->fetch_assoc()) {
            $vid = (int)$r['vendor_id'];
            if (!isset($threads[$vid])) {
                $threads[$vid] = [
                    'vendor_id' => $vid,
                    'name'      => trim($r['first_name'] . ' ' . $r['last_name']) ?: 'Vendor #' . $vid,
                    'business'  => (string)($r['business_name'] ?? ''),
                    'stall'     => (string)($r['stall_number'] ?? ''),
                    'section'   => (string)($r['market_section'] ?? ''),
                    'count'     => 0,
                    'unread'    => 0,
                    'last'      => null,
                    'wants'     => [],
                ];
            }
            $threads[$vid]['count']++;
            /* Only a message the office has not opened counts as unread;
               the office's own replies are never unread to them. */
            if ($r['direction'] === 'vendor' && (int)$r['is_read'] === 0) $threads[$vid]['unread']++;
            $threads[$vid]['last'] = $r;
            if ($r['direction'] === 'vendor' && !empty($r['stall_requested'])) {
                $threads[$vid]['wants'][$r['stall_requested']] = true;
            }
        }
    }

    $rows = array_values($threads);
    usort($rows, function ($a, $b) {
        $an = (int)$a['unread'] > 0 ? 1 : 0;
        $bn = (int)$b['unread'] > 0 ? 1 : 0;
        if ($an !== $bn) return $bn - $an;                 /* unread first */
        return strcmp((string)($b['last']['created_at'] ?? ''),
                      (string)($a['last']['created_at'] ?? ''));
    });
    return $rows;
}

/** How many vendor messages are waiting for the office (badge). */
function am_office_unread_count($conn) {
    if (!$res = $conn->query(
        "SELECT COUNT(*) c FROM admin_messages WHERE direction = 'vendor' AND is_read = 0"
    )) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/** How many office replies a vendor has not opened (their badge). */
function am_vendor_unread_count($conn, $vendorId) {
    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) return 0;
    if (!$stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM admin_messages
          WHERE vendor_id = ? AND direction = 'admin' AND is_read = 0"
    )) return 0;
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $n;
}

/**
 * Mark a vendor's messages as read for one side.
 *
 * @param string $for 'vendor' marks the office's own replies read,
 *                    'office' marks what the vendor sent read
 */
function am_mark_read($conn, $vendorId, $for = 'vendor') {
    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) return 0;

    $direction = $for === 'office' ? 'vendor' : 'admin';

    if (!$stmt = $conn->prepare(
        "UPDATE admin_messages
            SET is_read = 1, read_at = NOW()
          WHERE vendor_id = ? AND direction = ? AND is_read = 0"
    )) return 0;

    /* direction is a string, not an id: bound as 'i' it becomes 0 and the
       update silently matches nothing. */
    $stmt->bind_param('ss', $vendorId, $direction);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}
