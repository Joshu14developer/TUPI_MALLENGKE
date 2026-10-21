<?php
session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
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

/* ---------- Live stats ---------- */
$stat = [
    'vendors'     => 0,
    'customers'   => 0,
    'admins'      => 0,
    'pending'     => 0,
    'approved'    => 0,
    'rejected'    => 0,
];

if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors"))                          $stat['vendors']    = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM customers"))                        $stat['customers']  = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM admin_users WHERE is_active = 1"))  $stat['admins']     = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors WHERE status = 'Pending'")) $stat['pending']    = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors WHERE status = 'Approved'"))$stat['approved']   = (int)($res->fetch_assoc()['c'] ?? 0);
if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors WHERE status = 'Rejected'"))$stat['rejected']   = (int)($res->fetch_assoc()['c'] ?? 0);

/* Recent vendors */
$recentVendors = [];
if ($res = $conn->query(
    "SELECT id, first_name, middle_name, last_name, business_name,
            stall_number, market_section, status, created_at
       FROM vendors ORDER BY created_at DESC LIMIT 6"
)) {
    while ($r = $res->fetch_assoc()) $recentVendors[] = $r;
}

/* Recent customers */
$recentCustomers = [];
if ($res = $conn->query(
    "SELECT id, first_name, last_name, email, created_at
       FROM customers ORDER BY created_at DESC LIMIT 6"
)) {
    while ($r = $res->fetch_assoc()) $recentCustomers[] = $r;
}

/* Recent admin activity (needs the log table) */
$recentActivity = [];
if ($res = $conn->query(
    "SELECT l.id, l.action, l.target, l.details, l.created_at,
            a.username AS admin_username, a.full_name AS admin_name
       FROM admin_activity_log l
       LEFT JOIN admin_users a ON a.id = l.admin_id
      ORDER BY l.created_at DESC
      LIMIT 6"
)) {
    while ($r = $res->fetch_assoc()) $recentActivity[] = $r;
}

/* Active admins list */
$activeAdmins = [];
if ($res = $conn->query(
    "SELECT id, username, full_name, role, avatar_color, last_login
       FROM admin_users WHERE is_active = 1
      ORDER BY last_login DESC, created_at DESC LIMIT 5"
)) {
    while ($r = $res->fetch_assoc()) $activeAdmins[] = $r;
}

function ini_from($name) {
    $p = preg_split('/\s+/', trim((string)$name));
    return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1));
}
function time_ago($ts) {
    if (!$ts) return '—';
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60)  . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Admin Dashboard • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>


