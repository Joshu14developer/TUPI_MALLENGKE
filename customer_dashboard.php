<?php
$conn = require __DIR__ . '/config/db.php';
session_start();
require_once __DIR__ . '/config/profile.php';

if (!isset($_SESSION['customer_id'])) { header('Location: php/customer_login.php'); exit; }
$customerId = (int)$_SESSION['customer_id'];

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $customerId); $stmt->execute();
$customer = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$customer) { session_destroy(); header('Location: php/customer_login.php'); exit; }

$fullName = trim($customer['first_name'] . ' ' . $customer['last_name']);
$initials = strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1));
$customerAvatarUrl   = profile_avatar_url($customer['avatar_path'] ?? '', '');
$customerAvatarToken = profile_avatar_token();

/* The customer <-> vendor chat was retired; old links land on the dashboard. */
$view = $_GET['view'] ?? 'home';
if ($view === 'messages') $view = 'home';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Dashboard • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="assets/mallengke-app.css?v=20261021h" />
<style>
:root{--green-950:#022c22;/* ---- chat theme vars (defaults = soft sage) ---- */
    --chat-bg:#f1f5f9;--chat-bg-image:none;--bubble-in-bg:#ffffff;--bubble-in-text:#0f172a;--bubble-in-shadow:0 1px 3px rgba(2,44,34,.10);--bubble-in-meta:rgba(15,23,42,.45);--bubble-out-bg:linear-gradient(135deg,#10b981,#047857);--bubble-out-text:#ffffff;--bubble-out-shadow:0 4px 12px rgba(4,120,87,.28);--bubble-out-meta:rgba(255,255,255,.88);--day-sep-bg:rgba(6,78,59,.08);--day-sep-color:#047857;--chat-scrollbar:rgba(6,78,59,.22);/* ---- misc ---- */
    --accent:#059669;--accent-soft:#d1fae5;--accent-deep:#047857;}

.sidebar-link:hover .sidebar-count{transform:scale(1.12)}

.page-header{
    display:flex;justify-content:space-between;align-items:flex-end;gap:18px;
    flex-wrap:wrap;margin-bottom:22px;
    animation:fadeUp .45s var(--ease-expo);
}
.page-header h1{
    font-size:26px;font-weight:900;color:var(--ink);letter-spacing:-.7px;
    display:flex;align-items:center;gap:12px;
}
.page-header h1 .h1-icon{
    width:42px;height:42px;border-radius:14px;
    background:linear-gradient(135deg,#10b981,#047857);
    display:grid;place-items:center;color:#fff;font-size:17px;
    box-shadow:var(--glow-soft);
    transition:transform .4s var(--ease-spring);
}
.page-header:hover h1 .h1-icon{transform:rotate(-8deg) scale(1.06)}
.page-header p{font-size:13.5px;color:var(--muted);margin-top:6px;font-weight:500}
.btn-header.primary:active{transform:translateY(-1px) scale(.98)}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ============================================================
   HERO
   ============================================================ */
.hero{
    position:relative;overflow:hidden;
    border-radius:26px;padding:34px 36px;margin-bottom:20px;
    background:
        radial-gradient(circle at 88% 12%, rgba(74,222,128,.32) 0%, transparent 52%),
        radial-gradient(circle at 8% 100%, rgba(34,197,94,.18) 0%, transparent 45%),
        linear-gradient(135deg,#04231a 0%,#0a3d29 50%,#14532d 100%);
    color:#fff;
    display:flex;align-items:center;justify-content:space-between;gap:28px;
    box-shadow:0 22px 50px rgba(5,46,22,.28);
    animation:fadeUp .5s var(--ease-expo);
}
.hero::after{
    content:'';position:absolute;top:-60px;right:-60px;width:260px;height:260px;border-radius:50%;
    background:radial-gradient(circle, rgba(74,222,128,.28) 0%, transparent 68%);
    animation:pulseGlow 6s ease-in-out infinite;
}
@keyframes pulseGlow{0%,100%{opacity:.55;transform:scale(1)}50%{opacity:1;transform:scale(1.1)}}
.hero-chip{
    display:inline-flex;align-items:center;gap:8px;
    padding:6px 14px;border-radius:999px;
    background:rgba(74,222,128,.16);border:1px solid rgba(74,222,128,.3);
    color:#bbf7d0;font-size:11.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
    position:relative;z-index:2;margin-bottom:16px;
}
.hero h2{
    font-size:29px;font-weight:900;letter-spacing:-.9px;line-height:1.2;
    position:relative;z-index:2;
}
.hero h2 span{color:#4ade80}
.hero p{
    font-size:14.5px;color:#a9cbb9;margin-top:10px;max-width:520px;
    position:relative;z-index:2;line-height:1.6;
}
.hero-actions{margin-top:24px;display:flex;gap:11px;flex-wrap:wrap;position:relative;z-index:2}
.btn-hero{
    display:inline-flex;align-items:center;gap:9px;
    padding:12px 22px;border-radius:13px;
    font-size:13.5px;font-weight:700;text-decoration:none;
    transition:all .28s var(--ease-spring);
}
.btn-hero.primary{background:linear-gradient(135deg,#4ade80,#16a34a);color:#04231a;box-shadow:0 12px 28px rgba(34,197,94,.45)}
.btn-hero.primary:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 18px 38px rgba(34,197,94,.6)}
.btn-hero.ghost{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.2);color:#fff}
.btn-hero.ghost:hover{background:rgba(255,255,255,.18);transform:translateY(-2px)}
.hero-art{
    flex-shrink:0;width:140px;height:140px;border-radius:50%;
    display:grid;place-items:center;position:relative;z-index:2;
    background:radial-gradient(circle, rgba(74,222,128,.22) 0%, rgba(74,222,128,.05) 70%);
    border:1px solid rgba(74,222,128,.22);
    animation:floatY 4.5s ease-in-out infinite;
}
.hero-art i{font-size:56px;color:#4ade80;opacity:.9}
@keyframes floatY{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}@media(max-width:760px){.hero{padding:26px 24px;flex-direction:column;align-items:flex-start}.hero-art{display:none}.hero h2{font-size:24px}}

/* ============================================================
   STATS
   ============================================================ */
.stats-grid{
    display:grid;grid-template-columns:repeat(3,1fr);gap:14px;
    margin-bottom:20px;
    animation:fadeUp .55s var(--ease-expo);
}@media(max-width:720px){.stats-grid{grid-template-columns:1fr}}
.stat-card::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(135deg, rgba(16,185,129,.09), transparent 62%);
    opacity:0;transition:opacity .3s;
}
.stat-card:hover::before{opacity:1}
.stat-card:hover .stat-icon{transform:scale(1.12) rotate(-7deg)}
.stat-icon.g1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 8px 22px rgba(16,185,129,.35)}
.stat-icon.g2{background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 8px 22px rgba(52,211,153,.35)}
.stat-icon.g3{background:linear-gradient(135deg,#0d9488,#0f766e);box-shadow:0 8px 22px rgba(13,148,136,.32)}

/* ============================================================
   DASHBOARD GRID
   ============================================================ */
.dash-grid{
    display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start;
    animation:fadeUp .6s var(--ease-expo);
}@media(max-width:980px){.dash-grid{grid-template-columns:1fr}}

/* --- profile card --- */
.profile-card{padding:30px 24px;text-align:center}
.avatar{
    width:88px;height:88px;margin:0 auto 18px;border-radius:26px;
    background:linear-gradient(135deg,#34d399,#047857);
    display:grid;place-items:center;
    font-size:31px;font-weight:900;color:#fff;
    box-shadow:0 14px 32px rgba(16,185,129,.4);
    transition:transform .45s var(--ease-spring),box-shadow .3s;
}
.avatar:hover{transform:scale(1.07) rotate(-3deg);box-shadow:0 20px 44px rgba(16,185,129,.52)}
.profile-card h2{font-size:19px;font-weight:900;color:var(--ink);letter-spacing:-.4px}
.profile-card .email{font-size:13px;color:var(--muted);margin:5px 0 16px;word-break:break-all}
.role-badge{
    display:inline-flex;align-items:center;gap:7px;
    padding:7px 16px;border-radius:999px;
    background:var(--green-100);color:var(--green-800);
    font-size:11.5px;font-weight:800;letter-spacing:.03em;
    border:1px solid var(--green-200);
    margin-bottom:22px;
    transition:transform .3s var(--ease-spring);
}
.role-badge:hover{transform:scale(1.05)}
.info-list{text-align:left;border-top:1px solid var(--line-soft);padding-top:16px}
.info-list .row{
    display:flex;justify-content:space-between;gap:10px;
    padding:10px 8px;font-size:13px;border-radius:9px;
    transition:background .22s,padding-left .22s;
}
.info-list .row:hover{background:var(--green-50);padding-left:12px}
.info-list .label{color:var(--muted);font-weight:600}
.info-list .value{color:var(--ink);font-weight:700;text-align:right;overflow:hidden;text-overflow:ellipsis}
.profile-card .edit-cta{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    width:100%;margin-top:16px;padding:12px 16px;
    border-radius:12px;
    background:linear-gradient(135deg,#10b981,#047857);
    color:#fff;font-size:13px;font-weight:800;text-decoration:none;
    transition:all .28s var(--ease-spring);
    box-shadow:0 8px 20px rgba(16,185,129,.3);
}
.profile-card .edit-cta:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(16,185,129,.4)}

/* --- recent messages --- */
.card-head{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:20px 22px 16px;border-bottom:1px solid var(--line-soft);
}
.card-head h3{
    font-size:15.5px;font-weight:800;color:var(--ink);
    display:flex;align-items:center;gap:10px;letter-spacing:-.2px;
}
.card-head h3 i{
    width:32px;height:32px;border-radius:10px;
    background:var(--green-100);color:var(--accent-deep);
    display:grid;place-items:center;font-size:13px;
}
.link-more{
    font-size:12.5px;font-weight:700;color:var(--accent-deep);
    text-decoration:none;display:inline-flex;align-items:center;gap:7px;
    padding:7px 13px;border-radius:10px;
    transition:all .25s var(--ease-spring);
}
.link-more:hover{background:var(--green-100);transform:translateX(3px)}

.recent-list{padding:8px}
.recent-item{
    display:flex;align-items:center;gap:13px;
    padding:12px 14px;border-radius:15px;
    text-decoration:none;color:inherit;
    transition:all .25s var(--ease-smooth);
    position:relative;
}
.recent-item:hover{background:var(--green-50);transform:translateX(4px)}
.recent-avatar{
    width:46px;height:46px;border-radius:15px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;font-weight:800;font-size:15px;
    background:linear-gradient(135deg,#34d399,#059669);
    box-shadow:0 5px 14px rgba(16,185,129,.28);
    transition:transform .35s var(--ease-spring);
}
.recent-item:hover .recent-avatar{transform:scale(1.08) rotate(-4deg)}
.recent-meta{flex:1;min-width:0}
.recent-top{display:flex;align-items:baseline;justify-content:space-between;gap:10px}
.recent-name{font-size:14px;font-weight:700;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.recent-time{font-size:11px;font-weight:700;color:var(--muted);flex-shrink:0}
.recent-preview{
    font-size:12.5px;color:var(--muted);margin-top:3px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.recent-item.unread .recent-name{color:var(--accent-deep)}
.recent-item.unread .recent-preview{color:var(--ink-soft);font-weight:600}
.recent-badge{
    min-width:22px;height:22px;padding:0 7px;border-radius:999px;flex-shrink:0;
    background:linear-gradient(135deg,#10b981,#047857);color:#fff;
    font-size:11px;font-weight:800;
    display:grid;place-items:center;
    box-shadow:var(--glow-soft);
    animation:badgePop .5s var(--ease-spring);
}
@keyframes badgePop{0%{transform:scale(0)}60%{transform:scale(1.3)}100%{transform:scale(1)}}
.recent-empty{text-align:center;padding:52px 24px;color:var(--muted)}
.recent-empty i{font-size:44px;color:var(--green-200);display:block;margin-bottom:14px}
.recent-empty p{font-size:13.5px;margin-bottom:16px}

    </style>
</head>
<body>

<?php require __DIR__ . '/includes/customer_sidebar.php'; ?>

<main class="main-content">

<!-- ================= DASHBOARD VIEW ================= -->
<header class="page-header">
    <div>
        <h1><span class="h1-icon"><i class="fas fa-gauge-high"></i></span> Dashboard</h1>
        <p>Welcome back, <?= htmlspecialchars($fullName) ?> — here's your market at a glance.</p>
    </div>
    <div class="header-actions">
        <a href="customer/customer_browse.php" class="btn-header ghost"><i class="fas fa-magnifying-glass"></i> Search Market</a>
        <a href="customer/customer_browse.php" class="btn-header primary"><i class="fas fa-store"></i> Browse Vendors</a>
    </div>
</header>

<!-- HERO -->
<section class="hero">
    <div class="hero-content">
        <span class="hero-chip"><i class="fas fa-leaf"></i> Mallengke Market</span>
        <h2>Good day, <span><?= htmlspecialchars($customer['first_name']) ?></span>!</h2>
        <p>Discover local stalls and make the market your own.</p>
        <div class="hero-actions">
            <a href="customer/customer_browse.php" class="btn-hero primary"><i class="fas fa-store"></i> Browse Market</a>
            <a href="customer/customer_stall_map.php" class="btn-hero ghost"><i class="fas fa-map-location-dot"></i> Stall Map</a>
        </div>
    </div>
    <div class="hero-art"><i class="fas fa-basket-shopping"></i></div>
</section>

<!-- STATS -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon g3"><i class="fas fa-calendar-day"></i></div>
        <div>
            <div class="stat-num"><?= date('d') ?></div>
            <div class="stat-lbl"><?= date('F Y') ?></div>
        </div>
    </div>
</div>

<!-- GRID -->
<div class="dash-grid">
    <aside class="card profile-card">
        <div class="avatar profile-avatar-trigger<?= $customerAvatarUrl !== '' ? ' has-photo' : '' ?>"
             data-profile-avatar
             data-avatar-initials="<?= htmlspecialchars($initials) ?>"
             data-avatar-endpoint="php/profile_upload.php"
             data-avatar-token="<?= htmlspecialchars($customerAvatarToken) ?>"
             role="button" tabindex="0"
             aria-label="Change profile photo" title="Change profile photo">
            <?php if ($customerAvatarUrl !== ''): ?>
                <img class="profile-avatar-img" src="<?= htmlspecialchars($customerAvatarUrl) ?>" alt="" />
            <?php else: ?>
                <span class="profile-avatar-initials"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
            <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
            <input type="file" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
        </div>
        <h2><?= htmlspecialchars($fullName) ?></h2>
        <p class="email"><?= htmlspecialchars($customer['email']) ?></p>
        <span class="role-badge"><i class="fas fa-user-check"></i> Registered Customer</span>
        <div class="info-list">
            <div class="row"><span class="label">Username</span><span class="value"><?= htmlspecialchars($customer['username']) ?></span></div>
            <div class="row"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($customer['phone'] ?: '—') ?></span></div>
            <div class="row"><span class="label">Member since</span><span class="value"><?= date('M j, Y', strtotime($customer['created_at'])) ?></span></div>
        </div>
        <a href="customer/customer_edit_profile.php" class="edit-cta"><i class="fas fa-user-pen"></i> Edit Profile</a>
    </aside>

    <section class="card recent-card">
        <div class="card-head">
            <h3><i class="fas fa-compass"></i> Market shortcuts</h3>
        </div>
        <div class="recent-list">
            <a class="recent-item" href="customer/customer_browse.php">
                <div class="recent-avatar"><i class="fas fa-store"></i></div>
                <div class="recent-meta">
                    <div class="recent-top"><span class="recent-name">Browse vendors</span></div>
                    <div class="recent-preview">See every stall in the market.</div>
                </div>
            </a>
            <a class="recent-item" href="customer/customer_stall_map.php">
                <div class="recent-avatar"><i class="fas fa-map-location-dot"></i></div>
                <div class="recent-meta">
                    <div class="recent-top"><span class="recent-name">Stall map</span></div>
                    <div class="recent-preview">Find a stall by section and number.</div>
                </div>
            </a>
            <a class="recent-item" href="customer/customer_favorites.php">
                <div class="recent-avatar"><i class="fas fa-heart"></i></div>
                <div class="recent-meta">
                    <div class="recent-top"><span class="recent-name">Favorites</span></div>
                    <div class="recent-preview">Your saved vendors in one place.</div>
                </div>
            </a>
        </div>
    </section>
</div>

</main>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarBackdrop').classList.toggle('show');}
</script>

</body>
</html>
