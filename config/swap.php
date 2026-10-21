<?php
/* ============================================================
   STALL SWAP HELPERS
   ------------------------------------------------------------
   Shared by the vendor portal (dashboard.php, Messages tab) and
   the admin console (admin/admin_stall_swaps.php).

   Flow
     1. Vendor A files a request from a vendor chat thread.
     2. Vendor B is notified + a system message lands in the thread.
     3. The market office approves → stalls are traded for real.
     4. Both vendors get a notification about the decision.

   Include this file anywhere a $conn (mysqli) is available.
   ============================================================ */

/** Status metadata for display. */
function swap_status_meta() {
    return [
        'Pending'   => ['label' => 'Awaiting Approval', 'class' => 'pending',   'icon' => 'fa-hourglass-half'],
        'Approved'  => ['label' => 'Approved',          'class' => 'approved',  'icon' => 'fa-circle-check'],
        'Rejected'  => ['label' => 'Rejected',          'class' => 'rejected',  'icon' => 'fa-circle-xmark'],
        'Cancelled' => ['label' => 'Cancelled',         'class' => 'cancelled', 'icon' => 'fa-ban'],
    ];
}

/**
 * One row of vendor info needed by the swap flow.
 *
 * @return array|null ['id','name','business_name','stall_number','market_section']
 */
