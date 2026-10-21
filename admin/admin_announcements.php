<?php
/* ============================================================
   ADMIN ANNOUNCEMENTS
   ------------------------------------------------------------
   The market-wide bulletin board. An announcement is one row in
   `announcements` that is fanned out to the audience as ordinary
   vendor notifications, so it lands in their inbox with the unread
   badge and can be marked read / deleted like any other message.

   Private, one-to-one permit and payment updates live on
   admin_notifications.php — this page is for market-wide notices.
   ============================================================ */
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: admin_login.php'); exit; }

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
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

$TYPE_META  = notif_types();
$AUDIENCES  = announcement_audiences();

/* SMS: one settings read for the whole page (preview length, chip,
   whether the composer even offers the option). */
$SMS_CFG  = sms_settings();
$SMS_READY = sms_is_enabled();

/* ---------- Flash ---------- */
function set_flash($t, $m) { $_SESSION['flash'] = ['type'=>$t,'msg'=>$m]; }
function get_flash() { if (empty($_SESSION['flash'])) return null; $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }

/* ---------- Log helper ---------- */
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip); $stmt->execute(); $stmt->close(); }
}

function h($v) { return htmlspecialchars((string)$v); }

/* ============================================================
   ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? '';

/* ---- Publish a new announcement ---- */
if ($action === 'publish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $category = trim($_POST['category'] ?? 'announcement');
    $audience = ($_POST['audience'] ?? 'all') === 'section' ? 'section' : 'all';
    $section  = trim($_POST['section'] ?? '');
    $pinned   = !empty($_POST['is_pinned']);
    $expires  = trim($_POST['expires_at'] ?? '');
    $sendSms  = !empty($_POST['send_sms']) && $SMS_READY;

    if ($title === '') {
        set_flash('error', 'Please enter a title for the announcement.');
        header('Location: admin_announcements.php'); exit;
    }
    if ($audience === 'section' && $section === '') {
        set_flash('error', 'Pick the market section this announcement is for.');
        header('Location: admin_announcements.php'); exit;
    }

    $res = announcement_publish($conn, [
        'title'      => $title,
        'body'       => $body,
        'category'   => $category,
        'audience'   => $audience,
        'section'    => $section,
        'is_pinned'  => $pinned,
        'expires_at' => $expires,
        'send_sms'   => $sendSms,
    ], $adminId, $adminName);

    if (!$res['id']) {
        set_flash('error', 'Could not save the announcement. Please try again.');
    } elseif ($res['sent'] === 0) {
        set_flash('error', 'Saved, but no vendor matched that audience — nothing was delivered.');
    } else {
        $scope = $audience === 'all' ? 'all vendors' : 'section "' . $section . '"';
        log_action($conn, $adminId, 'announcement_published', $title,
            $scope . ' · ' . $res['sent'] . ' recipient(s)' . ($pinned ? ' · pinned' : '')
            . ($sendSms ? ' · sms ' . $res['sms']['sent'] : ''));
        set_flash('success', 'Announcement published to ' . $res['sent'] . ' vendor(s).'
            . ($sendSms ? ' ' . announcement_sms_summary($res['sms']) : ''));
    }
    header('Location: admin_announcements.php'); exit;
}

/* ---- Edit an existing announcement (no re-send) ---- */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = (int)($_POST['id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $category = trim($_POST['category'] ?? 'announcement');
    $audience = ($_POST['audience'] ?? 'all') === 'section' ? 'section' : 'all';
    $section  = trim($_POST['section'] ?? '');
    $expires  = trim($_POST['expires_at'] ?? '');

    if ($id && $title !== '') {
        if ($audience === 'section' && $section === '') $audience = 'all';
        if (!isset($TYPE_META[$category])) $category = 'announcement';

        $expiresSql = $expires !== '' && strtotime($expires) !== false
                    ? date('Y-m-d H:i:s', strtotime($expires)) : null;

        $stmt = $conn->prepare(
            "UPDATE announcements
                SET title = ?, body = ?, category = ?, audience = ?, section = ?, expires_at = ?
              WHERE id = ?"
        );
        if ($stmt) {
            $title = mb_substr($title, 0, 180);
            $body  = $body !== '' ? $body : '(no further details)';
            $stmt->bind_param("ssssssi", $title, $body, $category, $audience, $section, $expiresSql, $id);
            $stmt->execute();
            $stmt->close();

            /* The copies already sitting in vendor inboxes should not
               keep showing the text we just corrected. */
            $sync = $conn->prepare(
                "UPDATE notifications SET title = ?, body = ? WHERE announcement_id = ?"
            );
            if ($sync) { $sync->bind_param("ssi", $title, $body, $id); $sync->execute(); $sync->close(); }

            log_action($conn, $adminId, 'announcement_updated', '#' . $id, $title);
            set_flash('success', 'Announcement updated.');
        }
    }
    header('Location: admin_announcements.php'); exit;
}

/* ---- Pin / unpin ---- */
if ($action === 'toggle_pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        announcement_toggle_pin($conn, $id);
        log_action($conn, $adminId, 'announcement_pinned', '#' . $id);
        set_flash('success', 'Pin toggled.');
    }
    header('Location: admin_announcements.php'); exit;
}

