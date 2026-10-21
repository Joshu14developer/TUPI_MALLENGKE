<?php
/* ============================================================
   STALL MAP (market office)
   ------------------------------------------------------------
   The one place a stall is assigned.

   A vendor never picks their own stall — they file a request from
   their own stall map, and it lands in the queue below. Everything on
   this page that writes vendors.stall_number goes through
   config/stalls.php, so the transaction, the UNIQUE key and the audit
   trail are the same whichever button was pressed.
   ============================================================ */
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: admin_login.php'); exit; }

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/stalls.php';
require_once __DIR__ . '/../config/adminmsg.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole = $_SESSION['admin_role'] ?? 'admin';

function set_flash($t, $m) { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function get_flash() { if (empty($_SESSION['flash'])) return null; $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip); $stmt->execute(); $stmt->close(); }
}
function h($v) { return htmlspecialchars((string)$v); }
function ini_from($name) {
    $p = preg_split('/\s+/', trim((string)$name));
    return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1));
}

/** "Ana Cruz" for the flash and the log. */
function vendor_label($conn, $vendorId) {
    $vid = (int)$vendorId;
    $stmt = $conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS n FROM vendors WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $vid);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc()['n'] ?? '';
        $stmt->close();
        if (trim((string)$n) !== '') return trim((string)$n);
    }
    return 'Vendor #' . (int)$vendorId;
}

/* Tell a vendor where they stand. Best effort: a notification that
   fails to send must never undo an assignment that already worked. */
function notify_stall_decision($conn, $vendorId, $title, $body, $adminId, $adminName) {
    notify_vendor($conn, (int)$vendorId, 'stall', $title, $body, 'php/stall_map.php', (int)$adminId, (string)$adminName);
}

/* ============================================================
   ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? '';
$back   = 'admin_stall_map.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $filter = in_array($_POST['filter'] ?? '', ['pending','assigned','declined','all'], true)
        ? $_POST['filter'] : 'pending';
    $openThread = (int)($_POST['thread'] ?? 0);
    $back = 'admin_stall_map.php?filter=' . $filter;
    if ($openThread > 0) $back .= '&thread=' . $openThread;

    /* ---- answer a vendor who wrote to the office ---- */
    if ($action === 'reply_office') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $body     = trim((string)($_POST['reply'] ?? ''));

        $res = am_reply_from_admin($conn, $vendorId, $adminId, $adminName, $body);
        if ($res['ok']) {
            log_action($conn, $adminId, 'vendor_message_replied',
                vendor_label($conn, $vendorId), mb_substr($body, 0, 200));
            set_flash('success', 'Your reply is on its way to ' . vendor_label($conn, $vendorId) . '.');
        } else {
            set_flash('error', $res['error']);
        }

        header('Location: ' . $back);
        exit;
    }

    /* ---- open a thread: that is what marks it read ---- */
    if ($action === 'open_thread') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        am_mark_read($conn, $vendorId, 'office');
        header('Location: admin_stall_map.php?filter=' . $filter . '&thread=' . $vendorId);
        exit;
    }

    if ($action === 'assign_stall') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $stall    = (string)($_POST['stall_number'] ?? '');
        $note     = trim((string)($_POST['note'] ?? ''));

        $from = '';
        if ($stmt = $conn->prepare("SELECT stall_number FROM vendors WHERE id = ? LIMIT 1")) {
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            $from = strtoupper(trim((string)($stmt->get_result()->fetch_assoc()['stall_number'] ?? '')));
            $stmt->close();
        }

        $res = stall_assign($conn, $vendorId, $stall, $adminId, $adminName, $note);

        if ($res['ok'] && $res['moved']) {
            $label = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'stall_assigned', $label,
                strtoupper($stall) . ($from !== '' ? ' (moved from ' . $from . ')' : ''));

            $body = $from !== ''
                ? "The market office has moved you to Stall " . strtoupper($stall) . ".\n\n"
                . "Your previous stall (" . $from . ") is now free for someone else."
                : "The market office has assigned you Stall " . strtoupper($stall) . ".\n\n"
                . "You can see it on your stall map now.";
            if ($note !== '') $body .= "\n\nNote from the office: " . $note;
            notify_stall_decision($conn, $vendorId,
                'Stall assigned — ' . strtoupper($stall), $body, $adminId, $adminName);

            set_flash('success', $label . ' is now at Stall ' . strtoupper($stall) . '. They have been notified.');
        } elseif ($res['ok']) {
            /* They were already trading there — nothing changed, so
               nobody is told about a move that never happened. */
            set_flash('success', vendor_label($conn, $vendorId) . ' already trades at Stall ' . strtoupper($stall) . '.');
        } else {
            set_flash('error', $res['error']);
        }

        header('Location: ' . $back);
        exit;
    }

    if ($action === 'release_stall') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $note     = trim((string)($_POST['note'] ?? ''));

        $was = '';
        if ($stmt = $conn->prepare("SELECT stall_number FROM vendors WHERE id = ? LIMIT 1")) {
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            $was = strtoupper(trim((string)($stmt->get_result()->fetch_assoc()['stall_number'] ?? '')));
            $stmt->close();
        }

        $res = stall_release($conn, $vendorId, $adminId, $adminName, $note);

        if ($res['ok']) {
            $label = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'stall_released', $label, $was . ($note !== '' ? ' · ' . $note : ''));
            notify_stall_decision($conn, $vendorId,
                'Your stall has been released',
                "The market office has released your stall (" . $was . ").\n\n"
                . "You do not have a stall at the moment. Please apply again on your stall map."
                . ($note !== '' ? "\n\nNote from the office: " . $note : ''),
                $adminId, $adminName);
            set_flash('success', $label . ' no longer holds ' . $was . '. They have been notified.');
        } else {
            set_flash('error', $res['error']);
        }

        header('Location: ' . $back);
        exit;
    }

    if ($action === 'decline_stall_request') {
        $appId = (int)($_POST['id'] ?? 0);
        $note  = trim((string)($_POST['note'] ?? ''));

        $vendorId = 0;
        if ($stmt = $conn->prepare("SELECT vendor_id FROM stall_applications WHERE id = ? LIMIT 1")) {
            $stmt->bind_param('i', $appId);
            $stmt->execute();
            $vendorId = (int)($stmt->get_result()->fetch_assoc()['vendor_id'] ?? 0);
            $stmt->close();
        }

        $res = stall_decline($conn, $appId, $adminId, $adminName, $note);

        if ($res['ok']) {
            $label = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'stall_request_declined', $label, $note);
            notify_stall_decision($conn, $vendorId,
                'Your stall request was not granted',
                "Hi " . trim(explode(' ', $label)[0]) . ", the market office could not grant your stall request.\n\n"
                . "Reason: " . $note . "\n\nYou can apply again from your stall map.",
                $adminId, $adminName);
            set_flash('success', 'Request declined. ' . $label . ' has been told why.');
        } else {
            set_flash('error', $res['error']);
        }

        header('Location: ' . $back);
        exit;
    }
}

