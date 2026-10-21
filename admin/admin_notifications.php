<?php
/* ============================================================
   ADMIN NOTIFICATION CONSOLE
   ------------------------------------------------------------
   Lets the market office push updates to one vendor, many
   vendors, or the whole market. Every action is logged and the
   vendor sees it instantly on their Notifications page.
   ============================================================ */
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: admin_login.php'); exit; }

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/swap.php';
require_once __DIR__ . '/../config/sms.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole = $_SESSION['admin_role'] ?? 'admin';

$initials = '';
foreach (preg_split('/\s+/', trim($adminName)) as $p) {
    if ($p !== '') $initials .= strtoupper($p[0]);
    if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'A';

$TYPE_META = notif_types();

/* SMS: one settings read for the whole page (preview length, the
   opt-in on both forms, the "text again" button in the history). */
$SMS_CFG  = sms_settings();
$SMS_READY = sms_is_enabled();

/* ---------- Flash ---------- */
function set_flash($t, $m) { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function get_flash() { if (empty($_SESSION['flash'])) return null; $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }

/* ---------- Log helper ---------- */
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip); $stmt->execute(); $stmt->close(); }
}

/* ============================================================
   ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? '';

/* ---- Send notification ---- */
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target   = $_POST['target'] ?? 'vendor';        /* vendor | all | section */
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $section  = trim($_POST['market_section'] ?? '');
    $type     = trim($_POST['type'] ?? 'system');
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $link     = trim($_POST['link'] ?? '');
    $sendSms  = !empty($_POST['send_sms']) && $SMS_READY;

    if (!isset($TYPE_META[$type])) $type = 'system';
    if ($title === '') { set_flash('error', 'Please enter a title for the notification.'); header('Location: admin_notifications.php'); exit; }
    if (mb_strlen($title) > 160) $title = mb_substr($title, 0, 160);

    /* Build the recipient list */
    $recipients = [];
    if ($target === 'all') {
        if ($res = $conn->query("SELECT id FROM vendors WHERE status <> 'Rejected'")) {
            while ($r = $res->fetch_assoc()) $recipients[] = (int)$r['id'];
        }
    } elseif ($target === 'section' && $section !== '') {
        if ($stmt = $conn->prepare("SELECT id FROM vendors WHERE market_section = ? AND status <> 'Rejected'")) {
            $stmt->bind_param("s", $section);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $recipients[] = (int)$r['id'];
            $stmt->close();
        }
    } elseif ($vendorId > 0) {
        /* Confirm the vendor actually exists so we never create orphan rows */
        if ($s = $conn->prepare("SELECT id FROM vendors WHERE id = ? LIMIT 1")) {
            $s->bind_param("i", $vendorId);
            $s->execute();
            $found = $s->get_result()->fetch_assoc();
            $s->close();
            if ($found) $recipients[] = (int)$found['id'];
        }
    }

    if (empty($recipients)) {
        set_flash('error', 'No vendors matched that target. Pick a vendor, a section, or broadcast to all.');
        header('Location: admin_notifications.php');
        exit;
    }

    $sent = notify_vendors_bulk($conn, $recipients, $type, $title, $body, $link, $adminId, $adminName);

    /* The inbox copy always lands first; the text is a second, opt-in
       step on top of it, so a gateway outage can never lose the
       notification itself. */
    $sms = ($sendSms && $sent > 0)
         ? notification_sms($conn, $recipients, $title, $body)
         : sms_not_attempted(false, $sent > 0 ? 'Not requested' : 'No inbox copy');

    $scope = $target === 'all' ? 'all vendors'
           : ($target === 'section' ? 'section "' . $section . '"' : 'vendor #' . $vendorId);

    log_action($conn, $adminId, 'notification_sent', ucfirst($TYPE_META[$type]['label']) . ' notice',
        $scope . ' · ' . $sent . ' recipient(s)' . ($sendSms ? ' · sms ' . $sms['sent'] : '') . ' · ' . $title);

    set_flash('success', $sent . ' vendor(s) notified: "' . $title . '"'
        . ($sendSms ? ' ' . sms_outcome_summary($sms) : ''));
    header('Location: admin_notifications.php');
    exit;
}

/* ---- Update a vendor's permit / payment and tell them about it ----
   One form does both halves: it writes the new value onto the vendor's
   row (optional) and always sends the matching private notice, with the
   numbers filled in from the record so nothing is typed by hand. */