/* ---- Hide / show ---- */
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        announcement_toggle_active($conn, $id);
        log_action($conn, $adminId, 'announcement_toggled', '#' . $id);
        set_flash('success', 'Visibility toggled.');
    }
    header('Location: admin_announcements.php'); exit;
}

/* ---- Send the announcement to vendors added since it was published ---- */
if ($action === 'resend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $sent = announcement_fanout($conn, $id, $adminId, $adminName);
        if ($sent > 0) {
            /* A re-send is a fresh broadcast, so the SMS goes out again too. */
            $sms  = $SMS_READY ? announcement_sms($conn, $id) : announcement_sms_result(false, 'disabled');
            $text = announcement_sms_summary($sms);
            log_action($conn, $adminId, 'announcement_resent', '#' . $id,
                $sent . ' recipient(s) · ' . $text);
            set_flash('success', 'Re-sent to ' . $sent . ' vendor(s). ' . $text);
        } else {
            set_flash('error', 'No vendor matched that audience.');
        }
    }
    header('Location: admin_announcements.php'); exit;
}

/* ---- Text the audience without touching the inbox copies ---- */
if ($action === 'sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $sms = announcement_sms($conn, $id);
        log_action($conn, $adminId, 'announcement_sms', '#' . $id, announcement_sms_summary($sms));
        set_flash($sms['sent'] > 0 ? 'success' : 'error', announcement_sms_summary($sms));
    }
    header('Location: admin_announcements.php'); exit;
}

/* ---- Delete (cascades to every vendor copy) ---- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $title = '';
        if ($s = $conn->prepare("SELECT title FROM announcements WHERE id = ? LIMIT 1")) {
            $s->bind_param("i", $id); $s->execute();
            $r = $s->get_result()->fetch_assoc(); $s->close();
            $title = $r['title'] ?? '';
        }
        announcement_delete($conn, $id);
        log_action($conn, $adminId, 'announcement_deleted', '#' . $id, $title);
        set_flash('success', 'Announcement deleted from every vendor inbox.');
    }
    header('Location: admin_announcements.php'); exit;
}

$flash = get_flash();

/* ============================================================
   DATA
   ============================================================ */

/* Sections for the audience picker */
$sections = [];
if ($res = $conn->query("SELECT DISTINCT market_section FROM vendors WHERE market_section <> '' ORDER BY market_section")) {
    while ($r = $res->fetch_assoc()) $sections[] = $r['market_section'];
}

/* Editing? preload the composer */
$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
if ($editId && $s = $conn->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1")) {
    $s->bind_param("i", $editId); $s->execute();
    $edit = $s->get_result()->fetch_assoc(); $s->close();
}

/* List: pinned first, then newest. Live read state is recalculated on
   the way out so a vendor who just opened the notice is counted. */
$list = [];
if ($res = $conn->query(
    "SELECT a.id, a.title, a.body, a.category, a.audience, a.section, a.is_pinned,
            a.is_active, a.published_at, a.expires_at, a.recipient_count, a.read_count,
            a.sms_sent, a.sms_failed, a.sms_skipped, a.sms_sent_at,
            a.created_by_name
       FROM announcements a
      ORDER BY a.is_pinned DESC, a.published_at DESC, a.id DESC
      LIMIT 100"
)) {
    while ($r = $res->fetch_assoc()) $list[] = $r;
}