/* ============================================================
   READ
   ============================================================ */
$flash  = get_flash();
$layout = stall_layout();
list($leftWing, $rightWing) = stall_wings();

$occupied = stall_occupancy($conn);
$counts   = stall_counts($conn);

$filter = in_array($_GET['filter'] ?? '', ['pending','assigned','declined','all'], true) ? $_GET['filter'] : 'pending';
$search = trim((string)($_GET['q'] ?? ''));

$applications = stall_applications_list($conn, $filter, $search);
$appCounts    = stall_application_counts($conn);
$unassigned   = stall_unassigned($conn);
$waiting      = stall_pending_count($conn);
$noStall      = stall_unassigned_count($conn);

/* ---- the office inbox: one row per vendor who has written in ---- */
$amThreads   = am_inbox($conn);
$amUnread    = am_office_unread_count($conn);
$openVendor  = (int)($_GET['thread'] ?? 0);
$openThread  = [];
$openThreadVendor = null;
if ($openVendor > 0) {
    $openThread = am_thread($conn, $openVendor);
    if ($stmt = $conn->prepare(
        "SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, business_name,
                contact_number, stall_number, market_section, product_type
           FROM vendors WHERE id = ? LIMIT 1"
    )) {
        $stmt->bind_param('i', $openVendor);
        $stmt->execute();
        $openThreadVendor = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    /* Opening a thread from the link is what marks it read, so the
       badge does not nag about something already on screen. */
    am_mark_read($conn, $openVendor, 'office');
    $amUnread = am_office_unread_count($conn);
}

/* Pending applicants grouped by the stall they asked for, so the
   office can work down a list instead of opening each row. */
$wanting = [];
foreach ($applications as $a) {
    if ($a['status'] !== 'Pending') continue;
    $wanted = strtoupper(trim((string)($a['requested_stall'] ?? '')));
    if ($wanted === '') continue;
    $wanting[$wanted][] = $a;
}
$APP_META = stall_application_meta();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Stall Map • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--amber:#f59e0b;--red:#ef4444;--purple:#8b5cf6;}


.flash{display:flex;align-items:center;gap:12px;padding:14px 20px;border-radius:14px;font-size:13.5px;font-weight:600;margin-bottom:18px;animation:fadeUp .35s var(--ease-spring)}
.flash.success{background:var(--green-50);border:1.5px solid var(--green-200);color:var(--green-800)}
.flash.success i{color:var(--green-600)}
.flash.error{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.flash.error i{color:#dc2626}
.flash i{font-size:16px}
@keyframes fadeUp{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
a.mini-stat:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.mini-stat .icon{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;font-size:17px;flex-shrink:0}
.mini-stat .icon.i1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.35)}
.mini-stat .icon.i2{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 16px rgba(217,119,6,.32)}
.mini-stat .icon.i3{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 16px rgba(14,165,233,.32)}
.mini-stat .icon.i4{background:linear-gradient(135deg,#ef4444,#b91c1c);box-shadow:0 6px 16px rgba(239,68,68,.3)}
.mini-stat .num{font-size:23px;font-weight:900;color:var(--ink);letter-spacing:-.5px;line-height:1.1}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px}

.toolbar{background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:var(--sh-sm);margin-bottom:18px}
.toolbar .search-wrap{position:relative;flex:1;min-width:220px}
.toolbar .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
.toolbar input[type="text"]{width:100%;padding:11px 16px 11px 40px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg);color:var(--ink);font-family:inherit;font-size:13.5px;outline:none;transition:all .2s}
.toolbar input[type="text"]:focus{background:#fff;border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13)}
.toolbar select{padding:11px 38px 11px 14px;border:1.5px solid var(--line);border-radius:12px;background:#fff;font-family:inherit;font-size:13.5px;font-weight:600;color:var(--ink-soft);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:14px}

.panel{background:#fff;border:1.5px solid var(--line);border-radius:18px;box-shadow:var(--sh-sm);margin-bottom:18px;overflow:hidden}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1.5px solid var(--line-soft);flex-wrap:wrap}
.panel-head h2{font-size:15px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:9px}
.panel-head h2 i{color:var(--brand)}
.panel-head .sub{font-size:12px;color:var(--muted);margin-top:3px}
.panel-body{padding:18px 20px}

.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tab{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:999px;font-size:12.5px;
    font-weight:800;text-decoration:none;color:var(--ink-soft);background:var(--bg);border:1.5px solid var(--line);
    transition:all .2s var(--ease-spring)}
.tab:hover{border-color:var(--green-300);color:var(--brand-dark)}
.tab.active{background:linear-gradient(135deg,#10b981,#047857);color:#fff;border-color:transparent;box-shadow:var(--glow-soft)}
.tab .n{font-size:10.5px;padding:2px 7px;border-radius:999px;background:rgba(255,255,255,.65);color:var(--ink-soft)}
.tab.active .n{background:rgba(0,0,0,.16);color:#fff}

/* ---- the floor plan ---- */
.plan{background:linear-gradient(180deg,#fbfcfd,#f8fafc);border:1.5px solid var(--line);border-radius:16px;
    padding:20px;position:relative;overflow-x:auto}
.plan-wings{display:grid;grid-template-columns:1fr 58px 1fr;gap:14px;align-items:start;min-width:820px}
.plan-aisle{background:repeating-linear-gradient(45deg,#f1f5f9 0 8px,#eef2f7 8px 16px);border-radius:10px;
    border:1.5px dashed var(--line);min-height:120px}
.sec{background:#fff;border:1.5px solid var(--line);border-radius:14px;padding:11px;margin-bottom:11px}
.sec-head{display:flex;align-items:center;gap:9px;margin-bottom:9px}
.sec-letter{width:26px;height:26px;border-radius:9px;background:linear-gradient(135deg,#10b981,#047857);
    color:#fff;display:grid;place-items:center;font-size:12px;font-weight:900;flex-shrink:0}
.sec-name{font-size:12.5px;font-weight:800;color:var(--ink)}
.sec-count{margin-left:auto;font-size:10.5px;font-weight:800;color:var(--muted);background:var(--bg);
    padding:3px 8px;border-radius:999px}
.cells{display:grid;grid-template-columns:repeat(auto-fill,minmax(58px,1fr));gap:6px}
.cell{position:relative;border:1.5px solid var(--line);border-radius:9px;background:#fff;padding:8px 4px 7px;
    text-align:center;cursor:pointer;font-family:inherit;transition:all .2s var(--ease-spring);width:100%}
.cell-code{font-size:11.5px;font-weight:800;color:var(--ink);letter-spacing:-.2px;display:block}
.cell-name{font-size:8.5px;color:var(--muted);margin-top:2px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell.free{border-style:dashed;background:#fbfcfd}
.cell.free:hover{border-color:var(--green-400);background:var(--green-50);transform:translateY(-2px)}
.cell.held{border-color:var(--green-200);background:linear-gradient(135deg,#ecfdf5,#fff)}
.cell.held:hover{transform:translateY(-2px);box-shadow:var(--sh-md)}
.cell.pending{border-color:#fcd34d;background:linear-gradient(135deg,#fffbeb,#fff)}
.cell.pending:hover{transform:translateY(-2px);box-shadow:var(--sh-md)}
.cell.sel{outline:3px solid var(--green-400);outline-offset:1px}
.cell:focus-visible{outline:3px solid var(--green-500);outline-offset:2px;transform:translateY(-2px)}
.cell-dot{position:absolute;top:5px;right:5px;width:6px;height:6px;border-radius:50%}
.cell-dot.held{background:var(--green-500)}
.cell-dot.free{background:var(--line)}
.cell.pending .cell-dot{background:var(--amber)}

/* ---- the selected stall panel ---- */
.pick{border:1.5px solid var(--line);border-radius:14px;background:#fff;padding:16px 18px;margin-top:14px}
.pick.is-free{border-style:dashed;border-color:var(--green-300);background:linear-gradient(135deg,#ecfdf5,#fff)}
.pick-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.pick-code{font-size:19px;font-weight:900;color:var(--ink);letter-spacing:-.5px}
.pick-meta{font-size:12px;color:var(--muted);margin-top:3px}
.pick-grid{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.pick-info{display:flex;flex-direction:column;gap:7px;margin-bottom:14px;padding:13px 15px;
    border:1px solid var(--line-soft);border-radius:12px;background:#fbfcfd}
.pick-info-row{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--ink-soft)}
.pick-info-row i{width:24px;height:24px;border-radius:8px;flex-shrink:0;display:grid;place-items:center;
    background:#fff;border:1px solid var(--line-soft);color:var(--muted);font-size:10px}
.pick-info-row span{color:var(--ink);font-weight:600;word-break:break-word}
.pick-info .btn{align-self:flex-start;margin-top:3px}
.pick-field{display:flex;flex-direction:column;gap:5px;flex:1;min-width:190px}
.pick-field label{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.pick-field select,.pick-field input,.pick-field textarea{padding:10px 12px;border:1.5px solid var(--line);
    border-radius:10px;font-family:inherit;font-size:12.5px;color:var(--ink);background:#fff;width:100%}
.pick-field select:focus,.pick-field input:focus,.pick-field textarea:focus{outline:none;border-color:var(--green-400);
    box-shadow:0 0 0 3px rgba(52,211,153,.18)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:11px;
    font-size:12.5px;font-weight:800;border:none;cursor:pointer;font-family:inherit;text-decoration:none;
    transition:all .22s var(--ease-spring);white-space:nowrap}
.btn.primary{background:linear-gradient(135deg, #059669, #065f46);color:#fff;box-shadow:var(--glow-soft)}
.btn.primary:hover{transform:translateY(-2px);box-shadow:var(--glow)}
.btn.amber{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;box-shadow:0 6px 16px rgba(217,119,6,.3)}
.btn.amber:hover{transform:translateY(-2px)}
.btn.red{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.btn.red:hover{border-color:var(--red);background:#fee2e2;transform:translateY(-2px)}
.btn.ghost{background:#fff;border:1.5px solid var(--line);color:var(--ink-soft)}
.btn.ghost:hover{border-color:var(--green-300);color:var(--brand-dark)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}

.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:10.5px;
    font-weight:800;background:var(--bg);color:var(--ink-soft);border:1px solid var(--line)}
.chip.green{background:var(--green-50);color:var(--green-800);border-color:var(--green-200)}
.chip.amber{background:#fffbeb;color:#92400e;border-color:#fde68a}
.chip.red{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.chip.blue{background:#e0f2fe;color:#075985;border-color:#bae6fd}
.chip.purple{background:#faf5ff;color:#5b21b6;border-color:#ddd6fe}
.chip.sky{background:#f0f9ff;color:#075985;border-color:#e0f2fe}

/* ---- the queue ---- */
.qrow{border:1.5px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:10px;background:#fff}
.qrow:last-child{margin-bottom:0}
.qrow.pending{border-left:4px solid var(--amber)}
.qrow.assigned{border-left:4px solid var(--green-500)}
.qrow.declined{border-left:4px solid var(--red)}
.qrow-top{display:flex;align-items:center;gap:11px;flex-wrap:wrap;margin-bottom:8px}
.qavatar{width:34px;height:34px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;color:#fff;
    font-size:12px;font-weight:800;background:linear-gradient(135deg,#10b981,#047857)}
.qname{font-size:13.5px;font-weight:800;color:var(--ink)}
.qsub{font-size:11.5px;color:var(--muted);margin-top:2px}
.qnote{font-size:12.5px;color:var(--ink-soft);background:var(--bg);border-radius:10px;padding:9px 12px;
    margin:8px 0;font-style:italic}
.qdecided{font-size:12px;color:var(--ink-soft);background:#fbfcfd;border:1px dashed var(--line);
    border-radius:10px;padding:8px 12px;margin-top:8px}
.qactions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px}
.qfield{flex:1;min-width:200px}

.empty{text-align:center;padding:34px 20px;color:var(--muted)}
.empty i{font-size:34px;color:var(--line);display:block;margin-bottom:12px}
.empty p{font-size:13px}

/* ---- the office inbox: one thread per vendor ---- */
.thread{border:1.5px solid var(--line);border-radius:14px;background:#fff;margin-bottom:10px;overflow:hidden}
.thread:last-child{margin-bottom:0}
.thread.unread{border-left:4px solid var(--amber)}
.thread.open{border-color:var(--green-300);box-shadow:0 0 0 3px rgba(16,185,129,.1)}
.thread-row{display:flex;align-items:center;gap:12px;padding:13px 15px;flex-wrap:wrap}
.thread.unread .thread-row{background:linear-gradient(90deg,#fffbeb,#fff 55%)}
.thread .qname{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.thread-snippet{font-size:12px;color:var(--muted);margin-top:4px;line-height:1.5;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.thread .chips{margin-left:auto;align-items:center}
.thread-body{border-top:1px solid var(--line-soft);background:#fbfcfd;padding:14px 16px}
.thread-contact{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:12px;
    font-size:11.5px;color:var(--muted);font-weight:600}
.thread-contact span{display:inline-flex;align-items:center;gap:6px}
.thread-contact .btn{margin-left:auto}
.thread-messages{display:flex;flex-direction:column;gap:10px;max-height:340px;overflow-y:auto;margin-bottom:14px}
.thread-messages::-webkit-scrollbar{width:6px}
.thread-messages::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px}
.thread-none{font-size:12.5px;color:var(--muted);padding:12px 0}
.thread-msg{border-radius:12px;padding:11px 14px;max-width:86%;border:1px solid var(--line-soft);background:#fff}
.thread-msg.theirs{align-self:flex-start}
.thread-msg.mine{align-self:flex-end;background:linear-gradient(135deg,#ecfdf5,#fff);border-color:var(--green-200)}
.thread-msg-who{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;color:var(--muted);margin-bottom:5px;flex-wrap:wrap}
.thread-msg.mine .thread-msg-who{color:var(--green-700)}
.thread-msg-body{font-size:13px;color:var(--ink-soft);line-height:1.6}
.thread-reply{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.thread-reply textarea{flex:1;min-width:240px;padding:11px 13px;border:1.5px solid var(--line);border-radius:11px;
    font-family:inherit;font-size:13px;color:var(--ink);background:#fff;line-height:1.55;resize:vertical}
.thread-reply textarea:focus{outline:none;border-color:var(--green-400);box-shadow:0 0 0 3px rgba(16,185,129,.15)}
/* ---- the hover card: who is in that stall ---- */
.tip{
    position:fixed;z-index:60;width:290px;pointer-events:none;opacity:0;
    transform:translateY(6px);transition:opacity .16s ease,transform .16s ease;
    background:#fff;border:1.5px solid var(--line);border-radius:16px;
    box-shadow:0 18px 44px rgba(2,44,34,.18);overflow:hidden;
}
.tip.show{opacity:1;transform:none}
.tip-head{display:flex;align-items:center;gap:11px;padding:13px 15px;
    background:linear-gradient(135deg,#ecfdf5,#fff);border-bottom:1px solid var(--line-soft)}
.tip-avatar{width:40px;height:40px;border-radius:13px;flex-shrink:0;display:grid;place-items:center;
    color:#fff;font-size:13px;font-weight:800;
    background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.32)}
.tip-avatar.free{background:linear-gradient(135deg,#94a3b8,#64748b);box-shadow:0 6px 16px rgba(100,116,139,.3)}
.tip-name{font-size:13.5px;font-weight:800;color:var(--ink);line-height:1.25}
.tip-biz{font-size:11.5px;color:var(--muted);margin-top:2px}
.tip-code{font-size:11px;font-weight:900;letter-spacing:.04em;color:var(--green-700);
    background:var(--green-100);border:1px solid var(--green-200);border-radius:999px;padding:3px 9px;flex-shrink:0}
.tip-body{padding:12px 15px 14px}
.tip-row{display:flex;align-items:center;gap:9px;font-size:12px;color:var(--ink-soft);padding:4px 0}
.tip-row i{width:22px;height:22px;border-radius:7px;flex-shrink:0;display:grid;place-items:center;
    background:var(--bg);color:var(--muted);font-size:10px}
.tip-row span{color:var(--ink);font-weight:600}
.tip-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:9px}
.tip-foot{padding:9px 15px;border-top:1px solid var(--line-soft);background:#fbfcfd;
    font-size:11px;color:var(--muted);font-weight:600;display:flex;align-items:center;gap:6px}

.legend{display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:12px 20px;border-top:1.5px solid var(--line-soft);
    background:var(--bg);font-size:11.5px;color:var(--muted);font-weight:600}
.legend span{display:inline-flex;align-items:center;gap:6px}
.legend i{width:11px;height:11px;border-radius:3px;display:inline-block}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <!-- Hovering a stall shows who trades there; it follows the pointer
         and never takes the click, which is reserved for acting. -->
    <div class="tip" id="stallTip" role="tooltip" aria-hidden="true"></div>

    <header class="top-header">
        <div class="top-header-left">
            <h1><span class="h1-icon"><i class="fas fa-map-location-dot"></i></span> Stall Map</h1>
            <p>Vendors ask for a stall from their own map; the market office assigns them here. Nobody can claim a stall for themselves.</p>
        </div>
        <div class="header-actions">
            <a href="admin_dashboard.php" class="btn-header ghost"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>">
            <i class="fas <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= h($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="stat-row">
        <a href="admin_stall_map.php?filter=pending" class="mini-stat">
            <div class="icon i2"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="num"><?= (int)$appCounts['pending'] ?></div>
                <div class="lbl">Requests waiting</div>
            </div>
        </a>
        <a href="admin_stall_map.php?filter=assigned" class="mini-stat">
            <div class="icon i1"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="num"><?= (int)$appCounts['assigned'] ?></div>
                <div class="lbl">Granted</div>
            </div>
        </a>
        <a href="admin_stall_map.php?filter=declined" class="mini-stat">
            <div class="icon i4"><i class="fas fa-circle-xmark"></i></div>
            <div>
                <div class="num"><?= (int)$appCounts['declined'] ?></div>
                <div class="lbl">Not granted</div>
            </div>
        </a>
        <div class="mini-stat">
            <div class="icon i3"><i class="fas fa-store"></i></div>
            <div>
                <div class="num"><?= (int)$counts['available'] ?>/<?= (int)$counts['total'] ?></div>
                <div class="lbl">Stalls free</div>
            </div>
        </div>
    </div>

    <!-- ==================== THE MAP ==================== -->
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2><i class="fas fa-warehouse"></i> Mallengke Market — Floor Plan</h2>
                <div class="sub">
                    <?= (int)$counts['occupied'] ?> of <?= (int)$counts['total'] ?> stalls taken ·
                    <?= (int)$counts['available'] ?> free ·
                    <?= (int)$noStall ?> <?= $noStall === 1 ? 'vendor has' : 'vendors have' ?> no stall
                </div>
            </div>
        </div>

        <div class="panel-body">
            <div class="plan">
                <div class="plan-wings">
                    <div class="wing">
                        <?php foreach ($leftWing as $item):
                            $section = $item['section'];
                            $stalls  = $layout[$section] ?? [];
                            if (empty($stalls)) continue;
                            $held = 0;
                            foreach ($stalls as $c) { if (isset($occupied[strtoupper($c)])) $held++; }
                        ?>
                            <div class="sec">
                                <div class="sec-head">
                                    <div class="sec-letter"><?= h($item['letter']) ?></div>
                                    <div class="sec-name"><?= h($section) ?></div>
                                    <div class="sec-count"><?= $held ?>/<?= count($stalls) ?></div>
                                </div>
                                <div class="cells">
                                    <?php foreach ($stalls as $code):
                                        $up   = strtoupper($code);
                                        $who  = $occupied[$up] ?? null;
                                        $want = $wanting[$up] ?? [];
                                        $cls  = 'cell ' . ($who ? 'held' : 'free');
                                        if (!$who && $want) $cls .= ' pending';
                                    ?>
                                        <button type="button" class="<?= $cls ?>" data-stall="<?= h($up) ?>"
                                                aria-label="<?= h($up . ' - ' . $section . ($who ? ' - ' . $who['name'] : ' - free')) ?>">
                                            <span class="cell-dot <?= $who ? 'held' : 'free' ?>"></span>
                                            <span class="cell-code"><?= h($code) ?></span>
                                            <span class="cell-name"><?= $who
                                                ? h(ini_from($who['name']) . ' ' . (explode(' ', $who['name'])[0] ?? ''))
                                                : ($want ? count($want) . ' waiting' : 'Free') ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="plan-aisle" aria-hidden="true"></div>

                    <div class="wing">
                        <?php foreach ($rightWing as $item):
                            $section = $item['section'];
                            $stalls  = $layout[$section] ?? [];
                            if (empty($stalls)) continue;
                            $held = 0;
                            foreach ($stalls as $c) { if (isset($occupied[strtoupper($c)])) $held++; }
                        ?>
                            <div class="sec">
                                <div class="sec-head">
                                    <div class="sec-letter"><?= h($item['letter']) ?></div>
                                    <div class="sec-name"><?= h($section) ?></div>
                                    <div class="sec-count"><?= $held ?>/<?= count($stalls) ?></div>
                                </div>
                                <div class="cells">
                                    <?php foreach ($stalls as $code):
                                        $up   = strtoupper($code);
                                        $who  = $occupied[$up] ?? null;
                                        $want = $wanting[$up] ?? [];
                                        $cls  = 'cell ' . ($who ? 'held' : 'free');
                                        if (!$who && $want) $cls .= ' pending';
                                    ?>
                                        <button type="button" class="<?= $cls ?>" data-stall="<?= h($up) ?>"
                                                aria-label="<?= h($up . ' - ' . $section . ($who ? ' - ' . $who['name'] : ' - free')) ?>">
                                            <span class="cell-dot <?= $who ? 'held' : 'free' ?>"></span>
                                            <span class="cell-code"><?= h($code) ?></span>
                                            <span class="cell-name"><?= $who
                                                ? h(ini_from($who['name']) . ' ' . (explode(' ', $who['name'])[0] ?? ''))
                                                : ($want ? count($want) . ' waiting' : 'Free') ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="legend">
                <span><i style="background:#a7f3d0;border:1.5px solid var(--green-200)"></i> Taken</span>
                <span><i style="background:#fbfcfd;border:1.5px dashed var(--line)"></i> Free</span>
                <span><i style="background:#fffbeb;border:1.5px solid #fcd34d"></i> Free, with someone waiting</span>
                <span style="margin-left:auto">Click any stall to assign, move or release it.</span>
            </div>

            <!-- what the office does with the stall that was clicked -->
            <div class="pick is-free" id="stallPick">
                <div class="pick-head">
                    <div style="flex:1;min-width:220px">
                        <div class="pick-code">Pick a stall</div>
                        <div class="pick-meta">Click a stall on the plan above. Free stalls can be assigned to a vendor who asked for one; a taken stall can be moved or released.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ==================== THE QUEUE ==================== -->
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2><i class="fas fa-inbox"></i> Stall requests</h2>
                <div class="sub">Every request a vendor has filed. Granting one writes their stall.</div>
            </div>
            <div class="tabs">
                <?php foreach (['pending' => 'Waiting', 'assigned' => 'Granted', 'declined' => 'Not granted', 'all' => 'All'] as $k => $label): ?>
                    <a class="tab <?= $filter === $k ? 'active' : '' ?>" href="admin_stall_map.php?filter=<?= h($k) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
                        <?= h($label) ?> <span class="n"><?= (int)$appCounts[$k] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel-body">
            <form class="toolbar" method="get" action="admin_stall_map.php" style="box-shadow:none;border:none;padding:0 0 14px;margin-bottom:0;background:none">
                <input type="hidden" name="filter" value="<?= h($filter) ?>" />
                <div class="search-wrap">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search a vendor, business, stall or what they wrote…" />
                </div>
                <button type="submit" class="btn ghost"><i class="fas fa-filter"></i> Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn ghost" href="admin_stall_map.php?filter=<?= h($filter) ?>"><i class="fas fa-xmark"></i> Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($applications)): ?>
                <div class="empty">
                    <i class="fas fa-inbox"></i>
                    <p><?= $search !== '' ? 'No request matches “' . h($search) . '”.' : 'No requests here.' ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ($applications as $a):
                $meta      = $APP_META[$a['status']] ?? ['label' => $a['status'], 'color' => 'green', 'icon' => 'fa-flag'];
                $name      = trim($a['first_name'] . ' ' . $a['last_name']) ?: 'Vendor #' . (int)$a['vendor_id'];
                $wanted    = strtoupper(trim((string)($a['requested_stall'] ?? '')));
                $current   = strtoupper(trim((string)($a['current_stall'] ?? '')));
                $isPending = $a['status'] === 'Pending';
                $blocked   = (string)($a['access_kind'] ?? 'none') === 'removed';
            ?>
                <div class="qrow <?= h(strtolower($a['status'])) ?>">
                    <div class="qrow-top">
                        <div class="qavatar"><?= h(ini_from($name)) ?></div>
                        <div style="flex:1;min-width:160px">
                            <div class="qname"><?= h($name) ?></div>
                            <div class="qsub">
                                <?= h($a['business_name'] ?: 'Independent vendor') ?> ·
                                <?= $wanted !== '' ? 'asked for Stall ' . h($wanted) : 'no preference' ?>
                                · filed <?= h(date('M j, Y', strtotime((string)$a['created_at']))) ?>
                            </div>
                        </div>
                        <div class="chips">
                            <span class="chip <?= h($meta['color']) ?>"><i class="fas <?= h($meta['icon']) ?>"></i> <?= h($meta['label']) ?></span>
                            <?php if ($current !== ''): ?>
                                <span class="chip green"><i class="fas fa-location-dot"></i> At <?= h($current) ?></span>
                            <?php else: ?>
                                <span class="chip amber"><i class="fas fa-circle-exclamation"></i> No stall</span>
                            <?php endif; ?>
                            <?php if ($a['status'] === 'Assigned' && !empty($a['assigned_stall'])): ?>
                                <span class="chip blue"><i class="fas fa-circle-check"></i> Granted <?= h($a['assigned_stall']) ?></span>
                            <?php endif; ?>
                            <?php if ($blocked): ?>
                                <span class="chip red"><i class="fas fa-ban"></i> Login removed</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($a['request_note'])): ?>
                        <div class="qnote">“<?= h($a['request_note']) ?>”</div>
                    <?php endif; ?>

                    <?php if (!empty($a['decision_note'])): ?>
                        <div class="qdecided">
                            <i class="fas fa-note-sticky"></i>
                            Office said: “<?= h($a['decision_note']) ?>”
                            <?php if (!empty($a['decided_by_name'])): ?>
                                <br /><small>— <?= h($a['decided_by_name']) ?>, <?= h(date('M j, Y', strtotime((string)$a['decided_at']))) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isPending): ?>
                        <form method="post" action="admin_stall_map.php">
                            <input type="hidden" name="action" value="assign_stall" />
                            <input type="hidden" name="vendor_id" value="<?= (int)$a['vendor_id'] ?>" />
                            <input type="hidden" name="filter" value="<?= h($filter) ?>" />
                            <div class="qactions">
                                <div class="pick-field qfield">
                                    <label for="stall<?= (int)$a['id'] ?>">Assign stall</label>
                                    <select name="stall_number" id="stall<?= (int)$a['id'] ?>" required>
                                        <?php if ($wanted === ''): ?>
                                            <option value="">Pick a free stall…</option>
                                        <?php endif; ?>
                                        <?php foreach ($layout as $sec => $codes):
                                            $free = [];
                                            foreach ($codes as $c) {
                                                $up = strtoupper($c);
                                                $holder = $occupied[$up] ?? null;
                                                if (!$holder || (int)$holder['id'] === (int)$a['vendor_id']) $free[] = $up;
                                            }
                                            if (empty($free)) continue;
                                        ?>
                                            <optgroup label="<?= h($sec) ?>">
                                                <?php foreach ($free as $up): ?>
                                                    <option value="<?= h($up) ?>" <?= $up === $wanted ? 'selected' : '' ?>>
                                                        <?= h($up) ?><?= $up === $current ? ' — their current stall' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="pick-field qfield">
                                    <label for="note<?= (int)$a['id'] ?>">Note to the vendor (optional)</label>
                                    <input type="text" name="note" id="note<?= (int)$a['id'] ?>" maxlength="500"
                                           placeholder="e.g. Come to the office on Monday to collect your permit." />
                                </div>
                                <button type="submit" class="btn primary"
                                        onclick="return confirm('Assign <?= h($name) ?> a stall?');">
                                    <i class="fas fa-key"></i> Assign
                                </button>
                                <button type="submit" class="btn red" formnovalidate
                                        onclick="var n=this.form.querySelector('[name=note]');return confirm('Turn down this request?');">
                                    <i class="fas fa-circle-xmark"></i> Turn down
                                </button>
                            </div>
                        </form>

                        <form method="post" action="admin_stall_map.php" style="margin-top:8px">
                            <input type="hidden" name="action" value="decline_stall_request" />
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                            <input type="hidden" name="filter" value="<?= h($filter) ?>" />
                            <div class="qactions">
                                <div class="pick-field qfield">
                                    <label for="reason<?= (int)$a['id'] ?>">Why not (the vendor reads this)</label>
                                    <input type="text" name="note" id="reason<?= (int)$a['id'] ?>" maxlength="500"
                                           placeholder="e.g. All stalls in that section are taken right now." />
                                </div>
                                <button type="submit" class="btn red">
                                    <i class="fas fa-ban"></i> Turn down with a reason
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ==================== NO STALL YET ==================== -->
    <?php if (!empty($unassigned)): ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2><i class="fas fa-person-walking-arrow-right"></i> Vendors with no stall</h2>
                <div class="sub">These vendors cannot trade yet. Pick a stall for them on the map above.</div>
            </div>
            <span class="chip amber"><?= count($unassigned) ?></span>
        </div>
        <div class="panel-body">
            <div class="table-wrap">
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:left">
                            <th style="padding:9px 10px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Vendor</th>
                            <th style="padding:9px 10px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Section</th>
                            <th style="padding:9px 10px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Status</th>
                            <th style="padding:9px 10px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Waiting since</th>
                            <th style="padding:9px 10px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unassigned as $u):
                        $uname = trim((string)$u['name']) ?: 'Vendor #' . (int)$u['id'];
                    ?>
                        <tr style="border-top:1px solid var(--line-soft)">
                            <td style="padding:11px 10px">
                                <div style="display:flex;align-items:center;gap:9px">
                                    <span class="qavatar"><?= h(ini_from($uname)) ?></span>
                                    <span>
                                        <span style="display:block;font-size:13px;font-weight:800;color:var(--ink)"><?= h($uname) ?></span>
                                        <span style="display:block;font-size:11px;color:var(--muted)"><?= h($u['business_name'] ?: 'Independent vendor') ?></span>
                                    </span>
                                </div>
                            </td>
                            <td style="padding:11px 10px;font-size:12.5px;color:var(--ink-soft)"><?= h($u['market_section'] ?: '—') ?></td>
                            <td style="padding:11px 10px">
                                <span class="chip <?= $u['status'] === 'Approved' ? 'green' : 'amber' ?>"><?= h($u['status']) ?></span>
                            </td>
                            <td style="padding:11px 10px;font-size:12px;color:var(--muted)"><?= h(date('M j, Y', strtotime((string)$u['created_at']))) ?></td>
                            <td style="padding:11px 10px;text-align:right">
                                <button type="button" class="btn primary" data-pick-vendor="<?= (int)$u['id'] ?>">
                                    <i class="fas fa-location-dot"></i> Give them a stall
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ==================== THE OFFICE INBOX ====================
         The other half of "get a stall": vendors who wrote to the
         office instead of using the apply form. -->
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2><i class="fas fa-envelope-open-text"></i> Messages from vendors</h2>
                <div class="sub">Vendors who wrote to the office about a stall. Answering here
                    reaches them on their stall map and by private message.</div>
            </div>
            <?php if ($amUnread > 0): ?>
                <span class="chip amber"><i class="fas fa-envelope"></i> <?= (int)$amUnread ?> waiting to be read</span>
            <?php endif; ?>
        </div>

        <div class="panel-body">
            <?php if (empty($amThreads)): ?>
                <div class="empty">
                    <i class="fas fa-inbox"></i>
                    <p>No vendor has written to the office yet.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($amThreads as $t):
                $last     = $t['last'] ?? null;
                $unread   = (int)$t['unread'];
                $isOpen   = ((int)$t['vendor_id'] === $openVendor);
                $snippet  = $last ? mb_substr(trim((string)$last['body']), 0, 150) : '';
                $when     = $last ? date('M j, Y g:ia', strtotime((string)$last['created_at'])) : '';
                $fromOffice = ($last && $last['direction'] === 'admin');
            ?>
                <div class="thread<?= $unread > 0 ? ' unread' : '' ?><?= $isOpen ? ' open' : '' ?>">
                    <form method="post" action="admin_stall_map.php" class="thread-row">
                        <input type="hidden" name="action" value="open_thread" />
                        <input type="hidden" name="vendor_id" value="<?= (int)$t['vendor_id'] ?>" />
                        <input type="hidden" name="filter" value="<?= h($filter) ?>" />
                        <div class="qavatar"><?= h(ini_from($t['name'])) ?></div>
                        <div style="flex:1;min-width:0">
                            <div class="qname">
                                <?= h($t['name']) ?>
                                <?php if ($unread > 0): ?>
                                    <span class="chip amber"><?= $unread ?> new</span>
                                <?php endif; ?>
                            </div>
                            <div class="qsub">
                                <?= h($t['business'] ?: 'Independent vendor') ?>
                                · <?= $t['stall'] ? 'at ' . h($t['stall']) : 'no stall yet' ?>
                                · <?= (int)$t['count'] ?> message<?= (int)$t['count'] === 1 ? '' : 's' ?>
                                · <?= $fromOffice ? 'you last wrote' : 'they last wrote' ?> <?= h($when) ?>
                            </div>
                            <div class="thread-snippet"><?= h($snippet) ?><?= mb_strlen($snippet) >= 150 ? '…' : '' ?></div>
                        </div>
                        <div class="chips">
                            <?php if (!empty($t['wants'])): ?>
                                <?php foreach (array_keys($t['wants']) as $w): ?>
                                    <span class="chip purple">wants <?= h($w) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <button type="submit" class="btn <?= $isOpen ? 'ghost' : 'primary' ?>">
                                <i class="fas <?= $isOpen ? 'fa-chevron-up' : 'fa-reply' ?>"></i>
                                <?= $isOpen ? 'Hide' : 'Open' ?>
                            </button>
                        </div>
                    </form>

                    <?php if ($isOpen): ?>
                        <div class="thread-body">
                            <?php if ($openThreadVendor): ?>
                                <div class="thread-contact">
                                    <span><i class="fas fa-phone"></i> <?= h($openThreadVendor['contact_number'] ?: 'no number on file') ?></span>
                                    <span><i class="fas fa-box-open"></i> <?= h($openThreadVendor['product_type'] ?: 'no products on file') ?></span>
                                    <span><i class="fas fa-map-location-dot"></i> <?= h($openThreadVendor['market_section'] ?: '—') ?></span>
                                    <a class="btn ghost" href="admin_vendors.php?id=<?= (int)$t['vendor_id'] ?>">
                                        <i class="fas fa-address-card"></i> Full record
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="thread-messages">
                                <?php if (empty($openThread)): ?>
                                    <div class="thread-none">This conversation has no messages.</div>
                                <?php endif; ?>
                                <?php foreach ($openThread as $m):
                                    $fromOffice = ($m['direction'] === 'admin');
                                ?>
                                    <div class="thread-msg <?= $fromOffice ? 'mine' : 'theirs' ?>">
                                        <div class="thread-msg-who">
                                            <?= $fromOffice
                                                ? 'You' . (!empty($m['admin_name']) ? ' · ' . h($m['admin_name']) : '')
                                                : h($t['name']) ?>
                                            <span><?= h(date('M j, Y g:ia', strtotime((string)$m['created_at']))) ?></span>
                                            <?php if (!empty($m['stall_requested'])): ?>
                                                <span class="chip purple">wants <?= h($m['stall_requested']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="thread-msg-body"><?= nl2br(h((string)$m['body'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <form method="post" action="admin_stall_map.php" class="thread-reply">
                                <input type="hidden" name="action" value="reply_office" />
                                <input type="hidden" name="vendor_id" value="<?= (int)$t['vendor_id'] ?>" />
                                <input type="hidden" name="filter" value="<?= h($filter) ?>" />
                                <input type="hidden" name="thread" value="<?= (int)$t['vendor_id'] ?>" />
                                <textarea name="reply" rows="3" maxlength="2000" required
                                          placeholder="Reply to <?= h($t['name']) ?>…"></textarea>
                                <button type="submit" class="btn primary">
                                    <i class="fas fa-paper-plane"></i> Send reply
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

</main>

<script>
/* ============================================================
   The office works by clicking a stall. One panel shows whatever
   can be done to it, and every form it renders posts to this page.
   ============================================================ */
const STALLS   = <?= json_encode(array_map(
        fn($code) => [
            'code'    => $code,
            'section' => stall_section($code),
            'holder'  => $occupied[strtoupper($code)] ?? null,
            /* how many vendors are waiting for this exact stall, so the
               hover card can say so */
            'waiting' => count($wanting[strtoupper($code)] ?? []),
        ],
        array_merge(...array_values($layout))
    )) ?>;
const VENDORS  = <?= json_encode(array_map(
        fn($u) => ['id' => (int)$u['id'], 'name' => trim((string)$u['name'])],
        $unassigned
    )) ?>;

const pick = document.getElementById('stallPick');
const freeOptions = () => STALLS
    .filter(s => !s.holder)
    .map(s => `<option value="${s.code}">${s.code} · ${s.section}</option>`)
    .join('');

function render(stall) {
    const s = STALLS.find(x => x.code === stall);
    if (!s) return;

    document.querySelectorAll('.cell.sel').forEach(c => c.classList.remove('sel'));
    const cell = document.querySelector(`.cell[data-stall="${stall}"]`);
    if (cell) cell.classList.add('sel');

    if (!s.holder) {
        pick.className = 'pick is-free';
        pick.innerHTML = `
            <div class="pick-head">
                <div style="flex:1;min-width:220px">
                    <div class="pick-code">${esc(s.code)} <span class="chip green"><i class="fas fa-door-open"></i> Free</span></div>
                    <div class="pick-meta">${esc(s.section)} · nobody is trading here</div>
                </div>
            </div>
            <form method="post" action="admin_stall_map.php">
                <input type="hidden" name="action" value="assign_stall">
                <input type="hidden" name="stall_number" value="${esc(s.code)}">
                <input type="hidden" name="filter" value="<?= h($filter) ?>">
                <div class="pick-grid">
                    <div class="pick-field">
                        <label>Vendor to place here</label>
                        <select name="vendor_id" required>
                            <option value="">Pick a vendor…</option>
                            ${VENDORS.map(v => `<option value="${v.id}">${esc(v.name)} (no stall yet)</option>`).join('')}
                        </select>
                    </div>
                    <div class="pick-field">
                        <label>Note to the vendor (optional)</label>
                        <input type="text" name="note" maxlength="500" placeholder="Anything they should know.">
                    </div>
                    <button type="submit" class="btn primary"><i class="fas fa-key"></i> Assign ${esc(s.code)}</button>
                </div>
            </form>
            <p class="pick-meta" style="margin-top:10px">
                Only vendors with no stall are listed. To move somebody who already trades somewhere,
                click the stall they hold.
            </p>`;
        return;
    }

    pick.className = 'pick';
    pick.innerHTML = `
        <div class="pick-head">
            <div style="flex:1;min-width:220px">
                <div class="pick-code">${esc(s.code)} <span class="chip green"><i class="fas fa-store"></i> Taken</span></div>
                <div class="pick-meta">${esc(s.holder.name)}${s.holder.business ? ' · ' + esc(s.holder.business) : ''} · ${esc(s.section)}</div>
            </div>
            <div class="chips">
                <span class="chip purple">${esc(s.holder.status || '')}</span>
                <span class="chip">${esc(s.holder.permit || '')} permit</span>
                <span class="chip">${esc(s.holder.payment || '')}</span>
            </div>
        </div>
        <div class="pick-info">
            <div class="pick-info-row"><i class="fas fa-box-open"></i><span>${esc(s.holder.products || 'Not provided')}</span></div>
            <div class="pick-info-row"><i class="fas fa-phone"></i><span>${esc(s.holder.contact || 'Not provided')}</span></div>
            <div class="pick-info-row"><i class="fas fa-clock-rotate-left"></i><span>${s.holder.years > 0 ? s.holder.years + ' year' + (s.holder.years > 1 ? 's' : '') + ' in business' : 'Years in business not given'}</span></div>
            <a class="btn ghost" href="admin_vendors.php?id=${s.holder.id}">
                <i class="fas fa-address-card"></i> Open the vendor's full record
            </a>
        </div>
        <form method="post" action="admin_stall_map.php">
            <input type="hidden" name="action" value="assign_stall">
            <input type="hidden" name="vendor_id" value="${s.holder.id}">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <div class="pick-grid">
                <div class="pick-field">
                    <label>Move them to</label>
                    <select name="stall_number" required>
                        <option value="">Pick a free stall…</option>
                        ${freeOptions()}
                    </select>
                </div>
                <div class="pick-field">
                    <label>Note to the vendor (optional)</label>
                    <input type="text" name="note" maxlength="500" placeholder="e.g. Relocating you to a better spot.">
                </div>
                <button type="submit" class="btn amber" onclick="return confirm('Move ${esc(s.holder.name)} to the stall you picked?');">
                    <i class="fas fa-right-left"></i> Move vendor
                </button>
            </div>
        </form>
        <form method="post" action="admin_stall_map.php" style="margin-top:10px">
            <input type="hidden" name="action" value="release_stall">
            <input type="hidden" name="vendor_id" value="${s.holder.id}">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <div class="pick-grid">
                <div class="pick-field">
                    <label>Reason for releasing the stall (the vendor reads this)</label>
                    <input type="text" name="note" maxlength="500" placeholder="e.g. Stall closed for repairs until next month.">
                </div>
                <button type="submit" class="btn red" onclick="return confirm('Release ${esc(s.code)}? They will have no stall until you give them one.');">
                    <i class="fas fa-door-open"></i> Release the stall
                </button>
            </div>
        </form>`;
}

document.querySelectorAll('.cell').forEach(c => {
    c.addEventListener('click', () => render(c.dataset.stall));
});

/* ============================================================
   Hover card — who is in that stall.
   Purely a read: it never swallows the click, which is reserved for
   the assign / move / release panel.
   ============================================================ */
const tip = document.getElementById('stallTip');
let tipHide = null;

function esc(t) {
    return String(t == null ? '' : t).replace(/[&<>"']/g, ch =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
}

function initials(name) {
    const p = String(name || '').trim().split(/\s+/);
    return ((p[0] || 'U')[0] + (p[1] || '')[0] || 'U').toUpperCase();
}

function tipHtml(stall) {
    const s = STALLS.find(x => x.code === stall);
    if (!s) return '';

    if (!s.holder) {
        const waiting = (s.waiting || 0);
        return `
            <div class="tip-head">
                <div class="tip-avatar free"><i class="fas fa-door-open"></i></div>
                <div style="flex:1;min-width:0">
                    <div class="tip-name">Stall ${esc(s.code)} is free</div>
                    <div class="tip-biz">Nobody is trading here</div>
                </div>
            </div>
            <div class="tip-body">
                <div class="tip-row"><i class="fas fa-layer-group"></i>${esc(s.section)}</div>
                <div class="tip-row"><i class="fas fa-users"></i>${waiting} waiting to be placed</div>
            </div>
            <div class="tip-foot"><i class="fas fa-hand-pointer"></i> Click to assign a vendor</div>`;
    }

    const h = s.holder;
    const rows = [];
    if (h.business)   rows.push(`<div class="tip-row"><i class="fas fa-store"></i>${esc(h.business)}</div>`);
    rows.push(`<div class="tip-row"><i class="fas fa-layer-group"></i>${esc(s.section)}</div>`);
    if (h.products)   rows.push(`<div class="tip-row"><i class="fas fa-box-open"></i>${esc(h.products)}</div>`);
    if (h.contact)    rows.push(`<div class="tip-row"><i class="fas fa-phone"></i>${esc(h.contact)}</div>`);
    if (h.years > 0)   rows.push(`<div class="tip-row"><i class="fas fa-clock-rotate-left"></i>${h.years} year${h.years > 1 ? 's' : ''} in business</div>`);

    return `
        <div class="tip-head">
            <div class="tip-avatar">${esc(initials(h.name))}</div>
            <div style="flex:1;min-width:0">
                <div class="tip-name">${esc(h.name)}</div>
                <div class="tip-biz">${esc(h.business || 'Independent vendor')}</div>
            </div>
            <span class="tip-code">${esc(s.code)}</span>
        </div>
        <div class="tip-body">
            ${rows.join('')}
            <div class="tip-chips">
                <span class="chip ${h.status === 'Approved' ? 'green' : 'amber'}">${esc(h.status)}</span>
                <span class="chip ${h.permit === 'Valid' ? 'green' : 'amber'}">${esc(h.permit)} permit</span>
                <span class="chip ${h.payment === 'Paid' ? 'green' : 'amber'}">${esc(h.payment)}</span>
                ${h.access && h.access !== 'none' ? `<span class="chip red">${esc(h.access === 'removed' ? 'Login removed' : 'Suspended')}</span>` : ''}
            </div>
        </div>
        <div class="tip-foot"><i class="fas fa-hand-pointer"></i> Click to move or release this stall</div>`;
}

function placeTip(x, y) {
    const box = tip.getBoundingClientRect();
    /* Flip to the other side of the pointer when it would run off the
       edge, so the card is never cut off on a small screen. */
    let left = x + 16;
    if (left + box.width > window.innerWidth - 12) left = x - box.width - 16;
    let top = y + 16;
    if (top + box.height > window.innerHeight - 12) top = y - box.height - 16;
    tip.style.left = Math.max(12, left) + 'px';
    tip.style.top  = Math.max(12, top) + 'px';
}

function showTip(stall, x, y) {
    clearTimeout(tipHide);
    const html = tipHtml(stall);
    if (!html) return hideTip();
    tip.innerHTML = html;
    tip.classList.add('show');
    tip.setAttribute('aria-hidden', 'false');
    placeTip(x, y);
}

function hideTip() {
    tip.classList.remove('show');
    tip.setAttribute('aria-hidden', 'true');
}

document.querySelectorAll('.cell').forEach(c => {
    c.addEventListener('mouseenter', e => showTip(c.dataset.stall, e.clientX, e.clientY));
    c.addEventListener('mousemove',  e => { if (tip.classList.contains('show')) placeTip(e.clientX, e.clientY); });
    c.addEventListener('mouseleave', () => { tipHide = setTimeout(hideTip, 90); });
    c.addEventListener('focus', () => {
        const r = c.getBoundingClientRect();
        showTip(c.dataset.stall, r.right, r.top);
    });
    c.addEventListener('blur', hideTip);
});

/* Touch has no hover, so the card must not get in the way of a tap. */
if (window.matchMedia('(hover: none)').matches) {
    document.querySelectorAll('.tip').forEach(t => t.remove());
}

/* "Give them a stall" in the unplaced table: ask which stall first. */
document.querySelectorAll('[data-pick-vendor]').forEach(btn => {
    btn.addEventListener('click', () => {
        const first = STALLS.find(s => !s.holder);
        if (!first) { alert('Every stall in the market is taken.'); return; }
        render(first.code);
        hideTip();
        pick.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
});
</script>

</body>
</html>
