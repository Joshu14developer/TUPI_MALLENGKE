<?php
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: admin_login.php'); exit; }

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/swap.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole = $_SESSION['admin_role'] ?? 'admin';

$initials = '';
foreach (preg_split('/\s+/', trim($adminName)) as $p) {
    if ($p !== '') $initials .= strtoupper($p[0]);
    if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'A';

/* ---------- Flash ---------- */
function set_flash($t, $m) { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function get_flash() { if (empty($_SESSION['flash'])) return null; $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }

/* ---------- Log helper ---------- */
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip); $stmt->execute(); $stmt->close(); }
}

$STATUS_META = swap_status_meta();

/* ============================================================
   ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? '';

if ($action === 'decide_stall_swap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $swapId = (int)($_POST['id'] ?? 0);
    $verdict = ($_POST['verdict'] ?? '') === 'approve' ? 'Approved' : 'Rejected';
    $note = trim($_POST['note'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM stall_swap_requests WHERE id = ? LIMIT 1");
    $req = null;
    if ($stmt) {
        $stmt->bind_param("i", $swapId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$req || $req['status'] !== 'Pending') {
        set_flash('error', 'That request was already decided — nothing changed.');
        header('Location: admin_stall_swaps.php'); exit;
    }

    $a = swap_vendor_row($conn, (int)$req['requester_vendor_id']);
    $b = swap_vendor_row($conn, (int)$req['target_vendor_id']);

    if (!$a || !$b) {
        set_flash('error', 'One of the vendors no longer exists.');
        header('Location: admin_stall_swaps.php'); exit;
    }

    /* The stalls may have changed while the request sat in the queue */
    $stale = trim((string)$a['stall_number']) !== trim((string)$req['requester_stall'])
          || trim((string)$b['stall_number']) !== trim((string)$req['target_stall']);

    if ($verdict === 'Approved') {
        if (trim((string)$a['stall_number']) === trim((string)$b['stall_number'])) {
            set_flash('error', $a['name'] . ' and ' . $b['name'] . ' are now both at Stall ' . $a['stall_number'] . '. There is nothing to swap.');
            header('Location: admin_stall_swaps.php'); exit;
        }
        if ($stale && ($_POST['confirm_stale'] ?? '') !== '1') {
            set_flash('error', 'The stall assignments changed since this request was filed. Review the current stalls and confirm again.');
            header('Location: admin_stall_swaps.php?id=' . $swapId . '&view=1'); exit;
        }
    }

    if (swap_apply($conn, $swapId, $adminId, $adminName, $verdict, $note)) {
        swap_announce($conn, $req, $verdict, $adminName, $note);

        log_action(
            $conn, $adminId, 'stall_swap_' . strtolower($verdict),
            $a['name'] . ' ↔ ' . $b['name'],
            'Request #' . $swapId . ' · Stall ' . $req['requester_stall'] . ' ↔ ' . $req['target_stall']
            . ' → ' . ($verdict === 'Approved'
                ? $a['name'] . ' now at ' . $b['stall_number'] . ', ' . $b['name'] . ' now at ' . $a['stall_number']
                : 'rejected')
            . ($note ? ' · Note: ' . $note : '')
        );

        set_flash('success', $verdict === 'Approved'
            ? 'Stall swap approved — ' . $a['name'] . ' is now at Stall ' . $b['stall_number'] . ' and ' . $b['name'] . ' is now at Stall ' . $a['stall_number'] . '. Both vendors notified.'
            : 'Stall swap request rejected. Both vendors notified.');
    } else {
        set_flash('error', 'Could not update that request. Please try again.');
    }

    header('Location: admin_stall_swaps.php'); exit;
}

$flash = get_flash();

/* ============================================================
   DATA
   ============================================================ */
$statusF = trim($_GET['status'] ?? 'Pending');
if (!in_array($statusF, ['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'], true)) $statusF = 'Pending';
$q = trim($_GET['q'] ?? '');

