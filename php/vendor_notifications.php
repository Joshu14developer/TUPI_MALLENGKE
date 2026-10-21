<?php
/* ============================================================
   VENDOR NOTIFICATIONS
   ------------------------------------------------------------
   Reads the `notifications` table (populated by the admin console)
   and merges in live alerts derived from the vendor's own data.
   ============================================================ */
session_start();
$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/guard.php';
guard_vendor($conn, 'login.php');
require_once __DIR__ . '/../config/notify.php';

/* Fetch vendor */
$stmt = $conn->prepare("SELECT * FROM vendors WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$vendor   = $stmt->get_result()->fetch_assoc();
$stmt->close();
$vendorId = $vendor ? (int)$vendor['id'] : 0;

$fullName = '';
$initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));
if ($vendor) {
    $fullName = trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['middle_name'] ?? '') . ' ' . ($vendor['last_name'] ?? ''));
    $initials = strtoupper(substr($vendor['first_name'] ?? 'U', 0, 1) . substr($vendor['last_name'] ?? '', 0, 1));
}

$TYPE_META = notif_types();

/* ============================================================
   1. ADMIN → VENDOR NOTIFICATIONS  (persisted, real read state)
   ============================================================ */
$adminNotifs = [];
if ($vendorId) {
    $stmt = $conn->prepare(
        "SELECT n.id, n.category, n.title, n.body, n.link, n.is_read, n.created_at,
                n.read_at, n.sender_type, n.sender_name
           FROM notifications n
          WHERE n.recipient_type = 'vendor' AND n.recipient_id = ?
          ORDER BY n.created_at DESC, n.id DESC
          LIMIT 100"
    );
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $meta = $TYPE_META[$r['category']] ?? $TYPE_META['system'];
        $adminNotifs[] = [
            'id'     => (int)$r['id'],
            'source' => 'admin',
            'type'   => $r['category'],
            'title'  => $r['title'],
            'desc'   => $r['body'] ?: '',
            'url'    => notif_vendor_url($r['link'], (string)$r['category']),
            'time'   => $r['created_at'],
            'isRead' => (int)$r['is_read'] === 1,
            'icon'   => $meta['icon'],
            'color'  => $meta['color'],
            'badge'  => 0,
            'by'     => $r['sender_name'] ?: 'Market Office',
        ];
    }
    $stmt->close();
}

/* ============================================================
   2. LIVE ALERTS from the vendor's own data
   ============================================================ */
$liveNotifs = [];