/* Hero */
.admin-hero{
    position:relative;overflow:hidden;
    border-radius:24px;padding:30px 32px;
    background:
        radial-gradient(circle at 88% 15%, rgba(74,222,128,.32) 0%, transparent 55%),
        linear-gradient(135deg, #04231a 0%, #0a3d29 55%, #14532d 100%);
    color:#fff;
    display:flex;align-items:center;justify-content:space-between;
    gap:24px;flex-wrap:wrap;
    margin-bottom:20px;
    box-shadow:0 22px 50px rgba(5,46,22,.28);
}
.admin-hero::after{
    content:'';position:absolute;top:-60px;right:-60px;
    width:280px;height:280px;border-radius:50%;
    background:radial-gradient(circle, rgba(74,222,128,.28) 0%, transparent 68%);
    animation:floatY 7s ease-in-out infinite;
    pointer-events:none;
}
@keyframes floatY{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
.admin-hero-text{position:relative;z-index:2;max-width:640px;}
.admin-hero-chip{
    display:inline-flex;align-items:center;gap:7px;
    padding:6px 14px;border-radius:999px;
    background:rgba(74,222,128,.16);border:1px solid rgba(74,222,128,.32);
    color:#bbf7d0;font-size:11px;font-weight:800;
    letter-spacing:.1em;text-transform:uppercase;
    margin-bottom:14px;
}
.admin-hero h2{font-size:26px;font-weight:900;letter-spacing:-.6px;line-height:1.2;}
.admin-hero h2 span{color:#4ade80;}
.admin-hero p{font-size:14px;color:#a9cbb9;margin-top:8px;line-height:1.6;}
.admin-hero-art{
    position:relative;z-index:2;
    width:120px;height:120px;border-radius:50%;
    background:radial-gradient(circle, rgba(74,222,128,.22) 0%, rgba(74,222,128,.05) 70%);
    border:1px solid rgba(74,222,128,.24);
    display:grid;place-items:center;
    animation:floatY 4.5s ease-in-out infinite;
}
.admin-hero-art i{font-size:48px;color:#4ade80;opacity:.9;}@media(max-width:640px){.admin-hero-art{display:none;}}
.stat-card::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(135deg, rgba(16,185,129,.07), transparent 60%);
    opacity:0;transition:opacity .3s;
}
.stat-card:hover::before{opacity:1;}
.stat-card:hover .stat-icon{transform:scale(1.12) rotate(-7deg);}
.stat-icon.g1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 8px 22px rgba(16,185,129,.35);}
.stat-icon.g2{background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 8px 22px rgba(52,211,153,.35);}
.stat-icon.g3{background:linear-gradient(135deg,#0d9488,#0f766e);box-shadow:0 8px 22px rgba(13,148,136,.32);}
.stat-icon.g4{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 8px 22px rgba(14,165,233,.32);}

/* Sub stat pills */
.substats{
    display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;
}
.substat{
    display:inline-flex;align-items:center;gap:8px;
    padding:8px 14px;border-radius:12px;
    background:#fff;border:1.5px solid var(--line);
    font-size:12.5px;font-weight:700;color:var(--ink-soft);
    box-shadow:var(--sh-sm);
}
.substat .dot{
    width:9px;height:9px;border-radius:50%;
    background:var(--green-500);
}
.substat.pending .dot { background:#f59e0b; }
.substat.approved .dot{ background:#10b981; }
.substat.rejected .dot{ background:#ef4444; }
.substat strong{color:var(--ink);font-weight:900;}

/* Body grid */
.admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.admin-grid .full{grid-column:1/-1;}@media(max-width:1000px){.admin-grid{grid-template-columns:1fr;}}

.card-head{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:18px 22px 14px;border-bottom:1px solid var(--line-soft);
}
.card-head h3{
    font-size:15px;font-weight:800;color:var(--ink);
    display:flex;align-items:center;gap:10px;
}
.card-head h3 i{
    width:32px;height:32px;border-radius:10px;
    background:var(--green-100);color:var(--green-700);
    display:grid;place-items:center;font-size:13px;
}
.card-head a{
    font-size:12.5px;font-weight:800;color:var(--green-700);
    text-decoration:none;display:inline-flex;align-items:center;gap:7px;
    padding:7px 13px;border-radius:10px;
    transition:all .25s var(--ease-spring);
}
.card-head a:hover{background:var(--green-100);transform:translateX(3px);}

.list{padding:8px 12px 14px;}
.list-item{
    display:flex;align-items:center;gap:13px;
    padding:12px 14px;border-radius:14px;
    text-decoration:none;color:inherit;
    transition:all .25s var(--ease-smooth);
}
.list-item:hover{background:var(--green-50);transform:translateX(4px);}
.list-avatar{
    width:44px;height:44px;border-radius:14px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;
    font-weight:800;font-size:15px;
    background:linear-gradient(135deg,#34d399,#059669);
    box-shadow:0 5px 14px rgba(16,185,129,.28);
    transition:transform .35s var(--ease-spring);
}
.list-item:hover .list-avatar{transform:scale(1.08) rotate(-4deg);}
.list-avatar.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 5px 14px rgba(2,132,199,.28);}
.list-avatar.amber{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 5px 14px rgba(217,119,6,.28);}
.list-meta{flex:1;min-width:0;}
.list-title{font-size:13.5px;font-weight:800;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.list-sub{font-size:12px;color:var(--muted);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.list-badge{
    font-size:10.5px;font-weight:800;
    padding:4px 10px;border-radius:999px;
    background:var(--green-100);color:var(--green-800);
    border:1px solid var(--green-200);
    flex-shrink:0;text-transform:capitalize;
}
.list-badge.pending { background:#fef3c7;color:#92400e;border-color:#fde68a; }
.list-badge.approved{ background:var(--green-100);color:var(--green-800);border-color:var(--green-200); }
.list-badge.rejected{ background:#fee2e2;color:#991b1b;border-color:#fecaca; }

.list-time{font-size:11px;color:var(--muted);font-weight:700;flex-shrink:0;}

.empty-mini{padding:44px 24px;text-align:center;color:var(--muted);font-size:13px;}
.empty-mini i{font-size:40px;color:var(--green-200);display:block;margin-bottom:12px;}

/* Quick actions */
.quick-actions{
    display:grid;grid-template-columns:repeat(4,1fr);gap:12px;
    padding:14px 18px 18px;
}@media(max-width:760px){.quick-actions{grid-template-columns:repeat(2,1fr);}}
.quick-action{
    display:flex;flex-direction:column;gap:9px;
    padding:16px;border-radius:14px;
    background:#fbfcfd;border:1.5px solid var(--line);
    text-decoration:none;color:inherit;
    transition:all .28s var(--ease-spring);
    position:relative;overflow:hidden;
}
.quick-action::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(135deg, rgba(16,185,129,.08), transparent 60%);
    opacity:0;transition:opacity .3s;
}
.quick-action:hover{border-color:var(--green-300);transform:translateY(-4px);box-shadow:var(--sh-lg);}
.quick-action:hover::before{opacity:1;}
.quick-action i.qa-icon{
    width:40px;height:40px;border-radius:12px;
    display:grid;place-items:center;color:#fff;
    font-size:16px;position:relative;z-index:2;
    background:linear-gradient(135deg,#10b981,#047857);
    box-shadow:var(--glow-soft);
    transition:transform .4s var(--ease-spring);
}
.quick-action:hover i.qa-icon{transform:scale(1.1) rotate(-6deg);}
.quick-action h4{font-size:13.5px;font-weight:800;color:var(--ink);position:relative;z-index:2;}
.quick-action p{font-size:11.5px;color:var(--muted);position:relative;z-index:2;line-height:1.5;}

/* Admin list */
.admin-avatar{
    width:44px;height:44px;border-radius:14px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;
    font-weight:800;font-size:15px;
    box-shadow:0 5px 14px rgba(16,185,129,.28);
}
.role-badge{
    font-size:10px;font-weight:800;
    padding:4px 10px;border-radius:999px;
    text-transform:uppercase;letter-spacing:.06em;
}
.role-badge.superadmin{background:linear-gradient(135deg,#10b981,#047857);color:#fff;}
.role-badge.admin     {background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200);}
.role-badge.moderator {background:#e0f2fe;color:#075985;border:1px solid #bae6fd;}@media(max-width:900px){
    .admin-hero{padding:24px 22px;}
    .admin-hero h2{font-size:22px;}}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-shield-halved"></i></span>
                Admin Dashboard
            </h1>
            <p>Welcome back, <?= htmlspecialchars($adminName) ?> — signed in as <strong><?= htmlspecialchars($adminRole) ?></strong>.</p>
        </div>
        <div class="header-actions">
            <a href="admin_vendors.php" class="btn-header ghost">
                <i class="fas fa-store"></i> Manage Vendors
            </a>
            <a href="admin_customers.php" class="btn-header primary">
                <i class="fas fa-users"></i> Manage Customers
            </a>
        </div>
    </header>

    <!-- HERO -->
    <section class="admin-hero">
        <div class="admin-hero-text">
            <span class="admin-hero-chip"><i class="fas fa-shield-halved"></i> Administrator Access</span>
            <h2>Mallengke Public Market — <span>Admin Control</span></h2>
            <p>Monitor vendors, customers, stalls, and message activity in one place. All admin actions are logged automatically.</p>
        </div>
        <div class="admin-hero-art"><i class="fas fa-chart-pie"></i></div>
    </section>

    <!-- STATS -->
    <div class="stat-grid">
        <a class="stat-card" href="admin_vendors.php">
            <div class="stat-icon g1"><i class="fas fa-store"></i></div>
            <div>
                <div class="stat-num"><?= $stat['vendors'] ?></div>
                <div class="stat-lbl">Total Vendors</div>
            </div>
        </a>
        <a class="stat-card" href="admin_customers.php">
            <div class="stat-icon g2"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-num"><?= $stat['customers'] ?></div>
                <div class="stat-lbl">Customers</div>
            </div>
        </a>
        <a class="stat-card" href="admin_dashboard.php">
            <div class="stat-icon g4"><i class="fas fa-shield-halved"></i></div>
            <div>
                <div class="stat-num"><?= $stat['admins'] ?></div>
                <div class="stat-lbl">Active Admins</div>
            </div>
        </a>
    </div>

    <!-- SUBSTATS -->
    <div class="substats">
        <span class="substat pending">
            <span class="dot"></span>
            Pending applications <strong><?= $stat['pending'] ?></strong>
        </span>
        <span class="substat approved">
            <span class="dot"></span>
            Approved vendors <strong><?= $stat['approved'] ?></strong>
        </span>
        <span class="substat rejected">
            <span class="dot"></span>
            Rejected <strong><?= $stat['rejected'] ?></strong>
        </span>
    </div>

    <!-- BODY GRID -->
    <div class="admin-grid">

        <!-- RECENT VENDORS -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-store"></i> Recently Registered Vendors</h3>
                <a href="admin_vendors.php">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="list">
                <?php if (empty($recentVendors)): ?>
                    <div class="empty-mini"><i class="fas fa-store-slash"></i>No vendors registered yet.</div>
                <?php else: foreach ($recentVendors as $v):
                    $name = trim(($v['first_name'] ?? '') . ' ' . ($v['middle_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                ?>
                    <a class="list-item" href="admin_vendors.php?id=<?= (int)$v['id'] ?>">
                        <div class="list-avatar"><?= htmlspecialchars(ini_from($name)) ?></div>
                        <div class="list-meta">
                            <div class="list-title"><?= htmlspecialchars($name) ?></div>
                            <div class="list-sub">
                                <?= $v['business_name'] ? htmlspecialchars($v['business_name']) : 'Independent vendor' ?>
                                · <?= htmlspecialchars($v['stall_number'] ?: '—') ?>
                                · <?= htmlspecialchars($v['market_section'] ?: '—') ?>
                            </div>
                        </div>
                        <span class="list-badge <?= htmlspecialchars(strtolower($v['status'] ?? 'pending')) ?>">
                            <?= htmlspecialchars($v['status'] ?? 'pending') ?>
                        </span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- RECENT CUSTOMERS -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-users"></i> Recently Registered Customers</h3>
                <a href="admin_customers.php">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="list">
                <?php if (empty($recentCustomers)): ?>
                    <div class="empty-mini"><i class="fas fa-user-slash"></i>No customers yet.</div>
                <?php else: foreach ($recentCustomers as $c):
                    $cname = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                ?>
                    <a class="list-item" href="admin_customers.php?id=<?= (int)$c['id'] ?>">
                        <div class="list-avatar blue"><?= htmlspecialchars(ini_from($cname)) ?></div>
                        <div class="list-meta">
                            <div class="list-title"><?= htmlspecialchars($cname) ?></div>
                            <div class="list-sub"><?= htmlspecialchars($c['email'] ?: 'No email') ?></div>
                        </div>
                        <span class="list-time"><?= htmlspecialchars(time_ago($c['created_at'])) ?></span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- ADMIN TEAM -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-user-shield"></i> Admin Team</h3>
                <?php if ($adminRole === 'superadmin'): ?>
                    <a href="admin_dashboard.php">Admin overview <i class="fas fa-arrow-right"></i></a>
                <?php endif; ?>
            </div>
            <div class="list">
                <?php if (empty($activeAdmins)): ?>
                    <div class="empty-mini"><i class="fas fa-user-slash"></i>No admins found.</div>
                <?php else: foreach ($activeAdmins as $a):
                    $ainitials = ini_from($a['full_name'] ?: $a['username']);
                ?>
                    <div class="list-item">
                        <div class="list-avatar" style="background:linear-gradient(135deg, <?= htmlspecialchars($a['avatar_color']) ?>dd, <?= htmlspecialchars($a['avatar_color']) ?>);">
                            <?= htmlspecialchars($ainitials) ?>
                        </div>
                        <div class="list-meta">
                            <div class="list-title"><?= htmlspecialchars($a['full_name'] ?: $a['username']) ?></div>
                            <div class="list-sub">
                                @<?= htmlspecialchars($a['username']) ?>
                                · Last login: <?= htmlspecialchars(time_ago($a['last_login'])) ?>
                            </div>
                        </div>
                        <span class="role-badge <?= htmlspecialchars($a['role']) ?>">
                            <?= htmlspecialchars($a['role']) ?>
                        </span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-clipboard-list"></i> Recent Activity</h3>
                <a href="admin_dashboard.php">View all <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="list">
                <?php if (empty($recentActivity)): ?>
                    <div class="empty-mini"><i class="fas fa-history"></i>No activity logged yet.</div>
                <?php else: foreach ($recentActivity as $l):
                    $aName = $l['admin_name'] ?: $l['admin_username'] ?: 'System';
                ?>
                    <div class="list-item">
                        <div class="list-avatar amber"><i class="fas fa-bolt" style="font-size:14px;"></i></div>
                        <div class="list-meta">
                            <div class="list-title"><?= htmlspecialchars($l['action']) ?></div>
                            <div class="list-sub">
                                <?= htmlspecialchars($aName) ?>
                                <?= $l['target'] ? ' → ' . htmlspecialchars($l['target']) : '' ?>
                                <?= $l['details'] ? ' · ' . htmlspecialchars($l['details']) : '' ?>
                            </div>
                        </div>
                        <span class="list-time"><?= htmlspecialchars(time_ago($l['created_at'])) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="card full">
            <div class="card-head">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="admin_vendors.php" class="quick-action">
                    <i class="fas fa-store qa-icon"></i>
                    <h4>Manage Vendors</h4>
                    <p>Approve applications, edit details, and manage stalls.</p>
                </a>
                <a href="admin_customers.php" class="quick-action">
                    <i class="fas fa-users qa-icon"></i>
                    <h4>Manage Customers</h4>
                    <p>View accounts, contact info, and activity.</p>
                </a>
            </div>
        </div>

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