$sql = "SELECT s.*,
               TRIM(CONCAT_WS(' ', ra.first_name, ra.middle_name, ra.last_name)) AS requester_name,
               ra.business_name AS requester_biz,
               ra.stall_number  AS requester_now,
               ra.market_section AS requester_now_section,
               TRIM(CONCAT_WS(' ', tb.first_name, tb.middle_name, tb.last_name)) AS target_name,
               tb.business_name AS target_biz,
               tb.stall_number  AS target_now,
               tb.market_section AS target_now_section
          FROM stall_swap_requests s
          JOIN vendors ra ON ra.id = s.requester_vendor_id
          JOIN vendors tb ON tb.id = s.target_vendor_id";
$params = []; $types = '';

if ($statusF !== 'All') { $sql .= " WHERE s.status = ?"; $params[] = $statusF; $types .= 's'; }
if ($q !== '') {
    $sql .= ($statusF !== 'All' ? ' AND' : ' WHERE') .
            " (ra.first_name LIKE ? OR ra.last_name LIKE ? OR ra.business_name LIKE ? OR ra.stall_number LIKE ?" .
            " OR tb.first_name LIKE ? OR tb.last_name LIKE ? OR tb.business_name LIKE ? OR tb.stall_number LIKE ? OR s.reason LIKE ?)";
    $like = '%' . $q . '%';
    for ($i = 0; $i < 9; $i++) { $params[] = $like; $types .= 's'; }
}
/* A single status tab is already filtered, so ordering by id alone
   lets idx_swap_queue(status, id) deliver the rows in order. Only
   the "All" view needs the status grouping, and there FIELD() forces
   a sort — acceptable, since that list is short. */
$sql .= ($statusF === 'All')
    ? " ORDER BY FIELD(s.status,'Pending','Approved','Rejected','Cancelled'), s.id DESC"
    : " ORDER BY s.id DESC";