/* Refresh the denormalised read totals for everything on screen. */
$shownIds = [];
foreach ($list as $row) $shownIds[] = (int)$row['id'];
if ($shownIds) {
    $in = implode(',', $shownIds);
    $conn->query(
        "UPDATE announcements a
            SET a.recipient_count = (SELECT COUNT(*) FROM notifications n WHERE n.announcement_id = a.id),
                a.read_count      = (SELECT COUNT(*) FROM notifications n WHERE n.announcement_id = a.id AND n.is_read = 1)
          WHERE a.id IN ($in)"
    );
    foreach ($list as &$row) {
        if ($s = $conn->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(is_read = 1), 0) AS read_cnt
               FROM notifications WHERE announcement_id = ?"
        )) {
            $s->bind_param("i", $row['id']); $s->execute();
            $c = $s->get_result()->fetch_assoc(); $s->close();
            $row['recipient_count'] = (int)($c['total'] ?? 0);
            $row['read_count']      = (int)($c['read_cnt'] ?? 0);
        }
    }
    unset($row);
}

/* Stats */
$stat = ['live' => 0, 'pinned' => 0, 'reach' => 0, 'expired' => 0];
if ($res = $conn->query(
    "SELECT COALESCE(SUM(is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())), 0) AS live,
            COALESCE(SUM(is_pinned), 0) AS pinned,
            COALESCE(SUM(recipient_count), 0) AS reach,
            COALESCE(SUM(is_active = 1 AND expires_at IS NOT NULL AND expires_at <= NOW()), 0) AS expired
       FROM announcements"
)) {
    $stat = array_merge($stat, $res->fetch_assoc());
}
foreach ($stat as $k => $v) $stat[$k] = (int)$v;

