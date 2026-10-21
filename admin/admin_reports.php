<?php
/* ============================================================
   VENDOR REPORTS  (admin)
   ------------------------------------------------------------
   What vendors file into the market office. The queue is read-only
   evidence: closing a report records that the office looked at it,
   and nothing here changes the reported vendor's account.

   Filters: needs review · reviewed · dismissed · all, plus a search
   over both vendors and the report text.
   ============================================================ */
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: admin_login.php'); exit; }

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/reports.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole = $_SESSION['admin_role'] ?? 'admin';

/* ---------- Flash ---------- */
function set_flash($t, $m) { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function get_flash() { if (empty($_SESSION['flash'])) return null; $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }

/* ---------- Log helper ---------- */
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip); $stmt->execute(); $stmt->close(); }
}

$REASONS = report_reasons();
$STATUSES = report_status_meta();

/* ============================================================
   ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? '';

if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['id'] ?? 0);
    $take     = (string)($_POST['close_as'] ?? '');
    $note     = (string)($_POST['note'] ?? '');

    $res = report_close($conn, $reportId, $take, $note, $adminName);

    if ($res['ok']) {
        log_action($conn, $adminId, 'report_' . strtolower($take), '#' . $reportId,
            ($STATUSES[$take]['label'] ?? $take) . ($note !== '' ? ' · Note: ' . $note : ''));
        set_flash('success', 'Report #' . $reportId . ' marked ' . strtolower($STATUSES[$take]['label'] ?? 'closed') . '.');
    } else {
        set_flash('error', $res['error'] ?: 'Could not record that decision.');
    }

    $filter = in_array($_POST['filter'] ?? '', ['open','reviewed','dismissed','all'], true) ? $_POST['filter'] : 'open';
    header('Location: admin_reports.php?filter=' . $filter);
    exit;
}

/* ---- Act on the reported vendor's account ----
   Suspend stops them signing in; "remove access" drops the login for
   good but keeps their stall and history. Both take the description
   the vendor will read on their suspended screen. */
if (in_array($action, ['suspend', 'restore', 'remove_access', 'reissue'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendorId    = (int)($_POST['vendor_id'] ?? 0);
    $description = (string)($_POST['description'] ?? '');
    $filter      = in_array($_POST['filter'] ?? '', ['open','reviewed','dismissed','all'], true) ? $_POST['filter'] : 'open';

    if ($action === 'suspend') {
        $res = vendor_suspend($conn, $vendorId, $description, $adminName);
        if ($res['ok']) {
            $vLabel = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'vendor_suspended', $vLabel, $description);
            set_flash('success', $vLabel . ' is suspended. They cannot sign in, and will read your description on the suspended screen.');
        } else {
            set_flash('error', $res['error']);
        }
    } elseif ($action === 'restore') {
        $res = vendor_restore($conn, $vendorId, $description, $adminName);
        if ($res['ok']) {
            $vLabel = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'vendor_restored', $vLabel, $description);
            set_flash('success', $vLabel . ' can sign in again.');
        } else {
            set_flash('error', $res['error']);
        }
    } elseif ($action === 'remove_access') {
        $res = vendor_remove_access($conn, $vendorId, $description);
        if ($res['ok']) {
            $vLabel = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'vendor_access_removed', $vLabel, $description);
            set_flash('success', $vLabel . '\'s login has been removed. Their stall, permit and message history are untouched.');
        } else {
            set_flash('error', $res['error']);
        }
    } else {
        /* The only way back from a removal: the office sets a new password. */
        $res = vendor_reissue_login($conn, $vendorId, (string)($_POST['new_password'] ?? ''), $adminName);
        if ($res['ok']) {
            $vLabel = vendor_label($conn, $vendorId);
            log_action($conn, $adminId, 'vendor_login_reissued', $vLabel, 'New password set by the office');
            set_flash('success', $vLabel . ' has a new password. They can sign in again — tell them what it is.');
        } else {
            set_flash('error', $res['error']);
        }
    }

    header('Location: admin_reports.php?filter=' . $filter);
    exit;
}

$flash = get_flash();

/* ============================================================
   DATA
   ============================================================ */
