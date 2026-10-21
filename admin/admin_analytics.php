<?php
session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/swap.php';

$adminId    = (int)$_SESSION['admin_id'];
$adminName  = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole  = $_SESSION['admin_role'] ?? 'admin';
$adminColor = $_SESSION['admin_color'] ?? '#059669';

$initials = '';
foreach (preg_split('/\s+/', trim($adminName)) as $p) {
    if ($p !== '') $initials .= strtoupper($p[0]);
    if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'A';

/* ============================================================
   Range selector
   ============================================================ */
$range = $_GET['range'] ?? '30';
if (!in_array($range, ['7','30','90','365'], true)) $range = '30';
$days = (int)$range;

/* ============================================================
   KPI cards
   ============================================================ */
$kpi = [
    'vendors_total'      => 0,
    'vendors_new'        => 0,
    'vendors_pending'    => 0,
    'customers_total'    => 0,
    'customers_new'      => 0,
];

if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors"))                           $kpi['vendors_total']    = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors WHERE status = 'Pending'"))  $kpi['vendors_pending']  = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM customers"))                         $kpi['customers_total']  = (int)($res->fetch_assoc()['c'] ?? 0);

if ($s = $conn->prepare("SELECT COUNT(*) AS c FROM vendors WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)")) {
    $s->bind_param("i", $days); $s->execute();
    $kpi['vendors_new'] = (int)($s->get_result()->fetch_assoc()['c'] ?? 0); $s->close();
}
if ($s = $conn->prepare("SELECT COUNT(*) AS c FROM customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)")) {
    $s->bind_param("i", $days); $s->execute();
    $kpi['customers_new'] = (int)($s->get_result()->fetch_assoc()['c'] ?? 0); $s->close();
}

/* ============================================================
   Vendor status breakdown
   ============================================================ */
$vendorStatus = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
if ($res = $conn->query("SELECT status, COUNT(*) AS c FROM vendors GROUP BY status")) {
    while ($r = $res->fetch_assoc()) $vendorStatus[$r['status']] = (int)$r['c'];
}

/* ============================================================
   Section breakdown (vendors per market section)
   ============================================================ */
$sectionData = [];
if ($res = $conn->query(
    "SELECT market_section AS s, COUNT(*) AS c
       FROM vendors
      WHERE market_section <> ''
   GROUP BY market_section
   ORDER BY c DESC")) {
    while ($r = $res->fetch_assoc()) $sectionData[] = $r;
}

/* ============================================================
   Permit & payment status
   ============================================================ */
$permitData = ['Valid' => 0, 'Pending' => 0, 'Expired' => 0, 'Suspended' => 0];
if ($res = $conn->query("SELECT permit_status AS s, COUNT(*) AS c FROM vendors GROUP BY permit_status")) {
    while ($r = $res->fetch_assoc()) $permitData[$r['s']] = (int)$r['c'];
}
$paymentData = ['Paid' => 0, 'Partial' => 0, 'Unpaid' => 0];
if ($res = $conn->query("SELECT payment_status AS s, COUNT(*) AS c FROM vendors GROUP BY payment_status")) {
    while ($r = $res->fetch_assoc()) $paymentData[$r['s']] = (int)$r['c'];
}

/* ============================================================
   Time-series: daily signups (vendors + customers)
   ============================================================ */
$series = [];
$labels = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $labels[$d] = date($days <= 31 ? 'M j' : 'M j', strtotime($d));
    $series['vendors'][$d]   = 0;
    $series['customers'][$d] = 0;
}

if ($s = $conn->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c
       FROM vendors
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
   GROUP BY DATE(created_at)")) {
    $s->bind_param("i", $days); $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) if (isset($series['vendors'][$r['d']])) $series['vendors'][$r['d']] = (int)$r['c'];
    $s->close();
}
if ($s = $conn->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c
       FROM customers
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
   GROUP BY DATE(created_at)")) {
    $s->bind_param("i", $days); $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) if (isset($series['customers'][$r['d']])) $series['customers'][$r['d']] = (int)$r['c'];
    $s->close();
}

/* ============================================================
   Peak activity hours (vendor-to-vendor messages)
   ============================================================ */
