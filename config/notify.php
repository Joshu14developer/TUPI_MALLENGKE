<?php
/* ============================================================
   NOTIFICATION HELPERS
   ------------------------------------------------------------
   Lets the admin console push updates to a vendor, and lets the
   vendor portal read / mark them.

   Table shape (see config/db.php):
     sender_type / sender_id / sender_name   → who sent it
     recipient_type / recipient_id           → who it is for
     category                                → topic
     title / body / link / is_read / read_at

   Include this file anywhere a $conn (mysqli) is available.
   ============================================================ */

/* Payments are settled over the counter at the market office, so there
   is exactly one method and it is never something an admin picks.
   Declared once here and never read from the request, so a crafted
   POST cannot invent a payment channel. */
define('PAYMENT_METHOD', 'Walk-in');

/**
 * Turn a stored notification link into one that actually resolves from
 * php/vendor_notifications.php.
 *
 * Links were written from three different places — the app root, the
 * admin folder, and inside php/ — so the same page arrives as
 * "dashboard.php", "php/stall_map.php" or "../dashboard.php". Only one
 * of those resolves from the notifications page, and the rest are a
 * silent 404. This is the one place that knows where each vendor page
 * actually lives.
 *
 * @param string $link     whatever was stored
 * @param string $category notification category, used to pick a
 *                         sensible landing page when the link is empty
 * @return string          a URL relative to php/vendor_notifications.php
 */
function notif_vendor_url($link, $category = '') {
    /* Where each vendor page lives, from the notifications page. */
    static $pages = [
        'dashboard.php'            => '../dashboard.php',
        'stall_map.php'            => 'stall_map.php',
        'edit_profile.php'         => 'edit_profile.php',
        'vendor_notifications.php' => 'vendor_notifications.php',
        'vendor_passport.php'      => 'vendor_passport.php',
    ];

    /* Nothing usable stored: send them where the topic actually lives. */
    if (trim((string)$link) === '') {
        return ($category === 'stall') ? 'stall_map.php' : '../dashboard.php';
    }

    $l = trim((string)$link);

    /* Already absolute or protocol-relative: nothing to rewrite. */
    if (preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $l) || str_starts_with($l, '/')) return $l;

    $query = '';
    if (($at = strpos($l, '?')) !== false) {
        $query = substr($l, $at);
        $l     = substr($l, 0, $at);
    }

    /* A link written as "php/x.php" from the app root. */
    if (stripos($l, 'php/') === 0) $l = substr($l, 4);

    /* Already written relative to this folder. */
    if (str_starts_with($l, './') || str_starts_with($l, '../')) return $l . $query;

    $key = strtolower($l);
    if (isset($pages[$key])) return $pages[$key] . $query;

    /* An unknown page is worse than the dashboard, which always exists. */
    return $pages['dashboard.php'] . $query;
}

/* Valid categories → display metadata */
function notif_types() {
    return [
        'announcement' => ['label' => 'Announcement', 'icon' => 'fa-bullhorn',      'color' => 'purple'],
        'approval' => ['label' => 'Approval', 'icon' => 'fa-clipboard-check', 'color' => 'green'],
        'permit'   => ['label' => 'Permit',   'icon' => 'fa-file-shield',    'color' => 'sky'],
        'payment'  => ['label' => 'Payment',  'icon' => 'fa-wallet',         'color' => 'amber'],
        'stall'    => ['label' => 'Stall',    'icon' => 'fa-store',          'color' => 'purple'],
        'message'  => ['label' => 'Message',  'icon' => 'fa-comment-dots',   'color' => 'blue'],
        'reminder' => ['label' => 'Reminder', 'icon' => 'fa-bell',           'color' => 'amber'],
        'system'   => ['label' => 'System',   'icon' => 'fa-gear',           'color' => 'green'],
    ];
}

/**
 * Send one notification to a single vendor.
 *
 * @param mysqli   $conn
 * @param int      $vendorId
 * @param string   $type      one of notif_types()
 * @param string   $title     short headline
 * @param string   $body      longer message (optional)
 * @param string   $link      relative URL the vendor lands on (optional)
 * @param int|null $adminId   who sent it (null = system)
 * @param string   $adminName display name for the sender
 * @param int|null $announcementId  tie this row to a bulletin entry
 * @return bool
 */