if ($vendorId) {
    /* Unread vendor-to-vendor messages.
       Two indexed branches (idx_vc_a / idx_vc_b) merged here, so the
       top-10 comes back ordered without a filesort over an OR. */
    $unreadRows = [];
    $branch = "SELECT c.id,
                      CASE WHEN c.vendor_a_id = ? THEN c.unread_a ELSE c.unread_b END AS unread,
                      c.last_message_at, c.last_message_preview,
                      v.id AS peer_id,
                      TRIM(CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name)) AS peer_name
                 FROM vendor_conversations c
                 JOIN vendors v ON v.id = CASE WHEN c.vendor_a_id = ? THEN c.vendor_b_id ELSE c.vendor_a_id END
                WHERE c.%s = ?
                  AND (CASE WHEN c.vendor_a_id = ? THEN c.unread_a ELSE c.unread_b END) > 0";

    foreach (['vendor_a_id', 'vendor_b_id'] as $side) {
        $stmt = $conn->prepare(sprintf($branch, $side));
        $stmt->bind_param("iiii", $vendorId, $vendorId, $vendorId, $vendorId);
        $stmt->execute();
        $unreadRows = array_merge($unreadRows, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    usort($unreadRows, fn($a, $b) =>
        strcmp((string)($b['last_message_at'] ?? ''), (string)($a['last_message_at'] ?? '')));
    $unreadRows = array_slice($unreadRows, 0, 10);

    foreach ($unreadRows as $r) {
        $liveNotifs[] = [
            'id'     => 'vmsg-' . $r['id'],
            'source' => 'live',
            'type'   => 'message',
            'title'  => 'Vendor message from ' . $r['peer_name'],
            'desc'   => $r['last_message_preview'] ?: 'Tap to view the conversation',
            'url'    => '../dashboard.php?view=messages&tab=vendors&vpeer=' . (int)$r['peer_id'],
            'time'   => $r['last_message_at'],
            'isRead' => false,
            'icon'   => 'fa-store',
            'color'  => 'sky',
            'badge'  => (int)$r['unread'],
            'by'     => $r['peer_name'],
        ];
    }
}

if ($vendor) {
    /* Application status */
    if (($vendor['status'] ?? '') === 'Pending') {
        $liveNotifs[] = [
            'id' => 'app-pending', 'source' => 'live', 'type' => 'approval',
            'title' => 'Application under review',
            'desc'  => 'The market office is reviewing your vendor application. We will notify you once it is approved.',
            'url'   => '../dashboard.php', 'time' => $vendor['created_at'],
            'isRead' => false, 'icon' => 'fa-hourglass-half', 'color' => 'amber', 'badge' => 0,
            'by' => 'Market Office',
        ];
    } elseif (($vendor['status'] ?? '') === 'Rejected') {
        $liveNotifs[] = [
            'id' => 'app-rejected', 'source' => 'live', 'type' => 'approval',
            'title' => 'Application not approved',
            'desc'  => !empty($vendor['remarks'])
                     ? 'Reason: ' . $vendor['remarks']
                     : 'Please visit the market office for details.',
            'url'   => '../dashboard.php', 'time' => $vendor['updated_at'],
            'isRead' => false, 'icon' => 'fa-circle-xmark', 'color' => 'red', 'badge' => 0,
            'by' => 'Market Office',
        ];
    }

    /* Permit status */
    $permitStatus = $vendor['permit_status'] ?? 'Pending';
    if ($permitStatus === 'Suspended') {
        $liveNotifs[] = [
            'id' => 'permit-suspended', 'source' => 'live', 'type' => 'permit',
            'title' => 'Permit suspended',
            'desc'  => 'Your business permit has been suspended. Please settle the outstanding issue at the market office.',
            'url'   => '../dashboard.php', 'time' => $vendor['updated_at'],
            'isRead' => false, 'icon' => 'fa-ban', 'color' => 'red', 'badge' => 0,
            'by' => 'Market Office',
        ];
    }

    if (!empty($vendor['permit_expiry'])) {
        $days = (int)floor((strtotime($vendor['permit_expiry']) - time()) / 86400);
        if ($days < 0) {
            $liveNotifs[] = [
                'id' => 'permit-expired', 'source' => 'live', 'type' => 'permit',
                'title' => 'Permit expired',
                'desc'  => 'Your permit expired ' . abs($days) . ' day(s) ago. Please renew at the market office.',
                'url'   => '../dashboard.php', 'time' => $vendor['permit_expiry'],
                'isRead' => false, 'icon' => 'fa-file-circle-xmark', 'color' => 'red', 'badge' => 0,
                'by' => 'Market Office',
            ];
        } elseif ($days <= 30) {
            $liveNotifs[] = [
                'id' => 'permit-expiring', 'source' => 'live', 'type' => 'permit',
                'title' => 'Permit expiring soon',
                'desc'  => 'Your permit expires in ' . $days . ' day(s) — on '
                         . date('M j, Y', strtotime($vendor['permit_expiry'])) . '.',
                'url'   => '../dashboard.php', 'time' => date('Y-m-d'),
                'isRead' => false, 'icon' => 'fa-clock', 'color' => 'amber', 'badge' => 0,
                'by' => 'Market Office',
            ];
        }
    }

    /* Payment status */
    if (($vendor['payment_status'] ?? 'Unpaid') !== 'Paid') {
        $liveNotifs[] = [
            'id' => 'payment-' . ($vendor['payment_status'] ?? 'unpaid'),
            'source' => 'live', 'type' => 'payment',
            'title' => 'Payment ' . ($vendor['payment_status'] ?? 'Unpaid'),
            'desc'  => !empty($vendor['payment_due'])
                     ? 'Stall rental payment due on ' . date('M j, Y', strtotime($vendor['payment_due'])) . '.'
                     : 'Please settle your stall rental payment at the market office.',
            'url'   => '../dashboard.php', 'time' => $vendor['payment_due'] ?: date('Y-m-d'),
            'isRead' => false, 'icon' => 'fa-wallet', 'color' => 'amber', 'badge' => 0,
            'by' => 'Market Office',
        ];
    }

    /* Admin remarks */
    if (!empty($vendor['remarks'])) {
        $liveNotifs[] = [
            'id' => 'remarks-' . md5($vendor['remarks']),
            'source' => 'live', 'type' => 'system',
            'title' => 'Remarks from Market Office',
            'desc'  => mb_substr($vendor['remarks'], 0, 160) . (mb_strlen($vendor['remarks']) > 160 ? '…' : ''),
            'url'   => '../dashboard.php', 'time' => $vendor['updated_at'] ?: date('Y-m-d'),
            'isRead' => false, 'icon' => 'fa-note-sticky', 'color' => 'purple', 'badge' => 0,
            'by' => 'Market Office',
        ];
    }
}

/* ============================================================
   3. COMBINE + SORT
   ------------------------------------------------------------
   Two kinds of row:
     source = 'admin' → persisted, has a real read state
     source = 'live'  → derived from your own data, so it
                         cannot be "read away". It disappears
                         on its own once the condition clears.
   Unread counters deliberately only track the admin rows so
   that "Mark all read" always reaches zero.
   ============================================================ */
$notifications = array_merge($adminNotifs, $liveNotifs);

usort($notifications, function ($a, $b) {
    return strtotime($b['time'] ?: '1970-01-01') - strtotime($a['time'] ?: '1970-01-01');
});

/* Counts for the stat cards and filter tabs */
$unreadCount = 0;
$liveCount   = 0;
$typeCounts  = [];
foreach ($TYPE_META as $k => $_) $typeCounts[$k] = 0;
foreach ($notifications as $n) {
    if ($n['source'] === 'live') {
        $liveCount++;
    } elseif (!$n['isRead']) {
        $unreadCount++;
    }
    if (isset($typeCounts[$n['type']])) $typeCounts[$n['type']]++;
}
$totalCount = count($notifications);
$readCount  = $totalCount - $unreadCount - $liveCount;

function h($v) { return htmlspecialchars((string)$v); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Notifications • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--amber:#f59e0b;--red:#ef4444;--purple:#8b5cf6;--sky:#0ea5e9;}

.btn-header.danger{background:#fee2e2;border:1.5px solid #fecaca;color:#991b1b}
.btn-header.danger:hover{background:#fecaca;transform:translateY(-2px)}
.btn-header:disabled{opacity:.5;cursor:not-allowed;transform:none!important}

/* Notification stats */
.nstat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}@media(max-width:900px){.nstat-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.nstat-grid{grid-template-columns:1fr}}
.nstat{background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:16px;display:flex;align-items:center;gap:13px;box-shadow:var(--sh-xs);transition:all .25s var(--ease-spring)}
.nstat:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.nstat .num{font-size:22px;font-weight:900;color:var(--ink);letter-spacing:-.4px;line-height:1.1}
.nstat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px}
.nstat .icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:16px;flex-shrink:0}
.nstat .icon.i1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.35)}
.nstat .icon.i2{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 6px 16px rgba(220,38,38,.32)}
.nstat .icon.i3{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 16px rgba(14,165,233,.32)}
.nstat .icon.i4{background:linear-gradient(135deg,#8b5cf6,#7c3aed);box-shadow:0 6px 16px rgba(124,58,237,.3)}

/* Filter tabs */
.tabs{display:flex;gap:6px;padding:8px;background:#fff;border:1.5px solid var(--line);border-radius:16px;box-shadow:var(--sh-xs);margin-bottom:16px;overflow-x:auto;scrollbar-width:none}
.tabs::-webkit-scrollbar{display:none}
.tab{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:11px;border:none;background:transparent;font-family:inherit;font-size:13px;font-weight:800;color:var(--muted);cursor:pointer;white-space:nowrap;transition:all .25s var(--ease-spring)}
.tab:hover{color:var(--ink);background:var(--green-50)}
.tab.active{background:linear-gradient(135deg,#10b981,#047857);color:#fff;box-shadow:var(--glow-soft)}
.tab.active i{color:#fff}
.tab i{font-size:11px;color:var(--muted);transition:color .25s}
.tab .tab-count{font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:999px;background:var(--bg);color:var(--ink-soft)}
.tab.active .tab-count{background:rgba(255,255,255,.25);color:#fff}

/* Notification list */
.nlist{display:flex;flex-direction:column;gap:10px}
.nitem{
    position:relative;display:flex;gap:14px;align-items:flex-start;
    padding:16px 18px;background:#fff;border:1.5px solid var(--line);border-radius:16px;
    text-decoration:none;color:inherit;
    transition:all .25s var(--ease-smooth);
    box-shadow:var(--sh-xs);
}
.nitem:hover{background:var(--green-50);border-color:var(--green-300);transform:translateX(4px);box-shadow:var(--sh-sm)}
.nitem.unread{background:linear-gradient(90deg,var(--green-50),#fff 60%);border-color:var(--green-200)}
.nitem.unread::before{content:'';position:absolute;left:0;top:14px;bottom:14px;width:4px;border-radius:0 4px 4px 0;background:linear-gradient(180deg,#34d399,#047857)}

/* The whole card is the link. The title carries a real href so the row
   behaves like a link (keyboard, middle-click, new tab), and its ::after
   stretches over the card so any click lands on it. The title is left
   unpositioned on purpose, so that overlay resolves against the card.
   Anything that has to stay clickable in its own right sits above it. */
.ntitle{
    font-size:14px;font-weight:800;color:var(--ink);letter-spacing:-.1px;
    text-decoration:none;
}
.ntitle::after{content:'';position:absolute;inset:0;border-radius:16px}
.nitem:has(.ntitle):hover .ntitle{color:var(--brand-dark)}
.ntitle:focus-visible{outline:2.5px solid var(--green-500);outline-offset:3px;border-radius:6px}
.nfoot,.nread-btn{position:relative;z-index:2}
.ntag.go{margin-left:auto;background:var(--green-100);color:var(--green-800);border-color:var(--green-200);
    text-transform:none;letter-spacing:0;font-size:11px;font-weight:700;opacity:0;transition:opacity .2s var(--ease-smooth)}
.nitem:hover .ntag.go{opacity:1}
@media(hover:none){.ntag.go{opacity:1}}
/* Live action items: derived from your own data, so they cannot be "read away" */
.nitem.live{border-style:dashed}
.ntag.live-note{background:#fef3c7;color:#92400e;border-color:#fde68a;text-transform:none;letter-spacing:0;font-size:11px;font-weight:700}

.nicon{width:46px;height:46px;border-radius:14px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-size:17px;position:relative;transition:transform .35s var(--ease-spring)}
.nitem:hover .nicon{transform:scale(1.08) rotate(-4deg)}
.nicon.green{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.32)}
.nicon.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 6px 16px rgba(2,132,199,.32)}
.nicon.sky{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 16px rgba(14,165,233,.32)}
.nicon.amber{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 16px rgba(217,119,6,.32)}
.nicon.red{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 6px 16px rgba(220,38,38,.32)}
.nicon.purple{background:linear-gradient(135deg,#8b5cf6,#7c3aed);box-shadow:0 6px 16px rgba(124,58,237,.32)}
.nicon .badge-dot{
    position:absolute;top:-4px;right:-4px;
    min-width:20px;height:20px;padding:0 6px;
    border-radius:999px;background:#ef4444;color:#fff;
    font-size:10.5px;font-weight:800;
    display:grid;place-items:center;
    border:2px solid #fff;
}

.nmeta{flex:1;min-width:0}
.ntop{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:4px}
.ntitle{
    font-size:14px;font-weight:800;color:var(--ink);letter-spacing:-.1px;
    text-decoration:none;
}
.nitem.unread .ntitle{color:var(--green-900)}
.ntime{font-size:11px;color:var(--muted);font-weight:700;flex-shrink:0;white-space:nowrap}
.ndesc{font-size:13px;color:var(--muted);line-height:1.55}
.nitem.unread .ndesc{color:var(--ink-soft);font-weight:500}
.nfoot{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}
.ntag{
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 9px;border-radius:999px;
    font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;
    background:var(--green-50);color:var(--green-700);border:1px solid var(--green-100);
}
.ntag.by{background:var(--bg);color:var(--muted);border-color:var(--line);text-transform:none;letter-spacing:0;font-size:11px}
.nread-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:4px 10px;border-radius:999px;
    font-size:10.5px;font-weight:800;
    background:#fff;border:1.5px solid var(--line);color:var(--ink-soft);
    cursor:pointer;font-family:inherit;
    transition:all .2s var(--ease-spring);
}
.nread-btn:hover{border-color:var(--green-400);background:var(--green-50);color:var(--brand-dark);transform:translateY(-1px)}

.nempty{padding:80px 24px;text-align:center;color:var(--muted);background:#fff;border:1.5px dashed var(--green-200);border-radius:20px}
.nempty i{font-size:56px;color:var(--green-200);display:block;margin-bottom:16px}
.nempty h3{font-size:18px;font-weight:800;color:var(--ink);margin-bottom:8px}
.nempty p{font-size:14px;max-width:400px;margin:0 auto}
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/vendor_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1><i class="fas fa-bell"></i> Notifications</h1>
            <p>Updates from the market office — approvals, permits, payments, and messages.</p>
        </div>
        <div class="header-actions">
            <button type="button" class="btn-header ghost" id="btnRefresh">
                <i class="fas fa-rotate"></i> Refresh
            </button>
            <button type="button" class="btn-header primary" id="btnMarkAll">
                <i class="fas fa-check-double"></i> Mark all read
            </button>
            <button type="button" class="btn-header danger" id="btnClear">
                <i class="fas fa-trash"></i> Clear all
            </button>
        </div>
    </header>

    <!-- Stats -->
    <div class="nstat-grid">
        <div class="nstat">
            <div class="icon i1"><i class="fas fa-bell"></i></div>
            <div>
                <div class="num" id="statTotal"><?= $totalCount ?></div>
                <div class="lbl">Total</div>
            </div>
        </div>
        <div class="nstat">
            <div class="icon i2"><i class="fas fa-circle-exclamation"></i></div>
            <div>
                <div class="num" id="statUnread"><?= $unreadCount ?></div>
                <div class="lbl">Unread</div>
            </div>
        </div>
        <div class="nstat">
            <div class="icon i3"><i class="fas fa-envelope-open"></i></div>
            <div>
                <div class="num" id="statRead"><?= $readCount ?></div>
                <div class="lbl">Read</div>
            </div>
        </div>
        <div class="nstat">
            <div class="icon i4"><i class="fas fa-bolt"></i></div>
            <div>
                <div class="num" id="statLive"><?= $liveCount ?></div>
                <div class="lbl">Action Items</div>
            </div>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="tabs" id="notifTabs">
        <button type="button" class="tab active" data-filter="all"><i class="fas fa-layer-group"></i> All <span class="tab-count" id="cntAll"><?= $totalCount ?></span></button>
        <button type="button" class="tab" data-filter="unread"><i class="fas fa-circle"></i> Unread <span class="tab-count" id="cntUnread"><?= $unreadCount ?></span></button>
        <?php if ($liveCount > 0): ?>
            <button type="button" class="tab" data-filter="live"><i class="fas fa-bolt"></i> Action Items <span class="tab-count" id="cntLive"><?= $liveCount ?></span></button>
        <?php endif; ?>
        <?php foreach ($TYPE_META as $tk => $tm): if (empty($typeCounts[$tk])) continue; ?>
            <button type="button" class="tab" data-filter="<?= h($tk) ?>">
                <i class="fas <?= h($tm['icon']) ?>"></i> <?= h($tm['label']) ?>
                <span class="tab-count" data-count-type="<?= h($tk) ?>"><?= (int)$typeCounts[$tk] ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- List -->
    <div class="nlist" id="notifList">
        <?php foreach ($notifications as $n):
            $isLive = ($n['source'] === 'live');
            $isUnread = (!$isLive && !$n['isRead']);
            /* Resolved, so a link written from the app root or from
               admin/ still lands on a page that exists. */
            $href = notif_vendor_url($n['url'], $n['type']);
        ?>
            <div class="nitem<?= $isUnread ? ' unread' : '' ?><?= $isLive ? ' live' : '' ?>"
                 data-id="<?= h($n['id']) ?>"
                 data-type="<?= h($n['type']) ?>"
                 data-source="<?= h($n['source']) ?>"
                 data-url="<?= h($href) ?>">
                <div class="nicon <?= h($n['color']) ?>">
                    <i class="fas <?= h($n['icon']) ?>"></i>
                    <?php if (!empty($n['badge'])): ?><span class="badge-dot"><?= (int)$n['badge'] ?></span><?php endif; ?>
                </div>
                <div class="nmeta">
                    <div class="ntop">
                        <a class="ntitle" href="<?= h($href) ?>"><?= h($n['title']) ?></a>
                        <span class="ntime"><?= h(notif_time_ago($n['time'])) ?></span>
                    </div>
                    <?php if ($n['desc'] !== ''): ?>
                        <div class="ndesc"><?= nl2br(h($n['desc'])) ?></div>
                    <?php endif; ?>
                    <div class="nfoot">
                        <span class="ntag"><i class="fas <?= h($n['type']) === 'message' ? 'fa-comment-dots' : ($isLive ? 'fa-bolt' : 'fa-user-tie') ?>"></i> <?= h($TYPE_META[$n['type']]['label'] ?? 'System') ?></span>
                        <span class="ntag by"><i class="fas fa-user"></i> <?= h($n['by']) ?></span>
                        <span class="ntag go"><i class="fas fa-arrow-right"></i> Open</span>
                        <?php if ($isUnread): ?>
                            <button type="button" class="nread-btn" onclick="event.stopPropagation();markRead(this)">
                                <i class="fas fa-check"></i> Mark read
                            </button>
                        <?php elseif ($isLive): ?>
                            <span class="ntag live-note"><i class="fas fa-circle-info"></i> Clears automatically</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="nempty" id="notifEmpty" style="display:none;">
        <i class="fas fa-bell-slash"></i>
        <h3>You're all caught up!</h3>
        <p>No notifications match the current filter.</p>
    </div>

</main>

<script>
/* ============================================================
   Notifications — read state is stored in the database
   ============================================================ */
const API = 'notification_api.php';
let activeFilter = 'all';

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

/* Send a request to the JSON API */
async function api(action, payload = {}) {
    const body = new URLSearchParams({ action, ...payload });
    const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

/* Recompute every counter from the DOM.
   Unread = persisted rows only; live action items are counted apart. */
function refreshUI() {
    const items = [...document.querySelectorAll('.nitem')];
    let unread = 0, live = 0;
    const counts = { all: items.length, unread: 0, live: 0 };

    items.forEach(el => {
        const type = el.dataset.type;
        counts[type] = (counts[type] || 0) + 1;
        if (el.classList.contains('live')) {
            live++; counts.live++;
        } else if (el.classList.contains('unread')) {
            unread++; counts.unread++;
        }
    });

    document.getElementById('statTotal').textContent  = items.length;
    document.getElementById('statUnread').textContent = unread;
    document.getElementById('statRead').textContent   = items.length - unread - live;
    document.getElementById('statLive').textContent   = live;

    document.getElementById('cntAll').textContent    = counts.all;
    document.getElementById('cntUnread').textContent = counts.unread;
    const liveTab = document.getElementById('cntLive');
    if (liveTab) liveTab.textContent = live;
    document.querySelectorAll('[data-count-type]').forEach(el => {
        el.textContent = counts[el.dataset.countType] || 0;
    });

    const badge = document.getElementById('sidebarNotifCount');
    if (badge) {
        badge.textContent = unread;
        badge.style.display = unread > 0 ? '' : 'none';
    }

    document.getElementById('btnMarkAll').disabled = unread === 0;
    document.getElementById('btnClear').disabled  = items.length === 0;
}

/* Show / hide items per active filter */
function applyFilter() {
    let visible = 0;
    document.querySelectorAll('.nitem').forEach(el => {
        let show = false;
        if (activeFilter === 'all')          show = true;
        else if (activeFilter === 'unread')  show = el.classList.contains('unread');
        else if (activeFilter === 'live')    show = el.classList.contains('live');
        else                                 show = (el.dataset.type === activeFilter);

        el.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('notifEmpty').style.display = visible === 0 ? '' : 'none';
}

/* Tabs */
document.querySelectorAll('#notifTabs .tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('#notifTabs .tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeFilter = tab.dataset.filter;
        applyFilter();
    });
});

/* Remove the "mark read" button from a row and mark it read locally.
   Live action items have no read state, so they are left alone. */
function setRowRead(el) {
    if (el.dataset.source === 'live') return;
    el.classList.remove('unread');
    const btn = el.querySelector('.nread-btn');
    if (btn) btn.remove();
}

/* Mark one notification as read (and persist it when it came from the admin) */
async function markRead(btn) {
    const el = btn.closest('.nitem');
    if (!el) return;
    setRowRead(el);
    refreshUI();
    applyFilter();

    if (el.dataset.source === 'admin') {
        try { await api('mark_read', { id: el.dataset.id }); }
        catch (e) { /* keep the optimistic UI */ }
    }
}

/* Click a row → mark it read. Navigation is the title's real href, so
   this must not redirect: doing both would fire the click twice, and
   the browser already knows where the row goes. Modified clicks
   (new tab, middle click) still work because nothing is intercepted. */
document.querySelectorAll('.nitem').forEach(el => {
    el.addEventListener('click', async () => {
        setRowRead(el);
        refreshUI();

        if (el.dataset.source === 'admin' && /^\d+$/.test(el.dataset.id)) {
            try { await api('mark_read', { id: el.dataset.id }); } catch (e) {}
        }
    });
});

/* Mark all as read (persisted rows only — live items clear themselves) */
document.getElementById('btnMarkAll').addEventListener('click', async () => {
    document.querySelectorAll('.nitem').forEach(setRowRead);
    refreshUI();
    applyFilter();
    try { await api('mark_all_read'); } catch (e) {}
});

/* Clear history (deletes this vendor's stored notifications).
   Live action items stay, since they are derived from your account. */
document.getElementById('btnClear').addEventListener('click', async () => {
    if (!confirm('Clear your notification history? This cannot be undone.')) return;
    try { await api('clear_all'); } catch (e) {}

    document.querySelectorAll('.nitem').forEach(el => {
        if (el.dataset.source === 'live') return;
        el.remove();
    });
    document.querySelectorAll('#notifTabs .tab').forEach(t => {
        if (t.dataset.filter !== 'all' && t.dataset.filter !== 'live') t.style.display = 'none';
    });
    document.querySelectorAll('[data-count-type]').forEach(el => el.textContent = '0');
    document.getElementById('cntUnread').textContent = '0';
    refreshUI();
    applyFilter();
});

/* Refresh — pick up anything the market office sent while you were away */
document.getElementById('btnRefresh').addEventListener('click', function () {
    const icon = this.querySelector('i');
    icon.style.transition = 'transform .8s ease';
    icon.style.transform = 'rotate(360deg)';
    setTimeout(() => { icon.style.transform = 'rotate(0deg)'; }, 800);
    window.location.reload();
});

refreshUI();
applyFilter();
</script>

</body>
</html>