$peakHours = array_fill(0, 24, 0);
if ($s = $conn->prepare(
    "SELECT HOUR(created_at) AS h, COUNT(*) AS c
       FROM vendor_messages
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
   GROUP BY HOUR(created_at)")) {
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) $peakHours[(int)$r['h']] = (int)$r['c'];
    $s->close();
}

function h($v) { return htmlspecialchars((string)$v); }
function pct($n, $total) { return $total > 0 ? round(($n / $total) * 100, 1) : 0; }
function ini_from($name) {
    $p = preg_split('/\s+/', trim((string)$name));
    return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Analytics • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--blue-dark:#0284c7;--amber:#f59e0b;--red:#ef4444;--purple:#8b5cf6;--teal:#0d9488;}

.range-tabs{
    display:inline-flex;
    background:#fff;
    border:1.5px solid var(--line);
    border-radius:12px;
    padding:3px;
    box-shadow:var(--sh-sm);
}
.range-tab{
    padding:8px 16px;border:none;background:transparent;
    border-radius:9px;font-family:inherit;font-size:12.5px;font-weight:800;
    color:var(--muted);cursor:pointer;transition:all .25s var(--ease-spring);
    text-decoration:none;
    display:inline-flex;align-items:center;justify-content:center;
}
.range-tab:hover{color:var(--ink);}
.range-tab.active{
    background:linear-gradient(135deg,#8b5cf6,#7c3aed);
    color:#fff;
    box-shadow:0 4px 10px rgba(124,58,237,.32);
}

/* KPI Grid */
.kpi-grid{
    display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;
}@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}@media(max-width:520px){.kpi-grid{grid-template-columns:1fr;}}

.kpi-card{
    background:#fff;border:1.5px solid var(--line);border-radius:18px;
    padding:20px;position:relative;overflow:hidden;
    box-shadow:var(--sh-sm);
    transition:all .3s var(--ease-smooth);
}
.kpi-card:hover{transform:translateY(-5px);box-shadow:var(--sh-lg);border-color:var(--green-300);}
.kpi-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:4px;
    background:linear-gradient(90deg,#10b981,#047857);
}
.kpi-card.k2::before{background:linear-gradient(90deg,#0ea5e9,#0284c7);}
.kpi-card.k3::before{background:linear-gradient(90deg,#8b5cf6,#7c3aed);}
.kpi-card.k4::before{background:linear-gradient(90deg,#f59e0b,#d97706);}

.kpi-head{
    display:flex;align-items:flex-start;justify-content:space-between;
    margin-bottom:14px;
}
.kpi-icon{
    width:46px;height:46px;border-radius:14px;
    display:grid;place-items:center;color:#fff;font-size:18px;
    background:linear-gradient(135deg,#10b981,#047857);
    box-shadow:0 6px 14px rgba(16,185,129,.32);
    transition:transform .4s var(--ease-spring);
}
.kpi-card:hover .kpi-icon{transform:scale(1.1) rotate(-6deg);}
.kpi-card.k2 .kpi-icon{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 14px rgba(14,165,233,.32);}
.kpi-card.k3 .kpi-icon{background:linear-gradient(135deg,#8b5cf6,#7c3aed);box-shadow:0 6px 14px rgba(124,58,237,.32);}
.kpi-card.k4 .kpi-icon{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 14px rgba(217,119,6,.32);}

.kpi-trend{
    display:inline-flex;align-items:center;gap:5px;
    padding:5px 11px;border-radius:999px;
    font-size:11px;font-weight:800;letter-spacing:.02em;
    background:var(--green-100);color:var(--green-800);
    border:1px solid var(--green-200);
}
.kpi-trend.zero{background:var(--bg);color:var(--muted);border-color:var(--line);}
.kpi-trend i{font-size:9px;}

.kpi-num{
    font-size:32px;font-weight:900;color:var(--ink);
    letter-spacing:-1px;line-height:1;
    margin-bottom:6px;
}
.kpi-lbl{
    font-size:11.5px;font-weight:800;color:var(--muted);
    letter-spacing:.08em;text-transform:uppercase;
}
.kpi-sub{
    font-size:12px;color:var(--muted);
    margin-top:10px;padding-top:12px;
    border-top:1px dashed var(--line);
    display:flex;align-items:center;justify-content:space-between;
    font-weight:600;
}
.kpi-sub strong{color:var(--ink);font-weight:900;}

/* Chart grid */
.chart-grid{
    display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px;
}@media(max-width:1100px){.chart-grid{grid-template-columns:1fr;}}

.card-head{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:18px 22px 14px;border-bottom:1px solid var(--line-soft);
}
.card-head h3{
    font-size:14.5px;font-weight:800;color:var(--ink);
    display:flex;align-items:center;gap:10px;
}
.card-head h3 i{
    width:32px;height:32px;border-radius:10px;
    background:var(--green-100);color:var(--green-700);
    display:grid;place-items:center;font-size:13px;
}
.card-head .legend-inline{
    display:flex;gap:14px;font-size:11.5px;font-weight:700;color:var(--ink-soft);
}
.card-head .legend-dot{
    display:inline-flex;align-items:center;gap:6px;
}
.card-head .legend-dot::before{
    content:'';width:9px;height:9px;border-radius:3px;background:#10b981;
}
.card-head .legend-dot.blue::before{background:#0ea5e9;}
.card-head .legend-dot.purple::before{background:#8b5cf6;}

.card-body{padding:20px 22px 22px;}

.chart-wrap{position:relative;height:280px;}
.chart-wrap.tall{height:320px;}

/* Two col */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}@media(max-width:1100px){.two-col{grid-template-columns:1fr;}}

/* Table */
.rank-list{padding:8px 12px 14px;}
.rank-item{
    display:flex;align-items:center;gap:12px;
    padding:12px 14px;border-radius:14px;
    transition:all .25s var(--ease-smooth);
    text-decoration:none;color:inherit;
}
.rank-item:hover{background:var(--green-50);transform:translateX(4px);}
.rank-num{
    width:28px;height:28px;border-radius:9px;flex-shrink:0;
    display:grid;place-items:center;
    font-size:12px;font-weight:900;
    background:var(--green-100);color:var(--green-800);
    border:1px solid var(--green-200);
}
.rank-num.top1{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#fff;border-color:transparent;box-shadow:0 4px 10px rgba(245,158,11,.35);}
.rank-num.top2{background:linear-gradient(135deg,#cbd5e0,#94a3b8);color:#fff;border-color:transparent;}
.rank-num.top3{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border-color:transparent;}

.rank-avatar{
    width:40px;height:40px;border-radius:12px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;
    background:linear-gradient(135deg,#34d399,#059669);
    box-shadow:0 4px 10px rgba(16,185,129,.28);
}
.rank-avatar.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 4px 10px rgba(2,132,199,.28);}

.rank-meta{flex:1;min-width:0;}
.rank-name{font-size:13.5px;font-weight:800;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rank-sub{font-size:11.5px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rank-value{
    font-size:14px;font-weight:900;color:var(--green-700);
    flex-shrink:0;display:flex;align-items:center;gap:5px;
}
.rank-value i{font-size:11px;color:var(--muted);}

/* Progress bars */
.stat-bar-group{padding:8px 22px 22px;}
.stat-bar-item{margin-bottom:16px;}
.stat-bar-item:last-child{margin-bottom:0;}
.stat-bar-head{
    display:flex;justify-content:space-between;align-items:center;
    font-size:12.5px;font-weight:700;color:var(--ink-soft);
    margin-bottom:6px;
}
.stat-bar-head strong{color:var(--ink);font-weight:900;}
.stat-bar-track{
    height:10px;background:var(--bg);border-radius:999px;
    overflow:hidden;position:relative;
    border:1px solid var(--line-soft);
}
.stat-bar-fill{
    height:100%;border-radius:999px;
    background:linear-gradient(90deg,#10b981,#047857);
    transition:width 1s var(--ease-smooth);
    box-shadow:0 0 12px rgba(16,185,129,.4);
}
.stat-bar-fill.blue{background:linear-gradient(90deg,#0ea5e9,#0284c7);box-shadow:0 0 12px rgba(14,165,233,.4);}
.stat-bar-fill.amber{background:linear-gradient(90deg,#f59e0b,#d97706);box-shadow:0 0 12px rgba(217,119,6,.4);}
.stat-bar-fill.red{background:linear-gradient(90deg,#ef4444,#dc2626);box-shadow:0 0 12px rgba(220,38,38,.4);}
.stat-bar-fill.purple{background:linear-gradient(90deg,#8b5cf6,#7c3aed);box-shadow:0 0 12px rgba(124,58,237,.4);}

/* Heatmap hours */
.hour-grid{
    display:grid;grid-template-columns:repeat(24,1fr);gap:3px;
    padding:4px 0 8px;
}
.hour-cell{
    aspect-ratio:1/1;border-radius:5px;
    background:var(--bg);cursor:help;
    transition:all .2s var(--ease-spring);
    position:relative;
}
.hour-cell:hover{transform:scale(1.15);z-index:2;}
.hour-axis{
    display:flex;justify-content:space-between;
    font-size:10px;font-weight:700;color:var(--muted);
    padding-top:4px;
}

/* Empty mini */
.empty-mini{padding:36px 24px;text-align:center;color:var(--muted);font-size:13px;}
.empty-mini i{font-size:36px;color:var(--green-200);display:block;margin-bottom:12px;}@media(max-width:900px){
    .kpi-num{font-size:26px;}
    .chart-wrap{height:240px;}
    .chart-wrap.tall{height:260px;}
    .hour-grid{grid-template-columns:repeat(12,1fr);}}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <!-- HEADER -->
    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-chart-pie"></i></span>
                Analytics
            </h1>
            <p>Market insights, growth trends, and activity metrics for the last <strong><?= $days ?></strong> days.</p>
        </div>
        <div class="header-actions">
            <div class="range-tabs">
                <a href="?range=7"   class="range-tab <?= $range === '7'   ? 'active' : '' ?>">7d</a>
                <a href="?range=30"  class="range-tab <?= $range === '30'  ? 'active' : '' ?>">30d</a>
                <a href="?range=90"  class="range-tab <?= $range === '90'  ? 'active' : '' ?>">90d</a>
                <a href="?range=365" class="range-tab <?= $range === '365' ? 'active' : '' ?>">1y</a>
            </div>
            <a href="admin_dashboard.php" class="btn-header ghost">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </header>

    <!-- KPI CARDS -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-head">
                <div class="kpi-icon"><i class="fas fa-store"></i></div>
                <span class="kpi-trend <?= $kpi['vendors_new'] > 0 ? '' : 'zero' ?>">
                    <i class="fas fa-arrow-trend-up"></i>
                    +<?= (int)$kpi['vendors_new'] ?> new
                </span>
            </div>
            <div class="kpi-num"><?= (int)$kpi['vendors_total'] ?></div>
            <div class="kpi-lbl">Total Vendors</div>
            <div class="kpi-sub">
                <span>Pending approval</span>
                <strong><?= (int)$kpi['vendors_pending'] ?></strong>
            </div>
        </div>

        <div class="kpi-card k2">
            <div class="kpi-head">
                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                <span class="kpi-trend <?= $kpi['customers_new'] > 0 ? '' : 'zero' ?>">
                    <i class="fas fa-arrow-trend-up"></i>
                    +<?= (int)$kpi['customers_new'] ?> new
                </span>
            </div>
            <div class="kpi-num"><?= (int)$kpi['customers_total'] ?></div>
            <div class="kpi-lbl">Total Customers</div>
            <div class="kpi-sub">
                <span>Avg. per vendor</span>
                <strong><?= $kpi['vendors_total'] > 0 ? round($kpi['customers_total'] / $kpi['vendors_total'], 1) : '0' ?></strong>
            </div>
        </div>
    </div>

    <!-- GROWTH CHART -->
    <div class="chart-grid">
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-chart-area"></i> Growth Trend</h3>
                <div class="legend-inline">
                    <span class="legend-dot">Vendors</span>
                    <span class="legend-dot blue">Customers</span>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-wrap tall">
                    <canvas id="growthChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-clipboard-check"></i> Vendor Status</h3>
            </div>
            <div class="card-body">
                <div class="chart-wrap tall">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION & PERMIT/PAYMENT -->
    <div class="two-col">
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-layer-group"></i> Vendors by Market Section</h3>
            </div>
            <div class="card-body">
                <div class="chart-wrap">
                    <canvas id="sectionChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-file-shield"></i> Permit & Payment Compliance</h3>
            </div>
            <div class="card-body">
                <div class="chart-wrap">
                    <canvas id="complianceChart"></canvas>
                </div>
            </div>
        </div>
    </div>


    <!-- SECTION BREAKDOWN PROGRESS + HOURLY HEATMAP -->
    <div class="two-col">
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-chart-bar"></i> Section Distribution</h3>
            </div>
            <div class="stat-bar-group">
                <?php
                $maxSection = 0;
                foreach ($sectionData as $s) $maxSection = max($maxSection, (int)$s['c']);
                if (empty($sectionData)):
                ?>
                    <div class="empty-mini" style="padding:32px 0;"><i class="fas fa-folder-open"></i>No section data yet.</div>
                <?php else:
                    foreach (array_slice($sectionData, 0, 6) as $s):
                        $pctVal = $maxSection > 0 ? round(($s['c'] / $maxSection) * 100) : 0;
                ?>
                    <div class="stat-bar-item">
                        <div class="stat-bar-head">
                            <span><?= h($s['s']) ?></span>
                            <strong><?= (int)$s['c'] ?> vendors</strong>
                        </div>
                        <div class="stat-bar-track">
                            <div class="stat-bar-fill" style="width:<?= $pctVal ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-clock"></i> Peak Vendor Message Hours (30d)</h3>
            </div>
            <div class="card-body">
                <?php
                $maxHour = max($peakHours);
                $maxHour = $maxHour > 0 ? $maxHour : 1;
                ?>
                <div class="hour-grid">
                    <?php foreach ($peakHours as $hr => $cnt):
                        $intensity = $cnt / $maxHour;
                        $bg = 'var(--bg)';
                        if ($intensity > 0.8) $bg = 'linear-gradient(135deg,#047857,#065f46)';
                        elseif ($intensity > 0.5) $bg = 'linear-gradient(135deg,#10b981,#059669)';
                        elseif ($intensity > 0.25) $bg = 'linear-gradient(135deg,#6ee7b7,#34d399)';
                        elseif ($intensity > 0) $bg = 'linear-gradient(135deg,#d1fae5,#a7f3d0)';
                    ?>
                        <div class="hour-cell" style="background:<?= $bg ?>;" title="<?= $hr ?>:00 - <?= $cnt ?> vendor messages"></div>
                    <?php endforeach; ?>
                </div>
                <div class="hour-axis">
                    <span>12 AM</span>
                    <span>6 AM</span>
                    <span>12 PM</span>
                    <span>6 PM</span>
                    <span>11 PM</span>
                </div>

                <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--line);display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:var(--ink-soft);">
                    <span>Busiest hour</span>
                    <strong style="color:var(--ink);">
                        <?php
                        $peakH = array_search($maxHour, $peakHours);
                        echo $peakH !== false ? date('g A', strtotime(sprintf('%02d:00', $peakH))) : '—';
                        ?>
                    </strong>
                </div>
            </div>
        </div>
    </div>

</main>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

/* ============================================================
   Chart defaults
   ============================================================ */
Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
Chart.defaults.font.size = 11.5;
Chart.defaults.color = '#64748b';

const GREEN   = '#10b981';
const GREEN_D = '#047857';
const BLUE    = '#0ea5e9';
const AMBER   = '#f59e0b';
const RED     = '#ef4444';
const TEAL    = '#0d9488';

/* ============================================================
   Growth Trend
   ============================================================ */
const growthLabels = <?= json_encode(array_values($labels)) ?>;
const vendorSeries = <?= json_encode(array_values($series['vendors'])) ?>;
const customerSeries = <?= json_encode(array_values($series['customers'])) ?>;

const gctx = document.getElementById('growthChart').getContext('2d');
const gGradient = gctx.createLinearGradient(0, 0, 0, 320);
gGradient.addColorStop(0, 'rgba(16,185,129,.32)');
gGradient.addColorStop(1, 'rgba(16,185,129,0)');

new Chart(gctx, {
    type: 'line',
    data: {
        labels: growthLabels,
        datasets: [
            {
                label: 'Vendors',
                data: vendorSeries,
                borderColor: GREEN,
                backgroundColor: gGradient,
                borderWidth: 2.5,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#fff',
                pointBorderColor: GREEN,
                pointBorderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 6,
            },
            {
                label: 'Customers',
                data: customerSeries,
                borderColor: BLUE,
                backgroundColor: 'rgba(14,165,233,.08)',
                borderWidth: 2.5,
                tension: 0.4,
                fill: false,
                pointBackgroundColor: '#fff',
                pointBorderColor: BLUE,
                pointBorderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 6,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                titleFont: { size: 12, weight: '800' },
                bodyFont: { size: 12 },
                boxPadding: 6,
                borderColor: 'rgba(16,185,129,.3)',
                borderWidth: 1,
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10, font: { weight: '600' } }
            },
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(226,232,240,.5)', drawBorder: false },
                ticks: { font: { weight: '600' }, precision: 0 }
            }
        }
    }
});

/* ============================================================
   Vendor Status (Doughnut)
   ============================================================ */
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Pending', 'Rejected'],
        datasets: [{
            data: [
                <?= (int)$vendorStatus['Approved'] ?>,
                <?= (int)$vendorStatus['Pending'] ?>,
                <?= (int)$vendorStatus['Rejected'] ?>
            ],
            backgroundColor: [GREEN, AMBER, RED],
            borderColor: '#fff',
            borderWidth: 4,
            hoverOffset: 8,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 14,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: { size: 12, weight: '700' },
                    color: '#475569',
                }
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: ctx => ` ${ctx.label}: ${ctx.parsed}`
                }
            }
        }
    }
});

/* ============================================================
   Section Distribution (Bar)
   ============================================================ */
const sectionLabels = <?= json_encode(array_column($sectionData, 's')) ?>;
const sectionCounts = <?= json_encode(array_map('intval', array_column($sectionData, 'c'))) ?>;

const sctx = document.getElementById('sectionChart').getContext('2d');
const sGradient = sctx.createLinearGradient(0, 0, 0, 280);
sGradient.addColorStop(0, GREEN);
sGradient.addColorStop(1, GREEN_D);

new Chart(sctx, {
    type: 'bar',
    data: {
        labels: sectionLabels,
        datasets: [{
            label: 'Vendors',
            data: sectionCounts,
            backgroundColor: sGradient,
            borderRadius: 8,
            borderSkipped: false,
            maxBarThickness: 36,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: ctx => ` ${ctx.parsed.y} vendors`
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { weight: '600', size: 10.5 }, maxRotation: 30, minRotation: 0 }
            },
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(226,232,240,.5)', drawBorder: false },
                ticks: { font: { weight: '600' }, precision: 0 }
            }
        }
    }
});

/* ============================================================
   Compliance (Horizontal stacked bar)
   ============================================================ */
const cctx = document.getElementById('complianceChart').getContext('2d');
new Chart(cctx, {
    type: 'bar',
    data: {
        labels: ['Permit', 'Payment'],
        datasets: [
            {
                label: 'Valid / Paid',
                data: [
                    <?= (int)$permitData['Valid'] ?>,
                    <?= (int)$paymentData['Paid'] ?>
                ],
                backgroundColor: GREEN,
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 44,
            },
            {
                label: 'Pending / Partial',
                data: [
                    <?= (int)$permitData['Pending'] ?>,
                    <?= (int)$paymentData['Partial'] ?>
                ],
                backgroundColor: AMBER,
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 44,
            },
            {
                label: 'Expired / Unpaid',
                data: [
                    <?= (int)$permitData['Expired'] + (int)$permitData['Suspended'] ?>,
                    <?= (int)$paymentData['Unpaid'] ?>
                ],
                backgroundColor: RED,
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 44,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 12,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: { size: 11.5, weight: '700' },
                    color: '#475569',
                }
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
            }
        },
        scales: {
            x: {
                stacked: true,
                beginAtZero: true,
                grid: { color: 'rgba(226,232,240,.5)', drawBorder: false },
                ticks: { font: { weight: '600' }, precision: 0 }
            },
            y: {
                stacked: true,
                grid: { display: false },
                ticks: { font: { weight: '700', size: 12 }, color: '#475569' }
            }
        }
    }
});
</script>

</body>
</html>