function notify_vendor($conn, $vendorId, $type, $title, $body = '', $link = '', $adminId = null, $adminName = 'Market Office', $announcementId = null) {
    $vendorId = (int)$vendorId;
    if ($vendorId <= 0 || trim((string)$title) === '') return false;

    $types = notif_types();
    if (!isset($types[$type])) $type = 'system';

    $title = trim((string)$title);
    if (mb_strlen($title) > 180) $title = mb_substr($title, 0, 180);

    $adminId = (int)$adminId ?: null;
    $senderName = trim((string)$adminName) !== '' ? trim((string)$adminName) : 'Market Office';
    $announcementId = (int)$announcementId ?: null;

    $stmt = $conn->prepare(
        "INSERT INTO notifications
            (sender_type, sender_id, sender_name, recipient_type, recipient_id, announcement_id, category, title, body, link)
         VALUES (?, ?, ?, 'vendor', ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) return false;

    $senderType = $adminId ? 'admin' : 'system';
    $body = trim((string)$body);
    $link = trim((string)$link);

    $stmt->bind_param(
        "sisiissss",
        $senderType, $adminId, $senderName,
        $vendorId, $announcementId, $type, $title, $body, $link
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Send the same notification to many vendors at once.
 * Invalid / zero ids are skipped.
 *
 * @return int number of vendors notified
 */
function notify_vendors_bulk($conn, $vendorIds, $type, $title, $body = '', $link = '', $adminId = null, $adminName = 'Market Office', $announcementId = null) {
    $count = 0;
    foreach ((array)$vendorIds as $vid) {
        $vid = (int)$vid;
        if ($vid <= 0) continue;
        if (notify_vendor($conn, $vid, $type, $title, $body, $link, $adminId, $adminName, $announcementId)) $count++;
    }
    return $count;
}

/** Unread notification count for a vendor. */
function notif_unread_count($conn, $vendorId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM notifications
          WHERE recipient_type = 'vendor' AND recipient_id = ? AND is_read = 0"
    );
    if (!$stmt) return 0;
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/** Mark a single notification as read (scoped to its recipient). */
function notif_mark_read($conn, $vendorId, $notificationId) {
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
          WHERE id = ? AND recipient_type = 'vendor' AND recipient_id = ? AND is_read = 0"
    );
    if (!$stmt) return false;
    $nid = (int)$notificationId;
    $rid = (int)$vendorId;
    $stmt->bind_param("ii", $nid, $rid);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Mark every notification of a vendor as read. */
function notif_mark_all_read($conn, $vendorId) {
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
          WHERE recipient_type = 'vendor' AND recipient_id = ? AND is_read = 0"
    );
    if (!$stmt) return 0;
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

/** Delete a single notification of a vendor. */
function notif_delete($conn, $vendorId, $notificationId) {
    $stmt = $conn->prepare(
        "DELETE FROM notifications
          WHERE id = ? AND recipient_type = 'vendor' AND recipient_id = ?"
    );
    if (!$stmt) return false;
    $nid = (int)$notificationId;
    $rid = (int)$vendorId;
    $stmt->bind_param("ii", $nid, $rid);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Clear a vendor's whole notification history. */
function notif_clear_all($conn, $vendorId) {
    $stmt = $conn->prepare(
        "DELETE FROM notifications WHERE recipient_type = 'vendor' AND recipient_id = ?"
    );
    if (!$stmt) return 0;
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

/** How many vendor notifications were sent today (admin sidebar badge). */
function notif_sent_today($conn) {
    if (!$res = $conn->query(
        "SELECT COUNT(*) AS c FROM notifications
          WHERE recipient_type = 'vendor' AND DATE(created_at) = CURDATE()"
    )) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/** Readable "3 minutes ago" style label. */
function notif_time_ago($ts) {
    if (!$ts) return '—';
    $ts = strtotime($ts);
    if (!$ts) return '—';
    $diff = time() - $ts;
    if ($diff < 0)       $diff = 0;
    if ($diff < 60)      return 'Just now';
    if ($diff < 3600)    return floor($diff / 60) . 'm ago';
    if ($diff < 86400)   return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)  return floor($diff / 86400) . 'd ago';
    if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
    return date('M j, Y', $ts);
}

/* ============================================================
   TEXTING  (SMS)
   ------------------------------------------------------------
   The market office can text a vendor the very same words it puts
   in their inbox. The inbox row is always written first and texting
   is a separate, opt-in step, so a gateway outage costs the text
   copy and nothing else.

   Both flows share one outcome shape and one one-line summary, so
   the admin console reads the same whether it is texting an
   announcement or a private notice.
   ============================================================ */

/**
 * Shape returned when no SMS was attempted — SMS switched off, the
 * admin did not ask for it, or there was nothing to send.
 *
 * @return array ['enabled','attempted','sent','failed','skipped','error']
 */
function sms_not_attempted($enabled = false, $error = '') {
    return [
        'enabled'   => (bool)$enabled,
        'attempted' => false,
        'sent'      => 0,
        'failed'    => 0,
        'skipped'   => 0,
        'error'     => (string)$error,
    ];
}

/** Human-readable one-liner about a send outcome, for the admin flash. */
function sms_outcome_summary(array $sms) {
    if (empty($sms['enabled'])) return 'No SMS sent (' . ($sms['error'] ?: 'disabled') . ').';

    $bits = [];
    if (($sms['sent']    ?? 0) > 0) $bits[] = $sms['sent'] . ' texted';
    if (($sms['skipped'] ?? 0) > 0) $bits[] = $sms['skipped'] . ' without a valid mobile number';
    if (($sms['failed']  ?? 0) > 0) $bits[] = $sms['failed'] . ' rejected';
    if (!$bits) $bits[] = 'nothing delivered';

    $out = 'SMS: ' . implode(', ', $bits) . '.';
    if (!empty($sms['error'])) $out .= ' ' . $sms['error'];
    return $out;
}

/**
 * Text the recipients of a private notice.
 *
 * Same title, same body as the inbox copy — the phone gets the whole
 * notice and config/sms.php trims it to SMS_MAX_LENGTH.
 *
 * @param mysqli  $conn
 * @param int[]   $vendorIds  the vendors the notice reached
 * @param string  $title
 * @param string  $body
 * @param array   $options    prefix / max_length overrides for sms_send()
 * @return array  ['enabled','attempted','sent','failed','skipped','error']
 */
function notification_sms($conn, $vendorIds, $title, $body = '', array $options = []) {
    $title = trim((string)$title);
    if ($title === '') return sms_not_attempted(false, 'No title');

    if (!function_exists('sms_send_vendors')) {
        require_once __DIR__ . '/sms.php';
    }

    if (!sms_is_enabled()) {
        return sms_not_attempted(false, sms_last_error() ?: 'SMS is disabled.');
    }

    $text = trim($title . "\n" . trim((string)$body));
    $res  = sms_send_vendors($conn, $vendorIds, $text, $options);

    return [
        'enabled'   => true,
        'attempted' => true,
        'sent'      => (int)($res['sent'] ?? 0),
        'failed'    => (int)($res['failed'] ?? 0),
        'skipped'   => (int)($res['skipped'] ?? 0),
        'error'     => (string)($res['error'] ?? ''),
    ];
}

/* ============================================================
   ANNOUNCEMENTS  (market-wide bulletin board)
   ------------------------------------------------------------
   One row in `announcements` is the source of truth; the vendor
   sees it as a normal notification in their inbox. The fan-out
   rows carry announcement_id, so read counts are derived and a
   deleted notice cleans up after itself via ON DELETE CASCADE.
   ============================================================ */

/** Audience options for the composer. */
function announcement_audiences() {
    return [
        'all'     => ['label' => 'All vendors',  'icon' => 'fa-bullhorn',    'hint' => 'Every vendor in the market'],
        'section' => ['label' => 'One section',  'icon' => 'fa-layer-group','hint' => 'Only vendors trading in that section'],
    ];
}

/**
 * Vendor ids an announcement should reach.
 * Rejected applications never receive market notices.
 *
 * @return int[]
 */
function announcement_recipients($conn, $audience = 'all', $section = '') {
    $ids = [];
    if ($audience === 'section' && trim((string)$section) !== '') {
        if ($stmt = $conn->prepare(
            "SELECT id FROM vendors WHERE market_section = ? AND status <> 'Rejected'"
        )) {
            $stmt->bind_param("s", $section);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
            $stmt->close();
        }
    } else {
        if ($res = $conn->query("SELECT id FROM vendors WHERE status <> 'Rejected'")) {
            while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
        }
    }
    return $ids;
}

/**
 * Publish an announcement and fan it out to the audience.
 *
 * @param array $ann  keys: title, body, category, audience, section,
 *                    is_pinned, expires_at, send_sms
 * @return array ['id' => int, 'sent' => int, 'sms' => array]
 */
function announcement_publish($conn, array $ann, $adminId = null, $adminName = 'Market Office') {
    $title = trim((string)($ann['title'] ?? ''));
    $body  = trim((string)($ann['body'] ?? ''));
    if ($title === '') return ['id' => 0, 'sent' => 0, 'sms' => announcement_sms_result(false, 'No title')];

    $title = mb_substr($title, 0, 180);
    if ($body === '') $body = '(no further details)';

    $types   = notif_types();
    $type    = (string)($ann['category'] ?? 'announcement');
    if (!isset($types[$type])) $type = 'announcement';

    $audience = ($ann['audience'] ?? 'all') === 'section' ? 'section' : 'all';
    $section  = trim((string)($ann['section'] ?? ''));
    if ($audience === 'section' && $section === '') $audience = 'all';

    $pinned  = !empty($ann['is_pinned']) ? 1 : 0;
    $expires = trim((string)($ann['expires_at'] ?? ''));
    $expires = $expires !== '' ? $expires : null;
    if ($expires !== null && strtotime($expires) === false) $expires = null;
    if ($expires !== null) $expires = date('Y-m-d H:i:s', strtotime($expires));

    $adminId   = (int)$adminId ?: null;
    $adminName = trim((string)$adminName) !== '' ? trim((string)$adminName) : 'Market Office';

    $stmt = $conn->prepare(
        "INSERT INTO announcements
            (title, body, category, audience, section, is_pinned, is_active, published_at, expires_at, created_by, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?)"
    );
    if (!$stmt) return ['id' => 0, 'sent' => 0, 'sms' => announcement_sms_result(false, 'Insert failed')];

    $stmt->bind_param("sssssisss", $title, $body, $type, $audience, $section, $pinned, $expires, $adminId, $adminName);
    if (!$stmt->execute()) { $stmt->close(); return ['id' => 0, 'sent' => 0, 'sms' => announcement_sms_result(false, 'Insert failed')]; }
    $annId = (int)$conn->insert_id;
    $stmt->close();

    $sent = announcement_fanout($conn, $annId, $adminId, $adminName);

    /* Denormalised totals keep the list query to a single pass. */
    $up = $conn->prepare("UPDATE announcements SET recipient_count = ? WHERE id = ?");
    if ($up) { $up->bind_param("ii", $sent, $annId); $up->execute(); $up->close(); }

    /* Texting is a separate, opt-in step: the inbox copy always lands
       first, so a gateway outage can never lose the announcement. */
    $sms = !empty($ann['send_sms'])
         ? announcement_sms($conn, $annId)
         : announcement_sms_result(false, 'Not requested');

    return ['id' => $annId, 'sent' => $sent, 'sms' => $sms];
}

/** Shape returned when SMS was not attempted. */
function announcement_sms_result($enabled = false, $error = '') {
    return sms_not_attempted($enabled, $error);
}

/**
 * Text an announcement's audience through the SMS gateway.
 *
 * The helpers in config/sms.php do the work; this wrapper only
 * records the outcome on the announcement row so the admin can see
 * how many vendors were actually texted after the fact.
 *
 * @return array ['enabled','attempted','sent','failed','skipped','error']
 */
function announcement_sms($conn, $annId) {
    if (!function_exists('sms_send_announcement')) {
        require_once __DIR__ . '/sms.php';
    }

    $res = sms_send_announcement($conn, $annId);

    $out = [
        'enabled'   => !empty($res['enabled']),
        'attempted' => !empty($res['enabled']),
        'sent'      => (int)($res['sent'] ?? 0),
        'failed'    => (int)($res['failed'] ?? 0),
        'skipped'   => (int)($res['skipped'] ?? 0),
        'error'     => (string)($res['error'] ?? ''),
    ];

    /* Persist the counters; only meaningful when a send was tried. */
    if ($out['attempted']) {
        $stamp = $out['sent'] > 0 ? date('Y-m-d H:i:s') : null;
        if ($stmt = $conn->prepare(
            "UPDATE announcements
                SET sms_sent = ?, sms_failed = ?, sms_skipped = ?, sms_sent_at = ?
              WHERE id = ?"
        )) {
            $id = (int)$annId;
            $stmt->bind_param("iiisi", $out['sent'], $out['failed'], $out['skipped'], $stamp, $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    return $out;
}

/** Human-readable one-liner for the admin flash message. */
function announcement_sms_summary(array $sms) {
    return sms_outcome_summary($sms);
}

/**
 * Push an existing announcement to the audience again.
 * Existing copies for the same vendor are replaced, so a re-send
 * never doubles up in anybody's inbox.
 *
 * @return int number of vendors notified
 */
function announcement_fanout($conn, $annId, $adminId = null, $adminName = 'Market Office') {
    $annId = (int)$annId;
    if ($annId <= 0) return 0;

    if (!$stmt = $conn->prepare(
        "SELECT title, body, category, audience, section FROM announcements WHERE id = ? LIMIT 1"
    )) return 0;
    $stmt->bind_param("i", $annId);
    $stmt->execute();
    $ann = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ann) return 0;

    $recipients = announcement_recipients($conn, $ann['audience'], (string)$ann['section']);
    if (empty($recipients)) return 0;

    /* Clear the previous fan-out so a re-send stays 1:1. */
    if ($del = $conn->prepare("DELETE FROM notifications WHERE announcement_id = ?")) {
        $del->bind_param("i", $annId);
        $del->execute();
        $del->close();
    }

    $sent = notify_vendors_bulk(
        $conn, $recipients,
        (string)$ann['category'], (string)$ann['title'], (string)$ann['body'],
        'vendor_notifications.php', $adminId, $adminName, $annId
    );

    announcement_sync_counts($conn, $annId);
    return $sent;
}

/** Recompute recipient/read totals from the fan-out rows. */
function announcement_sync_counts($conn, $annId) {
    if (!$stmt = $conn->prepare(
        "UPDATE announcements a
            SET a.recipient_count = (SELECT COUNT(*) FROM notifications n WHERE n.announcement_id = a.id),
                a.read_count      = (SELECT COUNT(*) FROM notifications n WHERE n.announcement_id = a.id AND n.is_read = 1)
          WHERE a.id = ?"
    )) return false;
    $id = (int)$annId;
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    return true;
}

/** Delete an announcement; its notification copies cascade away. */
function announcement_delete($conn, $annId) {
    if (!$stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?")) return false;
    $id = (int)$annId;
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Flip the pin flag. */
function announcement_toggle_pin($conn, $annId) {
    if (!$stmt = $conn->prepare("UPDATE announcements SET is_pinned = 1 - is_pinned WHERE id = ?")) return false;
    $id = (int)$annId;
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Show / hide an announcement without losing its history. */
function announcement_toggle_active($conn, $annId) {
    if (!$stmt = $conn->prepare("UPDATE announcements SET is_active = 1 - is_active WHERE id = ?")) return false;
    $id = (int)$annId;
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** How many announcements are live (admin sidebar badge). */
function announcement_active_count($conn) {
    if (!$res = $conn->query(
        "SELECT COUNT(*) AS c FROM announcements
          WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())"
    )) return 0;
    return (int)($res->fetch_assoc()['c'] ?? 0);
}

/* ============================================================
   PERMIT & PAYMENT MESSAGES
   ------------------------------------------------------------
   Draft the private notice the market office sends when a
   vendor's permit or payment record changes. The text is built
   from the vendor's own row so the numbers are never typed by
   hand. Returns ['title' => …, 'body' => …].
   ============================================================ */

/** "Hi Ana, " — the greeting used at the top of vendor notices. */
function vendor_greeting($vendor) {
    $first = trim((string)($vendor['first_name'] ?? ''));
    return $first !== '' ? 'Hi ' . $first . ', ' : 'Hi, ';
}

/** "December 31, 2025", or a dash when the date is missing. */
function vendor_date($value, $fallback = '—') {
    if (empty($value)) return $fallback;
    $ts = strtotime((string)$value);
    return $ts ? date('F j, Y', $ts) : $fallback;
}

/** Actions the private permit/payment updater can perform. */
function permit_update_actions() {
    return [
        'permit_valid'     => ['group' => 'Permit',  'label' => 'Mark permit Valid',      'icon' => 'fa-file-circle-check'],
        'permit_expired'   => ['group' => 'Permit',  'label' => 'Mark permit Expired',    'icon' => 'fa-file-circle-xmark'],
        'permit_suspended' => ['group' => 'Permit',  'label' => 'Suspend permit',         'icon' => 'fa-ban'],
        'permit_pending'   => ['group' => 'Permit',  'label' => 'Send permit back to Pending', 'icon' => 'fa-hourglass-half'],
        'payment_paid'     => ['group' => 'Payment', 'label' => 'Record payment — Paid',  'icon' => 'fa-circle-check'],
        'payment_partial'  => ['group' => 'Payment', 'label' => 'Record payment — Partial','icon' => 'fa-circle-half-stroke'],
        'payment_unpaid'   => ['group' => 'Payment', 'label' => 'Mark payment Unpaid',    'icon' => 'fa-circle-exclamation'],
    ];
}

/**
 * Draft the notice for one permit/payment action.
 *
 * @param array $vendor the vendor row
 * @param string $action  key of permit_update_actions()
 * @param array $data    action inputs: expiry, amount, or_number,
 *                       period, months, notes
 * @return array ['title' => string, 'body' => string]
 */
function permit_update_message($vendor, $action, array $data = []) {
    $greet  = vendor_greeting($vendor);
    $stall  = trim((string)($vendor['stall_number'] ?? '')) ?: '—';
    $notes  = trim((string)($data['notes'] ?? ''));
    $amount = isset($data['amount']) && (float)$data['amount'] > 0 ? (float)$data['amount'] : 0.0;
    $orNo   = trim((string)($data['or_number'] ?? ''));
    $period = trim((string)($data['period'] ?? ''));
    $months = (int)($data['months'] ?? 0);

    /* Money always changes hands at the counter, so the line is a
       constant rather than something the admin selects. */
    $settled = "\n• Settled at: Market Office (" . PAYMENT_METHOD . ')';

    $extra = $notes !== '' ? "\n\nNote from the market office: " . $notes : '';

    switch ($action) {
        case 'permit_valid':
            $expiry = trim((string)($data['expiry'] ?? ''));
            $title  = 'Your business permit is now valid';
            $body   = $greet . "your business permit is active.\n\n"
                    . "• Stall: " . $stall
                    . "\n• Valid until: " . vendor_date($expiry ?: ($vendor['permit_expiry'] ?? null))
                    . ($months > 0 ? "\n• Term: " . $months . " month" . ($months === 1 ? '' : 's') : '')
                    . ($amount > 0 ? "\n• Fee paid: ₱" . number_format($amount, 2) : '')
                    . ($amount > 0 ? $settled : '')
                    . ($orNo   !== '' ? "\n• OR Number: " . $orNo : '')
                    . "\n\nPlease display your permit at your stall at all times." . $extra;
            break;

        case 'permit_expired':
            $expiry = trim((string)($data['expiry'] ?? ''));
            $title  = 'Your business permit has expired';
            $body   = $greet . "your business permit is no longer valid.\n\n"
                    . "• Stall: " . $stall
                    . "\n• Expired on: " . vendor_date($expiry ?: ($vendor['permit_expiry'] ?? null))
                    . "\n\nPlease renew at the market office to avoid any interruption to your business." . $extra;
            break;

        case 'permit_suspended':
            $title  = 'Your business permit has been suspended';
            $body   = $greet . "your business permit is now suspended and you cannot operate at your stall until it is reinstated.\n\n"
                    . "• Stall: " . $stall
                    . "\n\nPlease visit the market office to settle the outstanding issue." . $extra;
            break;

        case 'permit_pending':
            $title  = 'Your business permit is pending review';
            $body   = $greet . "your business permit is back under review. We need to verify a few details before it becomes valid again.\n\n"
                    . "• Stall: " . $stall
                    . "\n\nWe will notify you as soon as the review is complete." . $extra;
            break;

        case 'payment_paid':
            $next = trim((string)($data['next_due'] ?? '')) ?: date('Y-m-d', strtotime('+1 month'));
            $title = 'Payment received' . ($amount > 0 ? ' — ₱' . number_format($amount, 2) : '');
            $body  = $greet . "we have received your stall rental payment"
                   . ($amount > 0 ? " of ₱" . number_format($amount, 2) : '') . ".\n\n"
                   . "• Amount: ₱" . number_format($amount, 2)
                   . $settled
                   . ($orNo   !== '' ? "\n• OR Number: " . $orNo : '')
                   . ($period !== '' ? "\n• Period covered: " . $period : '')
                   . "\n• Next due: " . vendor_date($next)
                   . "\n\nThank you! Your account is now marked as Paid." . $extra;
            break;

        case 'payment_partial':
            $title = 'Partial payment recorded'
                   . ($amount > 0 ? ' — ₱' . number_format($amount, 2) : '');
            $due   = trim((string)($data['next_due'] ?? '')) ?: ($vendor['payment_due'] ?? null);
            $body  = $greet . "we have recorded a partial payment of ₱" . number_format($amount, 2) . ".\n\n"
                   . "• Amount received: ₱" . number_format($amount, 2)
                   . $settled
                   . ($orNo   !== '' ? "\n• OR Number: " . $orNo : '')
                   . ($period !== '' ? "\n• Period covered: " . $period : '')
                   . "\n• Balance still due: " . vendor_date($due)
                   . "\n\nPlease settle the remaining balance to keep your account in good standing." . $extra;
            break;

        case 'payment_unpaid':
            $due = trim((string)($data['next_due'] ?? '')) ?: ($vendor['payment_due'] ?? null);
            $title = 'Action needed — payment is due';
            $body  = $greet . "our records show no payment received for your stall.\n\n"
                   . "• Stall: " . $stall
                   . "\n• Amount due: ₱" . number_format($amount > 0 ? $amount : 0, 2)
                   . "\n• Due date: " . vendor_date($due)
                   . "\n\nPlease settle your account at the market office to avoid penalties." . $extra;
            break;

        default:
            $title = 'Update from the market office';
            $body  = $greet . 'there is an update on your record. Please check your dashboard.';
    }

    return ['title' => $title, 'body' => $body];
}

/** The vendors table columns an action is allowed to write. */
function permit_update_columns($action) {
    switch ($action) {
        case 'permit_valid':
            return ['permit_status' => 'Valid'];
        case 'permit_expired':
            return ['permit_status' => 'Expired'];
        case 'permit_suspended':
            return ['permit_status' => 'Suspended'];
        case 'permit_pending':
            return ['permit_status' => 'Pending'];
        case 'payment_paid':
            return ['payment_status' => 'Paid'];
        case 'payment_partial':
            return ['payment_status' => 'Partial'];
        case 'payment_unpaid':
            return ['payment_status' => 'Unpaid'];
    }
    return [];
}

/** Which notification category an action belongs to. */
function permit_update_category($action) {
    return strpos((string)$action, 'permit_') === 0 ? 'permit' : 'payment';
}