$filter = trim($_GET['filter'] ?? 'open');
if (!in_array($filter, ['open', 'reviewed', 'dismissed', 'all'], true)) $filter = 'open';
$search = trim($_GET['q'] ?? '');

$reports = report_list($conn, $filter, $search);
$counts  = report_counts($conn);

function h($v) { return htmlspecialchars((string)$v); }
function ini_from($name) { $p = preg_split('/\s+/', trim((string)$name)); return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1)); }

/** "Ana Cruz (Stall A-1)" — how a vendor is named in flash and log. */
function vendor_label($conn, $vendorId) {
    $vendorId = (int)$vendorId;
    if ($stmt = $conn->prepare(
        "SELECT first_name, last_name, stall_number FROM vendors WHERE id = ? LIMIT 1"
    )) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($v) {
            $name = trim($v['first_name'] . ' ' . $v['last_name']) ?: ('Vendor #' . $vendorId);
            return $name . ($v['stall_number'] ? ' (Stall ' . $v['stall_number'] . ')' : '');
        }
    }
    return 'Vendor #' . $vendorId;
}

$FILTERS = [
    'open'      => ['label' => 'Needs review', 'icon' => 'fa-inbox'],
    'reviewed'  => ['label' => 'Reviewed',     'icon' => 'fa-clipboard-check'],
    'dismissed' => ['label' => 'Dismissed',    'icon' => 'fa-box-archive'],
    'all'       => ['label' => 'All',          'icon' => 'fa-layer-group'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Vendor Reports • MALLENGKE</title>
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
.mini-stat.active{border-color:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.14)}
.mini-stat .icon{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;font-size:17px;flex-shrink:0}
.mini-stat .icon.i1{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 16px rgba(217,119,6,.32)}
.mini-stat .icon.i2{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.35)}
.mini-stat .icon.i3{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 16px rgba(14,165,233,.32)}
.mini-stat .num{font-size:23px;font-weight:900;color:var(--ink);letter-spacing:-.5px;line-height:1.1}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px}
.card-head{padding:18px 22px;border-bottom:1.5px solid var(--line-soft);display:flex;align-items:center;gap:12px;background:linear-gradient(180deg,var(--green-50),#fff)}
.card-head-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#ef4444,#b91c1c);display:grid;place-items:center;color:#fff;font-size:15px;box-shadow:0 5px 14px rgba(220,38,38,.3);flex-shrink:0;}
.card-head h2{font-size:15px;font-weight:800;color:var(--ink)}
.card-head p{font-size:12px;color:var(--muted);margin-top:1px}

.toolbar{background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:var(--sh-sm);margin-bottom:18px}
.toolbar .search-wrap{position:relative;flex:1;min-width:220px}
.toolbar .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
.toolbar input[type="text"]{width:100%;padding:11px 16px 11px 40px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg);color:var(--ink);font-family:inherit;font-size:13.5px;outline:none;transition:all .2s}
.toolbar input[type="text"]:focus{border-color:var(--green-400);background:#fff;box-shadow:0 0 0 4px rgba(16,185,129,.13)}
.pills{display:flex;gap:6px;flex-wrap:wrap}
.pill{position:relative}
.pill input{position:absolute;opacity:0;pointer-events:none}
.pill span{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:999px;border:1.5px solid var(--line);background:#fff;font-size:12.5px;font-weight:800;color:var(--ink-soft);cursor:pointer;transition:all .25s var(--ease-spring);user-select:none}
.pill span:hover{border-color:var(--green-300);background:var(--green-50)}
.pill input:checked + span{background:linear-gradient(135deg,#10b981,#047857);color:#fff;border-color:transparent;box-shadow:var(--glow-soft)}
.pill .n{font-size:10.5px;font-weight:900;padding:1px 7px;border-radius:999px;background:var(--bg);color:var(--ink-soft)}
.pill input:checked + span .n{background:rgba(255,255,255,.22);color:#fff}

/* One report */
.rep{padding:18px 22px;border-bottom:1px solid var(--line-soft);transition:background .15s}
.rep:last-child{border-bottom:none}
.rep:hover{background:#fbfcfd}
.rep-top{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}
.rep-icon{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-size:15px}
.rep-icon.red{background:linear-gradient(135deg,#ef4444,#b91c1c)}
.rep-icon.amber{background:linear-gradient(135deg,#f59e0b,#d97706)}
.rep-icon.blue{background:linear-gradient(135deg,#38bdf8,#0284c7)}
.rep-icon.sky{background:linear-gradient(135deg,#0ea5e9,#0284c7)}
.rep-icon.purple{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.rep-icon.green{background:linear-gradient(135deg,#10b981,#047857)}
.rep-head{flex:1;min-width:0}
.rep-title{font-size:14.5px;font-weight:800;color:var(--ink)}
.rep-meta{font-size:11.5px;color:var(--muted);margin-top:3px;display:flex;gap:9px;flex-wrap:wrap;align-items:center}

/* reporter → reported */
.versus{display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:stretch;margin-bottom:12px}@media(max-width:640px){.versus{grid-template-columns:1fr;}.versus .arrow{display:none}}
.party{background:var(--bg);border:1px solid var(--line-soft);border-radius:14px;padding:12px 14px;display:flex;align-items:center;gap:11px;min-width:0}
.party .av{width:38px;height:38px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px}
.party.rep .av{background:linear-gradient(135deg,#34d399,#047857)}
.party.target .av{background:linear-gradient(135deg,#f59e0b,#d97706)}
.party .who{min-width:0}
.party .nm{font-size:13.5px;font-weight:800;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.party .sub{font-size:11.5px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.party .role{font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:2px}
.arrow{display:grid;place-items:center;color:var(--muted);font-size:15px}

.rep-body{font-size:13px;color:var(--ink-soft);line-height:1.6;background:var(--bg);border-left:3px solid var(--line);border-radius:0 10px 10px 0;padding:11px 14px;margin-bottom:12px;white-space:pre-line}
.rep-foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:800;background:var(--bg);color:var(--ink-soft);border:1px solid var(--line);white-space:nowrap}
.chip.red{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.chip.amber{background:#fef3c7;color:#92400e;border-color:#fde68a}
.chip.green{background:var(--green-100);color:var(--green-800);border-color:var(--green-200)}
.chip.blue{background:#e0f2fe;color:#075985;border-color:#bae6fd}
.chip.purple{background:#ede9fe;color:#5b21b6;border-color:#ddd6fe}
.chip.muted{background:var(--bg);color:var(--muted);border-color:var(--line)}

.decided-note{font-size:12.5px;color:var(--ink-soft);background:var(--green-50);border:1px solid var(--green-200);border-radius:11px;padding:10px 13px;margin-top:10px;white-space:pre-line}

.close-row{background:var(--bg);border:1px solid var(--line-soft);border-radius:14px;padding:14px;margin-top:12px;display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.close-note{flex:1;min-width:220px;padding:10px 13px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font-family:inherit;font-size:13px;color:var(--ink);outline:none;resize:vertical;min-height:42px;line-height:1.5}
.close-note:focus{border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13)}
.act{display:inline-flex;align-items:center;gap:7px;padding:10px 15px;border-radius:11px;border:none;font-family:inherit;font-size:12.5px;font-weight:800;cursor:pointer;transition:all .22s var(--ease-spring)}
.act:hover{transform:translateY(-2px)}
.act.review{background:var(--green-50);border:1.5px solid var(--green-200);color:var(--green-800)}
.act.review:hover{border-color:var(--green-400);box-shadow:0 6px 16px rgba(16,185,129,.28)}
.act.dismiss{background:#fff;border:1.5px solid var(--line);color:var(--ink-soft)}
.act.dismiss:hover{border-color:var(--green-400);color:var(--brand-dark)}

/* Account action — about the vendor, not the report */
.access-box{margin-top:12px;padding:14px;border:1.5px dashed var(--line);border-radius:14px;background:#fbfcfd}
.access-box:has(.act.suspend){border-color:#fed7aa;background:#fffbf5}
.access-head{display:flex;align-items:flex-start;gap:10px;margin-bottom:11px}
.access-head i{width:32px;height:32px;border-radius:10px;flex-shrink:0;display:grid;place-items:center;background:#ffedd5;color:#9a3412;font-size:13px}
.access-title{font-size:12.5px;font-weight:800;color:var(--ink)}
.access-sub{font-size:11.5px;color:var(--muted);margin-top:2px;line-height:1.45}
.access-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:11px}
.access-row .act{flex:1;min-width:150px;justify-content:center}
.access-note-line{font-size:11.5px;color:var(--muted);margin-top:11px;line-height:1.5}
.act.suspend{background:#fff7ed;border:1.5px solid #fed7aa;color:#9a3412}
.act.suspend:hover{border-color:var(--amber);box-shadow:0 6px 16px rgba(245,158,11,.28)}
.act.restore{background:#fff;border:1.5px solid var(--line);color:var(--ink-soft)}
.act.restore:hover{border-color:var(--green-400);color:var(--brand-dark)}
.act.remove{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.act.remove:hover{border-color:var(--red);box-shadow:0 6px 16px rgba(239,68,68,.28)}

.empty-state{padding:56px 24px;text-align:center;color:var(--muted)}
.empty-state i{font-size:46px;color:var(--green-200);display:block;margin-bottom:14px}
.empty-state h3{font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px}
.empty-state p{font-size:13.5px}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-flag"></i></span>
                Vendor Reports
            </h1>
            <p>What vendors report about each other. Read it, then mark it reviewed or dismiss it.</p>
        </div>
        <div class="header-actions">
            <a href="admin_vendors.php" class="btn-header ghost"><i class="fas fa-store"></i> Vendors</a>
            <a href="admin_notifications.php" class="btn-header ghost"><i class="fas fa-bell"></i> Private Messages</a>
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
        <a href="admin_reports.php?filter=open" class="mini-stat<?= $filter === 'open' ? ' active' : '' ?>">
            <div class="icon i1"><i class="fas fa-inbox"></i></div>
            <div><div class="num"><?= (int)$counts['open'] ?></div><div class="lbl">Needs review</div></div>
        </a>
        <a href="admin_reports.php?filter=reviewed" class="mini-stat<?= $filter === 'reviewed' ? ' active' : '' ?>">
            <div class="icon i2"><i class="fas fa-clipboard-check"></i></div>
            <div><div class="num"><?= (int)$counts['reviewed'] ?></div><div class="lbl">Reviewed</div></div>
        </a>
        <a href="admin_reports.php?filter=all" class="mini-stat<?= $filter === 'all' ? ' active' : '' ?>">
            <div class="icon i3"><i class="fas fa-layer-group"></i></div>
            <div><div class="num"><?= (int)$counts['total'] ?></div><div class="lbl">All reports</div></div>
        </a>
    </div>

    <!-- QUEUE -->
    <div class="card">
        <div class="card-head">
            <div class="card-head-icon"><i class="fas fa-inbox"></i></div>
            <div>
                <h2>Report queue</h2>
                <p>Newest first · <?= count($reports) ?> shown</p>
            </div>
        </div>

        <form class="toolbar" method="get" action="admin_reports.php">
            <div class="search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search a vendor, a stall, or the report text…" />
            </div>
            <div class="pills">
                <?php foreach ($FILTERS as $key => $meta):
                    $n = $key === 'open' ? $counts['open']
                       : ($key === 'reviewed' ? $counts['reviewed']
                       : ($key === 'dismissed' ? $counts['dismissed'] : $counts['total']));
                ?>
                    <label class="pill">
                        <input type="radio" name="filter" value="<?= h($key) ?>" <?= $filter === $key ? 'checked' : '' ?> />
                        <span><i class="fas <?= h($meta['icon']) ?>"></i> <?= h($meta['label']) ?> <span class="n"><?= (int)$n ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-header primary"><i class="fas fa-filter"></i> Apply</button>
        </form>

        <?php if (empty($reports)): ?>
            <div class="empty-state">
                <i class="fas fa-circle-check"></i>
                <h3>Nothing here</h3>
                <p><?= $search !== '' ? 'No report matches “' . h($search) . '”.' : 'No reports in this view.' ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($reports as $r):
                $reasonMeta = $REASONS[$r['reason']] ?? ['label' => $r['reason'], 'icon' => 'fa-flag', 'color' => 'green'];
                $statMeta   = $STATUSES[$r['status']] ?? $STATUSES['Open'];
                $isOpen     = $r['status'] === 'Open';

                /* Removed is a stronger state than suspended: the
                   password is gone, so there is nothing to unlock. */
                $accessKind     = (string)($r['target_access_kind'] ?? 'none');
                $targetBlocked  = $accessKind === 'removed';
                $targetSuspended = $accessKind === 'suspended' || ((int)($r['target_blocked'] ?? 0) === 1 && !$targetBlocked);
            ?>
            <div class="rep">
                <div class="rep-top">
                    <div class="rep-icon <?= h($reasonMeta['color']) ?>">
                        <i class="fas <?= h($reasonMeta['icon']) ?>"></i>
                    </div>
                    <div class="rep-head">
                        <div class="rep-title"><?= h($reasonMeta['label']) ?></div>
                        <div class="rep-meta">
                            <span><i class="far fa-clock"></i> <?= h(notif_time_ago($r['created_at'])) ?></span>
                            <span class="chip muted"><i class="fas fa-hashtag"></i> Report #<?= (int)$r['id'] ?></span>
                            <span class="chip <?= h($statMeta['color']) ?>">
                                <i class="fas <?= h($statMeta['icon']) ?>"></i> <?= h($statMeta['label']) ?>
                            </span>
                            <?php if (!$isOpen && $r['reviewed_by_name']): ?>
                            <span><i class="fas fa-user-tie"></i> <?= h($r['reviewed_by_name']) ?>
                                · <?= h(notif_time_ago($r['reviewed_at'])) ?></span>
                            <?php endif; ?>
                            <?php if ((int)$r['open_against'] > 1): ?>
                                <span class="chip red"><i class="fas fa-layer-group"></i> <?= (int)$r['open_against'] ?> open reports about this stall</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="versus">
                    <div class="party rep">
                        <div class="av"><?= h(ini_from($r['reporter_name'])) ?></div>
                        <div class="who">
                            <div class="role">Reported by</div>
                            <div class="nm"><?= h($r['reporter_name']) ?></div>
                            <div class="sub">Stall <?= h($r['reporter_stall'] ?: '—') ?>
                                · <?= h($r['reporter_section']) ?>
                                <?php if ($r['reporter_biz']): ?> · <?= h($r['reporter_biz']) ?><?php endif; ?></div>
                        </div>
                    </div>
                    <div class="arrow"><i class="fas fa-arrow-right-long"></i></div>
                    <div class="party target">
                        <div class="av"><?= h(ini_from($r['target_name'])) ?></div>
                        <div class="who">
                            <div class="role">About</div>
                            <div class="nm"><?= h($r['target_name']) ?></div>
                            <div class="sub">Stall <?= h($r['target_stall'] ?: '—') ?>
                                · <?= h($r['target_section']) ?>
                                <?php if ($r['target_biz']): ?> · <?= h($r['target_biz']) ?><?php endif; ?></div>
                        </div>
                    </div>
                </div>

                <div class="rep-body"><?= h($r['details']) ?></div>

                <div class="rep-foot">
                    <a href="admin_vendors.php?id=<?= (int)$r['reported_vendor_id'] ?>" class="chip blue">
                        <i class="fas fa-circle-info"></i> Open the vendor's record
                    </a>
                    <?php if ($targetBlocked): ?>
                        <span class="chip red"><i class="fas fa-ban"></i> Login removed</span>
                    <?php elseif ($targetSuspended): ?>
                        <span class="chip amber"><i class="fas fa-pause-circle"></i> Suspended</span>
                    <?php elseif ($r['target_status'] !== 'Approved'): ?>
                        <span class="chip amber"><i class="fas fa-circle-info"></i> Currently <?= h($r['target_status']) ?></span>
                    <?php endif; ?>
                    <?php if ($targetBlocked || $targetSuspended): ?>
                        <span><i class="fas fa-user-tie"></i> by <?= h((string)($r['target_access_by'] ?: 'the market office')) ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($r['admin_note'])): ?>
                    <div class="decided-note"><i class="fas fa-note-sticky"></i> <?= h($r['admin_note']) ?></div>
                <?php endif; ?>

                <?php if ($isOpen): ?>
                <form class="close-row" method="post" action="admin_reports.php">
                    <input type="hidden" name="action" value="close" />
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                    <input type="hidden" name="filter" value="<?= h($filter) ?>" />

                    <input type="text" class="close-note" name="note" maxlength="500"
                           placeholder="Note for the record (optional) — why you closed it this way." />

                    <button type="submit" class="act review" name="close_as" value="Reviewed">
                        <i class="fas fa-clipboard-check"></i> Mark reviewed
                    </button>
                    <button type="submit" class="act dismiss" name="close_as" value="Dismissed"
                            onclick="return confirm('Dismiss this report? It stays on record, just out of the queue.');">
                        <i class="fas fa-box-archive"></i> Dismiss
                    </button>
                </form>
                <?php endif; ?>

                <!-- ACCOUNT ACTION: about the reported vendor, not the report -->
                <form class="access-box" method="post" action="admin_reports.php">
                    <?php /* Only what Enter in a field submits; the buttons
                           carry their own action, so it has to match the state. */ ?>
                    <input type="hidden" name="action" value="<?= $targetBlocked ? 'reissue' : 'suspend' ?>" />
                    <input type="hidden" name="vendor_id" value="<?= (int)$r['reported_vendor_id'] ?>" />
                    <input type="hidden" name="filter" value="<?= h($filter) ?>" />

                    <div class="access-head">
                        <i class="fas fa-user-lock"></i>
                        <div>
                            <div class="access-title"><?= $targetBlocked ? 'Login removed' : 'This vendor\'s account' ?></div>
                            <div class="access-sub"><?= $targetBlocked
                                ? 'Their password is gone - the account cannot be signed into again.'
                                : ($targetSuspended
                                    ? 'Currently suspended. Restoring lets them trade again straight away.'
                                    : 'Suspending stops them signing in. They read your description on the suspended screen.') ?></div>
                        </div>
                    </div>

                    <?php if ($targetBlocked): ?>
                    <p class="access-note-line">
                        <i class="fas fa-circle-info"></i> There is nothing to restore - the password was
                        destroyed. To bring <?= h($r['target_name']) ?> back, set a new password below and
                        pass it on at the counter. Their stall, permit, payments and message history were
                        never touched.
                    </p>

                    <input type="password" class="close-note" name="new_password" minlength="8"
                           autocomplete="new-password"
                           placeholder="New password for them (at least 8 characters)" />

                    <div class="access-row">
                        <button type="submit" class="act restore" formnovalidate name="action" value="reissue"
                                onclick="return confirm('Give <?= h($r['target_name']) ?> a new password and let them back in?');">
                            <i class="fas fa-key"></i> Re-issue login
                        </button>
                    </div>
                    <?php else: ?>
                    <input type="text" class="close-note" name="description" maxlength="500"
                           placeholder="<?= $targetSuspended ? 'Why are they being restored? (kept on the record)' : 'Why are they being suspended? (the vendor reads this)' ?>"
                           value="<?= h((string)($r['target_access_note'] ?? '')) ?>" />

                    <div class="access-row">
                        <button type="submit" class="act suspend"
                                onclick="return confirm('Suspend <?= h($r['target_name']) ?>? They will not be able to sign in.');">
                            <i class="fas fa-pause-circle"></i> <?= $targetSuspended ? 'Suspend again' : 'Suspend account' ?>
                        </button>

                        <?php if ($targetSuspended): ?>
                        <button type="submit" class="act restore" formnovalidate name="action" value="restore"
                                onclick="return confirm('Restore <?= h($r['target_name']) ?>? They can sign in again.');">
                            <i class="fas fa-unlock"></i> Restore access
                        </button>
                        <?php endif; ?>

                        <button type="submit" class="act remove" formnovalidate name="action" value="remove_access"
                                onclick="return confirm('Remove the login for <?= h($r['target_name']) ?>?\n\nTheir password is destroyed and the account can never sign in again. Their stall, permit, payments and message history stay.');">
                            <i class="fas fa-trash-can"></i> Remove login
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}
</script>

</body>
</html>