$requests = [];
if ($stmt = $conn->prepare($sql)) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Single request (modal view) */
$viewReq = null;
if (!empty($_GET['id'])) {
    $stmt = $conn->prepare(
        "SELECT s.*,
                TRIM(CONCAT_WS(' ', ra.first_name, ra.middle_name, ra.last_name)) AS requester_name,
                ra.business_name AS requester_biz, ra.stall_number AS requester_now,
                ra.market_section AS requester_now_section, ra.contact_number AS requester_contact,
                TRIM(CONCAT_WS(' ', tb.first_name, tb.middle_name, tb.last_name)) AS target_name,
                tb.business_name AS target_biz, tb.stall_number AS target_now,
                tb.market_section AS target_now_section, tb.contact_number AS target_contact
           FROM stall_swap_requests s
           JOIN vendors ra ON ra.id = s.requester_vendor_id
           JOIN vendors tb ON tb.id = s.target_vendor_id
          WHERE s.id = ? LIMIT 1"
    );
    if ($stmt) {
        $sid = (int)$_GET['id'];
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $viewReq = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$counts = swap_status_counts($conn);

/* Flags shown on the admin list */
foreach ($requests as &$r) {
    $r['stale'] = (trim((string)$r['requester_now']) !== trim((string)$r['requester_stall']))
               || (trim((string)$r['target_now'])    !== trim((string)$r['target_stall']));
}
unset($r);

function h($v) { return htmlspecialchars((string)$v); }
function ini_from($name) { $p = preg_split('/\s+/', trim((string)$name)); return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Stall Swaps • MALLENGKE</title>
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
.mini-stat.active{border-color:#d8b4fe;box-shadow:0 0 0 3px rgba(168,85,247,.14)}
.mini-stat .icon{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;font-size:17px;flex-shrink:0}
.mini-stat .icon.i1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.35)}
.mini-stat .icon.i2{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 16px rgba(217,119,6,.32)}
.mini-stat .icon.i3{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 16px rgba(14,165,233,.32)}
.mini-stat .icon.i4{background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 6px 16px rgba(168,85,247,.32)}
.mini-stat .num{font-size:23px;font-weight:900;color:var(--ink);letter-spacing:-.5px;line-height:1.1}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px}

.toolbar{background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:var(--sh-sm);margin-bottom:18px}
.toolbar .search-wrap{position:relative;flex:1;min-width:220px}
.toolbar .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
.toolbar input[type="text"]{width:100%;padding:11px 16px 11px 40px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg);color:var(--ink);font-family:inherit;font-size:13.5px;outline:none;transition:all .2s}
.toolbar input[type="text"]:focus{background:#fff;border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13)}
.toolbar select{padding:11px 38px 11px 14px;border:1.5px solid var(--line);border-radius:12px;background:#fff;font-family:inherit;font-size:13.5px;font-weight:600;color:var(--ink-soft);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:14px}

.table-card{background:#fff;border:1.5px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:var(--sh-sm)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:980px}
thead th{padding:14px 16px;text-align:left;font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;background:var(--green-50);border-bottom:1.5px solid var(--line);white-space:nowrap}
tbody td{padding:14px 16px;border-bottom:1px solid var(--line-soft);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background .15s}
tbody tr:hover{background:var(--green-50)}

.pair-cell{display:flex;flex-direction:column;gap:9px;min-width:250px}
.pair-side{display:flex;align-items:center;gap:10px}
.pair-side .ps-avatar{width:34px;height:34px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-weight:800;font-size:12.5px;background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 4px 10px rgba(16,185,129,.28)}
.pair-side.b .ps-avatar{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 4px 10px rgba(14,165,233,.28)}
.ps-name{font-size:13px;font-weight:800;color:var(--ink)}
.ps-sub{font-size:11.5px;color:var(--muted);margin-top:1px}
.swap-line{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:800;color:#6d28d9;letter-spacing:.04em;text-transform:uppercase;padding-left:2px}
.swap-line i{font-size:11px}

.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:999px;font-size:10.5px;font-weight:800;text-transform:capitalize;letter-spacing:.03em;white-space:nowrap}
.badge.pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge.approved{background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200)}
.badge.rejected,.badge.cancelled{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}

.flag{display:inline-flex;align-items:center;gap:5px;margin-top:5px;padding:3px 9px;border-radius:8px;font-size:10.5px;font-weight:800;background:#fef3c7;color:#92400e}
.reason-cell{font-size:12.5px;color:var(--ink-soft);max-width:280px;line-height:1.5}
.decided-cell{font-size:11.5px;color:var(--muted);line-height:1.5;max-width:220px}
.decided-cell b{color:var(--ink-soft)}

.row-actions{display:flex;gap:6px;justify-content:flex-end}
.row-btn{width:34px;height:34px;border-radius:10px;border:1.5px solid var(--line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:13px;color:var(--ink-soft);transition:all .2s var(--ease-spring);text-decoration:none}
.row-btn:hover{border-color:var(--green-400);color:var(--brand-dark);background:var(--green-50);transform:translateY(-2px)}
.row-btn.approve:hover{background:var(--green-100);border-color:var(--green-300);color:var(--green-800)}
.row-btn.reject:hover{background:#fee2e2;border-color:#fca5a5;color:#dc2626}

.empty-state{padding:64px 24px;text-align:center;color:var(--muted)}
.empty-state i{font-size:52px;color:var(--green-200);display:block;margin-bottom:14px}
.empty-state h3{font-size:17px;font-weight:900;color:var(--ink);margin-bottom:6px}
.empty-state p{font-size:13.5px}

/* modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,44,34,.55);backdrop-filter:blur(8px);z-index:500;display:none;align-items:center;justify-content:center;padding:20px;overflow-y:auto}
.modal-backdrop.open{display:flex}
.modal{background:#fff;border-radius:22px;width:100%;max-width:560px;box-shadow:var(--sh-xl);animation:modalPop .35s var(--ease-spring);overflow:hidden;max-height:92vh;display:flex;flex-direction:column}
@keyframes modalPop{from{opacity:0;transform:translateY(24px) scale(.94)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-head{padding:20px 22px 16px;border-bottom:1px solid var(--line-soft);display:flex;align-items:center;gap:13px}
.modal-head-icon{width:44px;height:44px;border-radius:14px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-size:17px}
.modal-head-icon.green{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 8px 20px rgba(16,185,129,.34)}
.modal-head-icon.red{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 8px 20px rgba(239,68,68,.32)}
.modal-head-icon.purple{background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 8px 20px rgba(168,85,247,.34)}
.modal-head-info{flex:1;min-width:0}
.modal-head-info h2{font-size:17.5px;font-weight:900;color:var(--ink)}
.modal-head-info p{font-size:12.5px;color:var(--muted);margin-top:2px}
.modal-close{width:34px;height:34px;border-radius:50%;background:var(--bg);border:none;cursor:pointer;color:var(--ink-soft);font-size:13px;display:flex;align-items:center;justify-content:center;transition:all .3s var(--ease-spring);flex-shrink:0}
.modal-close:hover{background:#fee2e2;color:#dc2626;transform:rotate(90deg)}
.modal-body{padding:18px 22px;overflow-y:auto}
.modal-footer{padding:14px 22px;border-top:1px solid var(--line-soft);display:flex;gap:9px;background:var(--bg);flex:0 0 auto}

.v-summary{background:var(--bg);border:1.5px solid var(--line);border-radius:14px;padding:4px 14px;margin-bottom:16px}
.v-summary .row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed var(--line-soft);font-size:13px}
.v-summary .row:last-child{border-bottom:none}
.v-summary .label{color:var(--muted);font-weight:700}
.v-summary .value{color:var(--ink);font-weight:800;text-align:right}
.v-summary .value.purple{color:#6d28d9}

.swap-preview{display:flex;align-items:center;justify-content:center;gap:14px;padding:16px 8px;margin-bottom:16px;flex-wrap:wrap;background:linear-gradient(180deg,#faf5ff,#fff);border:1.5px solid #ddd6fe;border-radius:16px}
.swap-preview .side{text-align:center;min-width:0}
.swap-preview .side .av{width:52px;height:52px;margin:0 auto 8px;border-radius:17px;display:grid;place-items:center;color:#fff;font-weight:800;font-size:17px;background:linear-gradient(135deg,#34d399,#059669)}
.swap-preview .side.b .av{background:linear-gradient(135deg,#38bdf8,#0284c7)}
.swap-preview .side .nm{font-size:12.5px;font-weight:800;color:var(--ink);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.swap-preview .side .now{font-size:11.5px;color:var(--muted);margin-top:2px}
.swap-preview .mid{display:flex;flex-direction:column;align-items:center;gap:4px;color:#6d28d9}
.swap-preview .mid i{font-size:18px}
.swap-preview .mid span{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}

.form-grid{display:grid;gap:14px}
.form-grid.c2{grid-template-columns:1fr 1fr}@media(max-width:560px){.form-grid.c2{grid-template-columns:1fr}}
.field label{display:block;font-size:12px;font-weight:800;color:var(--ink-soft);margin-bottom:6px;letter-spacing:.02em}
.field label i{color:var(--brand);margin-right:4px}
.field input,.field select,.field textarea{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:12px;font-family:inherit;font-size:13.5px;outline:none;background:var(--bg);color:var(--ink);transition:all .2s}
.field textarea{min-height:82px;resize:vertical;line-height:1.5}
.field input:focus,.field select:focus,.field textarea:focus{background:#fff;border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13)}
.req{color:var(--red)}
.optional{color:var(--muted);font-weight:600;font-size:11px}

.notice{display:flex;gap:11px;padding:13px 15px;border-radius:14px;font-size:12.5px;line-height:1.6;margin-bottom:16px}
.notice i{font-size:15px;margin-top:1px}
.notice.info{background:var(--green-50);border:1.5px solid var(--green-200);color:var(--green-800)}
.notice.warn{background:#fffbeb;border:1.5px solid #fde68a;color:#92400e}
.notice.bad{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.notice.purple{background:#faf5ff;border:1.5px solid #ddd6fe;color:#5b21b6}

.btn-form{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:12px;border:none;cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:800;transition:all .25s var(--ease-spring);text-decoration:none}
.btn-form.green{background:linear-gradient(135deg, #059669, #065f46);color:#fff;box-shadow:var(--glow-soft)}
.btn-form.green:hover{transform:translateY(-2px);box-shadow:var(--glow)}
.btn-form.red{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 8px 22px rgba(239,68,68,.3)}
.btn-form.red:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(239,68,68,.42)}
.btn-form.ghost{background:#fff;border:1.5px solid var(--line);color:var(--ink-soft)}
.btn-form.ghost:hover{border-color:var(--green-300);background:var(--green-50);color:var(--brand-dark)}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1><span class="h1-icon"><i class="fas fa-right-left"></i></span> Stall Swaps</h1>
            <p>Vendor-to-vendor stall swap requests filed from the Messages tab. Approving moves the stalls for real.</p>
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

    <!-- Summary stats -->
    <div class="stat-row">
        <a href="admin_stall_swaps.php?status=Pending" class="mini-stat <?= $statusF === 'Pending' ? 'active' : '' ?>">
            <div class="icon i2"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="num"><?= (int)$counts['Pending'] ?></div>
                <div class="lbl">Awaiting Approval</div>
            </div>
        </a>
        <a href="admin_stall_swaps.php?status=Approved" class="mini-stat <?= $statusF === 'Approved' ? 'active' : '' ?>">
            <div class="icon i1"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="num"><?= (int)$counts['Approved'] ?></div>
                <div class="lbl">Approved</div>
            </div>
        </a>
        <a href="admin_stall_swaps.php?status=Rejected" class="mini-stat <?= $statusF === 'Rejected' ? 'active' : '' ?>">
            <div class="icon i3"><i class="fas fa-circle-xmark"></i></div>
            <div>
                <div class="num"><?= (int)$counts['Rejected'] ?></div>
                <div class="lbl">Rejected</div>
            </div>
        </a>
        <a href="admin_stall_swaps.php?status=All" class="mini-stat <?= $statusF === 'All' ? 'active' : '' ?>">
            <div class="icon i4"><i class="fas fa-list-check"></i></div>
            <div>
                <div class="num"><?= (int)$counts['total'] ?></div>
                <div class="lbl">All Requests</div>
            </div>
        </a>
    </div>

    <!-- Filters -->
    <form class="toolbar" method="get" action="admin_stall_swaps.php">
        <div class="search-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search vendor, business, stall, or reason…" />
        </div>
        <select name="status">
            <option value="Pending"   <?= $statusF === 'Pending'   ? 'selected' : '' ?>>Awaiting approval</option>
            <option value="Approved"  <?= $statusF === 'Approved'  ? 'selected' : '' ?>>Approved</option>
            <option value="Rejected"  <?= $statusF === 'Rejected'  ? 'selected' : '' ?>>Rejected</option>
            <option value="Cancelled" <?= $statusF === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
            <option value="All"       <?= $statusF === 'All'       ? 'selected' : '' ?>>All statuses</option>
        </select>
        <button type="submit" class="btn-toolbar primary"><i class="fas fa-filter"></i> Apply</button>
        <?php if ($q !== '' || $statusF !== 'Pending'): ?>
            <a href="admin_stall_swaps.php" class="btn-toolbar ghost"><i class="fas fa-rotate-left"></i> Reset</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="table-card">
        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <i class="fas fa-right-left"></i>
                <h3>No stall swap requests</h3>
                <p>Requests filed by vendors from their Messages tab will queue up here.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Vendors &amp; Stalls</th>
                        <th>Filed</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r):
                    $meta = $STATUS_META[$r['status']] ?? ['label' => $r['status'], 'class' => 'pending', 'icon' => 'fa-store'];
                    $approveLabel = $r['requester_name'] . ' (' . ($r['requester_biz'] ?: 'Independent vendor') . ' · Stall ' . $r['requester_now']
                                  . ') ↔ ' . $r['target_name'] . ' (' . ($r['target_biz'] ?: 'Independent vendor') . ' · Stall ' . $r['target_now'] . ')';
                    $rejectLabel  = $r['requester_name'] . ' ↔ ' . $r['target_name'];
                    $approveJs = htmlspecialchars(json_encode($approveLabel), ENT_QUOTES);
                    $rejectJs  = htmlspecialchars(json_encode($rejectLabel), ENT_QUOTES);
                ?>
                    <tr>
                        <td>
                            <div class="pair-cell">
                                <div class="pair-side">
                                    <div class="ps-avatar"><?= h(ini_from($r['requester_name'])) ?></div>
                                    <div>
                                        <div class="ps-name"><?= h($r['requester_name']) ?></div>
                                        <div class="ps-sub">
                                            <?= h($r['requester_biz'] ?: 'Independent vendor') ?>
                                            · Stall <?= h($r['requester_now'] ?: '—') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="swap-line"><i class="fas fa-right-left"></i> wants to swap with</div>
                                <div class="pair-side b">
                                    <div class="ps-avatar"><?= h(ini_from($r['target_name'])) ?></div>
                                    <div>
                                        <div class="ps-name"><?= h($r['target_name']) ?></div>
                                        <div class="ps-sub">
                                            <?= h($r['target_biz'] ?: 'Independent vendor') ?>
                                            · Stall <?= h($r['target_now'] ?: '—') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size:12.5px;font-weight:700;color:var(--ink);"><?= date('M j, Y', strtotime($r['created_at'])) ?></div>
                            <div style="font-size:11px;color:var(--muted);margin-top:2px;"><?= date('g:i A', strtotime($r['created_at'])) ?> · #<?= (int)$r['id'] ?></div>
                            <?php if (!empty($r['stale']) && $r['status'] === 'Pending'): ?>
                                <span class="flag"><i class="fas fa-triangle-exclamation"></i> Stalls changed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="reason-cell">
                                <?= $r['reason'] ? h(mb_strimwidth((string)$r['reason'], 0, 120, '…')) : '<span style="color:var(--muted);">—</span>' ?>
                            </div>
                            <?php if (!empty($r['decision_note'])): ?>
                                <div class="decided-cell" style="margin-top:6px;color:#92400e;">
                                    <b>Note:</b> <?= h(mb_strimwidth((string)$r['decision_note'], 0, 90, '…')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= h($meta['class']) ?>"><i class="fas <?= h($meta['icon']) ?>"></i><?= h($meta['label']) ?></span>
                            <?php if (!empty($r['decided_at'])): ?>
                                <div class="decided-cell" style="margin-top:6px;">
                                    <?= date('M j', strtotime($r['decided_at'])) ?>
                                    <?php if (!empty($r['decided_by_name'])): ?><br>by <?= h($r['decided_by_name']) ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <?php if ($r['status'] === 'Pending'): ?>
                                    <button type="button" class="row-btn approve" title="Approve swap"
                                            onclick='openDecide(<?= (int)$r["id"] ?>, "approve", <?= $approveJs ?>, <?= $r['stale'] ? 'true' : 'false' ?>)'>
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="row-btn reject" title="Reject swap"
                                            onclick='openDecide(<?= (int)$r["id"] ?>, "reject", <?= $rejectJs ?>, false)'>
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                <?php endif; ?>
                                <a class="row-btn" href="admin_stall_swaps.php?id=<?= (int)$r['id'] ?>&view=1" title="View details"><i class="fas fa-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ============ DECIDE MODAL ============ -->
<div class="modal-backdrop" id="decideModal">
    <div class="modal" onclick="event.stopPropagation()">
        <form method="post" action="admin_stall_swaps.php">
            <input type="hidden" name="action" value="decide_stall_swap" />
            <input type="hidden" name="id" id="decideId" value="" />
            <input type="hidden" name="verdict" id="decideVerdict" value="approve" />
            <input type="hidden" name="confirm_stale" id="decideStale" value="0" />

            <div class="modal-head">
                <div class="modal-head-icon green" id="decideIcon"><i class="fas fa-circle-check"></i></div>
                <div class="modal-head-info">
                    <h2 id="decideTitle">Approve Stall Swap</h2>
                    <p id="decidePair">—</p>
                </div>
                <button type="button" class="modal-close" onclick="closeDecide()"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body" id="decideBody">
                <div class="notice purple">
                    <i class="fas fa-circle-info"></i>
                    <span id="decideExplainer"></span>
                </div>

                <div class="swap-preview" id="decidePreview" style="display:none;"></div>

                <div class="field">
                    <label for="decideNote">Note to the vendors <span class="optional">optional</span></label>
                    <textarea name="note" id="decideNote" maxlength="500"
                              placeholder="e.g. Cleared — both vendors visited the market office today…"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-form ghost" onclick="closeDecide()"><i class="fas fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn-form green" id="decideSubmit" style="margin-left:auto;"><i class="fas fa-check"></i> Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ DETAIL MODAL ============ -->
<?php if ($viewReq):
    $vmeta = $STATUS_META[$viewReq['status']] ?? ['label' => $viewReq['status'], 'class' => 'pending', 'icon' => 'fa-store'];
    $vstale = (trim((string)$viewReq['requester_now']) !== trim((string)$viewReq['requester_stall']))
           || (trim((string)$viewReq['target_now'])    !== trim((string)$viewReq['target_stall']));
?>
<div class="modal-backdrop open" id="viewModal" onclick="window.location='admin_stall_swaps.php'">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-head">
            <div class="modal-head-icon purple"><i class="fas fa-right-left"></i></div>
            <div class="modal-head-info">
                <h2>Stall Swap Request #<?= (int)$viewReq['id'] ?></h2>
                <p>Filed <?= date('M j, Y \a\t g:i A', strtotime($viewReq['created_at'])) ?></p>
            </div>
            <button type="button" class="modal-close" onclick="window.location='admin_stall_swaps.php'"><i class="fas fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <div class="swap-preview">
                <div class="side">
                    <div class="av"><?= h(ini_from($viewReq['requester_name'])) ?></div>
                    <div class="nm"><?= h($viewReq['requester_name']) ?></div>
                    <div class="now"><?= h($viewReq['requester_biz'] ?: 'Independent vendor') ?></div>
                    <div class="now">Request: Stall <?= h($viewReq['requester_stall'] ?: '—') ?></div>
                    <div class="now">Current: Stall <?= h($viewReq['requester_now'] ?: '—') ?><?= $viewReq['requester_now_section'] ? ' · ' . h($viewReq['requester_now_section']) : '' ?></div>
                </div>
                <div class="mid"><i class="fas fa-right-left"></i><span>swap</span></div>
                <div class="side b">
                    <div class="av"><?= h(ini_from($viewReq['target_name'])) ?></div>
                    <div class="nm"><?= h($viewReq['target_name']) ?></div>
                    <div class="now"><?= h($viewReq['target_biz'] ?: 'Independent vendor') ?></div>
                    <div class="now">Request: Stall <?= h($viewReq['target_stall'] ?: '—') ?></div>
                    <div class="now">Current: Stall <?= h($viewReq['target_now'] ?: '—') ?><?= $viewReq['target_now_section'] ? ' · ' . h($viewReq['target_now_section']) : '' ?></div>
                </div>
            </div>

            <?php if ($vstale && $viewReq['status'] === 'Pending'): ?>
                <div class="notice warn">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>The stall assignments changed after this request was filed. Approving will trade the <strong>current</strong> stalls shown above.</span>
                </div>
            <?php endif; ?>

            <div class="v-summary">
                <div class="row"><span class="label">Status</span><span class="value"><span class="badge <?= h($vmeta['class']) ?>"><?= h($vmeta['label']) ?></span></span></div>
                <?php if (!empty($viewReq['reason'])): ?>
                    <div class="row"><span class="label">Reason from vendor</span><span class="value"><?= h($viewReq['reason']) ?></span></div>
                <?php endif; ?>
                <div class="row"><span class="label">Contact</span><span class="value"><?= h(($viewReq['requester_contact'] ?: '—') . ' / ' . ($viewReq['target_contact'] ?: '—')) ?></span></div>
                <?php if (!empty($viewReq['decided_at'])): ?>
                    <div class="row"><span class="label">Decided</span><span class="value"><?= date('M j, Y g:i A', strtotime($viewReq['decided_at'])) ?><?= !empty($viewReq['decided_by_name']) ? '<br>by ' . h($viewReq['decided_by_name']) : '' ?></span></div>
                <?php endif; ?>
                <?php if (!empty($viewReq['decision_note'])): ?>
                    <div class="row"><span class="label">Note</span><span class="value"><?= h($viewReq['decision_note']) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="modal-footer">
            <a href="admin_stall_swaps.php" class="btn-form ghost" style="margin-left:auto;"><i class="fas fa-xmark"></i> Close</a>
            <?php if ($viewReq['status'] === 'Pending'): ?>
                <button type="button" class="btn-form green"
                        onclick='openDecide(<?= (int)$viewReq["id"] ?>, "approve", <?= htmlspecialchars(json_encode($viewReq['requester_name'] . " (" . ($viewReq['requester_biz'] ?: 'Independent vendor') . " · Stall " . $viewReq['requester_now'] . ") ↔ " . $viewReq['target_name'] . " (" . ($viewReq['target_biz'] ?: 'Independent vendor') . " · Stall " . $viewReq['target_now'] . ")"), ENT_QUOTES) ?>, <?= $vstale ? 'true' : 'false' ?>);document.getElementById("viewModal").classList.remove("open")'>
                    <i class="fas fa-check"></i> Approve Swap
                </button>
                <button type="button" class="btn-form red"
                        onclick='openDecide(<?= (int)$viewReq["id"] ?>, "reject", <?= htmlspecialchars(json_encode($viewReq['requester_name'] . " ↔ " . $viewReq['target_name']), ENT_QUOTES) ?>, false);document.getElementById("viewModal").classList.remove("open")'>
                    <i class="fas fa-xmark"></i> Reject
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

/* ============ DECIDE MODAL ============ */
const DECIDE_COPY = {
    approve: {
        title: 'Approve Stall Swap',
        explainer: 'The two vendors will trade stalls immediately. Both of them receive a notification and a note inside your vendor conversation, and the change is written to the vendor list.',
        cta: 'Confirm Approval',
        cls: 'green',
        icon: 'fa-circle-check',
        staleMsg: 'One of the stalls changed since this request was filed. Please review the current assignments in the list before approving.'
    },
    reject: {
        title: 'Reject Stall Swap',
        explainer: 'Both vendors keep their current stalls. They are notified with your note, and the request is closed.',
        cta: 'Confirm Rejection',
        cls: 'red',
        icon: 'fa-circle-xmark',
        staleMsg: ''
    }
};

function openDecide(id, verdict, pairLabel, stale) {
    const copy = DECIDE_COPY[verdict] || DECIDE_COPY.approve;
    const m = document.getElementById('decideModal');

    document.getElementById('decideId').value = id;
    document.getElementById('decideVerdict').value = verdict;
    document.getElementById('decideStale').value = stale ? '1' : '0';
    document.getElementById('decideTitle').textContent = copy.title;
    document.getElementById('decidePair').textContent = pairLabel;
    document.getElementById('decideExplainer').innerHTML = copy.explainer;
    document.getElementById('decideNote').value = '';

    const iconBox = document.getElementById('decideIcon');
    iconBox.className = 'modal-head-icon ' + copy.cls;
    iconBox.innerHTML = '<i class="fas ' + copy.icon + '"></i>';

    const submit = document.getElementById('decideSubmit');
    submit.className = 'btn-form ' + copy.cls;
    submit.style.marginLeft = 'auto';
    submit.innerHTML = '<i class="fas ' + (verdict === 'approve' ? 'fa-check' : 'fa-xmark') + '"></i> ' + copy.cta;

    /* stale warning inside the modal too */
    let warn = document.getElementById('decideStaleWarn');
    if (stale && verdict === 'approve') {
        if (!warn) {
            warn = document.createElement('div');
            warn.id = 'decideStaleWarn';
            warn.className = 'notice warn';
            document.getElementById('decideBody').insertBefore(warn, document.getElementById('decidePreview').nextSibling);
        }
        warn.style.display = '';
        warn.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>' + copy.staleMsg + '</span>';
    } else if (warn) {
        warn.style.display = 'none';
    }

    document.getElementById('decidePreview').style.display = 'none';
    m.classList.add('open');
    setTimeout(() => document.getElementById('decideNote').focus(), 120);
}

function closeDecide() {
    document.getElementById('decideModal').classList.remove('open');
}
</script>
</body>
</html>