function swap_vendor_row($conn, $vendorId) {
    $stmt = $conn->prepare(
        "SELECT id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name,
                business_name, stall_number, market_section
           FROM vendors WHERE id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Find (or create) the vendor ↔ vendor conversation between two vendors. */
function swap_conversation_id($conn, $vendorA, $vendorB) {
    $a = min((int)$vendorA, (int)$vendorB);
    $b = max((int)$vendorA, (int)$vendorB);

    $stmt = $conn->prepare("SELECT id FROM vendor_conversations WHERE vendor_a_id = ? AND vendor_b_id = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param("ii", $a, $b);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) return (int)$row['id'];

    $stmt = $conn->prepare("INSERT INTO vendor_conversations (vendor_a_id, vendor_b_id) VALUES (?, ?)");
    if (!$stmt) return 0;
    $stmt->bind_param("ii", $a, $b);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Drop a system notice into a vendor ↔ vendor thread so the swap
 * shows up inside the conversation, not just in notifications.
 *
 * @param int    $convId   vendor_conversations.id
 * @param int    $senderId who the notice appears to come from
 * @param int    $peerId   the other vendor (gets the unread bump)
 * @param string $body     notice text
 */
function swap_post_notice($conn, $convId, $senderId, $peerId, $body) {
    $convId  = (int)$convId;
    $senderId = (int)$senderId;
    $peerId  = (int)$peerId;
    if ($convId <= 0 || $senderId <= 0 || trim((string)$body) === '') return false;

    $body = mb_substr(trim($body), 0, 2000);

    $stmt = $conn->prepare("INSERT INTO vendor_messages (conversation_id, sender_vendor_id, body) VALUES (?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("iis", $convId, $senderId, $body);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return false;

    $preview = mb_strlen($body) > 80 ? mb_substr($body, 0, 77) . '…' : $body;
    $stmt = $conn->prepare("SELECT vendor_a_id FROM vendor_conversations WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $convId);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($conv) {
            if ($senderId === (int)$conv['vendor_a_id']) {
                $u = $conn->prepare("UPDATE vendor_conversations SET last_message_at = NOW(), last_message_preview = ?, unread_b = unread_b + 1 WHERE id = ?");
            } else {
                $u = $conn->prepare("UPDATE vendor_conversations SET last_message_at = NOW(), last_message_preview = ?, unread_a = unread_a + 1 WHERE id = ?");
            }
            if ($u) {
                $u->bind_param("si", $preview, $convId);
                $u->execute();
                $u->close();
            }
        }
    }

    return true;
}

/**
 * Is there already a request waiting on the admin between these two
 * vendors? Either vendor may have filed it.
 *
 * @return int swap id, or 0 when the pair is free
 */
function swap_open_request_id($conn, $vendorA, $vendorB) {
    $stmt = $conn->prepare(
        "SELECT id FROM stall_swap_requests
          WHERE status = 'Pending'
            AND ( (requester_vendor_id = ? AND target_vendor_id = ?)
               OR (requester_vendor_id = ? AND target_vendor_id = ?) )
          ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) return 0;
    $a = (int)$vendorA;
    $b = (int)$vendorB;
    $stmt->bind_param("iiii", $a, $b, $b, $a);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['id'] ?? 0);
}

/** All requests that involve this vendor, newest first. */
function swap_list_for_vendor($conn, $vendorId) {
    /* Two indexed branches instead of one OR: each side matches
       idx_swap_req / idx_swap_tgt, and PHP merges the two short
       ordered lists. */
    $stmt = $conn->prepare(
        "SELECT s.*,
                TRIM(CONCAT_WS(' ', ra.first_name, ra.middle_name, ra.last_name)) AS requester_name,
                ra.business_name AS requester_biz,
                TRIM(CONCAT_WS(' ', tb.first_name, tb.middle_name, tb.last_name)) AS target_name,
                tb.business_name AS target_biz
           FROM stall_swap_requests s
           JOIN vendors ra ON ra.id = s.requester_vendor_id
           JOIN vendors tb ON tb.id = s.target_vendor_id
          WHERE s.requester_vendor_id = ?
          UNION ALL
         SELECT s.*,
                TRIM(CONCAT_WS(' ', ra.first_name, ra.middle_name, ra.last_name)) AS requester_name,
                ra.business_name AS requester_biz,
                TRIM(CONCAT_WS(' ', tb.first_name, tb.middle_name, tb.last_name)) AS target_name,
                tb.business_name AS target_biz
           FROM stall_swap_requests s
           JOIN vendors ra ON ra.id = s.requester_vendor_id
           JOIN vendors tb ON tb.id = s.target_vendor_id
          WHERE s.target_vendor_id = ?"
    );
    if (!$stmt) return [];
    $vid = (int)$vendorId;
    $stmt->bind_param("ii", $vid, $vid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows) {
        /* Pending first, then newest first — mirrors the admin queue. */
        $order = ['Pending' => 0, 'Approved' => 1, 'Rejected' => 2, 'Cancelled' => 3];
        usort($rows, function ($a, $b) use ($order) {
            $ra = $order[$a['status']] ?? 9;
            $rb = $order[$b['status']] ?? 9;
            return $ra === $rb ? ((int)$b['id'] <=> (int)$a['id']) : ($ra <=> $rb);
        });
    }
    return $rows ?: [];
}

/** Requests that involve this vendor and the given peer vendor. */
function swap_list_for_pair($conn, $vendorId, $peerId) {
    $rows = swap_list_for_vendor($conn, $vendorId);
    $out = [];
    foreach ($rows as $r) {
        $other = ((int)$r['requester_vendor_id'] === (int)$vendorId)
               ? (int)$r['target_vendor_id'] : (int)$r['requester_vendor_id'];
        if ($other === (int)$peerId) $out[] = $r;
    }
    return $out;
}

/** Pending requests waiting on the admin (badge + list). */
function swap_pending_count($conn) {
    if (!$res = $conn->query("SELECT COUNT(*) AS c FROM stall_swap_requests WHERE status = 'Pending'")) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/** Counts for the admin summary row. */
function swap_status_counts($conn) {
    $out = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0, 'total' => 0];
    if (!$res = $conn->query(
        "SELECT status, COUNT(*) AS c FROM stall_swap_requests GROUP BY status"
    )) return $out;
    while ($r = $res->fetch_assoc()) {
        $k = (string)$r['status'];
        if (array_key_exists($k, $out)) $out[$k] = (int)$r['c'];
        $out['total'] += (int)$r['c'];
    }
    return $out;
}

/**
 * Swap the two vendors' stall numbers (+ section) and close the request.
 *
 * @return bool
 */
function swap_apply($conn, $requestId, $adminId, $adminName, $status, $note = '') {
    $requestId = (int)$requestId;
    $stmt = $conn->prepare("SELECT * FROM stall_swap_requests WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req || $req['status'] !== 'Pending') return false;

    $aId = (int)$req['requester_vendor_id'];
    $bId = (int)$req['target_vendor_id'];
    $aStall = (string)$req['requester_stall'];
    $aSec   = (string)($req['requester_section'] ?? '');
    $bStall = (string)$req['target_stall'];
    $bSec   = (string)($req['target_section'] ?? '');

    if (!$conn->query("START TRANSACTION")) return false;

    if ($status === 'Approved' && $aStall !== '' && $bStall !== '' && $aStall !== $bStall) {
        /* vendors.stall_number carries a UNIQUE key (one vendor per
           stall), so the two rows cannot be traded one statement at a
           time. Park both on temporary codes inside the transaction,
           then write the real values — the temporary codes never
           escape because anything failing rolls the whole thing back. */
        $park = function ($tmp, $id) use ($conn) {
            $u = $conn->prepare("UPDATE vendors SET stall_number = ? WHERE id = ?");
            if (!$u) return false;
            $u->bind_param("si", $tmp, $id);
            $ok = $u->execute();
            $u->close();
            return (bool)$ok;
        };

        if (!$park('~swap~' . $aId, $aId) || !$park('~swap~' . $bId, $bId)) {
            $conn->query("ROLLBACK");
            return false;
        }

        $u = $conn->prepare("UPDATE vendors SET stall_number = ?, market_section = ?, updated_at = NOW() WHERE id = ?");
        if (!$u) { $conn->query("ROLLBACK"); return false; }
        $u->bind_param("ssi", $bStall, $bSec, $aId);
        $ok = $u->execute();
        $u->close();
        if (!$ok) { $conn->query("ROLLBACK"); return false; }

        $u = $conn->prepare("UPDATE vendors SET stall_number = ?, market_section = ?, updated_at = NOW() WHERE id = ?");
        if (!$u) { $conn->query("ROLLBACK"); return false; }
        $u->bind_param("ssi", $aStall, $aSec, $bId);
        $ok = $u->execute();
        $u->close();
        if (!$ok) { $conn->query("ROLLBACK"); return false; }
    }

    $aid = $adminId ? (int)$adminId : null;
    $note = mb_substr(trim((string)$note), 0, 500);

    $u = $conn->prepare(
        "UPDATE stall_swap_requests
            SET status = ?, decided_by = ?, decided_by_name = ?, decision_note = ?, decided_at = NOW()
          WHERE id = ? AND status = 'Pending'"
    );
    if (!$u) { $conn->query("ROLLBACK"); return false; }
    $u->bind_param("sissi", $status, $aid, $adminName, $note, $requestId);
    $ok = $u->execute();
    $u->close();

    if (!$ok) { $conn->query("ROLLBACK"); return false; }
    if (!$conn->query("COMMIT")) { $conn->query("ROLLBACK"); return false; }

    return true;
}

/**
 * Tell both vendors how the market office ruled on their request,
 * and leave a notice inside their vendor thread.
 */
function swap_announce($conn, $req, $status, $adminName, $note = '') {
    require_once __DIR__ . '/notify.php';

    $meta = swap_status_meta();
    $label = $meta[$status]['label'] ?? $status;

    $a = swap_vendor_row($conn, (int)$req['requester_vendor_id']);
    $b = swap_vendor_row($conn, (int)$req['target_vendor_id']);
    if (!$a || !$b) return;

    $otherForA = $b;
    $otherForB = $a;

    foreach ([[$a, $otherForA], [$b, $otherForB]] as $pair) {
        list($me, $other) = $pair;
        $partnerFirst = trim(explode(' ', (string)$other['name'])[0]);
        $greet = $partnerFirst !== '' ? 'Hi ' . $partnerFirst . ', ' : '';

        if ($status === 'Approved') {
            $title = 'Stall swap approved — you are now at Stall ' . $me['stall_number'];
            $body = $greet . "the market office has approved your stall swap request with "
                  . $other['name'] . ($other['business_name'] ? ' (' . $other['business_name'] . ')' : '') . ".\n\n"
                  . "• Your new stall: " . $me['stall_number']
                  . ($me['market_section'] ? ' · ' . $me['market_section'] : '') . "\n"
                  . "• Their new stall: " . $other['stall_number'] . "\n\n"
                  . 'Please move to your new stall and update your display. Your permit and payment '
                  . 'records stay under your name.';
        } else {
            $title = 'Stall swap ' . strtolower($label);
            $body = $greet . "the market office has " . ($status === 'Rejected' ? 'rejected' : 'cancelled')
                  . " the stall swap request between you and "
                  . $other['name'] . ($other['business_name'] ? ' (' . $other['business_name'] . ')' : '') . ".\n\n"
                  . "• Your stall stays: " . $me['stall_number']
                  . ($me['market_section'] ? ' · ' . $me['market_section'] : '')
                  . ($note ? "\n\nNote from the market office: " . $note : '');
        }

        notify_vendor($conn, (int)$me['id'], 'stall', $title, $body, 'dashboard.php?view=messages&tab=vendors');

        $notice = $status === 'Approved'
            ? "[STALL SWAP APPROVED] " . $other['name'] . " ↔ you · You are now assigned to Stall "
              . $me['stall_number'] . ($me['market_section'] ? ' (' . $me['market_section'] . ')' : '')
              . '. Please move to your new stall.'
            : "[STALL SWAP " . strtoupper($status) . "] Request between you and " . $other['name']
              . ' was ' . strtolower($label) . '.' . ($note ? "\nNote: " . $note : '');

        swap_post_notice(
            $conn,
            (int)($req['conversation_id'] ?? 0) ?: swap_conversation_id($conn, (int)$me['id'], (int)$other['id']),
            (int)$me['id'],
            (int)$other['id'],
            $notice
        );
    }
}