if ($action === 'permit_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $op       = trim($_POST['update_action'] ?? '');
    $apply    = !empty($_POST['apply_record']);
    $sendSms  = !empty($_POST['send_sms']) && $SMS_READY;

    $data = [
        'expiry'   => trim($_POST['expiry'] ?? ''),
        'amount'   => (float)($_POST['amount'] ?? 0),
        'or_number'=> trim($_POST['or_number'] ?? ''),
        'period'   => trim($_POST['period'] ?? ''),
        'months'   => (int)($_POST['months'] ?? 0),
        'next_due' => trim($_POST['next_due'] ?? ''),
        'notes'    => trim($_POST['notes'] ?? ''),
    ];

    $ACTIONS = permit_update_actions();

    if (!$vendorId || !isset($ACTIONS[$op])) {
        set_flash('error', 'Pick a vendor and the update you want to send.');
        header('Location: admin_notifications.php#update'); exit;
    }

    /* Load the vendor we are talking about */
    $stmt = $conn->prepare(
        "SELECT id, first_name, middle_name, last_name, business_name, stall_number, market_section,
                permit_status, permit_expiry, payment_status, payment_due, last_payment_date
           FROM vendors WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$vendor) {
        set_flash('error', 'That vendor no longer exists.');
        header('Location: admin_notifications.php#update'); exit;
    }

    /* A "Partial" or "Unpaid" note without a figure reads badly, and a
       payment message without a receipt is not much use. */
    if (in_array($op, ['payment_partial', 'payment_unpaid'], true) && $data['amount'] <= 0) {
        set_flash('error', 'Enter the amount for that payment notice.');
        header('Location: admin_notifications.php?vendor=' . $vendorId . '#update'); exit;
    }

    /* ---- Half one: write the new value onto the record ---- */
    if ($apply) {
        $cols  = permit_update_columns($op);
        $sets  = [];
        $types = '';
        $vals  = [];

        if ($cols) {
            foreach ($cols as $col => $val) {
                $sets[]  = "$col = ?";
                $types  .= 's';
                $vals[]  = $val;
            }
        }

        if ($op === 'permit_valid') {
            $expiry = $data['expiry'] !== ''
                    ? $data['expiry']
                    : (!empty($vendor['permit_expiry']) && strtotime($vendor['permit_expiry']) > time()
                        ? $vendor['permit_expiry'] : date('Y-m-d'));
            $sets[] = 'permit_expiry = ?'; $types .= 's'; $vals[] = $expiry;
        }
        if ($op === 'payment_paid') {
            $next = $data['next_due'] !== '' ? $data['next_due'] : date('Y-m-d', strtotime('+1 month'));
            $sets[] = 'last_payment_date = ?'; $types .= 's'; $vals[] = date('Y-m-d');
            $sets[] = 'payment_due = ?';       $types .= 's'; $vals[] = $next;
        }

        if (!empty($sets)) {
            $sets[] = 'updated_at = NOW()';
            $sql = "UPDATE vendors SET " . implode(', ', $sets) . " WHERE id = ?";
            $types .= 'i';
            $vals[] = $vendorId;

            $up = $conn->prepare($sql);
            if ($up) {
                $up->bind_param($types, ...$vals);
                $up->execute();
                $up->close();
            }

            /* Keep the in-memory row in step so the notice we build next
               quotes the new value, not the one we just replaced. */
            foreach ($cols as $col => $val) $vendor[$col] = $val;
        }
    }

    /* ---- Half two: the private notice ---- */
    $msg    = permit_update_message($vendor, $op, $data);
    $cat    = permit_update_category($op);
    $vLabel = trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['last_name'] ?? '')) ?: 'Vendor #' . $vendorId;

    $sent = notify_vendor($conn, $vendorId, $cat, $msg['title'], $msg['body'], 'dashboard.php', $adminId, $adminName);

    /* Same words on the phone, sent only after the inbox copy landed. */
    $sms = ($sendSms && $sent)
         ? notification_sms($conn, [$vendorId], $msg['title'], $msg['body'])
         : sms_not_attempted(false, $sent ? 'Not requested' : 'No inbox copy');

    log_action($conn, $adminId, 'vendor_update_notice', $vLabel . ' (Stall ' . $vendor['stall_number'] . ')',
        $ACTIONS[$op]['label'] . ($apply ? ' · record updated' : ' · notice only')
        . ($data['amount'] > 0 ? ' · ₱' . number_format($data['amount'], 2) : '')
        . ($sendSms ? ' · sms ' . $sms['sent'] : ''));

    set_flash('success', $sent
        ? ucfirst($cat) . ' notice sent to ' . $vLabel . ($apply ? ' — record updated.' : '.')
          . ($sendSms ? ' ' . sms_outcome_summary($sms) : '')
        : 'Could not send the notice to ' . $vLabel . '.');
    header('Location: admin_notifications.php?vendor=' . $vendorId . '#update');
    exit;
}

/* ---- Text a sent notification again, without a second inbox copy ---- */
if ($action === 'resend_sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int)($_POST['id'] ?? 0);
    $row = null;

    if ($id && $s = $conn->prepare(
        "SELECT recipient_id, title, body FROM notifications
          WHERE id = ? AND recipient_type = 'vendor' LIMIT 1"
    )) {
        $s->bind_param("i", $id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
    }

    if (!$row) {
        set_flash('error', 'That notification no longer exists.');
        header('Location: admin_notifications.php');
        exit;
    }

    $sms = $SMS_READY
         ? notification_sms($conn, [(int)$row['recipient_id']], $row['title'], $row['body'])
         : sms_not_attempted(false, sms_last_error() ?: 'disabled');

    log_action($conn, $adminId, 'notification_sms', '#' . $id, sms_outcome_summary($sms));
    set_flash($sms['sent'] > 0 ? 'success' : 'error', sms_outcome_summary($sms));
    header('Location: admin_notifications.php');
    exit;
}

/* ---- Delete a sent notification ---- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            log_action($conn, $adminId, 'notification_deleted', '#' . $id);
            set_flash('success', 'Notification removed.');
        }
    }
    header('Location: admin_notifications.php');
    exit;
}

/* ---- Clear the whole outbox ---- */
if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->query("DELETE FROM notifications WHERE recipient_type = 'vendor'");
    log_action($conn, $adminId, 'notifications_cleared', 'All vendor notifications', 'Outbox wiped');
    set_flash('success', 'All notifications cleared.');
    header('Location: admin_notifications.php');
    exit;
}

