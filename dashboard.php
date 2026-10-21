<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: php/login.php'); exit; }
$conn = require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/guard.php';
guard_vendor($conn);
require_once __DIR__ . '/config/swap.php';
require_once __DIR__ . '/config/profile.php';

/* ---------- flash messages ---------- */
function swap_flash($type, $msg) { $_SESSION['swap_flash'] = ['type' => $type, 'msg' => $msg]; }
function swap_get_flash() { if (empty($_SESSION['swap_flash'])) return null; $f = $_SESSION['swap_flash']; unset($_SESSION['swap_flash']); return $f; }
function swap_back($peerId) {
    $peerId = (int)$peerId;
    header('Location: dashboard.php?view=messages&tab=vendors&vpeer=' . $peerId);
    exit;
}

/* ---------- fetch vendor ---------- */
$stmt = $conn->prepare("SELECT * FROM vendors WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']); $stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc(); $stmt->close();
$vendorId = $vendor ? (int)$vendor['id'] : 0;


/* ============================================================
   SEND MESSAGE TO ANOTHER VENDOR
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_vendor_message') {
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $peerId = (int)($_POST['peer_id'] ?? 0);
    $body   = trim($_POST['body'] ?? '');

    if ($peerId === $vendorId) $peerId = 0;

    if (!$convId && $peerId && $vendorId) {
        $a = min($vendorId, $peerId);
        $b = max($vendorId, $peerId);

        $stmt = $conn->prepare("SELECT id FROM vendor_conversations WHERE vendor_a_id = ? AND vendor_b_id = ? LIMIT 1");
        $stmt->bind_param("ii", $a, $b); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row) $convId = (int)$row['id'];
        else {
            $stmt = $conn->prepare("INSERT INTO vendor_conversations (vendor_a_id, vendor_b_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $a, $b); $stmt->execute();
            $convId = (int)$stmt->insert_id; $stmt->close();
        }
    }

    if ($convId && $vendorId && $body !== '') {
        $stmt = $conn->prepare("SELECT vendor_a_id, vendor_b_id FROM vendor_conversations WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $convId); $stmt->execute();
        $vconv = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($vconv && ($vendorId === (int)$vconv['vendor_a_id'] || $vendorId === (int)$vconv['vendor_b_id'])) {
            $body = mb_substr($body, 0, 2000);

            $stmt = $conn->prepare("INSERT INTO vendor_messages (conversation_id, sender_vendor_id, body) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $convId, $vendorId, $body); $stmt->execute(); $stmt->close();

            $preview = mb_strlen($body) > 80 ? mb_substr($body, 0, 77) . '…' : $body;

            if ($vendorId === (int)$vconv['vendor_a_id']) {
                $stmt = $conn->prepare("UPDATE vendor_conversations SET last_message_at = NOW(), last_message_preview = ?, unread_b = unread_b + 1 WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE vendor_conversations SET last_message_at = NOW(), last_message_preview = ?, unread_a = unread_a + 1 WHERE id = ?");
            }
            $stmt->bind_param("si", $preview, $convId); $stmt->execute(); $stmt->close();
        }
    }
    header('Location: dashboard.php?view=messages&tab=vendors&vpeer=' . $peerId); exit;
}

/* ============================================================
   STALL SWAP  ·  file a request to trade stalls with a vendor
   ------------------------------------------------------------
   Filed from a vendor chat thread. Nothing moves yet — the
   request waits for the market office to approve it.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_stall_swap') {
    $targetId = (int)($_POST['target_vendor_id'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');

    if (!$vendorId) {
        swap_flash('error', 'Your vendor account is not linked yet.');
        swap_back($targetId);
    }
    if ($targetId <= 0 || $targetId === $vendorId) {
        swap_flash('error', 'Pick another vendor to swap stalls with.');
        swap_back($targetId);
    }

    $me    = swap_vendor_row($conn, $vendorId);
    $other = swap_vendor_row($conn, $targetId);

    if (!$me || !$other) {
        swap_flash('error', 'One of the vendors could not be found.');
        swap_back($targetId);
    }

    if (trim((string)$me['stall_number']) === trim((string)$other['stall_number'])) {
        swap_flash('error', 'You are both listed at Stall ' . $me['stall_number'] . ' — nothing to swap.');
        swap_back($targetId);
    }

    if ($openId = swap_open_request_id($conn, $vendorId, $targetId)) {
        swap_flash('error', 'There is already a stall swap request pending between you two.');
        swap_back($targetId);
    }

    $reason = mb_substr($reason, 0, 500);
    $convId = swap_conversation_id($conn, $vendorId, $targetId);

    $stmt = $conn->prepare(
        "INSERT INTO stall_swap_requests
            (requester_vendor_id, requester_stall, requester_section,
             target_vendor_id, target_stall, target_section, reason, conversation_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        swap_flash('error', 'Could not save your request. Please try again.');
        swap_back($targetId);
    }

    $aStall = (string)$me['stall_number'];
    $aSec   = (string)($me['market_section'] ?? '');
    $bStall = (string)$other['stall_number'];
    $bSec   = (string)($other['market_section'] ?? '');
    $stmt->bind_param("ississsi", $vendorId, $aStall, $aSec, $targetId, $bStall, $bSec, $reason, $convId);
    $ok = $stmt->execute();
    $swapId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok) {
        swap_flash('error', 'Could not save your request. Please try again.');
        swap_back($targetId);
    }

    $notice = "[STALL SWAP REQUEST] I filed a request to the market office to swap stalls with you —\n"
            . "• " . $me['name'] . ($me['business_name'] ? ' (' . $me['business_name'] . ')' : '')
            . ': Stall ' . $aStall . ($aSec ? ' · ' . $aSec : '') . "\n"
            . "• " . $other['name'] . ($other['business_name'] ? ' (' . $other['business_name'] . ')' : '')
            . ': Stall ' . $bStall . ($bSec ? ' · ' . $bSec : '') . "\n"
            . ($reason !== '' ? "\nReason: " . $reason : '')
            . "\n\nStatus: awaiting market office approval.";
    swap_post_notice($conn, $convId, $vendorId, $targetId, $notice);

    require_once __DIR__ . '/config/notify.php';
    $partnerFirst = trim(explode(' ', (string)$other['name'])[0]);
    $greet = $partnerFirst !== '' ? 'Hi ' . $partnerFirst . ', ' : '';
    notify_vendor(
        $conn,
        $targetId,
        'stall',
        'Stall swap request filed with the market office',
        $greet . $me['name'] . ($me['business_name'] ? ' (' . $me['business_name'] . ')' : '')
        . " would like to swap stalls with you.\n\n"
        . '• Their stall: ' . $aStall . ($aSec ? ' · ' . $aSec : '') . "\n"
        . '• Your stall: ' . $bStall . ($bSec ? ' · ' . $bSec : '')
        . ($reason !== '' ? "\n\nTheir message: " . $reason : '')
        . "\n\nThe market office will review this request. Nothing changes until it is approved — "
        . "you can message them from this chat if you have questions.",
        'dashboard.php?view=messages&tab=vendors&vpeer=' . $vendorId
    );

    swap_flash('success', 'Stall swap request #' . $swapId . ' sent to the market office. You will be notified once it is approved.');
    swap_back($targetId);
}

/* ---- Vendor withdraws their own pending request ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_stall_swap') {
    $swapId   = (int)($_POST['swap_id'] ?? 0);
    $targetId = (int)($_POST['target_vendor_id'] ?? 0);

    $stmt = $conn->prepare("SELECT * FROM stall_swap_requests WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $swapId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $req = null;
    }

    if (!$req || (int)$req['requester_vendor_id'] !== $vendorId || $req['status'] !== 'Pending') {
        swap_flash('error', 'That request can no longer be withdrawn.');
        swap_back($targetId);
    }

    $stmt = $conn->prepare("UPDATE stall_swap_requests SET status = 'Cancelled', decided_at = NOW() WHERE id = ? AND status = 'Pending'");
    if ($stmt) {
        $stmt->bind_param("i", $swapId);
        $stmt->execute();
        $stmt->close();
    }

    $other = swap_vendor_row($conn, (int)$req['target_vendor_id']);
    if ($other) {
        require_once __DIR__ . '/config/notify.php';
        notify_vendor(
            $conn,
            (int)$other['id'],
            'stall',
            'Stall swap request withdrawn',
            'The stall swap request between you and ' . ($vendor['business_name'] ?: 'your vendor') . ' was withdrawn by the requester. Your stall stays as it is.',
            'dashboard.php?view=messages&tab=vendors&vpeer=' . $vendorId
        );
        swap_post_notice(
            $conn,
            (int)$req['conversation_id'] ?: swap_conversation_id($conn, $vendorId, (int)$other['id']),
            $vendorId,
            (int)$other['id'],
            "[STALL SWAP WITHDRAWN] The request to swap stalls was withdrawn. Nothing changes."
        );
    }

    swap_flash('success', 'Stall swap request withdrawn.');
    swap_back($targetId);
}

/* ---------- helpers ---------- */
$fullName = ''; $refNo = ''; $qrToken = '';
$initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));
if ($vendor) {
    $fullName = trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['middle_name'] ?? '') . ' ' . ($vendor['last_name'] ?? ''));
    $refNo    = 'VND-' . str_pad((string)$vendor['id'], 5, '0', STR_PAD_LEFT);
    $initials = strtoupper(substr($vendor['first_name'] ?? 'U', 0, 1) . substr($vendor['last_name'] ?? '', 0, 1));
    $qrToken  = trim((string)($vendor['qr_token'] ?? ''));
}

$vendorAvatarUrl   = $vendor ? profile_avatar_url($vendor['avatar_path'] ?? '', '') : '';
$vendorAvatarToken = profile_avatar_token();

$view    = $_GET['view'] ?? 'home';
$tab     = 'vendors';   // customer <-> vendor chat retired; only vendor threads remain
$vPeerId = isset($_GET['vpeer']) ? (int)$_GET['vpeer'] : 0;
$q       = trim($_GET['q'] ?? '');



$unreadVendorChats = 0;
if ($vendorId) {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN vendor_a_id = ? THEN unread_a ELSE unread_b END), 0) AS u
           FROM vendor_conversations
          WHERE vendor_a_id = ? OR vendor_b_id = ?");
    $stmt->bind_param("iii", $vendorId, $vendorId, $vendorId);
    $stmt->execute();
    $unreadVendorChats = (int)($stmt->get_result()->fetch_assoc()['u'] ?? 0);
    $stmt->close();
}


$notifUnread = 0;
if ($vendorId) {
    require_once __DIR__ . '/config/notify.php';
    $notifUnread = notif_unread_count($conn, $vendorId);
}


