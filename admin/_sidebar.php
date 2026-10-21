<?php
/**
 * Shared admin sidebar.
 *
 * Every admin page includes this file so the navigation buttons, their order,
 * labels, icons and badge counts are always identical:
 *
 *     require __DIR__ . '/_sidebar.php';
 *
 * Requires: a session with admin_id and $conn (mysqli) from config/db.php.
 */

require_once __DIR__ . '/../config/profile.php';

/* Which page is active (PHP_SELF carries no query string). */
$sidebarCurrent = basename((string)($_SERVER['PHP_SELF'] ?? 'admin_dashboard.php'));

/* Badge counts, computed here so every page shows the same numbers. */
$sidebarBadges = [
    'vendors'       => 0,
    'customers'     => 0,
    'permits'       => 0,
    'swaps'         => 0,
    'reports'       => 0,
    'stalls'        => 0,
    'announcements' => 0,
    'notifications' => 0,
];

if (isset($conn) && $conn instanceof mysqli) {
    require_once __DIR__ . '/../config/notify.php';
    require_once __DIR__ . '/../config/swap.php';
    require_once __DIR__ . '/../config/reports.php';
    require_once __DIR__ . '/../config/stalls.php';
    require_once __DIR__ . '/../config/adminmsg.php';

    if ($res = $conn->query("SELECT COUNT(*) AS c FROM vendors"))
        $sidebarBadges['vendors'] = (int)($res->fetch_assoc()['c'] ?? 0);

    if ($res = $conn->query("SELECT COUNT(*) AS c FROM customers"))
        $sidebarBadges['customers'] = (int)($res->fetch_assoc()['c'] ?? 0);

    if ($res = $conn->query(
        "SELECT
            SUM(permit_status='Expired') AS expired,
            SUM(permit_status='Valid' AND permit_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring,
            SUM(payment_status='Unpaid') AS unpaid
          FROM vendors"
    )) {
        $permitRow = $res->fetch_assoc() ?: [];
        $sidebarBadges['permits'] = (int)($permitRow['expired'] ?? 0)
                                 + (int)($permitRow['expiring'] ?? 0)
                                 + (int)($permitRow['unpaid'] ?? 0);
    }

    $sidebarBadges['swaps']         = (int)swap_pending_count($conn);
    $sidebarBadges['reports']       = (int)report_open_count($conn);
    /* Requests waiting, vendors with nowhere to trade, and letters from
       vendors — everything the office can act on from that one page. */
    $sidebarBadges['stalls']        = (int)stall_pending_count($conn)
                                     + (int)stall_unassigned_count($conn)
                                     + (int)am_office_unread_count($conn);
    $sidebarBadges['announcements'] = (int)announcement_active_count($conn);
    $sidebarBadges['notifications'] = (int)notif_sent_today($conn);
}

/* Signed-in admin */
$sidebarName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$sidebarRole = $_SESSION['admin_role'] ?? 'admin';

$sidebarInitials = '';
foreach (preg_split('/\s+/', trim((string)$sidebarName)) as $part) {
    if ($part !== '') $sidebarInitials .= strtoupper($part[0]);
    if (strlen($sidebarInitials) >= 2) break;
}
if ($sidebarInitials === '') $sidebarInitials = 'A';

$sidebarAvatarPath = '';
$sidebarAdminId    = (int)($_SESSION['admin_id'] ?? 0);
if ($sidebarAdminId > 0 && isset($conn) && $conn instanceof mysqli) {
    $sidebarPhotoStmt = $conn->prepare("SELECT avatar_path FROM admin_users WHERE id = ? LIMIT 1");
    if ($sidebarPhotoStmt) {
        $sidebarPhotoStmt->bind_param("i", $sidebarAdminId);
        $sidebarPhotoStmt->execute();
        $sidebarPhotoRow = $sidebarPhotoStmt->get_result()->fetch_assoc() ?: [];
        $sidebarPhotoStmt->close();
        $sidebarAvatarPath = (string)($sidebarPhotoRow['avatar_path'] ?? '');
    }
}

$sidebarAvatarUrl      = profile_avatar_url($sidebarAvatarPath, '../');
$sidebarAvatarToken    = profile_avatar_token();
$sidebarAvatarEndpoint = '../php/profile_upload.php';

/* The one and only admin menu — same order and labels on every page,
   grouped like the vendor and customer menus. */
$sidebarItems = [
    'Overview' => [
        ['file' => 'admin_dashboard.php',     'icon' => 'fas fa-gauge-high',  'label' => 'Dashboard'],
    ],
    'People' => [
        ['file' => 'admin_vendors.php',       'icon' => 'fas fa-store',       'label' => 'Vendors',            'badge' => $sidebarBadges['vendors']],
        ['file' => 'admin_customers.php',     'icon' => 'fas fa-users',       'label' => 'Customers',          'badge' => $sidebarBadges['customers']],
    ],
    'Marketplace' => [
        ['file' => 'admin_stall_map.php',     'icon' => 'fas fa-map-location-dot', 'label' => 'Stall Map',      'badge' => $sidebarBadges['stalls']],
        ['file' => 'admin_stall_swaps.php',   'icon' => 'fas fa-right-left',  'label' => 'Stall Swaps',        'badge' => $sidebarBadges['swaps']],
        ['file' => 'admin_permits.php',       'icon' => 'fas fa-file-shield', 'label' => 'Permits & Payments', 'badge' => $sidebarBadges['permits']],
    ],
    'Communication' => [
        ['file' => 'admin_reports.php',       'icon' => 'fas fa-flag',        'label' => 'Vendor Reports',     'badge' => $sidebarBadges['reports']],
        ['file' => 'admin_announcements.php', 'icon' => 'fas fa-bullhorn',    'label' => 'Announcements',      'badge' => $sidebarBadges['announcements']],
        ['file' => 'admin_notifications.php', 'icon' => 'fas fa-bell',        'label' => 'Private Messages',   'badge' => $sidebarBadges['notifications']],
    ],
    'Insight' => [
        ['file' => 'admin_analytics.php',     'icon' => 'fas fa-chart-line',  'label' => 'Analytics'],
    ],
];
?>
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fas fa-shield-halved" aria-hidden="true"></i></div>
        <div>
            <h1>MALLENGKE</h1>
            <p>Admin Console</p>
        </div>
    </div>

    <div class="sidebar-scroll">
        <div class="sidebar-user">
            <?php if ($sidebarAdminId > 0): ?>
                <div class="sidebar-avatar profile-avatar-trigger<?= $sidebarAvatarUrl !== '' ? ' has-photo' : '' ?>"
                     data-profile-avatar
                     data-avatar-initials="<?= htmlspecialchars((string)$sidebarInitials) ?>"
                     data-avatar-endpoint="<?= htmlspecialchars($sidebarAvatarEndpoint) ?>"
                     data-avatar-token="<?= htmlspecialchars($sidebarAvatarToken) ?>"
                     role="button" tabindex="0"
                     aria-label="Change profile photo" title="Change profile photo">
                    <?php if ($sidebarAvatarUrl !== ''): ?>
                        <img class="profile-avatar-img" src="<?= htmlspecialchars($sidebarAvatarUrl) ?>" alt="" />
                    <?php else: ?>
                        <span class="profile-avatar-initials"><?= htmlspecialchars((string)$sidebarInitials) ?></span>
                    <?php endif; ?>
                    <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
                    <input type="file" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
                </div>
            <?php else: ?>
                <div class="sidebar-avatar"><?= htmlspecialchars((string)$sidebarInitials) ?></div>
            <?php endif; ?>
            <div class="sidebar-user-info">
                <div class="name"><?= htmlspecialchars((string)$sidebarName) ?></div>
                <div class="role"><?= htmlspecialchars(ucfirst((string)$sidebarRole)) ?></div>
            </div>
        </div>

        <?php foreach ($sidebarItems as $sidebarGroupLabel => $sidebarGroupItems): ?>
            <div class="sidebar-label"><?= $sidebarGroupLabel ?></div>
            <nav class="sidebar-nav">
                <?php foreach ($sidebarGroupItems as $item):
                    $isActive = ($sidebarCurrent === $item['file']);
                    $count    = isset($item['badge']) ? (int)$item['badge'] : 0;
                ?>
                <a href="<?= $item['file'] ?>" class="sidebar-link<?= $isActive ? ' active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                    <i class="<?= $item['icon'] ?>" aria-hidden="true"></i><span><?= $item['label'] ?></span>
                    <?php if ($count > 0): ?><span class="sidebar-count"><?= $count ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <a href="admin_logout.php" class="sidebar-link danger">
            <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Sign Out</span>
        </a>
    </div>
</aside>

<button type="button" class="mobile-toggle" onclick="toggleSidebar()"
        aria-label="Menu" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()" aria-hidden="true"></div>
<script src="../assets/profile-avatar.js?v=20261021a" defer></script>