$flash = get_flash();

/* ============================================================
   DATA
   ============================================================ */
/* Vendors for the picker */
$vendors = [];
if ($res = $conn->query(
    "SELECT id, first_name, middle_name, last_name, business_name, stall_number,
            market_section, status, permit_status, permit_expiry,
            payment_status, payment_due, last_payment_date
       FROM vendors ORDER BY stall_number ASC"
)) {
    while ($r = $res->fetch_assoc()) $vendors[] = $r;
}

/* The update panel quotes the vendor's own figures, so ship the record
   to the browser and let it draft the message before anything is sent. */
$vendorJson = [];
foreach ($vendors as $v) {
    $vendorJson[$v['id']] = [
        'name'       => trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')),
        'first'      => (string)($v['first_name'] ?? ''),
        'business'   => (string)($v['business_name'] ?? ''),
        'stall'      => (string)($v['stall_number'] ?? ''),
        'section'    => (string)($v['market_section'] ?? ''),
        'permit'     => (string)($v['permit_status'] ?? 'Pending'),
        'expiry'     => (string)($v['permit_expiry'] ?? ''),
        'payment'    => (string)($v['payment_status'] ?? 'Unpaid'),
        'due'        => (string)($v['payment_due'] ?? ''),
        'lastPay'    => (string)($v['last_payment_date'] ?? ''),
    ];
}

/* Actions offered by the update panel, grouped the way they read best. */
$UPDATE_ACTIONS = permit_update_actions();

/* Sections for the broadcast picker */
$sections = [];
if ($res = $conn->query("SELECT DISTINCT market_section FROM vendors WHERE market_section <> '' ORDER BY market_section")) {
    while ($r = $res->fetch_assoc()) $sections[] = $r['market_section'];
}

/* Sent history, newest first */
$history = [];
if ($res = $conn->query(
    "SELECT n.id, n.category, n.title, n.body, n.link, n.is_read, n.read_at, n.created_at,
            n.sender_name,
            v.stall_number,
            TRIM(CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name)) AS vendor_name
       FROM notifications n
       JOIN vendors v ON v.id = n.recipient_id
      WHERE n.recipient_type = 'vendor'
      ORDER BY n.created_at DESC, n.id DESC
      LIMIT 150"
)) {
    while ($r = $res->fetch_assoc()) $history[] = $r;
}

/* Stats (note: "read" is a reserved word — alias it as read_cnt) */
$stat = ['total' => 0, 'unread' => 0, 'read_cnt' => 0, 'today' => 0];
if ($res = $conn->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(is_read = 0), 0) AS unread,
            COALESCE(SUM(is_read = 1), 0) AS read_cnt,
            COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today
       FROM notifications
      WHERE recipient_type = 'vendor'"
)) {
    $stat = array_merge($stat, $res->fetch_assoc());
}

/* Per-vendor unread, for the "needs attention" list */
$attention = [];
if ($res = $conn->query(
    "SELECT v.id, v.stall_number, v.permit_status, v.payment_status, v.status,
            TRIM(CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name)) AS vendor_name,
            (SELECT COUNT(*) FROM notifications n
              WHERE n.recipient_type = 'vendor' AND n.recipient_id = v.id AND n.is_read = 0) AS unread
       FROM vendors v
      WHERE v.status = 'Pending'
         OR v.permit_status IN ('Expired','Suspended')
         OR v.payment_status <> 'Paid'
      ORDER BY unread DESC, v.stall_number ASC
      LIMIT 12"
)) {
    while ($r = $res->fetch_assoc()) $attention[] = $r;
}