$vendorConvs = [];
if ($vendorId && $view === 'messages' && $tab === 'vendors') {
    $branch = "SELECT c.id, c.last_message_at, c.last_message_preview,
                      CASE WHEN c.vendor_a_id = ? THEN c.unread_a ELSE c.unread_b END AS unread,
                      v.id AS peer_id,
                      TRIM(CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name)) AS peer_name,
                      v.business_name AS peer_biz,
                      v.stall_number AS peer_stall,
                      v.market_section AS peer_section
                 FROM vendor_conversations c
                 JOIN vendors v ON v.id = CASE WHEN c.vendor_a_id = ? THEN c.vendor_b_id ELSE c.vendor_a_id END
                WHERE c.%s = ?";

    foreach (['vendor_a_id', 'vendor_b_id'] as $side) {
        $stmt = $conn->prepare(sprintf($branch, $side));
        $stmt->bind_param("iii", $vendorId, $vendorId, $vendorId);
        $stmt->execute();
        $vendorConvs = array_merge($vendorConvs, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    usort($vendorConvs, fn($a, $b) =>
        strcmp((string)($b['last_message_at'] ?? ''), (string)($a['last_message_at'] ?? '')));
}


$allVendors = [];
if ($vendorId && $view === 'messages' && $tab === 'vendors') {
    $stmt = $conn->prepare(
        "SELECT id,
                TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name,
                business_name, stall_number, market_section
           FROM vendors
          WHERE id <> ? AND stall_number IS NOT NULL AND stall_number <> ''
          ORDER BY market_section, stall_number");
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $allVendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}


$activeVChatId = 0; $vendorPeer = null; $vThreadMsgs = [];
if ($vendorId && $view === 'messages' && $tab === 'vendors' && $vPeerId) {
    $stmt = $conn->prepare(
        "SELECT id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name,
                business_name, stall_number, market_section
           FROM vendors WHERE id = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("ii", $vPeerId, $vendorId);
    $stmt->execute();
    $vendorPeer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($vendorPeer) {
        $a = min($vendorId, $vPeerId);
        $b = max($vendorId, $vPeerId);

        $stmt = $conn->prepare("SELECT id FROM vendor_conversations WHERE vendor_a_id = ? AND vendor_b_id = ? LIMIT 1");
        $stmt->bind_param("ii", $a, $b); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($row) $activeVChatId = (int)$row['id'];
        else {
            $stmt = $conn->prepare("INSERT INTO vendor_conversations (vendor_a_id, vendor_b_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $a, $b); $stmt->execute();
            $activeVChatId = (int)$stmt->insert_id; $stmt->close();
        }

        if ($vendorId === $a) {
            $conn->query("UPDATE vendor_conversations SET unread_a = 0 WHERE id = " . (int)$activeVChatId);
        } else {
            $conn->query("UPDATE vendor_conversations SET unread_b = 0 WHERE id = " . (int)$activeVChatId);
        }

        $conn->query("UPDATE vendor_messages SET is_read = 1 WHERE conversation_id = " . (int)$activeVChatId . " AND sender_vendor_id <> " . $vendorId);

        $stmt = $conn->prepare("SELECT id, sender_vendor_id, body, is_read, created_at FROM vendor_messages WHERE conversation_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $activeVChatId); $stmt->execute();
        $vThreadMsgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
}

/* ---------- stall swap data for the vendor thread ---------- */
$mySwaps      = [];
$threadSwaps  = [];
$swapOpen     = 0;
$myPendingSwaps = 0;
if ($vendorId) {
    $mySwaps = swap_list_for_vendor($conn, $vendorId);
    foreach ($mySwaps as $s) {
        if ($s['status'] === 'Pending') $myPendingSwaps++;
    }
    if ($vPeerId) {
        $threadSwaps = swap_list_for_pair($conn, $vendorId, $vPeerId);
        foreach ($threadSwaps as $s) {
            if ($s['status'] === 'Pending') $swapOpen = (int)$s['id'];
        }
    }
}

$swapFlash = swap_get_flash();
$swapMeta  = swap_status_meta();

function build_thread_groups(array $msgs, string $senderField, string $outSender): array {
    $groups = []; $prev = null;
    foreach ($msgs as $m) {
        $day = chat_day_label($m['created_at']);
        $sender = $m[$senderField];
        $isOut = ((string)$sender === (string)$outSender) ? 'out' : 'in';
        if ($prev === null || $prev['day'] !== $day || $prev['sender'] !== $isOut) {
            if ($prev !== null) $groups[] = $prev;
            $prev = ['sender' => $isOut, 'day' => $day, 'items' => [$m]];
        } else { $prev['items'][] = $m; }
    }
    if ($prev !== null) $groups[] = $prev;
    return $groups;
}
function ini_from($name) {
    $p = preg_split('/\s+/', trim((string)$name));
    return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1));
}
function chat_day_label($ts) {
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'Today';
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('M j, Y', $t);
}
function chat_list_time($ts) {
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('g:i A', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('M j', $t);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<title>Dashboard • MALLENGKE</title>
<meta name="theme-color" content="#04443a" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="assets/mallengke-app.css?v=20261021h" />
<style>
:root{/* Chat surfaces */
    --chat-bg:#f1f5f9;--chat-bg-image:none;--bubble-in-bg:#ffffff;--bubble-in-text:#0f172a;--bubble-in-shadow:0 1px 2px rgba(15,23,42,.06);--bubble-in-meta:rgba(15,23,42,.42);--bubble-out-bg:linear-gradient(135deg,#10b981,#047857);--bubble-out-text:#ffffff;--bubble-out-shadow:0 2px 8px rgba(4,120,87,.24);--bubble-out-meta:rgba(255,255,255,.88);--day-sep-bg:rgba(6,78,59,.08);--day-sep-color:#047857;--chat-scrollbar:rgba(6,78,59,.22);}


/* ============================================================
   RESET
   ============================================================ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{-webkit-text-size-adjust:100%;}
button{font-family:inherit;}
input,textarea,select{font-family:inherit;color:inherit;}
a{color:inherit;}
::selection{background:var(--brand-200);color:var(--brand-900);}

/* Scrollbars */
*::-webkit-scrollbar{width:8px;height:8px;}
*::-webkit-scrollbar-track{background:transparent;}
*::-webkit-scrollbar-thumb{background:rgba(6,78,59,.16);border-radius:4px;border:2px solid transparent;background-clip:padding-box;}
*::-webkit-scrollbar-thumb:hover{background:rgba(6,78,59,.3);background-clip:padding-box;border:2px solid transparent;}

/* Focus ring — a single visible, consistent treatment */
:focus-visible{
    outline:2.5px solid var(--brand-500);
    outline-offset:2px;
    border-radius:var(--r-sm);
}
.sidebar-scroll::-webkit-scrollbar{width:5px;}
.header-action-btn.green{
    background:linear-gradient(135deg, #059669, #065f46);
    color:#fff;
    box-shadow:0 4px 12px rgba(16,185,129,.32);
}
.header-action-btn.green:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(16,185,129,.45);
}

/* Dropdowns */
.notif-wrap,.user-wrap{position:relative;}
.notif-dropdown,.user-dropdown{
    position:absolute;top:calc(100% + 10px);right:0;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--r-lg);
    box-shadow:var(--sh-xl);
    overflow:hidden;z-index:60;
    opacity:0;transform:translateY(-6px) scale(.97);
    pointer-events:none;
    transition:opacity var(--dur),transform var(--dur) var(--ease-spring);
    transform-origin:top right;
}
.notif-dropdown{width:340px;max-width:calc(100vw - 32px);}
.user-dropdown{width:236px;}
.notif-wrap.open .notif-dropdown,
.user-wrap.open .user-dropdown{
    opacity:1;transform:translateY(0) scale(1);pointer-events:auto;
}
.notif-dropdown-head{
    padding:12px 16px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    background:var(--brand-50);
}
.notif-dropdown-head h4{
    font-size:12.5px;font-weight:800;color:var(--text-primary);
    display:flex;align-items:center;gap:7px;
}
.notif-dropdown-head h4 i{color:var(--brand-600);}
.notif-dropdown-head a{
    font-size:11.5px;font-weight:700;color:var(--brand-700);
    text-decoration:none;
}
.notif-dropdown-body{max-height:340px;overflow-y:auto;}
.notif-item{
    display:flex;gap:11px;padding:12px 16px;
    border-bottom:1px solid var(--border);
    text-decoration:none;color:inherit;
    transition:background var(--dur);
}
.notif-item:hover{background:var(--brand-50);}
.notif-item:last-child{border-bottom:none;}
.notif-item-icon{
    width:34px;height:34px;border-radius:10px;flex-shrink:0;
    display:grid;place-items:center;font-size:13px;color:#fff;
    background:linear-gradient(135deg,#10b981,#047857);
}
.notif-item-icon.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);}
.notif-item-icon.green{background:linear-gradient(135deg,#34d399,#059669);}
.notif-item-icon.red{background:linear-gradient(135deg,#ef4444,#dc2626);}
.notif-item-content{flex:1;min-width:0;}
.notif-item-title{font-size:12.5px;font-weight:700;color:var(--text-primary);margin-bottom:2px;}
.notif-item-desc{
    font-size:11.5px;color:var(--text-tertiary);line-height:1.45;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.notif-item-time{font-size:10.5px;color:var(--text-muted);font-weight:600;margin-top:4px;}

.user-dropdown-head{padding:14px 16px;border-bottom:1px solid var(--border);background:var(--brand-50);}
.user-dropdown-head .ud-name{font-size:13px;font-weight:800;color:var(--text-primary);}
.user-dropdown-head .ud-ref{font-size:11px;font-weight:700;color:var(--brand-700);margin-top:2px;}
.user-dropdown-list{padding:6px;}
.user-dropdown-item{
    display:flex;align-items:center;gap:10px;
    padding:9px 11px;border-radius:var(--r-sm);
    font-size:13px;font-weight:600;color:var(--text-secondary);
    text-decoration:none;
    transition:background var(--dur),color var(--dur);
}
.user-dropdown-item i{width:16px;text-align:center;color:var(--text-tertiary);font-size:13px;transition:color var(--dur);}
.user-dropdown-item:hover{background:var(--brand-50);color:var(--brand-700);}
.user-dropdown-item:hover i{color:var(--brand-600);}
.user-dropdown-item.danger{color:#dc2626;}
.user-dropdown-item.danger i{color:#ef4444;}
.user-dropdown-item.danger:hover{background:#fef2f2;color:#991b1b;}
.user-dropdown-sep{height:1px;background:var(--border);margin:4px 0;}

/* ============================================================
   WELCOME BANNER
   ============================================================ */
.welcome-banner{
    position:relative;overflow:hidden;
    border-radius:var(--r-2xl);
    padding:26px 28px;
    background:
        radial-gradient(90% 100% at 88% 15%, rgba(74,222,128,.28) 0%, transparent 55%),
        linear-gradient(135deg,#04231a 0%,#0a3d29 55%,#14532d 100%);
    color:#fff;
    margin-bottom:16px;
    display:flex;align-items:center;justify-content:space-between;
    gap:24px;flex-wrap:wrap;
    box-shadow:0 20px 40px -12px rgba(5,46,22,.3);
}
.welcome-banner::after{
    content:'';position:absolute;top:-70px;right:-70px;
    width:240px;height:240px;border-radius:50%;
    background:radial-gradient(circle,rgba(74,222,128,.2) 0%,transparent 65%);
    pointer-events:none;
}
.welcome-text{position:relative;z-index:2;max-width:600px;min-width:0;}
.welcome-chip{
    display:inline-flex;align-items:center;gap:7px;
    padding:5px 12px;border-radius:var(--r-full);
    background:rgba(74,222,128,.16);
    border:1px solid rgba(74,222,128,.3);
    color:#bbf7d0;
    font-size:10.5px;font-weight:800;
    letter-spacing:.08em;text-transform:uppercase;
    margin-bottom:12px;
}
.welcome-banner h2{font-size:22px;font-weight:800;letter-spacing:-.4px;margin-bottom:6px;line-height:1.25;color:#fff;}
.welcome-banner h2 span{color:#4ade80;}
.welcome-banner p{font-size:13.5px;color:#a9cbb9;line-height:1.6;}
.welcome-banner p strong{color:#4ade80;font-weight:700;}
.welcome-banner p a{color:#4ade80;text-decoration:underline;text-underline-offset:2px;font-weight:700;}
.welcome-art{
    position:relative;z-index:2;
    width:104px;height:104px;border-radius:50%;
    background:radial-gradient(circle,rgba(74,222,128,.2) 0%,rgba(74,222,128,.04) 70%);
    display:grid;place-items:center;
    border:1px solid rgba(74,222,128,.22);
    flex-shrink:0;
}
.welcome-art i{font-size:42px;color:#4ade80;opacity:.9;}@media(max-width:640px){
    .welcome-banner{padding:22px 20px;}
    .welcome-art{display:none;}
    .welcome-banner h2{font-size:19px;}}
.stat-card::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(135deg,rgba(16,185,129,.05),transparent 60%);
    opacity:0;transition:opacity var(--dur-slow);
}
.stat-card:hover::before{opacity:1;}
.stat-card:hover .stat-icon{transform:scale(1.08) rotate(-5deg);}
.stat-icon.g1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.34);}
.stat-icon.g2{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 6px 16px rgba(2,132,199,.34);}
.stat-icon.g3{background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 6px 16px rgba(168,85,247,.34);}
.stat-icon.g4{background:linear-gradient(135deg,#0d9488,#0f766e);box-shadow:0 6px 16px rgba(13,148,136,.32);}

/* ============================================================
   DASHBOARD CARDS
   ============================================================ */
.container{width:100%;margin:0;display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start;}@media(max-width:1000px){.container{grid-template-columns:1fr;}}

.profile-card{padding:26px 22px;text-align:center;}
.avatar{
    width:84px;height:84px;margin:0 auto 14px;
    border-radius:24px;
    background:linear-gradient(135deg,#34d399,#047857);
    display:grid;place-items:center;
    font-size:29px;font-weight:900;color:#fff;
    box-shadow:0 14px 28px rgba(16,185,129,.36);
    transition:transform var(--dur-slow) var(--ease-spring),box-shadow var(--dur-slow);
}
.avatar:hover{transform:scale(1.06) rotate(-3deg);box-shadow:0 18px 36px rgba(16,185,129,.48);}
.profile-card h2{font-size:18px;font-weight:800;color:var(--text-primary);margin-bottom:4px;letter-spacing:-.3px;}
.profile-card .email{font-size:12.5px;color:var(--text-tertiary);margin-bottom:14px;word-break:break-all;}
.profile-card .ref-badge{
    display:inline-flex;align-items:center;gap:6px;
    padding:5px 12px;border-radius:var(--r-full);
    background:var(--brand-100);color:var(--brand-800);
    font-size:11.5px;font-weight:800;
    border:1px solid var(--brand-200);
    margin-bottom:18px;
}
.profile-card .info-list{text-align:left;border-top:1px solid var(--border);padding-top:14px;}
.profile-card .info-list .row{
    display:flex;justify-content:space-between;gap:10px;
    padding:9px 6px;font-size:13px;border-radius:var(--r-sm);
    transition:background var(--dur),padding-left var(--dur);
    border-bottom:1px dashed var(--border);
}
.profile-card .info-list .row:hover{background:var(--brand-50);padding-left:10px;}
.profile-card .info-list .row:last-child{border-bottom:none;}
.profile-card .info-list .label{color:var(--text-tertiary);font-weight:600;font-size:12.5px;}
.profile-card .info-list .value{
    color:var(--text-primary);font-weight:700;font-size:12.5px;text-align:right;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%;
}
.profile-card .edit-cta{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    width:100%;margin-top:16px;padding:12px 16px;
    border-radius:var(--r-md);
    background:linear-gradient(135deg,#10b981,#047857);
    color:#fff;font-size:13.5px;font-weight:800;text-decoration:none;
    transition:all var(--dur) var(--ease-spring);
    box-shadow:var(--glow-sm);
}
.profile-card .edit-cta:hover{transform:translateY(-2px);box-shadow:var(--glow);}

.main-col{display:flex;flex-direction:column;gap:16px;}
.section-head a:hover{background:var(--brand-50);transform:translateX(2px);}

/* QR section */
.qr-section{
    padding:22px;
    display:grid;grid-template-columns:auto 1fr;gap:24px;align-items:center;
    background:linear-gradient(135deg,var(--brand-50),#fff);
}@media(max-width:640px){
    .qr-section{grid-template-columns:1fr;text-align:center;}
    .qr-frame{margin:0 auto;}
    .qr-actions{justify-content:center;}}
.qr-frame{
    width:196px;padding:14px 12px 12px;
    background:var(--surface);
    border-radius:var(--r-lg);
    border:2px dashed var(--brand-300);
    display:flex;flex-direction:column;align-items:center;gap:10px;
    flex-shrink:0;
}
.qr-frame .qr-box{width:156px;height:156px;display:flex;align-items:center;justify-content:center;}
.qr-frame .qr-label{
    font-size:9.5px;font-weight:800;color:var(--brand-800);
    letter-spacing:.08em;text-transform:uppercase;text-align:center;
}
.qr-frame .qr-ref{font-size:11.5px;font-weight:800;color:var(--text-primary);letter-spacing:.04em;}
.qr-section h3{
    font-size:16px;font-weight:800;color:var(--text-primary);
    margin-bottom:8px;display:flex;align-items:center;gap:10px;
}
.qr-section h3 i{color:var(--brand-600);font-size:17px;}
.qr-section p{font-size:13px;color:var(--text-secondary);margin-bottom:16px;line-height:1.6;max-width:560px;}
.qr-actions{display:flex;gap:8px;flex-wrap:wrap;}
.qr-actions button,.qr-actions a{
    display:inline-flex;align-items:center;gap:7px;
    padding:10px 16px;border-radius:var(--r-md);
    font-family:inherit;font-size:12.5px;font-weight:800;
    text-decoration:none;
    transition:all var(--dur) var(--ease-spring);
    cursor:pointer;border:none;
}
.link-primary{background:linear-gradient(135deg,#10b981,#047857);color:#fff;box-shadow:var(--glow-sm);}
.link-primary:hover{transform:translateY(-2px);box-shadow:var(--glow);}
.link-secondary{background:var(--surface);border:1px solid var(--border);color:var(--text-secondary);}
.link-secondary:hover{border-color:var(--brand-300);background:var(--brand-50);color:var(--brand-700);transform:translateY(-2px);box-shadow:var(--sh-sm);}
.link-green{background:linear-gradient(135deg,#34d399,#059669);color:#fff;box-shadow:0 4px 12px rgba(16,185,129,.32);}
.link-green:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(16,185,129,.45);}
.link-passport{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;box-shadow:0 4px 12px rgba(220,38,38,.32);}
.link-passport:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(220,38,38,.45);}

/* Recent messages */
.recent-list{padding:6px 8px 10px;}
.recent-item{
    display:flex;gap:12px;padding:11px 12px;
    border-radius:var(--r-md);text-decoration:none;color:inherit;
    align-items:center;
    transition:background var(--dur),transform var(--dur);
}
.recent-item:hover{background:var(--brand-50);transform:translateX(3px);}
.recent-avatar{
    width:44px;height:44px;border-radius:14px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;
    background:linear-gradient(135deg,#34d399,#059669);
    box-shadow:0 4px 12px rgba(16,185,129,.26);
    transition:transform var(--dur-slow) var(--ease-spring);
}
.recent-avatar.vendor{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 4px 12px rgba(2,132,199,.26);}
.recent-item:hover .recent-avatar{transform:scale(1.06) rotate(-3deg);}
.recent-meta{flex:1;min-width:0;}
.recent-name{font-size:13.5px;font-weight:700;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.recent-preview{font-size:12px;color:var(--text-tertiary);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.recent-time{font-size:10.5px;color:var(--text-muted);font-weight:700;flex-shrink:0;}
.recent-badge{
    min-width:20px;height:20px;padding:0 6px;border-radius:var(--r-full);
    background:linear-gradient(135deg,#10b981,#047857);color:#fff;
    font-size:10.5px;font-weight:800;
    display:grid;place-items:center;
    box-shadow:var(--glow-sm);
}
.recent-badge.vendor{background:linear-gradient(135deg,#0ea5e9,#0284c7);}
.empty-mini{padding:40px 24px;text-align:center;color:var(--text-tertiary);font-size:13px;}
.empty-mini i{font-size:38px;color:var(--brand-200);display:block;margin-bottom:12px;}

.remarks-box{padding:18px 20px;border-radius:var(--r-lg);background:#fef2f2;border:1px solid #fecaca;}
.remarks-box h4{
    font-size:11.5px;font-weight:800;color:#991b1b;margin-bottom:8px;
    display:flex;align-items:center;gap:8px;
    text-transform:uppercase;letter-spacing:.06em;
}
.remarks-box p{font-size:13px;color:#7f1d1d;line-height:1.6;}

.info-banner{
    padding:28px 24px;border-radius:var(--r-lg);
    background:linear-gradient(135deg,var(--brand-50),#fff);
    border:2px dashed var(--brand-200);
    text-align:center;
}
.info-banner i.big{font-size:40px;color:var(--brand-400);margin-bottom:14px;display:block;}
.info-banner h3{font-size:15.5px;font-weight:800;color:var(--text-primary);margin-bottom:8px;}
.info-banner p{font-size:13px;color:var(--text-secondary);margin-bottom:18px;line-height:1.6;}
.info-banner a{
    display:inline-flex;align-items:center;gap:8px;
    padding:12px 22px;border-radius:var(--r-md);
    background:linear-gradient(135deg,#10b981,#047857);
    color:#fff;font-size:13.5px;font-weight:800;
    text-decoration:none;
    box-shadow:var(--glow-sm);
    transition:all var(--dur) var(--ease-spring);
}
.info-banner a:hover{transform:translateY(-3px) scale(1.02);box-shadow:var(--glow);}

/* ============================================================
   PASSPORT TEMPLATE (hidden, for PDF export)
   ============================================================ */
#passport-template{position:fixed;top:0;left:0;width:700px;background:#fff;font-family:'Inter',Arial,sans-serif;color:#1e293b;z-index:-1;opacity:0;pointer-events:none;overflow:hidden;}
#passport-template .pt-card{background:#fff;border-radius:22px;overflow:hidden;border:1px solid #e2e8f0;}
#passport-template .pt-top{background:linear-gradient(135deg,#ecfdf5,#d1fae5);padding:26px 30px;border-bottom:1px solid #a7f3d0;display:grid;grid-template-columns:auto 1fr auto;gap:20px;align-items:center;}
#passport-template .pt-avatar{width:74px;height:74px;border-radius:18px;background:linear-gradient(135deg,#34d399,#059669);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#fff;}
#passport-template .pt-name{font-size:20px;font-weight:700;color:#0f172a;margin-bottom:3px;}
#passport-template .pt-ref{font-size:12.5px;font-weight:700;color:#065f46;letter-spacing:.08em;}
#passport-template .pt-biz{font-size:13px;color:#64748b;margin-top:3px;}
#passport-template .pt-stamp{width:66px;height:66px;border:2.5px dashed #059669;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#059669;text-align:center;line-height:1.15;transform:rotate(-8deg);}
#passport-template .pt-body{padding:26px 30px 30px;display:grid;grid-template-columns:1fr 1fr;gap:20px;}
#passport-template .pt-section{background:#ecfdf5;border:1px solid #e2e8f0;border-radius:14px;padding:18px 18px 12px;}
#passport-template .pt-section h4{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#059669;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #e2e8f0;}
#passport-template .pt-row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;font-size:14px;border-bottom:1px dashed #e2e8f0;}
#passport-template .pt-row:last-child{border-bottom:none;}
#passport-template .pt-label{color:#64748b;font-weight:500;font-size:13px;}
#passport-template .pt-value{color:#0f172a;font-weight:600;text-align:right;}
#passport-template .pt-footer{padding:16px 30px;background:#ecfdf5;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;font-size:12.5px;color:#64748b;}
#passport-template .pt-badge{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
#passport-template .pt-badge-pending{background:#d1fae5;color:#065f46;}
#passport-template .pt-badge-approved{background:#d1fae5;color:#065f46;}
#passport-template .pt-badge-rejected{background:#fee2e2;color:#991b1b;}

/* ============================================================
   CHAT SHELL
   ============================================================ */
body.chat-mode{height:100vh;height:100dvh;overflow:hidden;}
body.chat-mode .main-content{
    height:100vh;height:100dvh;
    padding:16px 20px;
    overflow:hidden;
    display:flex;flex-direction:column;
}@media(max-width:960px){
    body.chat-mode .main-content{
        padding:70px 10px 10px;
        height:100vh;height:100dvh;
    }}

.chat-shell{
    flex:1 1 auto;min-height:0;
    display:grid;grid-template-columns:340px 1fr;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--r-xl);
    overflow:hidden;
    box-shadow:var(--sh-md);
}
.chat-shell > *{min-height:0;}@media(max-width:960px){
    .chat-shell{grid-template-columns:1fr;border-radius:var(--r-lg);}
    .chat-shell.show-thread .conv-list-panel{display:none;}
    .chat-shell:not(.show-thread) .thread-panel{display:none;}}

/* ---------- Conversation list panel ---------- */
.conv-list-panel{
    border-right:1px solid var(--border);
    display:flex;flex-direction:column;
    min-width:0;min-height:0;overflow:hidden;
    background:var(--surface);
}

/* Segmented tab control */
.conv-tabs{
    display:flex;gap:4px;
    padding:14px 14px 0;
    background:var(--surface);
    flex-shrink:0;
}
.conv-tab{
    flex:1;
    display:inline-flex;align-items:center;justify-content:center;gap:7px;
    padding:10px 12px;
    border-radius:var(--r-md);
    font-size:12.5px;font-weight:700;
    color:var(--text-tertiary);
    text-decoration:none;
    background:transparent;
    border:none;cursor:pointer;
    transition:all var(--dur) var(--ease-spring);
    font-family:inherit;
    position:relative;
}
.conv-tab i{font-size:12px;transition:transform var(--dur) var(--ease-spring);}
.conv-tab:hover{color:var(--text-primary);background:var(--gray-50);}
.conv-tab:hover i{transform:scale(1.1);}
.conv-tab.active{
    color:#fff;
    background:linear-gradient(135deg,#10b981,#047857);
    box-shadow:0 4px 12px rgba(16,185,129,.32);
}
.conv-tab.active i{color:#fff;}
.conv-tab.vendor-tab.active{
    background:linear-gradient(135deg,#0ea5e9,#0284c7);
    box-shadow:0 4px 12px rgba(14,165,233,.32);
}
.conv-tab .tab-badge{
    font-size:10px;font-weight:800;
    padding:1px 6px;border-radius:var(--r-full);
    background:#ef4444;color:#fff;
    min-width:16px;text-align:center;
    line-height:1.5;
    display:inline-block;
}
.conv-tab.active .tab-badge{background:rgba(255,255,255,.25);color:#fff;}

.conv-search{padding:14px 14px 12px;flex:0 0 auto;border-bottom:1px solid var(--border);}
.conv-search-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:11px;padding:0 2px;}
.conv-search-title{font-size:19px;font-weight:900;color:var(--text-primary);letter-spacing:-.5px;}
.new-chat-btn{
    width:34px;height:34px;border-radius:50%;
    background:var(--brand-100);color:var(--brand-700);
    display:inline-flex;align-items:center;justify-content:center;
    text-decoration:none;font-size:12.5px;
    border:none;cursor:pointer;
    transition:all var(--dur-slow) var(--ease-spring);
}
.new-chat-btn:hover{
    background:linear-gradient(135deg, #059669, #065f46);
    color:#fff;
    transform:scale(1.1) rotate(-8deg);
    box-shadow:var(--glow);
}
.new-chat-btn.vendor{background:var(--blue-100);color:var(--blue-600);}
.new-chat-btn.vendor:hover{background:linear-gradient(135deg,#0ea5e9,#0284c7);}

.conv-search-input{position:relative;}
.conv-search-input i{
    position:absolute;left:13px;top:50%;transform:translateY(-50%);
    color:var(--text-muted);font-size:12.5px;pointer-events:none;
    transition:color var(--dur),transform var(--dur);
}
.conv-search-input input{
    width:100%;padding:10px 14px 10px 38px;
    border:1px solid transparent;border-radius:var(--r-md);
    font-family:inherit;font-size:13.5px;outline:none;
    background:var(--gray-50);color:var(--text-primary);
    transition:background var(--dur),border-color var(--dur),box-shadow var(--dur);
}
.conv-search-input input::placeholder{color:var(--text-muted);}
.conv-search-input input:focus{
    background:var(--surface);
    border-color:var(--brand-300);
    box-shadow:0 0 0 4px rgba(16,185,129,.12);
}
.conv-search-input:focus-within i{color:var(--brand-600);transform:translateY(-50%) scale(1.05);}

.conv-search-form{display:contents;}
.conv-list{flex:1 1 auto;min-height:0;overflow-y:auto;padding:8px 8px 12px;overscroll-behavior:contain;}

.conv-item{
    display:flex;gap:12px;padding:10px 12px;
    border-radius:var(--r-md);
    transition:background var(--dur),transform var(--dur);
    align-items:center;
    text-decoration:none;color:inherit;
    position:relative;
    margin-bottom:2px;
}
.conv-item:hover{background:var(--brand-50);transform:translateX(2px);}
.conv-item.active{background:var(--brand-100);}
.conv-item.active::before{
    content:'';position:absolute;left:0;top:14px;bottom:14px;width:3px;
    border-radius:0 3px 3px 0;
    background:linear-gradient(180deg,#34d399,#047857);
}
.conv-item.active .conv-name{color:var(--brand-900);}
.conv-item.vendor-item:hover{background:var(--blue-50);}
.conv-item.vendor-item.active{background:var(--blue-100);}
.conv-item.vendor-item.active::before{background:linear-gradient(180deg,#38bdf8,#0284c7);}
.conv-item.vendor-item.active .conv-name{color:#075985;}

.conv-avatar{
    width:48px;height:48px;border-radius:14px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:800;font-size:15px;
    background:linear-gradient(135deg,#34d399,#059669);
    position:relative;
    box-shadow:0 4px 12px rgba(16,185,129,.24);
    transition:transform var(--dur-slow) var(--ease-spring);
}
.conv-avatar.vendor{
    background:linear-gradient(135deg,#38bdf8,#0284c7);
    box-shadow:0 4px 12px rgba(2,132,199,.24);
}
.conv-item:hover .conv-avatar{transform:scale(1.05);}
.conv-avatar::after{
    content:'';position:absolute;bottom:-1px;right:-1px;
    width:12px;height:12px;border-radius:50%;
    background:#10b981;border:2.5px solid var(--surface);
}
.conv-avatar.vendor::after{background:#0ea5e9;}

.conv-meta{flex:1;min-width:0;}
.conv-name{
    font-size:14px;font-weight:700;color:var(--text-primary);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    line-height:1.3;
}
.conv-preview{
    font-size:12.5px;color:var(--text-tertiary);margin-top:3px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    line-height:1.3;
}
.conv-item.unread .conv-preview{color:var(--text-secondary);font-weight:600;}
.conv-side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;}
.conv-time{font-size:10.5px;color:var(--text-muted);font-weight:700;}
.conv-item.unread .conv-time{color:var(--brand-700);font-weight:800;}
.conv-item.vendor-item.unread .conv-time{color:var(--blue-600);}
.conv-badge{
    min-width:19px;height:19px;padding:0 6px;border-radius:var(--r-full);
    background:linear-gradient(135deg,#10b981,#047857);color:#fff;
    font-size:10.5px;font-weight:800;
    display:flex;align-items:center;justify-content:center;
    box-shadow:var(--glow-sm);
}
.conv-badge.vendor{background:linear-gradient(135deg,#0ea5e9,#0284c7);}

.conv-empty{text-align:center;padding:60px 24px;color:var(--text-tertiary);}
.conv-empty i{font-size:46px;color:var(--brand-200);display:block;margin-bottom:14px;}
.conv-empty.vendor i{color:var(--blue-200);}
.conv-empty p{font-size:13.5px;margin-bottom:16px;}

/* ---------- Thread panel ---------- */
.thread-panel{
    display:flex;flex-direction:column;
    min-width:0;min-height:0;overflow:hidden;
    background:var(--surface);
    position:relative;
}
.thread-head{
    padding:12px 18px;
    border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:12px;
    background:var(--surface);
    flex:0 0 auto;z-index:2;
}
.thread-avatar{
    width:42px;height:42px;border-radius:13px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:800;font-size:14.5px;
    background:linear-gradient(135deg,#34d399,#059669);
    position:relative;
    box-shadow:0 4px 12px rgba(16,185,129,.24);
}
.thread-avatar.vendor{
    background:linear-gradient(135deg,#38bdf8,#0284c7);
    box-shadow:0 4px 12px rgba(2,132,199,.24);
}
.thread-avatar::after{
    content:'';position:absolute;bottom:-2px;right:-2px;
    width:12px;height:12px;border-radius:50%;
    background:#10b981;border:2.5px solid var(--surface);
}
.thread-avatar.vendor::after{background:#0ea5e9;}
.thread-info{flex:1;min-width:0;}
.thread-name{
    font-size:14.5px;font-weight:800;color:var(--text-primary);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    line-height:1.3;
}
.thread-sub{
    font-size:12px;color:var(--text-tertiary);margin-top:2px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    display:flex;align-items:center;gap:6px;flex-wrap:wrap;
}
.thread-sub .vendor-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:1px 7px;border-radius:var(--r-full);
    background:var(--blue-100);color:#075985;
    font-size:10px;font-weight:800;
}
.thread-actions{display:flex;gap:6px;flex-shrink:0;}
.icon-btn.report-peer:hover{
    background:linear-gradient(135deg,#ef4444,#b91c1c);
    box-shadow:0 8px 20px rgba(239,68,68,.4);
}
.thread-back{
    display:none;width:38px;height:38px;border-radius:11px;
    background:var(--gray-50);border:none;cursor:pointer;
    color:var(--text-primary);
    align-items:center;justify-content:center;
    font-size:14px;flex-shrink:0;
    transition:all var(--dur) var(--ease-spring);
}
.thread-back:hover{background:var(--brand-100);color:var(--brand-700);}@media(max-width:960px){.thread-back{display:inline-flex;}}

/* ---------- Thread body (messages) ---------- */
.thread-body{
    flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;
    padding:20px 22px 12px;
    display:flex;flex-direction:column;
    scroll-behavior:smooth;
    background-color:var(--chat-bg);
    background-image:var(--chat-bg-image);
    background-size:cover;background-position:center;
    background-repeat:no-repeat;background-attachment:local;
    position:relative;
    scrollbar-width:thin;scrollbar-color:var(--chat-scrollbar) transparent;
}
.thread-body::-webkit-scrollbar{width:8px;}
.thread-body::-webkit-scrollbar-thumb{
    background:var(--chat-scrollbar);border-radius:4px;
    border:2px solid transparent;background-clip:padding-box;
}

.msg-group{
    display:flex;flex-direction:column;gap:2px;
    margin-bottom:12px;max-width:72%;
    animation:msgIn .3s cubic-bezier(.2,.8,.2,1);
}
.msg-group.out{align-self:flex-end;align-items:flex-end;}
.msg-group.in{align-self:flex-start;align-items:flex-start;}
.msg-group:last-of-type{margin-bottom:2px;}
@keyframes msgIn{
    from{opacity:0;transform:translateY(8px) scale(.97);}
    to{opacity:1;transform:translateY(0) scale(1);}
}
.msg-row{
    display:flex;gap:8px;max-width:100%;align-items:flex-end;
    position:relative;
}
.msg-row.out{flex-direction:row-reverse;}
.msg-row.in{flex-direction:row;}
.msg-bubble{
    padding:9px 14px;
    font-size:14.5px;line-height:1.5;
    word-break:break-word;white-space:pre-wrap;
    letter-spacing:-.005em;
    position:relative;max-width:100%;
    border-radius:18px;border:none;box-shadow:none;
    transition:transform var(--dur) var(--ease-spring);
}
.msg-row.in .msg-bubble{
    background:var(--bubble-in-bg);color:var(--bubble-in-text);
    box-shadow:var(--bubble-in-shadow);
    border-bottom-left-radius:6px;
}
.msg-row.out .msg-bubble{
    background:var(--bubble-out-bg);color:var(--bubble-out-text);
    box-shadow:var(--bubble-out-shadow);
    border-bottom-right-radius:6px;
}
.msg-group.in .msg-row:not(:last-child) .msg-bubble{border-bottom-left-radius:18px;}
.msg-group.out .msg-row:not(:last-child) .msg-bubble{border-bottom-right-radius:18px;}

.msg-meta{
    display:flex;align-items:center;gap:4px;
    font-size:10.5px;font-weight:700;
    align-self:flex-end;
    opacity:0;transform:translateX(4px);
    transition:opacity var(--dur),transform var(--dur);
    white-space:nowrap;margin:0 6px 4px;
    pointer-events:none;
}
.msg-row:hover .msg-meta,.msg-row:last-child .msg-meta{opacity:1;transform:translateX(0);}
.msg-row.in .msg-meta{color:var(--bubble-in-meta);}
.msg-row.out .msg-meta{color:var(--bubble-out-meta);}
.msg-meta .read-tick{opacity:.95;font-size:11px;display:inline-flex;align-items:center;}
.msg-row.out .msg-meta .read-tick{color:#fff;}

.day-sep{
    align-self:center;margin:14px 0;
    padding:5px 14px;border-radius:var(--r-full);
    background:var(--day-sep-bg);color:var(--day-sep-color);
    font-size:10.5px;font-weight:800;
    letter-spacing:.08em;text-transform:uppercase;
    transition:background var(--dur);
}

/* ---------- Composer ---------- */
.composer{
    flex:0 0 auto;
    border-top:1px solid var(--border);
    padding:12px 16px;
    padding-bottom:calc(12px + env(safe-area-inset-bottom,0));
    background:var(--surface);
    display:flex;gap:10px;align-items:flex-end;
    position:relative;z-index:2;
}
.composer-input-wrap{
    flex:1;display:flex;align-items:flex-end;
    background:var(--gray-50);
    border-radius:24px;
    padding:3px 4px;
    transition:background var(--dur),border-color var(--dur),box-shadow var(--dur);
    border:1.5px solid transparent;
}
.composer-input-wrap:focus-within{
    background:var(--surface);
    border-color:var(--brand-300);
    box-shadow:0 0 0 4px rgba(16,185,129,.1);
}
.composer textarea{
    flex:1;min-height:36px;max-height:120px;
    padding:9px 14px;border:none;background:transparent;
    font-family:inherit;font-size:14.5px;
    resize:none;outline:none;line-height:1.4;
    color:var(--text-primary);
    scrollbar-width:thin;
}
.composer textarea::placeholder{color:var(--text-muted);}
.composer-send{
    width:36px;height:36px;border-radius:50%;border:none;
    background:linear-gradient(135deg,#10b981,#047857);color:#fff;
    font-size:14px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;margin:1px;
    transition:all var(--dur-slow) var(--ease-spring);
    box-shadow:var(--glow-sm);
}
.composer-send.vendor{background:linear-gradient(135deg,#0ea5e9,#0284c7);}
.composer-send:hover{transform:scale(1.1) rotate(-10deg);box-shadow:var(--glow);}
.composer-send.vendor:hover{box-shadow:0 8px 20px rgba(14,165,233,.45);}
.composer-send:active{transform:scale(.92);}

.scroll-bottom-btn{
    position:absolute;bottom:90px;right:24px;
    width:42px;height:42px;border-radius:50%;
    background:var(--surface);color:var(--brand-700);
    border:1px solid var(--brand-200);
    box-shadow:var(--sh-lg);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:14px;
    opacity:0;pointer-events:none;
    transform:translateY(10px) scale(.85);
    transition:all var(--dur-slow) var(--ease-spring);
    z-index:5;
}
.scroll-bottom-btn.show{opacity:1;pointer-events:auto;transform:translateY(0) scale(1);}
.scroll-bottom-btn:hover{
    background:linear-gradient(135deg, #059669, #065f46);
    color:#fff;transform:translateY(-2px) scale(1.06);
    box-shadow:var(--glow);
}

/* ---------- Empty thread ---------- */
.thread-empty{
    flex:1;display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    padding:40px;text-align:center;color:var(--text-tertiary);
    background:radial-gradient(circle at 50% 40%,rgba(16,185,129,.06) 0%,transparent 60%),var(--surface-2);
}
.thread-empty .empty-icon{
    width:96px;height:96px;border-radius:50%;
    background:linear-gradient(135deg,var(--brand-100),var(--brand-50));
    display:flex;align-items:center;justify-content:center;
    margin-bottom:22px;
    animation:floatY 3.5s ease-in-out infinite;
}
.thread-empty .empty-icon i{font-size:40px;color:var(--brand-500);opacity:.9;}
.thread-empty .empty-icon.vendor{background:linear-gradient(135deg,var(--blue-100),var(--blue-50));}
.thread-empty .empty-icon.vendor i{color:var(--blue-600);}
@keyframes floatY{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
.thread-empty h3{font-size:19px;font-weight:900;color:var(--text-primary);margin-bottom:8px;letter-spacing:-.3px;}
.thread-empty p{font-size:13.5px;max-width:340px;color:var(--text-tertiary);line-height:1.6;}

/* ============================================================
   MODALS — shared pattern
   ============================================================ */
.newchat-modal,.swap-modal,.theme-modal{
    position:fixed;inset:0;
    background:rgba(2,44,34,.5);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
    z-index:600;
    display:none;align-items:center;justify-content:center;
    padding:20px;
    overflow-y:auto;
}
.newchat-modal.open,.swap-modal.open,.theme-modal.open{display:flex;}
.swap-modal{z-index:620;}
.theme-modal{z-index:500;}

.newchat-modal-inner,.swap-modal-inner,.theme-modal-inner{
    background:var(--surface);
    border-radius:var(--r-2xl);
    width:100%;
    box-shadow:var(--sh-xl);
    overflow:hidden;
    animation:modalPop .3s var(--ease-spring);
    display:flex;flex-direction:column;
    max-height:90vh;max-height:90dvh;
}
.newchat-modal-inner{max-width:480px;}
.swap-modal-inner{max-width:520px;}
.theme-modal-inner{max-width:640px;}
@keyframes modalPop{
    from{opacity:0;transform:translateY(20px) scale(.96);}
    to{opacity:1;transform:translateY(0) scale(1);}
}

.newchat-head,.swap-modal-head{
    padding:18px 20px 14px;
    border-bottom:1px solid var(--border);
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:12px;
    flex-shrink:0;
}
.newchat-head h3,.swap-modal-head h3{
    font-size:16px;font-weight:800;color:var(--text-primary);
    display:flex;align-items:center;gap:9px;line-height:1.3;
}
.newchat-head h3 i,.swap-modal-head h3 i{color:var(--brand-600);}
.newchat-head.vendor h3 i{color:var(--blue-600);}
.swap-modal-head h3 i{color:var(--purple-500);}
.swap-modal-head p{font-size:12.5px;color:var(--text-tertiary);margin-top:4px;line-height:1.5;}
.newchat-close{
    width:32px;height:32px;border-radius:50%;
    background:var(--gray-50);border:none;cursor:pointer;
    color:var(--text-secondary);font-size:12.5px;
    display:flex;align-items:center;justify-content:center;
    transition:all var(--dur-slow) var(--ease-spring);
    flex-shrink:0;
}
.newchat-close:hover{background:var(--brand-100);color:var(--brand-700);transform:rotate(90deg);}

.newchat-search{padding:12px 18px;border-bottom:1px solid var(--border);}
.newchat-search-input{position:relative;}
.newchat-search-input i{
    position:absolute;left:13px;top:50%;transform:translateY(-50%);
    color:var(--text-muted);font-size:12.5px;pointer-events:none;
}
.newchat-search-input input{
    width:100%;padding:10px 14px 10px 36px;
    border:1px solid transparent;border-radius:var(--r-md);
    font-family:inherit;font-size:13.5px;outline:none;
    background:var(--gray-50);color:var(--text-primary);
    transition:all var(--dur);
}
.newchat-search-input input:focus{
    background:var(--surface);border-color:var(--brand-300);
    box-shadow:0 0 0 4px rgba(16,185,129,.1);
}

.newchat-list{flex:1 1 auto;min-height:0;overflow-y:auto;padding:6px 8px 10px;}
.newchat-item{
    display:flex;gap:11px;padding:10px 12px;
    border-radius:var(--r-md);cursor:pointer;
    transition:background var(--dur),transform var(--dur);
    align-items:center;
    border:none;background:transparent;
    width:100%;text-align:left;font-family:inherit;
}
.newchat-item:hover{background:var(--brand-50);transform:translateX(2px);}
.newchat-item.selected{background:var(--brand-100);}
.newchat-item.selected .newchat-item-name{color:var(--brand-700);}
.newchat-item.vendor:hover{background:var(--blue-50);}
.newchat-item.vendor.selected{background:var(--blue-100);}
.newchat-item.vendor.selected .newchat-item-name{color:var(--blue-600);}
.newchat-avatar{
    width:42px;height:42px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:800;font-size:14px;
    background:linear-gradient(135deg,#34d399,#059669);
}
.newchat-avatar.vendor{
    background:linear-gradient(135deg,#38bdf8,#0284c7);
    border-radius:13px;
}
.newchat-item-meta{flex:1;min-width:0;}
.newchat-item-name{
    font-size:14px;font-weight:700;color:var(--text-primary);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.newchat-item-sub{
    font-size:12px;color:var(--text-tertiary);margin-top:2px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.newchat-empty{text-align:center;padding:40px 20px;color:var(--text-tertiary);}
.newchat-empty i{font-size:36px;color:var(--brand-200);display:block;margin-bottom:12px;}

.newchat-compose{
    padding:12px 18px 16px;
    padding-bottom:calc(16px + env(safe-area-inset-bottom,0));
    border-top:1px solid var(--border);
    background:var(--surface);
    flex:0 0 auto;
}
.newchat-compose textarea{
    width:100%;min-height:52px;max-height:100px;
    padding:11px 13px;
    border:1px solid var(--border);border-radius:var(--r-md);
    font-family:inherit;font-size:13.5px;
    resize:none;outline:none;line-height:1.5;
    color:var(--text-primary);background:var(--gray-50);
    transition:all var(--dur);
}
.newchat-compose textarea:focus{
    border-color:var(--brand-300);
    background:var(--surface);
    box-shadow:0 0 0 4px rgba(16,185,129,.1);
}
.newchat-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px;}
.newchat-btn{
    padding:10px 18px;border-radius:var(--r-md);
    border:none;cursor:pointer;font-family:inherit;
    font-size:13px;font-weight:800;
    transition:all var(--dur) var(--ease-spring);
}
.newchat-btn.cancel{background:var(--gray-100);color:var(--text-secondary);}
.newchat-btn.cancel:hover{background:var(--gray-200);transform:translateY(-1px);}
.newchat-btn.primary{
    background:linear-gradient(135deg, #059669, #065f46);
    color:#fff;box-shadow:var(--glow-sm);
}
.newchat-btn.primary.vendor{
    background:linear-gradient(135deg,#0ea5e9,#0284c7);
    box-shadow:0 4px 12px rgba(14,165,233,.28);
}
.newchat-btn.primary:hover{transform:translateY(-2px);box-shadow:var(--glow);}
.newchat-btn.primary.vendor:hover{box-shadow:0 8px 20px rgba(14,165,233,.45);}
.newchat-btn.primary:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none;}

/* ============================================================
   STALL SWAP
   ============================================================ */
.flash-note{
    display:flex;align-items:center;gap:10px;
    padding:11px 16px;border-radius:var(--r-md);
    font-size:13px;font-weight:700;margin-bottom:12px;
    animation:fadeUp .3s var(--ease-spring);
    flex-shrink:0;
}
.flash-note.success{background:var(--brand-100);border:1px solid var(--brand-200);color:var(--brand-800);}
.flash-note.error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.flash-note i{font-size:15px;}
.flash-note.success i{color:var(--brand-600);}
.flash-note.error i{color:#dc2626;}
@keyframes fadeUp{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}

.swap-btn{
    display:inline-flex;align-items:center;gap:7px;
    height:36px;padding:0 14px;
    border-radius:var(--r-full);
    border:1px solid var(--border);
    background:var(--surface);color:var(--text-secondary);
    font-family:inherit;font-size:12.5px;font-weight:800;
    cursor:pointer;white-space:nowrap;
    transition:all var(--dur-slow) var(--ease-spring);
}
.swap-btn:hover:not(:disabled){
    background:linear-gradient(135deg,#8b5cf6,#6d28d9);
    border-color:transparent;color:#fff;
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(168,85,247,.4);
}
.swap-btn:disabled{
    opacity:.55;cursor:not-allowed;
    background:var(--purple-50);
    border-color:var(--purple-200);
    color:var(--purple-700);
}
.swap-btn i{font-size:12.5px;}@media(max-width:640px){
    .swap-btn span{display:none;}
    .swap-btn{width:36px;padding:0;justify-content:center;}}

.swap-panel{
    flex:0 0 auto;
    border-bottom:1px solid var(--border);
    background:linear-gradient(180deg,var(--purple-50),var(--surface));
    padding:12px 18px;
    max-height:220px;overflow-y:auto;
}
.swap-panel:empty{display:none;}
.swap-card{
    border:1px solid var(--border);
    border-radius:var(--r-md);
    background:var(--surface);
    padding:11px 13px;
    margin-bottom:8px;
    transition:box-shadow var(--dur);
}
.swap-card:last-child{margin-bottom:0;}
.swap-card:hover{box-shadow:var(--sh-sm);}
.swap-card-top{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:8px;}
.swap-card-title{
    font-size:12.5px;font-weight:800;color:var(--text-primary);
    line-height:1.35;
}
.swap-card-title small{
    display:block;font-size:10.5px;font-weight:600;
    color:var(--text-tertiary);margin-top:2px;
}
.swap-status{
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 9px;border-radius:var(--r-full);
    font-size:10px;font-weight:800;
    text-transform:uppercase;letter-spacing:.04em;
    margin-left:auto;
}
.swap-status.pending{background:#fef3c7;color:#92400e;}
.swap-status.approved{background:var(--brand-100);color:var(--brand-800);}
.swap-status.rejected,.swap-status.cancelled{background:#fee2e2;color:#991b1b;}
.swap-pair{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
    font-size:12px;font-weight:700;color:var(--text-secondary);
}
.swap-pair .pill{
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 9px;border-radius:var(--r-sm);
    background:var(--gray-50);border:1px solid var(--border);
}
.swap-pair .arrow{color:var(--purple-500);font-size:12px;}
.swap-reason{
    font-size:11.5px;color:var(--text-tertiary);
    margin-top:7px;line-height:1.5;
}
.swap-note{
    font-size:11.5px;color:#92400e;
    background:#fffbeb;border:1px solid #fde68a;
    border-radius:var(--r-sm);padding:6px 9px;margin-top:7px;line-height:1.5;
}
.swap-card-foot{display:flex;align-items:center;gap:8px;margin-top:9px;}
.swap-card-time{font-size:10.5px;color:var(--text-muted);font-weight:700;margin-left:auto;}
.swap-withdraw{
    padding:5px 11px;border-radius:var(--r-sm);
    border:1px solid #fecaca;background:var(--surface);color:#dc2626;
    font-family:inherit;font-size:11px;font-weight:800;
    cursor:pointer;transition:all var(--dur) var(--ease-spring);
}
.swap-withdraw:hover{background:#fee2e2;transform:translateY(-1px);}

.swap-modal-body{padding:18px 20px;overflow-y:auto;}
.swap-vs{
    display:flex;align-items:center;justify-content:center;
    gap:12px;margin-bottom:18px;flex-wrap:wrap;
}
.swap-vs-side{text-align:center;min-width:0;flex:1;}
.swap-vs-avatar{
    width:48px;height:48px;margin:0 auto 7px;
    border-radius:15px;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:800;font-size:15px;
    background:linear-gradient(135deg,#38bdf8,#0284c7);
    box-shadow:0 4px 12px rgba(2,132,199,.26);
}
.swap-vs-side.me .swap-vs-avatar{
    background:linear-gradient(135deg,#34d399,#059669);
    box-shadow:0 4px 12px rgba(16,185,129,.26);
}
.swap-vs-name{
    font-size:12.5px;font-weight:800;color:var(--text-primary);
    max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    margin:0 auto;
}
.swap-vs-stall{font-size:11.5px;color:var(--text-tertiary);margin-top:2px;font-weight:600;}
.swap-vs-mid{display:flex;flex-direction:column;align-items:center;gap:4px;color:var(--purple-500);}
.swap-vs-mid i{font-size:17px;}
.swap-vs-mid span{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;}
.swap-field{margin-bottom:14px;}
.swap-field label{
    display:block;font-size:12px;font-weight:800;
    color:var(--text-secondary);margin-bottom:6px;
}
.swap-field label i{color:var(--purple-500);margin-right:4px;}
.swap-field input,.swap-field textarea{
    width:100%;padding:11px 13px;
    border:1px solid var(--border);border-radius:var(--r-md);
    font-family:inherit;font-size:13.5px;
    outline:none;background:var(--gray-50);color:var(--text-primary);
    transition:all var(--dur);
}
.swap-field textarea{min-height:80px;resize:vertical;line-height:1.5;}
.swap-field input:focus,.swap-field textarea:focus{
    border-color:var(--purple-200);
    background:var(--surface);
    box-shadow:0 0 0 4px rgba(168,85,247,.1);
}
.swap-field input[readonly]{
    background:var(--gray-100);color:var(--text-tertiary);
    cursor:not-allowed;
}
.swap-hint{
    display:flex;gap:9px;padding:11px 13px;
    border-radius:var(--r-md);
    background:var(--purple-50);border:1px solid var(--purple-200);
    font-size:12px;color:var(--purple-700);line-height:1.55;
}
.swap-hint i{font-size:14px;margin-top:1px;}
.swap-hint strong{font-weight:800;}

.swap-modal-foot{
    padding:14px 20px;
    padding-bottom:calc(14px + env(safe-area-inset-bottom,0));
    border-top:1px solid var(--border);
    display:flex;gap:9px;justify-content:flex-end;
    background:var(--surface);flex:0 0 auto;
}
.swap-modal-foot .swap-submit{
    display:inline-flex;align-items:center;gap:8px;
    padding:11px 20px;border-radius:var(--r-md);
    border:none;cursor:pointer;font-family:inherit;
    font-size:13.5px;font-weight:800;
    background:linear-gradient(135deg,#8b5cf6,#6d28d9);
    color:#fff;box-shadow:0 6px 18px rgba(168,85,247,.34);
    transition:all var(--dur) var(--ease-spring);
}
.swap-modal-foot .swap-submit:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(168,85,247,.48);}
.swap-modal-foot .swap-cancel{
    padding:11px 18px;border-radius:var(--r-md);
    border:none;cursor:pointer;font-family:inherit;
    font-size:13.5px;font-weight:800;
    background:var(--gray-100);color:var(--text-secondary);
    transition:all var(--dur) var(--ease-spring);
}
.swap-modal-foot .swap-cancel:hover{background:var(--gray-200);transform:translateY(-1px);}

/* ============================================================
   THEME MODAL
   ============================================================ */
.theme-modal-head{
    padding:20px 24px 14px;
    border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.theme-modal-head h3{
    font-size:17px;font-weight:800;color:var(--text-primary);
    display:flex;align-items:center;gap:10px;
}
.theme-modal-head h3 i{color:var(--brand-600);}
.theme-modal-close{
    width:32px;height:32px;border-radius:50%;
    background:var(--gray-50);border:none;cursor:pointer;
    color:var(--text-secondary);
    display:flex;align-items:center;justify-content:center;
    transition:all var(--dur-slow) var(--ease-spring);
}
.theme-modal-close:hover{background:var(--brand-100);color:var(--brand-700);transform:rotate(90deg);}

.theme-tabs{
    display:flex;gap:4px;padding:10px 20px 0;
    border-bottom:1px solid var(--border);
    overflow-x:auto;scrollbar-width:none;
}
.theme-tabs::-webkit-scrollbar{display:none;}
.theme-tab{
    padding:8px 14px;
    font-family:inherit;font-size:12.5px;font-weight:700;
    color:var(--text-tertiary);
    background:transparent;border:none;
    border-radius:var(--r-sm) var(--r-sm) 0 0;
    cursor:pointer;white-space:nowrap;
    transition:all var(--dur);margin-bottom:-1px;
}
.theme-tab:hover{color:var(--text-primary);background:var(--gray-50);}
.theme-tab.active{color:var(--brand-700);background:var(--brand-50);}

.theme-modal-body{padding:18px 20px;overflow-y:auto;flex:1;}
.theme-panel{display:none;}
.theme-panel.active{display:block;animation:fadeIn .25s var(--ease-out);}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px);}to{opacity:1;transform:translateY(0);}}

.theme-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}@media(max-width:520px){.theme-grid{grid-template-columns:repeat(3,1fr);}}
.theme-option{
    position:relative;
    border:2px solid transparent;
    border-radius:var(--r-md);
    overflow:hidden;cursor:pointer;
    background:var(--gray-50);padding:0;
    font-family:inherit;
    transition:all var(--dur-slow) var(--ease-spring);
}
.theme-option:hover{
    transform:translateY(-3px) scale(1.02);
    border-color:var(--brand-300);
    box-shadow:var(--sh-lg);
}
.theme-option.active{
    border-color:var(--brand-500);
    box-shadow:0 0 0 3px rgba(16,185,129,.18);
}
.theme-option.active::after{
    content:'\f00c';
    font-family:'Font Awesome 6 Free';font-weight:900;
    position:absolute;top:5px;right:5px;
    width:18px;height:18px;border-radius:50%;
    background:linear-gradient(135deg,#10b981,#047857);
    color:#fff;font-size:8px;
    display:flex;align-items:center;justify-content:center;
    z-index:3;
    box-shadow:0 2px 6px rgba(16,185,129,.4);
}
.theme-swatch{
    height:60px;width:100%;
    background-size:cover;background-position:center;
    position:relative;
    display:flex;align-items:flex-end;padding:8px;
}
.theme-swatch::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(180deg,transparent 40%,rgba(0,0,0,.22));
}
.theme-mini{position:relative;z-index:2;display:flex;flex-direction:column;gap:3px;width:100%;}
.theme-mini span{
    display:block;height:3px;border-radius:var(--r-full);
    background:rgba(255,255,255,.92);
    box-shadow:0 1px 3px rgba(0,0,0,.32);
}
.theme-mini .in{align-self:flex-start;width:45%;}
.theme-mini .out{align-self:flex-end;width:55%;}
.theme-label{
    display:block;padding:7px 5px 8px;
    font-size:10.5px;font-weight:700;
    color:var(--text-primary);text-align:center;
    background:var(--surface);
}
.theme-option.active .theme-label{color:var(--brand-700);}
.theme-upload{
    display:flex;align-items:center;justify-content:center;
    height:60px;
    background:repeating-linear-gradient(135deg,var(--gray-50) 0 10px,var(--border) 10px 20px);
    color:var(--text-muted);font-size:18px;
}
.color-picker-row{
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;padding:12px 14px;
    border-radius:var(--r-md);
    background:var(--gray-50);border:1px solid var(--border);
    margin-bottom:10px;
    transition:border-color var(--dur);
}
.color-picker-row:hover{border-color:var(--brand-300);}
.color-picker-row label{
    font-size:13px;font-weight:700;color:var(--text-primary);
    display:flex;align-items:center;gap:8px;
}
.color-picker-row label i{color:var(--brand-700);}
.color-picker-row input[type="color"]{
    width:52px;height:34px;
    border:1px solid var(--border);border-radius:var(--r-sm);
    cursor:pointer;padding:2px;background:var(--surface);
    transition:transform var(--dur) var(--ease-spring);
}
.color-picker-row input[type="color"]:hover{transform:scale(1.06);}
.theme-modal-footer{
    padding:14px 20px;
    padding-bottom:calc(14px + env(safe-area-inset-bottom,0));
    border-top:1px solid var(--border);
    display:flex;justify-content:space-between;align-items:center;gap:12px;
    flex-shrink:0;
}
.theme-action-btn{
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 16px;border-radius:var(--r-md);
    font-family:inherit;font-size:12.5px;font-weight:700;
    cursor:pointer;border:none;
    transition:all var(--dur) var(--ease-spring);
}
.theme-action-btn.reset{background:var(--gray-100);color:var(--text-secondary);}
.theme-action-btn.reset:hover{background:var(--gray-200);transform:translateY(-1px);}@media(max-width:960px){
    .chat-shell{border-radius:var(--r-md);}
    .thread-body{padding:16px 14px 10px;}
    .msg-group{max-width:82%;}
    .composer{padding:10px 12px;}
    .composer{padding-bottom:calc(10px + env(safe-area-inset-bottom,0));}
    .scroll-bottom-btn{bottom:80px;right:16px;width:40px;height:40px;}}@media(max-width:480px){
    .msg-group{max-width:88%;}
    .msg-bubble{font-size:14px;padding:8px 12px;}
    .thread-head{padding:10px 14px;gap:10px;}
    .thread-avatar{width:38px;height:38px;border-radius:12px;font-size:13px;}
    .thread-name{font-size:13.5px;}
    .conv-search-title{font-size:17px;}}

/* Reduced motion */
@media (prefers-reduced-motion:reduce){
    *,*::before,*::after{
        animation-duration:.01ms !important;
        animation-iteration-count:1 !important;
        transition-duration:.01ms !important;
        scroll-behavior:auto !important;
    }
}
    </style>
</head>
<body class="<?= $view === 'messages' ? 'chat-mode' : '' ?>">

<?php require __DIR__ . '/includes/vendor_sidebar.php'; ?>

<main class="main-content">
<?php if ($view === 'messages'): ?>

<?php if ($swapFlash): ?>
    <div class="flash-note <?= htmlspecialchars($swapFlash['type']) ?>">
        <i class="fas <?= $swapFlash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($swapFlash['msg']) ?></span>
    </div>
<?php endif; ?>

<div class="chat-shell <?= $vPeerId ? 'show-thread' : '' ?>">
    <section class="conv-list-panel">


        <div class="conv-search">
            <div class="conv-search-top">
                <span class="conv-search-title">Vendor Chats</span>
                <button type="button" class="new-chat-btn vendor" onclick="openNewVendorChat()" title="New vendor conversation"><i class="fas fa-pen-to-square"></i></button>
            </div>
            <form method="get" action="dashboard.php" class="conv-search-form">
                <input type="hidden" name="view" value="messages" />
                <?php if ($tab === 'vendors'): ?><input type="hidden" name="tab" value="vendors" /><?php endif; ?>
                <div class="conv-search-input">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search conversations…" autocomplete="off" />
                </div>
            </form>
        </div>

        <div class="conv-list">
            <?php
            $filteredVendor = $vendorConvs;
            if ($q !== '') {
                $ql = mb_strtolower($q);
                $filteredVendor = array_values(array_filter($vendorConvs, fn($c) =>
                    str_contains(mb_strtolower($c['peer_name']), $ql) ||
                    str_contains(mb_strtolower((string)$c['peer_biz']), $ql) ||
                    str_contains(mb_strtolower((string)$c['peer_stall']), $ql) ||
                    str_contains(mb_strtolower((string)$c['last_message_preview']), $ql)));
            }
            ?>
            <?php if (empty($filteredVendor)): ?>
                <div class="conv-empty vendor">
                    <i class="fas fa-store"></i>
                    <p>No vendor conversations yet.</p>
                    <button type="button" class="new-chat-btn vendor" style="width:auto;padding:11px 22px;border-radius:999px;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;font-size:13px;font-weight:800;gap:8px;display:inline-flex;align-items:center;" onclick="openNewVendorChat()">
                        <i class="fas fa-plus"></i> Message a vendor
                    </button>
                </div>
            <?php else: foreach ($filteredVendor as $c):
                $isActive = ((int)$c['peer_id'] === $vPeerId) ? ' active' : '';
                $unread   = (int)$c['unread'];
                $unreadCls= ($unread > 0 && !$isActive) ? ' unread' : '';
            ?>
                <a class="conv-item vendor-item<?= $isActive ?><?= $unreadCls ?>" href="dashboard.php?view=messages&amp;tab=vendors&amp;vpeer=<?= (int)$c['peer_id'] ?>">
                    <div class="conv-avatar vendor"><?= htmlspecialchars(ini_from($c['peer_name'])) ?></div>
                    <div class="conv-meta">
                        <div class="conv-name"><?= htmlspecialchars($c['peer_name']) ?></div>
                        <div class="conv-preview"><?= htmlspecialchars($c['last_message_preview'] ?: 'Say hi to a fellow vendor 👋') ?></div>
                    </div>
                    <div class="conv-side">
                        <div class="conv-time"><?= htmlspecialchars($c['last_message_at'] ? chat_list_time($c['last_message_at']) : '—') ?></div>
                        <?php if ($unread > 0 && !$isActive): ?><span class="conv-badge vendor"><?= $unread ?></span><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </section>

    <section class="thread-panel">

        <?php if ($vendorPeer && $activeVChatId): ?>
            <!-- VENDOR THREAD -->
            <div class="thread-head">
                <button class="thread-back" onclick="window.location='dashboard.php?view=messages&tab=vendors'" aria-label="Back to vendor list"><i class="fas fa-arrow-left"></i></button>
                <div class="thread-avatar vendor"><?= htmlspecialchars(ini_from($vendorPeer['name'])) ?></div>
                <div class="thread-info">
                    <div class="thread-name"><?= htmlspecialchars($vendorPeer['name']) ?></div>
                    <div class="thread-sub">
                        <span class="vendor-badge"><i class="fas fa-store"></i> Vendor</span>
                        <span><?= htmlspecialchars($vendorPeer['business_name'] ?: 'Independent vendor') ?></span>
                        <?php if (!empty($vendorPeer['stall_number'])): ?><span>· Stall <?= htmlspecialchars($vendorPeer['stall_number']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="thread-actions">
                    <?php if ($swapOpen): ?>
                        <button type="button" class="swap-btn" disabled title="A swap request is already awaiting approval"><i class="fas fa-hourglass-half"></i><span>Awaiting</span></button>
                    <?php else: ?>
                        <button type="button" class="swap-btn" onclick="openSwapModal()" title="Request a stall swap with this vendor"><i class="fas fa-right-left"></i><span>Swap</span></button>
                    <?php endif; ?>
                    <button type="button" class="icon-btn report-peer" id="reportPeerBtn"
                            data-vendor="<?= (int)$vendorPeer['id'] ?>"
                            data-name="<?= htmlspecialchars(($vendorPeer['name'] ?? 'This vendor') . ' — Stall ' . ($vendorPeer['stall_number'] ?: '—')) ?>"
                            title="Report this vendor to the market office"
                            aria-label="Report this vendor"><i class="fas fa-flag"></i></button>
                    <button type="button" class="icon-btn" id="themeBtn" title="Change chat theme" aria-label="Change chat theme"><i class="fas fa-palette"></i></button>
                </div>
            </div>

            <?php if (!empty($threadSwaps)): ?>
            <div class="swap-panel">
                <?php foreach ($threadSwaps as $s):
                    $meta  = $swapMeta[$s['status']] ?? ['label' => $s['status'], 'class' => 'pending', 'icon' => 'fa-store'];
                    $mine  = ((int)$s['requester_vendor_id'] === $vendorId);
                    $from  = $mine ? $s['requester_name'] : $s['target_name'];
                    $to    = $mine ? $s['target_name']   : $s['requester_name'];
                    $fromS = $mine ? $s['requester_stall'] : $s['target_stall'];
                    $toS   = $mine ? $s['target_stall']   : $s['requester_stall'];
                ?>
                    <div class="swap-card">
                        <div class="swap-card-top">
                            <div class="swap-card-title">
                                Stall swap <?= $mine ? 'you requested' : 'requested by ' . htmlspecialchars((string)$from) ?>
                                <small>#<?= (int)$s['id'] ?> · filed <?= htmlspecialchars(chat_list_time($s['created_at'])) ?></small>
                            </div>
                            <span class="swap-status <?= htmlspecialchars($meta['class']) ?>">
                                <i class="fas <?= htmlspecialchars($meta['icon']) ?>"></i><?= htmlspecialchars($meta['label']) ?>
                            </span>
                        </div>
                        <div class="swap-pair">
                            <span class="pill"><i class="fas fa-store"></i> <?= htmlspecialchars((string)$from) ?> — Stall <?= htmlspecialchars((string)$fromS) ?></span>
                            <i class="fas fa-arrow-right-long arrow"></i>
                            <span class="pill"><i class="fas fa-store"></i> <?= htmlspecialchars((string)$to) ?> — Stall <?= htmlspecialchars((string)$toS) ?></span>
                        </div>
                        <?php if (!empty($s['reason'])): ?>
                            <div class="swap-reason"><strong>Reason:</strong> <?= nl2br(htmlspecialchars((string)$s['reason'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($s['decision_note'])): ?>
                            <div class="swap-note"><i class="fas fa-comment-dots"></i> <?= nl2br(htmlspecialchars((string)$s['decision_note'])) ?></div>
                        <?php endif; ?>
                        <div class="swap-card-foot">
                            <?php if ($mine && $s['status'] === 'Pending'): ?>
                                <form method="post" action="dashboard.php" onsubmit="return confirm('Withdraw this stall swap request?');">
                                    <input type="hidden" name="action" value="cancel_stall_swap" />
                                    <input type="hidden" name="swap_id" value="<?= (int)$s['id'] ?>" />
                                    <input type="hidden" name="target_vendor_id" value="<?= (int)$vPeerId ?>" />
                                    <button type="submit" class="swap-withdraw"><i class="fas fa-rotate-left"></i> Withdraw</button>
                                </form>
                            <?php endif; ?>
                            <span class="swap-card-time">
                                <?php if ($s['status'] === 'Pending'): ?>
                                    Sent to market office · nothing changes yet
                                <?php elseif (!empty($s['decided_at'])): ?>
                                    Decided <?= htmlspecialchars(chat_list_time($s['decided_at'])) ?><?= !empty($s['decided_by_name']) ? ' by ' . htmlspecialchars((string)$s['decided_by_name']) : '' ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="thread-body" id="threadBody">
                <?php if (empty($vThreadMsgs)): ?>
                    <div class="day-sep">No messages yet</div>
                    <div style="text-align:center;padding:24px 12px;color:var(--text-tertiary);font-size:13.5px;line-height:1.6;">
                        Say hi to <strong style="color:var(--text-primary);"><?= htmlspecialchars($vendorPeer['name']) ?></strong> — you can discuss stall swaps, coordinate deliveries, or share tips.
                    </div>
                <?php else:
                    $groups = build_thread_groups($vThreadMsgs, 'sender_vendor_id', $vendorId);
                    foreach ($groups as $g):
                        $out = ($g['sender'] === 'out');
                ?>
                    <div class="day-sep"><?= htmlspecialchars($g['day']) ?></div>
                    <div class="msg-group <?= $out ? 'out' : 'in' ?>">
                        <?php foreach ($g['items'] as $m): $time = date('g:i A', strtotime($m['created_at'])); ?>
                            <div class="msg-row <?= $out ? 'out' : 'in' ?>">
                                <?php if ($out): ?>
                                    <div class="msg-meta"><?= htmlspecialchars($time) ?>
                                        <span class="read-tick"><i class="fas <?= ((int)$m['is_read']===1) ? 'fa-check-double' : 'fa-check' ?>"></i></span>
                                    </div>
                                <?php endif; ?>
                                <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
                                <?php if (!$out): ?><div class="msg-meta"><?= htmlspecialchars($time) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <button type="button" class="scroll-bottom-btn" id="scrollBottomBtn" aria-label="Scroll to latest messages"><i class="fas fa-arrow-down"></i></button>
            <form class="composer" method="post" action="dashboard.php" autocomplete="off">
                <input type="hidden" name="action" value="send_vendor_message" />
                <input type="hidden" name="conversation_id" value="<?= (int)$activeVChatId ?>" />
                <input type="hidden" name="peer_id" value="<?= (int)$vPeerId ?>" />
                <div class="composer-input-wrap">
                    <textarea name="body" id="msgInput" rows="1" placeholder="Message this vendor…" required></textarea>
                    <button type="submit" class="composer-send vendor" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>
                </div>
            </form>


        <?php else: ?>
            <div class="thread-empty">
                <div class="empty-icon vendor"><i class="fas fa-store"></i></div>
                <h3>Vendor-to-vendor chat</h3>
                <p>Pick a vendor from the left to start a conversation — coordinate deliveries, plan stall swaps, or share tips.</p>
                <button type="button" class="new-chat-btn vendor" style="width:auto;padding:12px 24px;border-radius:999px;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;margin-top:12px;font-size:13px;font-weight:800;gap:8px;display:inline-flex;align-items:center;" onclick="openNewVendorChat()">
                    <i class="fas fa-plus"></i> New Vendor Chat
                </button>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php else: ?>

<!-- DASHBOARD VIEW -->
<header class="top-header">
    <div class="top-header-left">
        <h1><i class="fas fa-gauge-high"></i> Dashboard</h1>
        <p>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
    </div>

    <div class="top-header-right">
        <div class="user-wrap" id="userWrap">
            <button type="button" class="header-icon-btn" onclick="toggleUser()" title="Account" aria-label="Account menu">
                <span class="header-avatar-slot" data-profile-avatar-display data-avatar-fallback="fas fa-user">
                    <?php if ($vendorAvatarUrl !== ''): ?>
                        <img class="header-avatar-img" src="<?= htmlspecialchars($vendorAvatarUrl) ?>" alt="" />
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </span>
            </button>
            <div class="user-dropdown">
                <div class="user-dropdown-head">
                    <div class="ud-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
                    <?php if ($vendor): ?><div class="ud-ref"><?= htmlspecialchars($refNo) ?></div><?php endif; ?>
                </div>
                <div class="user-dropdown-list">
                    <a href="php/edit_profile.php" class="user-dropdown-item"><i class="fas fa-user-pen"></i> Edit Profile</a>
                    <div class="user-dropdown-sep"></div>
                    <a href="php/logout.php" class="user-dropdown-item danger"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
                </div>
            </div>
        </div>

        <a href="php/edit_profile.php" class="header-action-btn green">
            <i class="fas fa-user-pen"></i> Edit Profile
        </a>
    </div>
</header>

<section class="welcome-banner">
    <div class="welcome-text">
        <span class="welcome-chip"><i class="fas fa-leaf"></i> Mallengke Vendor</span>
        <h2>Welcome back, <span><?= htmlspecialchars($vendor['first_name'] ?? $_SESSION['username']) ?></span>!</h2>
        <p>
            <?php if ($vendor && !empty($vendor['stall_number'])): ?>
                You're managing <strong>Stall <?= htmlspecialchars($vendor['stall_number']) ?></strong>
                in the <strong><?= htmlspecialchars($vendor['market_section']) ?></strong> section.
                Keep your QR passport handy and stay connected with the market.
            <?php elseif ($vendor): ?>
                You do not have a stall yet &mdash; only the market office assigns one.
                <a href="php/stall_map.php"><strong>Ask for a stall</strong></a> and we will let you know when it is decided.
            <?php else: ?>
                Register your stall to unlock your vendor dashboard, QR passport, and vendor chat.
            <?php endif; ?>
        </p>
    </div>
    <div class="welcome-art"><i class="fas fa-basket-shopping"></i></div>
</section>

<div class="stat-grid">
    <a class="stat-card" href="dashboard.php?view=messages&tab=vendors">
        <div class="stat-icon g2"><i class="fas fa-store"></i></div>
        <div>
            <div class="stat-num"><?= $unreadVendorChats ?></div>
            <div class="stat-lbl">Vendor chats</div>
        </div>
    </a>
</div>

<div class="container">
    <aside class="card profile-card">
        <?php if ($vendor): ?>
            <div class="avatar profile-avatar-trigger<?= $vendorAvatarUrl !== '' ? ' has-photo' : '' ?>"
                 data-profile-avatar
                 data-avatar-initials="<?= htmlspecialchars($initials) ?>"
                 data-avatar-endpoint="php/profile_upload.php"
                 data-avatar-token="<?= htmlspecialchars($vendorAvatarToken) ?>"
                 role="button" tabindex="0"
                 aria-label="Change profile photo" title="Change profile photo">
                <?php if ($vendorAvatarUrl !== ''): ?>
                    <img class="profile-avatar-img" src="<?= htmlspecialchars($vendorAvatarUrl) ?>" alt="" />
                <?php else: ?>
                    <span class="profile-avatar-initials"><?= htmlspecialchars($initials) ?></span>
                <?php endif; ?>
                <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
                <input type="file" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
            </div>
        <?php else: ?>
            <div class="avatar"><?= htmlspecialchars($initials) ?></div>
        <?php endif; ?>
        <h2><?= $vendor ? htmlspecialchars($fullName) : htmlspecialchars($_SESSION['username']) ?></h2>
        <p class="email"><?= htmlspecialchars($_SESSION['email']) ?></p>
        <?php if ($vendor): ?>
            <span class="ref-badge"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($refNo) ?></span>
            <div class="info-list">
                <div class="row"><span class="label">Stall</span><span class="value"><?= htmlspecialchars($vendor['stall_number'] ?: 'Not assigned yet') ?></span></div>
                <div class="row"><span class="label">Section</span><span class="value"><?= htmlspecialchars($vendor['market_section']) ?></span></div>
                <div class="row"><span class="label">Products</span><span class="value"><?= htmlspecialchars($vendor['product_type']) ?></span></div>
                <div class="row"><span class="label">Registered</span><span class="value"><?= date('M j, Y', strtotime($vendor['created_at'])) ?></span></div>
            </div>
            <a href="php/edit_profile.php" class="edit-cta"><i class="fas fa-user-pen"></i> Edit Profile</a>
        <?php endif; ?>
    </aside>

    <section class="main-col">
        <?php if (!$vendor): ?>
            <div class="info-banner">
                <i class="fas fa-store-slash big"></i>
                <h3>No stall application on file</h3>
                <p>You haven't submitted a stall application yet. Register as a vendor to avail a stall at Mallengke, Tupi, South Cotabato.</p>
                <a href="php/vendor_register.php"><i class="fas fa-user-plus"></i> Register as Vendor</a>
            </div>
        <?php else: ?>

            <?php if ($qrToken !== ''): ?>
            <div class="card">
                <div class="section-head">
                    <h3><i class="fas fa-qrcode"></i> Smart Vendor QR Passport</h3>
                    <a href="php/vendor_passport.php?t=<?= htmlspecialchars($qrToken) ?>" target="_blank">Open full page <i class="fas fa-arrow-up-right-from-square"></i></a>
                </div>
                <div class="qr-section">
                    <div class="qr-frame">
                        <div class="qr-box" id="qrcode"></div>
                        <div class="qr-label">Mallengke Vendor ID</div>
                        <div class="qr-ref"><?= htmlspecialchars($refNo) ?></div>
                    </div>
                    <div>
                        <h3><i class="fas fa-id-card"></i> Show this at the market office</h3>
                        <p>Your digital ID. Print it, download as PNG, or export as PDF for verification and stall identification.</p>
                        <div class="qr-actions">
                            <button type="button" class="link-green" onclick="downloadQr()"><i class="fas fa-download"></i> Download QR</button>
                            <button type="button" class="link-passport" id="download-passport-btn" onclick="downloadPassportPdf()"><i class="fas fa-file-pdf"></i> Download PDF</button>
                            <a href="php/vendor_passport.php?t=<?= htmlspecialchars($qrToken) ?>" target="_blank" class="link-primary"><i class="fas fa-external-link-alt"></i> Open Passport</a>
                            <a href="php/stall_map.php" class="link-secondary"><i class="fas fa-map-location-dot"></i> Stall Map</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="section-head">
                    <h3><i class="fas fa-comments"></i> Recent Vendor Messages</h3>
                    <a href="dashboard.php?view=messages&tab=vendors">Open Messages <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="recent-list">
                    <?php
                    $recentVendor = [];
                    if ($vendorId) {
                        $stmt = $conn->prepare(
                            "SELECT c.id, c.last_message_at, c.last_message_preview,
                                    CASE WHEN c.vendor_a_id = ? THEN c.unread_a ELSE c.unread_b END AS unread,
                                    v.id AS peer_id,
                                    TRIM(CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name)) AS peer_name,
                                    v.business_name AS peer_biz
                               FROM vendor_conversations c
                               JOIN vendors v ON v.id = CASE WHEN c.vendor_a_id = ? THEN c.vendor_b_id ELSE c.vendor_a_id END
                              WHERE c.vendor_a_id = ? OR c.vendor_b_id = ?
                              ORDER BY c.last_message_at DESC
                              LIMIT 5");
                        $stmt->bind_param("iiii", $vendorId, $vendorId, $vendorId, $vendorId);
                        $stmt->execute();
                        $recentVendor = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                    }
                    ?>
                    <?php if (empty($recentVendor)): ?>
                        <div class="empty-mini">
                            <i class="fas fa-inbox"></i>
                            No vendor conversations yet. Other vendors will appear here when they message you.
                        </div>
                    <?php else: foreach ($recentVendor as $c):
                        $unread = (int)$c['unread'];
                    ?>
                        <a class="recent-item" href="dashboard.php?view=messages&tab=vendors&vpeer=<?= (int)$c['peer_id'] ?>">
                            <div class="recent-avatar vendor"><?= htmlspecialchars(ini_from($c['peer_name'])) ?></div>
                            <div class="recent-meta">
                                <div class="recent-name"><?= htmlspecialchars($c['peer_name']) ?></div>
                                <div class="recent-preview"><?= htmlspecialchars($c['last_message_preview'] ?: 'No messages yet') ?></div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                                <span class="recent-time"><?= htmlspecialchars($c['last_message_at'] ? chat_list_time($c['last_message_at']) : '—') ?></span>
                                <?php if ($unread > 0): ?><span class="recent-badge vendor"><?= $unread ?></span><?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <?php if (!empty($vendor['remarks'])): ?>
                <div class="remarks-box">
                    <h4><i class="fas fa-note-sticky"></i> Remarks from Market Office</h4>
                    <p><?= htmlspecialchars($vendor['remarks']) ?></p>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
</main>

<?php require __DIR__ . '/includes/report_modal.php'; ?>

<?php if ($vendor): ?>
<div id="passport-template" aria-hidden="true">
    <div class="pt-card">
        <div class="pt-top">
            <div class="pt-avatar"><?= htmlspecialchars($initials) ?></div>
            <div>
                <div class="pt-name"><?= htmlspecialchars($fullName) ?></div>
                <div class="pt-ref"><?= htmlspecialchars($refNo) ?></div>
                <?php if (!empty($vendor['business_name'])): ?><div class="pt-biz"><?= htmlspecialchars($vendor['business_name']) ?></div><?php endif; ?>
            </div>
            <div class="pt-stamp">MALLENGKE<br>VERIFIED</div>
        </div>
        <div class="pt-body">
            <div class="pt-section"><h4>Stall Location</h4>
                <div class="pt-row"><span class="pt-label">Stall Number</span><span class="pt-value"><?= htmlspecialchars($vendor['stall_number'] ?: 'Not assigned yet') ?></span></div>
                <div class="pt-row"><span class="pt-label">Market Section</span><span class="pt-value"><?= htmlspecialchars($vendor['market_section']) ?></span></div>
                <div class="pt-row"><span class="pt-label">Market</span><span class="pt-value">Mallengke, Poblacion</span></div>
                <div class="pt-row"><span class="pt-label">Municipality</span><span class="pt-value">Tupi, South Cotabato</span></div>
            </div>
            <div class="pt-section"><h4>Products</h4>
                <div class="pt-row"><span class="pt-label">Main Products</span><span class="pt-value"><?= htmlspecialchars($vendor['product_type']) ?></span></div>
                <div class="pt-row"><span class="pt-label">Years in Business</span><span class="pt-value"><?= (int)$vendor['years_in_business'] ?> year(s)</span></div>
                <div class="pt-row"><span class="pt-label">Overall Status</span><span class="pt-value"><span class="pt-badge pt-badge-<?= strtolower($vendor['status']) ?>"><?= htmlspecialchars($vendor['status']) ?></span></span></div>
            </div>
            <div class="pt-section"><h4>Permit Status</h4>
                <div class="pt-row"><span class="pt-label">Permit</span><span class="pt-value"><?= htmlspecialchars($vendor['permit_status'] ?? 'Pending') ?></span></div>
                <div class="pt-row"><span class="pt-label">Valid Until</span><span class="pt-value"><?= !empty($vendor['permit_expiry']) ? date('M j, Y', strtotime($vendor['permit_expiry'])) : '—' ?></span></div>
            </div>
            <div class="pt-section"><h4>Payment Status</h4>
                <div class="pt-row"><span class="pt-label">Payment</span><span class="pt-value"><?= htmlspecialchars($vendor['payment_status'] ?? 'Unpaid') ?></span></div>
                <div class="pt-row"><span class="pt-label">Next Due</span><span class="pt-value"><?= !empty($vendor['payment_due']) ? date('M j, Y', strtotime($vendor['payment_due'])) : '—' ?></span></div>
            </div>
        </div>
        <div class="pt-footer">
            <span>Registered: <strong><?= date('M j, Y', strtotime($vendor['created_at'])) ?></strong></span>
            <span>Scanned via Smart QR Passport</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- NEW VENDOR CHAT MODAL -->
<div class="newchat-modal" id="newVendorChatModal" onclick="closeNewVendorChat(event)">
    <div class="newchat-modal-inner" onclick="event.stopPropagation()">
        <div class="newchat-head vendor">
            <h3><i class="fas fa-store"></i> Message a Vendor</h3>
            <button type="button" class="newchat-close" onclick="closeNewVendorChat()" aria-label="Close"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="newchat-search">
            <div class="newchat-search-input">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" id="newVendorChatSearch" placeholder="Search vendors by name, business, or stall…" autocomplete="off" oninput="filterNewVendorChatList()" />
            </div>
        </div>
        <div class="newchat-list" id="newVendorChatList">
            <?php foreach ($allVendors as $v):
                $vn  = $v['name'];
                $sub = trim(($v['business_name'] ?: 'Independent vendor') . ($v['stall_number'] ? ' · Stall ' . $v['stall_number'] : ''));
            ?>
                <button type="button" class="newchat-item vendor" data-id="<?= (int)$v['id'] ?>" data-name="<?= htmlspecialchars($vn) ?>" data-search="<?= htmlspecialchars(mb_strtolower($vn . ' ' . $sub . ' ' . $v['market_section'])) ?>" onclick="pickVendor(this)">
                    <div class="newchat-avatar vendor"><?= htmlspecialchars(ini_from($vn)) ?></div>
                    <div class="newchat-item-meta">
                        <div class="newchat-item-name"><?= htmlspecialchars($vn) ?></div>
                        <div class="newchat-item-sub"><?= htmlspecialchars($sub) ?></div>
                    </div>
                </button>
            <?php endforeach; ?>
            <?php if (empty($allVendors)): ?>
                <div class="newchat-empty"><i class="fas fa-store-slash"></i><p>No other vendors yet.</p></div>
            <?php endif; ?>
        </div>
        <form class="newchat-compose" method="post" action="dashboard.php" id="newVendorChatForm" onsubmit="return validateNewVendorChat()">
            <input type="hidden" name="action" value="send_vendor_message" />
            <input type="hidden" name="conversation_id" value="" />
            <input type="hidden" name="peer_id" id="newVendorChatPeerId" value="" />
            <textarea name="body" id="newVendorChatMessage" rows="2" placeholder="Say hi, discuss a stall swap, coordinate a delivery…"></textarea>
            <div class="newchat-actions">
                <button type="button" class="newchat-btn cancel" onclick="closeNewVendorChat()">Cancel</button>
                <button type="submit" class="newchat-btn primary vendor" id="newVendorChatStartBtn" disabled>
                    <i class="fas fa-paper-plane"></i> Start Conversation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ STALL SWAP REQUEST MODAL ============ -->
<div class="swap-modal" id="swapModal" onclick="closeSwapModal(event)">
    <div class="swap-modal-inner" onclick="event.stopPropagation()">
        <div class="swap-modal-head">
            <div>
                <h3><i class="fas fa-right-left"></i> Request a Stall Swap</h3>
                <p>Trade stalls with <?= htmlspecialchars($vendorPeer['name'] ?? 'this vendor') ?> — the market office approves before anything moves.</p>
            </div>
            <button type="button" class="newchat-close" onclick="closeSwapModal()" aria-label="Close"><i class="fas fa-xmark"></i></button>
        </div>

        <form method="post" action="dashboard.php" id="swapForm">
            <div class="swap-modal-body">
                <input type="hidden" name="action" value="request_stall_swap" />
                <input type="hidden" name="target_vendor_id" value="<?= (int)$vPeerId ?>" />

                <div class="swap-vs">
                    <div class="swap-vs-side me">
                        <div class="swap-vs-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="swap-vs-name"><?= htmlspecialchars($fullName ?: 'You') ?></div>
                        <div class="swap-vs-stall">Stall <?= htmlspecialchars((string)($vendor['stall_number'] ?? '—')) ?></div>
                    </div>
                    <div class="swap-vs-mid">
                        <i class="fas fa-right-left"></i>
                        <span>swap</span>
                    </div>
                    <div class="swap-vs-side">
                        <div class="swap-vs-avatar"><?= htmlspecialchars(ini_from($vendorPeer['name'] ?? 'V')) ?></div>
                        <div class="swap-vs-name"><?= htmlspecialchars($vendorPeer['name'] ?? '—') ?></div>
                        <div class="swap-vs-stall">Stall <?= htmlspecialchars((string)($vendorPeer['stall_number'] ?? '—')) ?></div>
                    </div>
                </div>

                <div class="swap-field">
                    <label><i class="fas fa-store"></i> Your stall right now</label>
                    <input type="text" value="<?= htmlspecialchars((string)($vendor['stall_number'] ?? '')) ?>" readonly />
                </div>

                <div class="swap-field">
                    <label for="swapReason">Why do you want to swap? <span style="color:var(--text-muted);font-weight:600;">optional</span></label>
                    <textarea name="reason" id="swapReason" maxlength="500"
                              placeholder="e.g. My delivery truck cannot reach Stall B-12 anymore, and my neighbor needs a front-row spot near the entrance…"></textarea>
                </div>

                <div class="swap-hint">
                    <i class="fas fa-circle-info"></i>
                    <span>
                        <strong>How it works:</strong> your request goes to the market office for review.
                        Nothing changes until it is approved. You and your neighbor are notified
                        the moment there is a decision, and you can withdraw the request any time
                        while it is still pending.
                    </span>
                </div>
            </div>

            <div class="swap-modal-foot">
                <button type="button" class="swap-cancel" onclick="closeSwapModal()">Cancel</button>
                <button type="submit" class="swap-submit"><i class="fas fa-paper-plane"></i> Send Swap Request</button>
            </div>
        </form>
    </div>
</div>

<!-- THEME MODAL -->
<div class="theme-modal" id="themeModal" onclick="ChatTheme.close(event)">
    <div class="theme-modal-inner" onclick="event.stopPropagation()">
        <div class="theme-modal-head">
            <h3><i class="fas fa-palette"></i> Chat theme</h3>
            <button type="button" class="theme-modal-close" onclick="ChatTheme.close()" aria-label="Close"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="theme-tabs">
            <button type="button" class="theme-tab active" data-tab="color">Colors</button>
            <button type="button" class="theme-tab" data-tab="gradient">Gradients</button>
            <button type="button" class="theme-tab" data-tab="pattern">Patterns</button>
            <button type="button" class="theme-tab" data-tab="image">Image</button>
        </div>
        <div class="theme-modal-body">
            <div class="theme-panel active" data-panel="color">
                <div class="theme-grid" id="grid-color"></div>
                <div class="color-picker-row">
                    <label for="customColorInput"><i class="fas fa-eye-dropper"></i> Custom color</label>
                    <input type="color" id="customColorInput" value="#f1f5f9" />
                </div>
            </div>
            <div class="theme-panel" data-panel="gradient"><div class="theme-grid" id="grid-gradient"></div></div>
            <div class="theme-panel" data-panel="pattern"><div class="theme-grid" id="grid-pattern"></div></div>
            <div class="theme-panel" data-panel="image">
                <div class="theme-grid" style="grid-template-columns:1fr 1fr;">
                    <button type="button" class="theme-option" id="uploadBtn">
                        <div class="theme-upload"><i class="fas fa-cloud-arrow-up"></i></div>
                        <span class="theme-label">Upload image</span>
                    </button>
                    <button type="button" class="theme-option" id="customImgTile" style="display:none;">
                        <div class="theme-swatch" id="customImgSwatch" style="background:#d1fae5;">
                            <div class="theme-mini"><span class="in"></span><span class="out"></span></div>
                        </div>
                        <span class="theme-label">Your image</span>
                    </button>
                </div>
                <input type="file" id="themeImageInput" accept="image/*" hidden />
            </div>
        </div>
        <div class="theme-modal-footer">
            <span style="font-size:12px;color:var(--text-tertiary);">Theme saved to this browser</span>
            <button type="button" class="theme-action-btn reset" id="resetThemeBtn"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const b = document.getElementById('sidebarBackdrop');
    const open = s.classList.toggle('open');
    b.classList.toggle('show', open);
}

/* notifications + user dropdowns */
function toggleNotif() {
    document.getElementById('notifWrap')?.classList.toggle('open');
    document.getElementById('userWrap')?.classList.remove('open');
}
function toggleUser() {
    document.getElementById('userWrap')?.classList.toggle('open');
    document.getElementById('notifWrap')?.classList.remove('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('#notifWrap')) document.getElementById('notifWrap')?.classList.remove('open');
    if (!e.target.closest('#userWrap'))  document.getElementById('userWrap')?.classList.remove('open');
});

/* NEW VENDOR CHAT MODAL */
function openNewVendorChat() {
    document.getElementById('newVendorChatModal').classList.add('open');
    document.getElementById('newVendorChatSearch').value = '';
    document.getElementById('newVendorChatMessage').value = '';
    document.getElementById('newVendorChatPeerId').value = '';
    document.getElementById('newVendorChatStartBtn').disabled = true;
    document.querySelectorAll('#newVendorChatList .newchat-item').forEach(el => el.classList.remove('selected'));
    filterNewVendorChatList();
    setTimeout(() => document.getElementById('newVendorChatSearch').focus(), 120);
}
function closeNewVendorChat(e) {
    if (e && e.target.id !== 'newVendorChatModal') return;
    document.getElementById('newVendorChatModal').classList.remove('open');
}
function filterNewVendorChatList() {
    const q = document.getElementById('newVendorChatSearch').value.trim().toLowerCase();
    document.querySelectorAll('#newVendorChatList .newchat-item').forEach(el => {
        el.style.display = (!q || (el.dataset.search || '').includes(q)) ? '' : 'none';
    });
}
function pickVendor(btn) {
    document.querySelectorAll('#newVendorChatList .newchat-item').forEach(el => el.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('newVendorChatPeerId').value = btn.dataset.id;
    document.getElementById('newVendorChatStartBtn').disabled = false;
    document.getElementById('newVendorChatMessage').focus();
}
function validateNewVendorChat() {
    if (!document.getElementById('newVendorChatPeerId').value) {
        alert('Please select a vendor to message.');
        return false;
    }
    return true;
}

/* STALL SWAP MODAL */
function openSwapModal() {
    const m = document.getElementById('swapModal');
    if (!m) return;
    m.classList.add('open');
    const ta = document.getElementById('swapReason');
    if (ta) ta.value = '';
    setTimeout(() => ta && ta.focus(), 120);
}
function closeSwapModal(e) {
    if (e && e.target.id !== 'swapModal') return;
    document.getElementById('swapModal')?.classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const m2 = document.getElementById('newVendorChatModal');
        const m3 = document.getElementById('swapModal');
        if (m2 && m2.classList.contains('open')) m2.classList.remove('open');
        if (m3 && m3.classList.contains('open')) m3.classList.remove('open');
    }
});

/* chat scroll & composer */
(function() {
    const b   = document.getElementById('threadBody');
    const btn = document.getElementById('scrollBottomBtn');
    if (!b) return;
    const NEAR = 120;
    const atBottom = () => (b.scrollHeight - b.scrollTop - b.clientHeight) <= NEAR;
    const jumpToBottom   = () => { b.scrollTop = b.scrollHeight; };
    const smoothToBottom = () => { b.scrollTo({ top: b.scrollHeight, behavior: 'smooth' }); };
    jumpToBottom();
    requestAnimationFrame(jumpToBottom);
    window.addEventListener('load', jumpToBottom);
    if (btn) {
        const update = () => btn.classList.toggle('show', !atBottom());
        b.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
        btn.addEventListener('click', smoothToBottom);
    }
    const form = b.parentElement?.querySelector('.composer');
    if (form) form.addEventListener('submit', () => sessionStorage.setItem('chat_scroll_bottom', atBottom() ? '1' : '0'));
    if (sessionStorage.getItem('chat_scroll_bottom') === '1') {
        sessionStorage.removeItem('chat_scroll_bottom');
        requestAnimationFrame(() => smoothToBottom());
    }
})();
(function() {
    const ta = document.getElementById('msgInput'); if (!ta) return;
    ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; });
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ta.closest('form').submit(); }});
    ta.focus();
})();

/* ============================================================
   Theme engine
   ============================================================ */
const ChatTheme = (function() {
    const THEME_KEY = 'mallengke_chat_theme_v3';
    const IMAGE_KEY = 'mallengke_chat_image_v3';
    const COLOR_KEY = 'mallengke_chat_custom_color_v3';
    const root = document.documentElement;

    const COLORS = [
        { key:'sage', label:'Sage', bg:'#f1f5f9' },
        { key:'white', label:'White', bg:'#ffffff' },
        { key:'mint', label:'Mint', bg:'#d1fae5' },
        { key:'leaf', label:'Leaf', bg:'#ecfdf5' },
        { key:'sand', label:'Sand', bg:'#fdf7ec' },
        { key:'sky', label:'Sky', bg:'#eff6ff' },
        { key:'forest', label:'Forest', bg:'#0a5c4a' },
        { key:'pine', label:'Pine', bg:'#064e3b' },
        { key:'charcoal', label:'Charcoal', bg:'#1f2937' },
        { key:'midnight', label:'Midnight', bg:'#0f172a' },
        { key:'slate', label:'Slate', bg:'#475569' },
        { key:'graphite', label:'Graphite', bg:'#111827' }
    ];
    const GRADIENTS = [
        { key:'g-emerald', label:'Emerald', bg:'linear-gradient(135deg,#d1fae5,#6ee7b7)' },
        { key:'g-forest', label:'Forest', bg:'linear-gradient(135deg,#0a5c4a,#10b981)' },
        { key:'g-mint', label:'Mint', bg:'linear-gradient(135deg,#ecfdf5,#a7f3d0)' },
        { key:'g-sunrise', label:'Sunrise', bg:'linear-gradient(135deg,#fef3c7,#6ee7b7)' },
        { key:'g-ocean', label:'Ocean', bg:'linear-gradient(135deg,#a1c4fd,#c2e9fb)' },
        { key:'g-aurora', label:'Aurora', bg:'linear-gradient(135deg,#a8edea,#fed6e3)' },
        { key:'g-sage', label:'Sage', bg:'linear-gradient(135deg,#ecfdf5,#d1fae5)' },
        { key:'g-lime', label:'Lime', bg:'linear-gradient(135deg,#d4fc79,#a7f3d0)' },
        { key:'g-rose', label:'Rose Gold', bg:'linear-gradient(135deg,#f6d365,#fda085)' },
        { key:'g-plum', label:'Plum', bg:'linear-gradient(135deg,#6d5aa8,#eaafc8)' },
        { key:'g-mid', label:'Midnight', bg:'linear-gradient(135deg,#232526,#414345)' },
        { key:'g-deep', label:'Deep Sea', bg:'linear-gradient(135deg,#022c22,#0f766e)' }
    ];
    const PATTERNS = [
        { key:'p-dots', label:'Dots', bg:"radial-gradient(#a7f3d0 1px, transparent 1.5px) 0 0 / 20px 20px, #f8fafc" },
        { key:'p-grid', label:'Grid', bg:"linear-gradient(#d1fae5 1px,transparent 1px) 0 0/24px 24px, linear-gradient(90deg,#d1fae5 1px,transparent 1px) 0 0/24px 24px, #f8fafc" },
        { key:'p-diag', label:'Diagonal', bg:"repeating-linear-gradient(45deg,#ecfdf5 0 12px,#d1fae5 12px 24px)" },
        { key:'p-waves', label:'Waves', bg:"repeating-radial-gradient(circle at 0 0,#ecfdf5 0 12px,#d1fae5 12px 24px)" },
        { key:'p-chev', label:'Chevron', bg:"repeating-linear-gradient(135deg,#ecfdf5 0 10px,#d1fae5 10px 20px)" },
        { key:'p-stripe', label:'Stripes', bg:"repeating-linear-gradient(90deg,#f8fafc 0 20px,#d1fae5 20px 40px)" }
    ];

    const isDark = hex => {
        const r=parseInt(hex.substr(1,2),16),g=parseInt(hex.substr(3,2),16),b=parseInt(hex.substr(5,2),16);
        return (0.299*r+0.587*g+0.114*b)/255 < 0.55;
    };

    function palette(dark) {
        return {
            inBg: dark ? 'rgba(255,255,255,.12)' : '#ffffff',
            inText: dark ? '#ffffff' : '#0f172a',
            inMeta: dark ? 'rgba(255,255,255,.6)' : 'rgba(15,23,42,.42)',
            inShadow: dark ? 'none' : '0 1px 2px rgba(15,23,42,.06)',
            outBg: dark ? '#10b981' : 'linear-gradient(135deg,#10b981,#047857)',
            outText: '#ffffff',
            outMeta: 'rgba(255,255,255,.88)',
            outShadow: dark ? 'none' : '0 2px 8px rgba(4,120,87,.24)',
            dayBg: dark ? 'rgba(255,255,255,.08)' : 'rgba(6,78,59,.08)',
            dayTx: dark ? 'rgba(255,255,255,.65)' : '#047857'
        };
    }

    function applyTheme({ bgValue, imageUrl, dark }) {
        if (imageUrl) {
            root.style.setProperty('--chat-bg', '#0a5c4a');
            root.style.setProperty('--chat-bg-image', `url("${imageUrl}")`);
        } else {
            root.style.setProperty('--chat-bg', bgValue);
            root.style.setProperty('--chat-bg-image', 'none');
        }
        const p = palette(dark);
        root.style.setProperty('--bubble-in-bg',      p.inBg);
        root.style.setProperty('--bubble-in-text',    p.inText);
        root.style.setProperty('--bubble-in-meta',    p.inMeta);
        root.style.setProperty('--bubble-in-shadow',  p.inShadow);
        root.style.setProperty('--bubble-out-bg',     p.outBg);
        root.style.setProperty('--bubble-out-text',   p.outText);
        root.style.setProperty('--bubble-out-meta',   p.outMeta);
        root.style.setProperty('--bubble-out-shadow', p.outShadow);
        root.style.setProperty('--day-sep-bg',        p.dayBg);
        root.style.setProperty('--day-sep-color',     p.dayTx);
    }

    const tileHTML = (t, type) => `<button type="button" class="theme-option" data-type="${type}" data-key="${t.key}"><div class="theme-swatch" style="background:${t.bg};"><div class="theme-mini"><span class="in"></span><span class="out"></span></div></div><span class="theme-label">${t.label}</span></button>`;

    const buildGrids = () => {
        document.getElementById('grid-color').innerHTML = COLORS.map(t => tileHTML(t, 'color')).join('');
        document.getElementById('grid-gradient').innerHTML = GRADIENTS.map(t => tileHTML(t, 'gradient')).join('');
        document.getElementById('grid-pattern').innerHTML = PATTERNS.map(t => tileHTML(t, 'pattern')).join('');
    };
    const markActive = (type, key) => document.querySelectorAll('.theme-option').forEach(el => el.classList.toggle('active', el.dataset.type === type && el.dataset.key === key));
    const save = (type, key) => localStorage.setItem(THEME_KEY, JSON.stringify({ type, key }));

    function load() {
        applyTheme({ bgValue:'#f1f5f9', dark:false });
        markActive('color', 'sage');
        let saved;
        try { saved = JSON.parse(localStorage.getItem(THEME_KEY) || '{}'); } catch(e) { saved = {}; }
        const img = localStorage.getItem(IMAGE_KEY);

        if (saved.type === 'image' && img) {
            applyTheme({ imageUrl: img, dark: true });
            document.getElementById('customImgTile').classList.add('active');
            document.getElementById('customImgSwatch').style.background = `url("${img}") center/cover`;
            document.getElementById('customImgTile').style.display = '';
            return;
        }
        if (saved.type === 'custom-color') {
            const hex = localStorage.getItem(COLOR_KEY) || '#f1f5f9';
            applyTheme({ bgValue: hex, dark: isDark(hex) });
            document.getElementById('customColorInput').value = hex;
            return;
        }
        if (saved.type === 'color') {
            const t = COLORS.find(x => x.key === saved.key);
            if (t) { applyTheme({ bgValue: t.bg, dark: isDark(t.bg) }); markActive('color', t.key); return; }
        }
        if (saved.type === 'gradient') {
            const t = GRADIENTS.find(x => x.key === saved.key);
            if (t) { applyTheme({ bgValue: t.bg, dark: t.key === 'g-mid' || t.key === 'g-forest' || t.key === 'g-deep' }); markActive('gradient', t.key); return; }
        }
        if (saved.type === 'pattern') {
            const t = PATTERNS.find(x => x.key === saved.key);
            if (t) { applyTheme({ bgValue: t.bg, dark: false }); markActive('pattern', t.key); return; }
        }
        if (img) {
            document.getElementById('customImgSwatch').style.background = `url("${img}") center/cover`;
            document.getElementById('customImgTile').style.display = '';
        }
    }

    function wire() {
        buildGrids();

        document.querySelectorAll('.theme-tab').forEach(tab => tab.addEventListener('click', () => {
            document.querySelectorAll('.theme-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.theme-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            document.querySelector('.theme-panel[data-panel="' + tab.dataset.tab + '"]').classList.add('active');
        }));

        document.querySelectorAll('.theme-grid').forEach(grid => grid.addEventListener('click', e => {
            const btn = e.target.closest('.theme-option');
            if (!btn || !btn.dataset.key) return;
            const type = btn.dataset.type, key = btn.dataset.key;
            let t;
            if (type === 'color') t = COLORS.find(x => x.key === key);
            if (type === 'gradient') t = GRADIENTS.find(x => x.key === key);
            if (type === 'pattern') t = PATTERNS.find(x => x.key === key);
            if (!t) return;
            const dark = type === 'color' ? isDark(t.bg) : (key === 'g-mid' || key === 'g-forest' || key === 'g-deep');
            applyTheme({ bgValue: t.bg, dark });
            save(type, key);
            markActive(type, key);
            document.getElementById('customImgTile').classList.remove('active');
        }));

        document.getElementById('customColorInput').addEventListener('input', e => {
            const hex = e.target.value;
            applyTheme({ bgValue: hex, dark: isDark(hex) });
            localStorage.setItem(COLOR_KEY, hex);
            localStorage.setItem(THEME_KEY, JSON.stringify({ type:'custom-color', key: hex }));
            document.querySelectorAll('.theme-option').forEach(el => el.classList.remove('active'));
        });

        document.getElementById('uploadBtn').addEventListener('click', () => document.getElementById('themeImageInput').click());

        document.getElementById('themeImageInput').addEventListener('change', ev => {
            const file = ev.target.files && ev.target.files[0];
            if (!file) return;
            if (file.size > 2*1024*1024) { alert('Please choose an image smaller than 2 MB.'); ev.target.value = ''; return; }
            const r = new FileReader();
            r.onload = e => {
                try {
                    localStorage.setItem(IMAGE_KEY, e.target.result);
                    localStorage.setItem(THEME_KEY, JSON.stringify({ type:'image' }));
                    applyTheme({ imageUrl: e.target.result, dark: true });
                    document.querySelectorAll('.theme-option').forEach(el => el.classList.remove('active'));
                    document.getElementById('customImgTile').classList.add('active');
                    document.getElementById('customImgSwatch').style.background = `url("${e.target.result}") center/cover`;
                    document.getElementById('customImgTile').style.display = '';
                } catch (err) { alert('That image is too large to store.'); }
            };
            r.readAsDataURL(file);
        });

        document.getElementById('customImgTile').addEventListener('click', () => {
            const img = localStorage.getItem(IMAGE_KEY);
            if (!img) return;
            applyTheme({ imageUrl: img, dark: true });
            localStorage.setItem(THEME_KEY, JSON.stringify({ type:'image' }));
            document.querySelectorAll('.theme-option').forEach(el => el.classList.remove('active'));
            document.getElementById('customImgTile').classList.add('active');
        });

        document.getElementById('resetThemeBtn').addEventListener('click', () => {
            localStorage.removeItem(THEME_KEY);
            localStorage.removeItem(IMAGE_KEY);
            localStorage.removeItem(COLOR_KEY);
            document.getElementById('customImgTile').style.display = 'none';
            load();
        });
    }

    return {
        init() { wire(); load(); },
        open() { document.getElementById('themeModal').classList.add('open'); },
        close(e) { if (e && e.target.id !== 'themeModal') return; document.getElementById('themeModal').classList.remove('open'); }
    };
})();

(function() { ChatTheme.init(); const tb = document.getElementById('themeBtn'); if (tb) tb.addEventListener('click', ChatTheme.open); })();

/* The report button in a vendor thread opens the shared modal. */
(function () {
    const btn = document.getElementById('reportPeerBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (window.openReport) window.openReport(btn.dataset.vendor, btn.dataset.name);
    });
})();
</script>

<?php if ($vendor && !empty($vendor['qr_token'])): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
const passportUrl = "<?= htmlspecialchars((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']).'/php/vendor_passport.php?t='.$vendor['qr_token']) ?>";
const fileName = "MALLENGKE-QR-<?= htmlspecialchars($refNo) ?>.png";
const vendorName = "<?= htmlspecialchars($fullName) ?>";
const vendorRef = "<?= htmlspecialchars($refNo) ?>";
const vendorStall = "<?= htmlspecialchars($vendor['stall_number'] ?: 'Not assigned yet') ?>";
new QRCode(document.getElementById("qrcode"),{text:passportUrl,width:160,height:160,colorDark:"#0f172a",colorLight:"#ffffff",correctLevel:QRCode.CorrectLevel.H});
function downloadQr(){
    const s=document.querySelector('#qrcode canvas'); if(!s){alert('QR still rendering');return;}
    const W=400,H=520,c=document.createElement('canvas');c.width=W;c.height=H;const x=c.getContext('2d');
    x.fillStyle='#fff';x.fillRect(0,0,W,H);
    const g=x.createLinearGradient(0,0,W,0);g.addColorStop(0,'#34d399');g.addColorStop(1,'#047857');x.fillStyle=g;x.fillRect(0,0,W,70);
    x.fillStyle='#ffffff';x.textAlign='center';x.font='bold 22px Inter, Arial';x.fillText('MALLENGKE',W/2,34);
    x.font='500 12px Inter, Arial';x.fillText('Vendor Monitoring & Management System',W/2,54);
    const q=240,qx=(W-q)/2,qy=100;x.strokeStyle='#34d399';x.lineWidth=2;x.setLineDash([6,6]);x.strokeRect(qx-8,qy-8,q+16,q+16);x.setLineDash([]);
    x.drawImage(s,qx,qy,q,q);x.fillStyle='#0f172a';x.font='bold 18px Inter, Arial';x.fillText(vendorName,W/2,qy+q+42);
    x.fillStyle='#065f46';x.font='bold 14px Inter, Arial';x.fillText(vendorRef,W/2,qy+q+66);
    x.fillStyle='#475569';x.font='600 13px Inter, Arial';x.fillText('Stall '+vendorStall,W/2,qy+q+90);
    x.fillStyle='#64748b';x.font='400 11px Inter, Arial';x.fillText('Scan this QR to verify vendor details',W/2,H-24);
    const l=document.createElement('a');l.download=fileName;l.href=c.toDataURL('image/png');document.body.appendChild(l);l.click();document.body.removeChild(l);
}
async function downloadPassportPdf(){
    const tpl=document.getElementById('passport-template'),btn=document.getElementById('download-passport-btn');
    if(!tpl||!btn)return;const orig=btn.innerHTML;
    try{
        btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Generating…';btn.disabled=true;
        tpl.style.opacity='1';tpl.style.zIndex='9999';tpl.style.position='fixed';tpl.style.top='0';tpl.style.left='-9999px';
        const c=await html2canvas(tpl.querySelector('.pt-card'),{scale:2,useCORS:true,backgroundColor:'#fff',logging:false,windowWidth:700,windowHeight:tpl.querySelector('.pt-card').scrollHeight});
        tpl.style.opacity='0';tpl.style.zIndex='-1';tpl.style.left='0';
        const img=c.toDataURL('image/png'),{jsPDF}=window.jspdf,pdf=new jsPDF('p','mm','a4');
        const pw=pdf.internal.pageSize.getWidth(),ph=pdf.internal.pageSize.getHeight(),m=10;
        let w=pw-m*2,h=(c.height*w)/c.width;if(h>ph-m*2){h=ph-m*2;w=(c.width*h)/c.height;}
        pdf.addImage(img,'PNG',(pw-w)/2,(ph-h)/2,w,h);pdf.save('MALLENGKE-Passport-'+vendorRef+'.pdf');
    }catch(e){console.error(e);alert('Could not generate PDF.');}
    finally{btn.innerHTML=orig;btn.disabled=false;}
}
</script>
<?php endif; ?>

</body>
</html>