/* Audience options worth a one-click shortcut */
$perm = $conn->query("SELECT id FROM vendors WHERE permit_status IN ('Expired','Suspended') AND status <> 'Rejected'");
$permCount = $perm ? $perm->num_rows : 0;
$due = $conn->query("SELECT id FROM vendors WHERE payment_status <> 'Paid' AND status <> 'Rejected'");
$dueCount = $due ? $due->num_rows : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Announcements • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--amber:#f59e0b;--red:#ef4444;--purple:#8b5cf6;}


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
.mini-stat .icon.g2{background:linear-gradient(135deg,#8b5cf6,#7c3aed);box-shadow:0 5px 14px rgba(124,58,237,.3);}
.mini-stat .icon.g3{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 5px 14px rgba(14,165,233,.32);}
.mini-stat .icon.g4{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 5px 14px rgba(217,119,6,.3);}
.mini-stat .num{font-size:22px;font-weight:900;color:var(--ink);letter-spacing:-.4px;line-height:1.1;}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px;}

/* Layout */
.grid-2{display:grid;grid-template-columns:minmax(0,380px) minmax(0,1fr);gap:18px;align-items:start;}@media(max-width:1100px){.grid-2{grid-template-columns:1fr;}}
.card-head{padding:18px 22px;border-bottom:1.5px solid var(--line-soft);display:flex;align-items:center;gap:12px;background:linear-gradient(180deg,var(--green-50),#fff);}
.card-head-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#8b5cf6,#7c3aed);display:grid;place-items:center;color:#fff;font-size:15px;box-shadow:0 5px 14px rgba(124,58,237,.3);flex-shrink:0;}
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
.field textarea{resize:vertical;min-height:120px;line-height:1.55;}
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

/* SMS preview */
.sms-phone{background:#0f172a;border-radius:14px;padding:12px;border:1.5px solid var(--line);}
.sms-screen{background:#152220;border-radius:10px;padding:12px;min-height:64px;display:flex;align-items:flex-end;}
.sms-bubble{
    background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-size:12.5px;line-height:1.5;
    padding:9px 12px;border-radius:14px 14px 4px 14px;max-width:100%;white-space:pre-line;word-break:break-word;
    box-shadow:0 4px 12px rgba(0,0,0,.25);
}
.sms-bubble.empty{background:rgba(255,255,255,.07);color:#8ba3b8;box-shadow:none;}
.sms-phone .hint{margin-top:8px;display:block;}

/* Checkbox row */
.check-row{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:var(--green-50);cursor:pointer;transition:all .2s;margin-bottom:14px;}
.check-row:hover{border-color:var(--green-300);}
.check-row input{width:17px;height:17px;margin-top:1px;accent-color:var(--brand);cursor:pointer;flex-shrink:0;}
.check-row .ct{font-size:12.5px;font-weight:800;color:var(--ink);}
.check-row .cd{font-size:11.5px;color:var(--muted);margin-top:2px;line-height:1.45;}

.btn-form{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border-radius:12px;font-family:inherit;font-size:13.5px;font-weight:800;cursor:pointer;border:none;transition:all .25s var(--ease-spring);text-decoration:none;width:100%;}
.btn-form.primary{background:linear-gradient(135deg, #059669, #065f46);color:#fff;box-shadow:var(--glow-soft);}
.btn-form.primary:hover{transform:translateY(-2px);box-shadow:var(--glow);}
.btn-form.ghost{background:var(--bg);color:var(--ink-soft);border:1.5px solid var(--line);}
.btn-form.ghost:hover{background:var(--line-soft);}

/* Announcement list */
.ann-list{display:flex;flex-direction:column;}
.ann{padding:16px 22px;border-bottom:1px solid var(--line-soft);transition:background .15s;}
.ann:last-child{border-bottom:none;}
.ann:hover{background:var(--green-50);}
.ann.pinned{background:linear-gradient(90deg,#faf5ff,#fff 55%);}
.ann.pinned:hover{background:#f5f3ff;}
.ann.hidden-ann{opacity:.62;}
.ann-top{display:flex;align-items:flex-start;gap:12px;margin-bottom:7px;}
.ann-icon{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-size:15px;}
.ann-icon.green{background:linear-gradient(135deg,#10b981,#047857);}
.ann-icon.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);}
.ann-icon.sky{background:linear-gradient(135deg,#0ea5e9,#0284c7);}
.ann-icon.amber{background:linear-gradient(135deg,#f59e0b,#d97706);}
.ann-icon.red{background:linear-gradient(135deg,#ef4444,#dc2626);}
.ann-icon.purple{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.ann-head{flex:1;min-width:0;}
.ann-title{font-size:14.5px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.ann-title .pin{color:#8b5cf6;font-size:12px;}
.ann-meta{font-size:11.5px;color:var(--muted);margin-top:3px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.ann-body{font-size:13px;color:var(--ink-soft);line-height:1.6;white-space:pre-line;}
.ann-foot{display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;}
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:800;background:var(--bg);color:var(--ink-soft);border:1px solid var(--line);white-space:nowrap;}
.chip.live{background:var(--green-100);color:var(--green-800);border-color:var(--green-200);}
.chip.off{background:#f1f5f9;color:#64748b;border-color:#e2e8f0;}
.chip.expiry{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.chip.sms{background:#ecfdf5;color:#065f46;border-color:#a7f3d0;}
.chip.sms.none{background:var(--bg);color:var(--muted);border-color:var(--line);}
.chip.sms i{font-size:10px;}
.ann-actions{margin-left:auto;display:flex;gap:6px;}
.icon-btn.on{border-color:#a78bfa;background:#faf5ff;color:#7c3aed;}
.icon-btn.del:hover{border-color:#fca5a5;background:#fee2e2;color:#dc2626;}

/* Read progress */
.progress-wrap{display:flex;align-items:center;gap:8px;margin-top:10px;}
.progress{flex:1;height:7px;border-radius:999px;background:var(--line-soft);overflow:hidden;}
.progress > i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#34d399,#059669);transition:width .5s var(--ease-smooth);}
.progress-label{font-size:10.5px;font-weight:800;color:var(--muted);white-space:nowrap;}

.empty-state{padding:56px 24px;text-align:center;color:var(--muted);}
.empty-state i{font-size:48px;color:var(--green-200);display:block;margin-bottom:14px;}
.empty-state h3{font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.empty-state p{font-size:13.5px;}

.filter-row{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:16px;}@media(max-width:900px){.card-body{padding:16px}}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-bullhorn"></i></span>
                Announcements
            </h1>
            <p>Post a notice for the whole market or a single section — vendors get it in their inbox instantly.</p>
        </div>
        <div class="header-actions">
            <a href="admin_notifications.php" class="btn-header ghost"><i class="fas fa-bell"></i> Private Messages</a>
            <a href="admin_permits.php" class="btn-header ghost"><i class="fas fa-file-shield"></i> Permits &amp; Payments</a>
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
            <div class="icon g1"><i class="fas fa-bullhorn"></i></div>
            <div><div class="num"><?= $stat['live'] ?></div><div class="lbl">Live Notices</div></div>
        </div>
        <div class="mini-stat">
            <div class="icon g2"><i class="fas fa-thumbtack"></i></div>
            <div><div class="num"><?= $stat['pinned'] ?></div><div class="lbl">Pinned</div></div>
        </div>
        <div class="mini-stat">
            <div class="icon g3"><i class="fas fa-send"></i></div>
            <div><div class="num"><?= $stat['reach'] ?></div><div class="lbl">Total Reach</div></div>
        </div>
        <div class="mini-stat">
            <div class="icon g4"><i class="fas fa-clock-rotate-left"></i></div>
            <div><div class="num"><?= $stat['expired'] ?></div><div class="lbl">Expired</div></div>
        </div>
    </div>

    <div class="grid-2">

        <!-- ============================================================
             COMPOSER
             ============================================================ -->
        <div>
            <div class="card">
                <div class="card-head">
                    <div class="card-head-icon"><i class="fas <?= $edit ? 'fa-pen' : 'fa-pen-to-square' ?>"></i></div>
                    <div>
                        <h2><?= $edit ? 'Edit Announcement #' . (int)$edit['id'] : 'New Announcement' ?></h2>
                        <p><?= $edit ? 'Editing text only — use Re-send to deliver it again' : 'Reach every vendor or just one section' ?></p>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="admin_announcements.php" id="annForm">
                        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'publish' ?>" />
                        <?php if ($edit): ?>
                            <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>" />
                        <?php endif; ?>

                        <!-- Audience -->
                        <div class="field">
                            <label>Audience <span class="req">*</span></label>
                            <div class="target-pills">
                                <?php
                                $curAudience = $edit['audience'] ?? 'all';
                                foreach ($AUDIENCES as $ak => $am): ?>
                                    <label class="pill">
                                        <input type="radio" name="audience" value="<?= h($ak) ?>" <?= $curAudience === $ak ? 'checked' : '' ?> />
                                        <span><i class="fas <?= h($am['icon']) ?>"></i> <?= h($am['label']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Section -->
                        <div class="field" id="wrapSection" <?= ($edit['audience'] ?? 'all') === 'section' ? '' : 'style="display:none;"' ?>>
                            <label>Market Section <span class="req">*</span></label>
                            <select name="section" id="sectionSelect">
                                <option value="">— Select a section —</option>
                                <?php $curSection = $edit['section'] ?? ''; ?>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= h($s) ?>" <?= $curSection === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Category -->
                        <div class="field">
                            <label>Label</label>
                            <select name="category" id="categorySelect">
                                <?php $curCat = $edit['category'] ?? 'announcement'; ?>
                                <?php foreach ($TYPE_META as $tk => $tm): ?>
                                    <option value="<?= h($tk) ?>" <?= $curCat === $tk ? 'selected' : '' ?>><?= h($tm['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">The badge and icon the vendor sees.</span>
                        </div>

                        <!-- Title -->
                        <div class="field">
                            <label>Title <span class="req">*</span></label>
                            <input type="text" name="title" id="titleInput" maxlength="180"
                                   placeholder="e.g. Market opens at 5:00 AM starting Monday"
                                   value="<?= h($edit['title'] ?? '') ?>" required />
                        </div>

                        <!-- Body -->
                        <div class="field">
                            <label>Message</label>
                            <textarea name="body" id="bodyInput" placeholder="Give the vendors the details they need to act on…"><?= h($edit['body'] ?? '') ?></textarea>
                        </div>

                        <!-- SMS preview: shows the exact text a phone would receive -->
                        <?php if ($SMS_READY): ?>
                        <div class="field">
                            <label><i class="fas fa-mobile-screen"></i> Text message preview</label>
                            <div class="sms-phone">
                                <div class="sms-screen"><div class="sms-bubble" id="smsPreview"></div></div>
                                <span class="hint" id="smsCounter">
                                    Trimmed to <?= (int)$SMS_CFG['max_length'] ?> characters.
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Expiry -->
                        <div class="field">
                            <label>Hide after (optional)</label>
                            <input type="datetime-local" name="expires_at" id="expiresInput"
                                   value="<?= !empty($edit['expires_at']) ? h(date('Y-m-d\TH:i', strtotime($edit['expires_at']))) : '' ?>" />
                            <span class="hint">Leave blank to keep the notice on the board indefinitely.</span>
                        </div>

                        <?php if (!$edit): ?>
                        <label class="check-row">
                            <input type="checkbox" name="is_pinned" value="1" />
                            <span>
                                <span class="ct"><i class="fas fa-thumbtack"></i> Pin to the top</span>
                                <span class="cd">Pinned notices stay above everything else on the vendor's board.</span>
                            </span>
                        </label>
                        <?php endif; ?>

                        <!-- SMS opt-in. The inbox copy is always created; this
                             only adds the text copy on top of it. -->
                        <?php if ($SMS_READY): ?>
                        <label class="check-row">
                            <input type="checkbox" name="send_sms" value="1" checked />
                            <span>
                                <span class="ct"><i class="fas fa-comment-sms"></i> Also text the vendors</span>
                                <span class="cd">Sends the message above to every vendor with a valid mobile number. Vendors without one are skipped.</span>
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
                            <i class="fas <?= $edit ? 'fa-floppy-disk' : 'fa-paper-plane' ?>"></i>
                            <?= $edit ? 'Save Changes' : 'Publish Announcement' ?>
                        </button>

                        <?php if ($edit): ?>
                            <a href="admin_announcements.php" class="btn-form ghost" style="margin-top:9px;">
                                <i class="fas fa-xmark"></i> Cancel Edit
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Ready-made notices -->
            <div class="card">
                <div class="card-head">
                    <div class="card-head-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 5px 14px rgba(14,165,233,.3);">
                        <i class="fas fa-wand-magic-sparkles"></i>
                    </div>
                    <div>
                        <h2>Starter Templates</h2>
                        <p>Click to fill the composer</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="tpl-grid">
                        <button type="button" class="tpl" data-tpl="hours"><i class="far fa-clock"></i> Market hours</button>
                        <button type="button" class="tpl" data-tpl="cleanup"><i class="fas fa-broom"></i> Clean-up day</button>
                        <button type="button" class="tpl" data-tpl="permitbatch"><i class="fas fa-file-shield"></i> Permit reminder</button>
                        <button type="button" class="tpl" data-tpl="paymentdue"><i class="fas fa-wallet"></i> Payment due</button>
                        <button type="button" class="tpl" data-tpl="evacuation"><i class="fas fa-triangle-exclamation"></i> Advisory</button>
                    </div>
                    <p class="hint" style="font-size:11.5px;color:var(--muted);line-height:1.55;">
                        <?= $permCount ?> vendor(s) have an expired or suspended permit ·
                        <?= $dueCount ?> still have an unpaid balance.
                        Use <a href="admin_notifications.php" style="color:var(--brand-dark);font-weight:800;">Private Messages</a>
                        to reach those vendors one by one.
                    </p>
                </div>
            </div>
        </div>

        <!-- ============================================================
             BULLETIN BOARD
             ============================================================ -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-icon" style="background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 5px 14px rgba(16,185,129,.3);">
                    <i class="fas fa-list-check"></i>
                </div>
                <div>
                    <h2>Bulletin Board</h2>
                    <p><?= count($list) ?> announcement(s) · pinned first</p>
                </div>
                <div class="seq" data-seq>
                    <button type="button" class="seq-btn" data-seq-prev title="Previous notice" aria-label="Previous notice"><i class="fas fa-chevron-up"></i></button>
                    <span class="seq-count" data-seq-count>1 of <?= max(1, count($list)) ?></span>
                    <button type="button" class="seq-btn" data-seq-next title="Next notice" aria-label="Next notice"><i class="fas fa-chevron-down"></i></button>
                </div>
            </div>

            <?php if (empty($list)): ?>
                <div class="empty-state">
                    <i class="fas fa-bullhorn"></i>
                    <h3>No announcements yet</h3>
                    <p>Use the composer to post the market's first notice.</p>
                </div>
            <?php else: ?>
                <div class="ann-list board wide" data-board tabindex="0" role="region" aria-label="Announcement list">
                    <?php foreach ($list as $a):
                        $meta     = $TYPE_META[$a['category']] ?? $TYPE_META['announcement'];
                        $reach    = (int)$a['recipient_count'];
                        $reads    = (int)$a['read_count'];
                        $pct      = $reach > 0 ? (int)round($reads * 100 / $reach) : 0;
                        $smsSent  = (int)($a['sms_sent'] ?? 0);
                        $smsFail  = (int)($a['sms_failed'] ?? 0);
                        $smsSkip  = (int)($a['sms_skipped'] ?? 0);
                        $isLive   = (int)$a['is_active'] === 1
                                 && (empty($a['expires_at']) || strtotime($a['expires_at']) > time());
                    ?>
                        <div class="ann<?= (int)$a['is_pinned'] ? ' pinned' : '' ?><?= $isLive ? '' : ' hidden-ann' ?>">
                            <div class="ann-top">
                                <div class="ann-icon <?= h($meta['color']) ?>">
                                    <i class="fas <?= h($meta['icon']) ?>"></i>
                                </div>
                                <div class="ann-head">
                                    <div class="ann-title">
                                        <?php if ((int)$a['is_pinned']): ?><i class="fas fa-thumbtack pin"></i><?php endif; ?>
                                        <?= h($a['title']) ?>
                                    </div>
                                    <div class="ann-meta">
                                        <span><i class="far fa-user"></i> <?= h($a['created_by_name'] ?: 'Market Office') ?></span>
                                        <span><i class="far fa-clock"></i> <?= h(notif_time_ago($a['published_at'])) ?></span>
                                        <span><i class="fas fa-users"></i> <?= $a['audience'] === 'all' ? 'All vendors' : h($a['section'] ?: 'Section') ?></span>
                                    </div>
                                </div>
                            </div>

                            <?php if (trim((string)$a['body']) !== ''): ?>
                                <div class="ann-body"><?= h($a['body']) ?></div>
                            <?php endif; ?>

                            <div class="ann-foot">
                                <span class="chip <?= $isLive ? 'live' : 'off' ?>">
                                    <i class="fas <?= $isLive ? 'fa-circle-check' : 'fa-eye-slash' ?>"></i>
                                    <?= $isLive ? 'Live' : ((int)$a['is_active'] ? 'Expired' : 'Hidden') ?>
                                </span>
                                <span class="chip"><i class="fas fa-tag"></i> <?= h($meta['label']) ?></span>
                                <?php if (!empty($a['expires_at'])): ?>
                                    <span class="chip expiry"><i class="far fa-hourglass"></i> Until <?= h(date('M j, Y', strtotime($a['expires_at']))) ?></span>
                                <?php endif; ?>
                                <span class="chip"><i class="fas fa-id-badge"></i> #<?= (int)$a['id'] ?></span>

                                <?php if ($SMS_READY): ?>
                                    <span class="chip sms <?= $smsSent > 0 ? '' : 'none' ?>"
                                          title="<?= $smsSent > 0
                                              ? $smsSent . ' texted'
                                                . ($smsSkip ? ', ' . $smsSkip . ' skipped (no valid mobile number)' : '')
                                                . ($smsFail ? ', ' . $smsFail . ' rejected' : '')
                                              : 'Not sent by SMS' ?>">
                                        <i class="fas fa-comment-sms"></i>
                                        <?php if ($smsSent > 0): ?>
                                            <?= $smsSent ?> texted<?= $smsFail ? ' · ' . $smsFail . ' failed' : '' ?>
                                        <?php else: ?>
                                            No SMS
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>

                                <div class="ann-actions">
                                    <form method="post" action="admin_announcements.php">
                                        <input type="hidden" name="action" value="toggle_pin" />
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                                        <button type="submit" class="icon-btn<?= (int)$a['is_pinned'] ? ' on' : '' ?>" title="<?= (int)$a['is_pinned'] ? 'Unpin' : 'Pin to top' ?>">
                                            <i class="fas fa-thumbtack"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="admin_announcements.php">
                                        <input type="hidden" name="action" value="toggle_active" />
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                                        <button type="submit" class="icon-btn" title="<?= (int)$a['is_active'] ? 'Hide from vendors' : 'Show to vendors' ?>">
                                            <i class="fas <?= (int)$a['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        </button>
                                    </form>
                                    <a href="admin_announcements.php?edit=<?= (int)$a['id'] ?>#composer" class="icon-btn" title="Edit text">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <form method="post" action="admin_announcements.php"
                                          onsubmit="return confirm('Re-send this announcement? Every vendor in the audience will get a fresh copy.');">
                                        <input type="hidden" name="action" value="resend" />
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                                        <button type="submit" class="icon-btn" title="Re-send to the audience">
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
                                    </form>
                                    <?php if ($SMS_READY): ?>
                                        <form method="post" action="admin_announcements.php"
                                              onsubmit="return confirm('Text this announcement to its audience? This sends real SMS to every vendor with a valid mobile number.');">
                                            <input type="hidden" name="action" value="sms" />
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                                            <button type="submit" class="icon-btn" title="Text this announcement to the audience">
                                                <i class="fas fa-comment-sms"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="admin_announcements.php"
                                          onsubmit="return confirm('Delete this announcement? It will also disappear from every vendor inbox.');">
                                        <input type="hidden" name="action" value="delete" />
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                                        <button type="submit" class="icon-btn del" title="Delete">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <?php if ($reach > 0): ?>
                                <div class="progress-wrap">
                                    <div class="progress"><i style="width:<?= $pct ?>%"></i></div>
                                    <span class="progress-label"><?= $reads ?>/<?= $reach ?> read (<?= $pct ?>%)</span>
                                </div>
                            <?php else: ?>
                                <div class="progress-wrap">
                                    <div class="progress"><i style="width:0%"></i></div>
                                    <span class="progress-label">Not delivered to anyone</span>
                                </div>
                            <?php endif; ?>
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

/* Show only the picker that matches the chosen audience */
function syncAudience() {
    const audience = document.querySelector('input[name="audience"]:checked').value;
    document.getElementById('wrapSection').style.display = (audience === 'section') ? '' : 'none';
}
document.querySelectorAll('input[name="audience"]').forEach(r => r.addEventListener('change', syncAudience));

/* Starter templates — only fill empty fields so typing is never lost */
const TEMPLATES = {
    hours: {
        category: 'announcement',
        title: 'Change in market hours',
        body:  'Good day!\n\nStarting Monday, the market will open at 5:00 AM instead of 4:00 AM. Please plan your setup accordingly and make sure your stall is secured before the opening bell.'
    },
    cleanup: {
        category: 'announcement',
        title: 'General clean-up day this Saturday',
        body:  'The market will hold a general clean-up day this Saturday.\n\nKindly clear your stall of waste and unused containers before 6:00 AM. Vendors who leave rubbish around will be fined on a per-basket basis.'
    },
    permitbatch: {
        category: 'permit',
        title: 'Reminder: renew your business permit',
        body:  'This is a friendly reminder that all business permits must be renewed on or before the expiry date shown on your dashboard.\n\nRenew at the market office from 8:00 AM to 5:00 PM. Please bring your old permit and a valid ID.'
    },
    paymentdue: {
        category: 'payment',
        title: 'Monthly stall rental is now due',
        body:  'Stall rental for the current month is now due. Kindly settle your account at the market office to avoid penalties.\n\nYou can check the amount and due date on your dashboard under Payments.'
    },
    evacuation: {
        category: 'reminder',
        title: 'Advisory from the market office',
        body:  'Please take note of this advisory and act accordingly. Check with the market office if you have questions about your stall.'
    }
};

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

    let text = body.replace(/[ \t]+/g, ' ').trim().replace(/\n{3,}/g, '\n\n');
    if (SMS_PREFIX) text = SMS_PREFIX + ': ' + text;
    text = title ? title + '\n' + text : text;

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

document.querySelectorAll('.tpl').forEach(btn => {
    btn.addEventListener('click', () => {
        const tpl = TEMPLATES[btn.dataset.tpl];
        if (!tpl) return;
        document.getElementById('categorySelect').value = tpl.category;
        if (!document.getElementById('titleInput').value.trim()) document.getElementById('titleInput').value = tpl.title;
        if (!document.getElementById('bodyInput').value.trim())  document.getElementById('bodyInput').value  = tpl.body;
        document.getElementById('titleInput').focus();
    });
});

/* Jump to the composer when editing */
if (window.location.search.includes('edit=')) {
    document.getElementById('annForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('titleInput').focus();
}

syncAudience();
renderSmsPreview();
</script>

<?php /* The bulletin board scrolls inside itself and steps one notice at a time. */
require __DIR__ . '/_board_sequence.php'; ?>

</body>
</html>