function h($v) { return htmlspecialchars((string)$v); }
function ini_from($name) { $p = preg_split('/\s+/', trim((string)$name)); return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Private Messages • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--amber:#f59e0b;--red:#ef4444;--purple:#8b5cf6;}

.btn-header.danger{background:#fee2e2;color:#991b1b;border:1.5px solid #fecaca;}
.btn-header.danger:hover{background:#fecaca;}

.flash{display:flex;align-items:center;gap:12px;padding:14px 20px;border-radius:14px;font-size:13.5px;font-weight:600;margin-bottom:18px;animation:fadeUp .35s var(--ease-spring);}
.flash.success{background:var(--green-50);border:1.5px solid var(--green-200);color:var(--green-800);}
.flash.success i{color:var(--green-600);}
.flash.error{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
.flash.error i{color:#dc2626;}
.flash i{font-size:16px;}
@keyframes fadeUp{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.mini-stat:hover{transform:translateY(-3px);box-shadow:var(--sh-md);}
.mini-stat .icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:16px;flex-shrink:0;}
.mini-stat .icon.g1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 5px 14px rgba(16,185,129,.35);}
.mini-stat .icon.g2{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 5px 14px rgba(220,38,38,.32);}
.mini-stat .icon.g3{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 5px 14px rgba(14,165,233,.32);}
.mini-stat .icon.g4{background:linear-gradient(135deg,#8b5cf6,#7c3aed);box-shadow:0 5px 14px rgba(124,58,237,.3);}
.mini-stat .num{font-size:22px;font-weight:900;color:var(--ink);letter-spacing:-.4px;line-height:1.1;}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px;}

/* Layout */
.grid-2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;}@media(max-width:1100px){.grid-2{grid-template-columns:1fr;}}
.card-head{padding:18px 22px;border-bottom:1.5px solid var(--line-soft);display:flex;align-items:center;gap:12px;background:linear-gradient(180deg,var(--green-50),#fff);}
.card-head-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#34d399,#047857);display:grid;place-items:center;color:#fff;font-size:15px;box-shadow:0 5px 14px rgba(16,185,129,.3);flex-shrink:0;}
.card-head h2{font-size:15px;font-weight:800;color:var(--ink);}
.card-head p{font-size:12px;color:var(--muted);margin-top:1px;}
.card-body{padding:20px 22px;}

/* Form */
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}
.field:last-child{margin-bottom:0;}
.field label{font-size:12px;font-weight:800;color:var(--ink-soft);letter-spacing:.02em;display:flex;align-items:center;gap:5px;}
.field label .req{color:#dc2626;}
.field input,.field select,.field textarea{
    width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);
    font-family:inherit;font-size:13.5px;outline:none;transition:all .2s;
}
.field textarea{resize:vertical;min-height:96px;line-height:1.55;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13);}
.field select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:38px;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat:no-repeat;background-position:right 12px center;background-size:13px;}
.field .hint{font-size:11px;color:var(--muted);}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}@media(max-width:640px){.field-row{grid-template-columns:1fr;}}

/* Target radio pills */
.target-pills{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px;}
.pill{position:relative;}
.pill input{position:absolute;opacity:0;pointer-events:none;}
.pill span{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:999px;border:1.5px solid var(--line);background:#fff;font-size:12.5px;font-weight:800;color:var(--ink-soft);cursor:pointer;transition:all .25s var(--ease-spring);user-select:none;}
.pill span:hover{border-color:var(--green-300);background:var(--green-50);}
.pill input:checked + span{background:linear-gradient(135deg,#10b981,#047857);color:#fff;border-color:transparent;box-shadow:var(--glow-soft);}
.pill input:focus-visible + span{box-shadow:0 0 0 4px rgba(16,185,129,.25);}

/* Templates */
.tpl-grid{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;}
.tpl{border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:7px 14px;font-family:inherit;font-size:12px;font-weight:800;color:var(--ink-soft);cursor:pointer;transition:all .25s var(--ease-spring);display:inline-flex;align-items:center;gap:6px;}
.tpl:hover{border-color:var(--green-400);background:var(--green-50);color:var(--brand-dark);transform:translateY(-2px);}

/* SMS: the phone the vendor is holding */
.check-row{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:var(--green-50);cursor:pointer;transition:all .2s;margin-bottom:14px;}
.check-row:hover{border-color:var(--green-300);}
.check-row input{width:17px;height:17px;margin-top:1px;accent-color:var(--brand);cursor:pointer;flex-shrink:0;}
.check-row .ct{font-size:12.5px;font-weight:800;color:var(--ink);}
.check-row .cd{font-size:11.5px;color:var(--muted);margin-top:2px;line-height:1.45;}

.sms-phone{background:#0f172a;border-radius:14px;padding:12px;border:1.5px solid var(--line);}
.sms-screen{background:#152220;border-radius:10px;padding:12px;min-height:64px;display:flex;align-items:flex-end;}
.sms-bubble{
    background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-size:12.5px;line-height:1.5;
    padding:9px 12px;border-radius:14px 14px 4px 14px;max-width:100%;white-space:pre-line;word-break:break-word;
}
.sms-bubble.empty{background:rgba(255,255,255,.07);color:#8ba3b8;box-shadow:none;}
.sms-phone .hint{margin-top:8px;display:block;}

.btn-form{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border-radius:12px;font-family:inherit;font-size:13.5px;font-weight:800;cursor:pointer;border:none;transition:all .25s var(--ease-spring);text-decoration:none;width:100%;}
.btn-form.primary{background:linear-gradient(135deg, #059669, #065f46);color:#fff;box-shadow:var(--glow-soft);}
.btn-form.primary:hover{transform:translateY(-2px);box-shadow:var(--glow);}
.btn-form.ghost{background:var(--bg);color:var(--ink-soft);border:1.5px solid var(--line);}
.btn-form.ghost:hover{background:var(--line-soft);}

/* Attention list */
.att-list{display:flex;flex-direction:column;}
.att-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px dashed var(--line);}
.att-item:last-child{border-bottom:none;}
.att-avatar{width:38px;height:38px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px;background:linear-gradient(135deg,#34d399,#059669);}
.att-info{flex:1;min-width:0;}
.att-name{font-size:13.5px;font-weight:800;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.att-meta{font-size:11.5px;color:var(--muted);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
.att-unread{font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;}
.att-unread.zero{background:var(--green-100);color:var(--green-800);}
.att-btn{width:34px;height:34px;border-radius:10px;border:1.5px solid var(--line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:13px;color:var(--ink-soft);transition:all .2s var(--ease-spring);text-decoration:none;flex-shrink:0;}
.att-btn:hover{border-color:var(--green-400);background:var(--green-50);color:var(--brand-dark);transform:translateY(-2px);}

/* History */
.hist-list{display:flex;flex-direction:column;}
.hist{display:flex;gap:13px;align-items:flex-start;padding:14px 22px;border-bottom:1px solid var(--line-soft);transition:background .15s;}
.hist:last-child{border-bottom:none;}
.hist:hover{background:var(--green-50);}
.hist-icon{width:38px;height:38px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-size:14px;}
.hist-icon.green{background:linear-gradient(135deg,#10b981,#047857);}
.hist-icon.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);}
.hist-icon.sky{background:linear-gradient(135deg,#0ea5e9,#0284c7);}
.hist-icon.amber{background:linear-gradient(135deg,#f59e0b,#d97706);}
.hist-icon.red{background:linear-gradient(135deg,#ef4444,#dc2626);}
.hist-icon.purple{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.hist-body{flex:1;min-width:0;}
.hist-title{font-size:13.5px;font-weight:800;color:var(--ink);}
.hist-msg{font-size:12.5px;color:var(--muted);margin-top:3px;line-height:1.5;}
.hist-foot{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;}
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:800;background:var(--bg);color:var(--ink-soft);border:1px solid var(--line);white-space:nowrap;}
.chip.read{background:var(--green-100);color:var(--green-800);border-color:var(--green-200);}
.chip.unread{background:#fef3c7;color:#92400e;border-color:#fde68a;}
/* The two history buttons (text again, delete) sit side by side. */
.hist > form{display:flex;}
.hist-sms{width:30px;height:30px;border-radius:9px;border:1.5px solid var(--line);background:#fff;display:grid;place-items:center;cursor:pointer;color:var(--muted);font-size:12px;transition:all .2s;flex-shrink:0;}
.hist-sms:hover{border-color:var(--green-300);background:var(--green-50);color:var(--brand-dark);}
.hist-del{width:30px;height:30px;border-radius:9px;border:1.5px solid var(--line);background:#fff;display:grid;place-items:center;cursor:pointer;color:var(--muted);font-size:12px;transition:all .2s;flex-shrink:0;}
.hist-del:hover{border-color:#fca5a5;background:#fee2e2;color:#dc2626;}

.badge{display:inline-flex;align-items:center;padding:4px 11px;border-radius:999px;font-size:10.5px;font-weight:800;text-transform:capitalize;letter-spacing:.03em;white-space:nowrap;}
.badge.pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge.approved,.badge.valid,.badge.paid{background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200);}
.badge.rejected,.badge.expired{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.badge.suspended{background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;}
.badge.partial{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge.unpaid{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

.empty-state{padding:56px 24px;text-align:center;color:var(--muted);}
.empty-state i{font-size:48px;color:var(--green-200);display:block;margin-bottom:14px;}
.empty-state h3{font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.empty-state p{font-size:13.5px;}@media(max-width:900px){.card-body{padding:16px}}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-bell"></i></span>
                Private Messages
            </h1>
            <p>Message one vendor about their permit or payment — they appear instantly on their Notifications page, and on their phone when you tick the SMS box.</p>
        </div>
        <div class="header-actions">
            <a href="admin_announcements.php" class="btn-header ghost"><i class="fas fa-bullhorn"></i> Announcements</a>
            <a href="admin_vendors.php" class="btn-header ghost"><i class="fas fa-store"></i> Vendors</a>
            <?php if (!empty($history)): ?>
                <form method="post" action="admin_notifications.php" onsubmit="return confirm('Delete ALL notifications for every vendor? This cannot be undone.');">
                    <input type="hidden" name="action" value="clear" />
                    <button type="submit" class="btn-header danger"><i class="fas fa-trash-can"></i> Clear All</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>">
            <i class="fas <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= h($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stat-row">
        <div class="mini-stat">
            <div class="icon g1"><i class="fas fa-paper-plane"></i></div>
            <div><div class="num"><?= (int)$stat['total'] ?></div><div class="lbl">Total Sent</div></div>
        </div>
        <div class="mini-stat">
            <div class="icon g2"><i class="fas fa-envelope"></i></div>
            <div><div class="num"><?= (int)$stat['unread'] ?></div><div class="lbl">Unread</div></div>
        </div>
        <div class="mini-stat">
            <div class="icon g3"><i class="fas fa-envelope-open"></i></div>
            <div><div class="num"><?= (int)$stat['read_cnt'] ?></div><div class="lbl">Read</div></div>
        </div>
        <div class="mini-stat">
            <div class="icon g4"><i class="fas fa-calendar-day"></i></div>
            <div><div class="num"><?= (int)$stat['today'] ?></div><div class="lbl">Sent Today</div></div>
        </div>
    </div>

    <?php require __DIR__ . '/_update_panel.php'; ?>

    <div class="grid-2">

        <!-- ============================================================
             COMPOSER
             ============================================================ -->
        <div>
            <div class="card">
                <div class="card-head">
                    <div class="card-head-icon"><i class="fas fa-pen-to-square"></i></div>
                    <div>
                        <h2>Compose Notification</h2>
                            <p>Update a vendor, a section, or the whole market</p>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="admin_notifications.php" id="composeForm">
                        <input type="hidden" name="action" value="send" />

                        <!-- Who to send to -->
                        <div class="field">
                            <label>Send to <span class="req">*</span></label>
                            <div class="target-pills">
                                <label class="pill">
                                    <input type="radio" name="target" value="vendor" checked />
                                    <span><i class="fas fa-user"></i> One vendor</span>
                                </label>
                                <label class="pill">
                                    <input type="radio" name="target" value="section" />
                                    <span><i class="fas fa-layer-group"></i> A section</span>
                                </label>
                                <label class="pill">
                                    <input type="radio" name="target" value="all" />
                                    <span><i class="fas fa-bullhorn"></i> All vendors</span>
                                </label>
                            </div>
                        </div>

                        <!-- Vendor picker -->
                        <div class="field" id="wrapVendor">
                            <label>Vendor <span class="req">*</span></label>
                            <select name="vendor_id" id="vendorSelect" required>
                                <option value="">— Select a vendor —</option>
                                <?php foreach ($vendors as $v):
                                    $vName = trim(($v['first_name'] ?? '') . ' ' . ($v['middle_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                                ?>
                                    <option value="<?= (int)$v['id'] ?>">
                                        <?= h($vName) ?> — Stall <?= h($v['stall_number']) ?> (<?= h($v['market_section']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">Type a name or stall number in the box above to narrow the list.</span>
                        </div>

                        <!-- Section picker -->
                        <div class="field" id="wrapSection" style="display:none;">
                            <label>Market Section <span class="req">*</span></label>
                            <select name="market_section">
                                <option value="">— Select a section —</option>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= h($s) ?>"><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Category -->
                        <div class="field">
                            <label>Category</label>
                            <select name="type" id="typeSelect">
                                <?php foreach ($TYPE_META as $tk => $tm): ?>
                                    <option value="<?= h($tk) ?>"><?= h($tm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Quick templates -->
                        <div class="field">
                            <label>Quick templates</label>
                            <div class="tpl-grid">
                                <button type="button" class="tpl" data-tpl="approved"><i class="fas fa-circle-check"></i> Approved</button>
                                <button type="button" class="tpl" data-tpl="rejected"><i class="fas fa-circle-xmark"></i> Rejected</button>
                                <button type="button" class="tpl" data-tpl="permit"><i class="fas fa-file-shield"></i> Permit renewal</button>
                                <button type="button" class="tpl" data-tpl="payment"><i class="fas fa-wallet"></i> Payment received</button>
                                <button type="button" class="tpl" data-tpl="stall"><i class="fas fa-store"></i> Stall change</button>
                                <button type="button" class="tpl" data-tpl="reminder"><i class="fas fa-bell"></i> General reminder</button>
                            </div>
                        </div>

                        <!-- Title -->
                        <div class="field">
                            <label>Title <span class="req">*</span></label>
                            <input type="text" name="title" id="titleInput" maxlength="160" placeholder="e.g. Your business permit has been renewed" required />
                        </div>

                        <!-- Message -->
                        <div class="field">
                            <label>Message</label>
                            <textarea name="body" id="bodyInput" placeholder="Add the details the vendor needs to know…"></textarea>
                        </div>

                        <!-- SMS preview: shows the exact text a phone would receive -->
                        <?php if ($SMS_READY): ?>
                        <div class="field">
                            <label><i class="fas fa-mobile-screen"></i> Text message preview</label>
                            <div class="sms-phone">
                                <div class="sms-screen"><div class="sms-bubble" id="smsPreview"></div></div>
                                <span class="hint" id="smsCounter">Trimmed to <?= (int)$SMS_CFG['max_length'] ?> characters.</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Link (optional) -->
                        <div class="field">
                            <label>Link (optional)</label>
                            <select name="link">
                                <option value="">— No link —</option>
                                <option value="dashboard.php">Vendor dashboard</option>
                                <option value="dashboard.php?view=messages">Vendor messages</option>
                                <option value="edit_profile.php">Edit profile</option>
                                <option value="stall_map.php">Stall map</option>
                            </select>
                        </div>

                        <!-- SMS opt-in. The inbox copy is always created; this only
                             adds the text copy on top of it. -->
                        <?php if ($SMS_READY): ?>
                        <label class="check-row">
                            <input type="checkbox" name="send_sms" value="1" checked />
                            <span>
                                <span class="ct"><i class="fas fa-comment-sms"></i> Also text the vendors</span>
                                <span class="cd">Sends the message above to every vendor you picked who has a valid mobile number. The rest are skipped.</span>
                            </span>
                        </label>
                        <?php else: ?>
                        <div class="check-row" style="background:var(--bg);border-color:var(--line);color:var(--muted);">
                            <i class="fas fa-circle-info" style="margin-top:2px;color:var(--muted);"></i>
                            <span>
                                <span class="ct" style="color:var(--muted);">SMS is switched off</span>
                                <span class="cd">Set <code>SMS_ENABLED</code> and the gateway credentials in <code>config/sms_config.php</code> to text vendors.</span>
                            </span>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="btn-form primary">
                            <i class="fas fa-paper-plane"></i> Send Notification
                        </button>
                    </form>
                </div>
            </div>

            <!-- Needs attention -->
            <div class="card">
                <div class="card-head">
                    <div class="card-head-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 5px 14px rgba(217,119,6,.3);">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h2>Needs Attention</h2>
                        <p>Pending, expired, or unpaid vendors</p>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($attention)): ?>
                        <div class="empty-state" style="padding:36px 12px;">
                            <i class="fas fa-circle-check"></i>
                            <h3>All clear</h3>
                            <p>No pending, expired, or unpaid vendors right now.</p>
                        </div>
                    <?php else: ?>
                        <div class="att-list">
                            <?php foreach ($attention as $a):
                                /* Open the panel already pointing at the right action. */
                                $focus = 'permit_expired';
                                if (in_array($a['permit_status'], ['Expired', 'Suspended'], true)) {
                                    $focus = $a['permit_status'] === 'Suspended' ? 'permit_suspended' : 'permit_expired';
                                } elseif ($a['status'] === 'Pending') {
                                    $focus = 'permit_pending';
                                } elseif ($a['payment_status'] !== 'Paid') {
                                    $focus = 'payment_unpaid';
                                }
                            ?>
                                <div class="att-item">
                                    <div class="att-avatar"><?= h(ini_from($a['vendor_name'])) ?></div>
                                    <div class="att-info">
                                        <div class="att-name"><?= h($a['vendor_name']) ?></div>
                                        <div class="att-meta">
                                            <span class="chip"><i class="fas fa-location-dot"></i> <?= h($a['stall_number']) ?></span>
                                            <?php if ($a['status'] === 'Pending'): ?>
                                                <span class="badge pending">Application pending</span>
                                            <?php endif; ?>
                                            <?php if (in_array($a['permit_status'], ['Expired','Suspended'], true)): ?>
                                                <span class="badge <?= h(strtolower($a['permit_status'])) ?>">Permit <?= h($a['permit_status']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($a['payment_status'] !== 'Paid'): ?>
                                                <span class="badge <?= h(strtolower($a['payment_status'])) ?>">Payment <?= h($a['payment_status']) ?></span>
                                            <?php endif; ?>
                                            <span class="att-unread <?= (int)$a['unread'] > 0 ? '' : 'zero' ?>">
                                                <?= (int)$a['unread'] ?> unread
                                            </span>
                                        </div>
                                    </div>
                                    <a href="admin_notifications.php?vendor=<?= (int)$a['id'] ?>&focus=<?= h($focus) ?>#update" class="att-btn" title="Message this vendor about it">
                                        <i class="fas fa-bell"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================================
             HISTORY
             ============================================================ -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 5px 14px rgba(14,165,233,.3);">
                    <i class="fas fa-clock-rotate-left"></i>
                </div>
                <div>
                    <h2>Sent History</h2>
                    <p>Latest <?= count($history) ?> notification(s)</p>
                </div>
                <div class="seq" data-seq>
                    <button type="button" class="seq-btn" data-seq-prev title="Previous message" aria-label="Previous message"><i class="fas fa-chevron-up"></i></button>
                    <span class="seq-count" data-seq-count>1 of <?= max(1, count($history)) ?></span>
                    <button type="button" class="seq-btn" data-seq-next title="Next message" aria-label="Next message"><i class="fas fa-chevron-down"></i></button>
                </div>
            </div>

            <?php if (empty($history)): ?>
                <div class="empty-state">
                    <i class="fas fa-paper-plane"></i>
                    <h3>Nothing sent yet</h3>
                    <p>Use the composer to send your first vendor update.</p>
                </div>
            <?php else: ?>
                <div class="hist-list board" data-board tabindex="0" role="region" aria-label="Sent message list">
                    <?php foreach ($history as $n):
                        $meta = $TYPE_META[$n['category']] ?? $TYPE_META['system'];
                    ?>
                        <div class="hist">
                            <div class="hist-icon <?= h($meta['color']) ?>">
                                <i class="fas <?= h($meta['icon']) ?>"></i>
                            </div>
                            <div class="hist-body">
                                <div class="hist-title"><?= h($n['title']) ?></div>
                                <?php if (!empty($n['body'])): ?>
                                    <div class="hist-msg"><?= h(mb_strimwidth($n['body'], 0, 130, '…')) ?></div>
                                <?php endif; ?>
                                <div class="hist-foot">
                                    <span class="chip"><i class="fas fa-store"></i> <?= h($n['vendor_name']) ?></span>
                                    <span class="chip"><i class="fas fa-location-dot"></i> <?= h($n['stall_number']) ?></span>
                                    <span class="chip <?= (int)$n['is_read'] ? 'read' : 'unread' ?>">
                                        <i class="fas <?= (int)$n['is_read'] ? 'fa-check' : 'fa-envelope' ?>"></i>
                                        <?= (int)$n['is_read'] ? 'Read' : 'Unread' ?>
                                    </span>
                                    <span class="chip"><i class="fas fa-clock"></i> <?= h(notif_time_ago($n['created_at'])) ?></span>
                                    <span class="chip"><i class="fas fa-user-tie"></i> <?= h($n['sender_name'] ?: 'System') ?></span>
                                </div>
                            </div>
                        <?php if ($SMS_READY): ?>
                            <form method="post" action="admin_notifications.php" onsubmit="return confirm('Text this message to <?= h($n['vendor_name']) ?> again?');">
                                <input type="hidden" name="action" value="resend_sms" />
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>" />
                                <button type="submit" class="hist-sms" title="Text this vendor again"><i class="fas fa-comment-sms"></i></button>
                            </form>
                        <?php endif; ?>
                            <form method="post" action="admin_notifications.php" onsubmit="return confirm('Remove this notification?');">
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>" />
                                <button type="submit" class="hist-del" title="Delete"><i class="fas fa-xmark"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

</main>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

/* Show only the picker that matches the chosen target */
function syncTarget() {
    const target = document.querySelector('input[name="target"]:checked').value;
    document.getElementById('wrapVendor').style.display  = (target === 'vendor')  ? '' : 'none';
    document.getElementById('wrapSection').style.display = (target === 'section') ? '' : 'none';
}
document.querySelectorAll('input[name="target"]').forEach(r => r.addEventListener('change', syncTarget));

/* Template library */
const TEMPLATES = {
    approved: {
        type: 'approval',
        title: 'Your vendor application has been approved',
        body:  'Congratulations! Your application has been approved by the market office. You may now start operating at your assigned stall. Please bring a copy of your ID when you visit the office.'
    },
    rejected: {
        type: 'approval',
        title: 'Action needed on your vendor application',
        body:  'We regret to inform you that your application could not be approved at this time. Please visit the market office to clarify the requirements and submit your application again.'
    },
    permit: {
        type: 'permit',
        title: 'Your business permit has been renewed',
        body:  'Good news! Your business permit is now valid. Please display this permit at your stall at all times.'
    },
    payment: {
        type: 'payment',
        title: 'Payment received — thank you!',
        body:  'We have received your stall rental payment. Your account is now marked as Paid. Please keep your official receipt.'
    },
    stall: {
        type: 'stall',
        title: 'Update on your stall assignment',
        body:  'The market office has made a change to your stall assignment. Please stop by the office to confirm the details of your new stall.'
    },
    reminder: {
        type: 'reminder',
        title: 'Reminder from the market office',
        body:  'This is a friendly reminder to settle any outstanding market fees and keep your documents up to date. Thank you for your cooperation.'
    }
};

document.querySelectorAll('.tpl').forEach(btn => {
    btn.addEventListener('click', () => {
        const tpl = TEMPLATES[btn.dataset.tpl];
        if (!tpl) return;
        document.getElementById('typeSelect').value  = tpl.type;
        document.getElementById('titleInput').value = tpl.title;
        document.getElementById('bodyInput').value  = tpl.body;
        renderSmsPreview();
        document.getElementById('titleInput').focus();
    });
});

/* ============================================================
   SMS PREVIEW
   Mirrors config/sms.php sms_format_body(): the prefix is
   prepended, blank lines collapse, and the result is clamped to
   SMS_MAX_LENGTH. Keeping the two in step means the admin sees the
   real text, not an approximation.
   ============================================================ */
const SMS_PREFIX   = <?= json_encode($SMS_CFG['sender_prefix']) ?>;
const SMS_MAX      = <?= (int)$SMS_CFG['max_length'] ?>;

/* Trim to the limit on a whole character boundary, ending with an
   ellipsis exactly like sms_clamp() does. */
function smsClamp(text, max) {
    if (max < 1) max = 1;
    return Array.from(text).length <= max
        ? text
        : Array.from(text).slice(0, max - 1).join('').replace(/\s+$/, '') + '…';
}

function renderSmsPreview() {
    const bubble = document.getElementById('smsPreview');
    if (!bubble) return;

    const counter = document.getElementById('smsCounter');
    const title   = document.getElementById('titleInput').value.trim();
    const body    = document.getElementById('bodyInput').value.trim();

    let text = (title ? title + '\n' : '') + body.replace(/[ \t]+/g, ' ').trim().replace(/\n{3,}/g, '\n\n');
    if (text && SMS_PREFIX) text = SMS_PREFIX + ': ' + text;

    const shown  = smsClamp(text, SMS_MAX);
    const chars  = Array.from(shown).length;
    const isCut  = chars === SMS_MAX && chars < Array.from(text).length;

    bubble.textContent = shown || 'Your text message appears here…';
    bubble.classList.toggle('empty', shown === '');

    if (counter) {
        counter.textContent = shown === ''
            ? 'Nothing to send yet.'
            : chars + ' / ' + SMS_MAX + ' character' + (SMS_MAX === 1 ? '' : 's')
              + (isCut ? ' — trimmed, the rest is cut off.' : '');
    }
}

const titleField = document.getElementById('titleInput');
const bodyField  = document.getElementById('bodyInput');
[titleField, bodyField].forEach(el => {
    if (el) el.addEventListener('input', renderSmsPreview);
});

/* Prefill when arriving from "Needs Attention" */
const params = new URLSearchParams(window.location.search);
const presetVendor = params.get('vendor');
if (presetVendor) {
    document.getElementById('vendorSelect').value = presetVendor;
    document.getElementById('composeForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* Texting is a real send — confirm before the phones start ringing. */
document.getElementById('composeForm').addEventListener('submit', function (e) {
    const smsBox = document.querySelector('#composeForm input[name="send_sms"]');
    if (!smsBox || !smsBox.checked) return;
    if (!confirm('Text this message as well? Real SMS goes out to every vendor you picked who has a valid mobile number.')) {
        e.preventDefault();
    }
});

syncTarget();
renderSmsPreview();
</script>

<?php /* Sent history scrolls inside itself and steps one message at a time. */
require __DIR__ . '/_board_sequence.php'; ?>

</body>
</html>
