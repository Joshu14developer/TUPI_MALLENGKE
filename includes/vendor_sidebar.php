<?php
/**
 * Shared vendor sidebar.
 *
 * Every vendor page includes this file so the navigation buttons, their
 * order, labels, icons and badge counts are always identical:
 *
 *     dashboard.php                 -> require __DIR__ . '/includes/vendor_sidebar.php';
 *     php/stall_map.php (etc.)      -> require __DIR__ . '/../includes/vendor_sidebar.php';
 *
 * Needs: session with user_id + username, and $conn (mysqli).
 * Optional: $vendor (row already fetched), $view, $sidebarBase override.
 */

require_once __DIR__ . '/../config/profile.php';

/* Prefix that gets us back to the app root from this page. */
$vsBase = $sidebarBase ?? (
    basename(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))))
        === basename(dirname(__DIR__))
    ? '' : '../'
);

/* Signed-in vendor. The session holds users.id, the vendor row hangs off it. */
$vsUserId   = (int)($_SESSION['user_id'] ?? 0);
$vsUsername = (string)($_SESSION['username'] ?? 'Vendor');
$vsRow      = (isset($vendor) && is_array($vendor)) ? $vendor : [];

if (!$vsRow && isset($conn) && $conn instanceof mysqli && $vsUserId) {
    $vsStmt = $conn->prepare("SELECT id, first_name, last_name, stall_number, avatar_path FROM vendors WHERE user_id = ? LIMIT 1");
    if ($vsStmt) {
        $vsStmt->bind_param("i", $vsUserId);
        $vsStmt->execute();
        $vsRow = $vsStmt->get_result()->fetch_assoc() ?: [];
        $vsStmt->close();
    }
}

$vsId       = (int)($vsRow['id'] ?? 0);
$vsInitials = strtoupper(substr($vsUsername, 0, 1));
if ($vsRow) {
    $vsInitials = strtoupper(
        substr($vsRow['first_name'] ?? 'U', 0, 1) . substr($vsRow['last_name'] ?? '', 0, 1)
    );
}

/* A page may pass a hand-picked vendor row that predates the photo
   column. Fetch only the missing piece rather than hiding the photo. */
if ($vsId > 0 && !array_key_exists('avatar_path', $vsRow) && isset($conn) && $conn instanceof mysqli) {
    $vsPhotoStmt = $conn->prepare("SELECT avatar_path FROM vendors WHERE id = ? LIMIT 1");
    if ($vsPhotoStmt) {
        $vsPhotoStmt->bind_param("i", $vsId);
        $vsPhotoStmt->execute();
        $vsPhotoRow = $vsPhotoStmt->get_result()->fetch_assoc() ?: [];
        $vsPhotoStmt->close();
        $vsRow['avatar_path'] = $vsPhotoRow['avatar_path'] ?? null;
    }
}

$vsAvatarUrl      = profile_avatar_url($vsRow['avatar_path'] ?? '', $vsBase);
$vsAvatarToken    = profile_avatar_token();
$vsAvatarEndpoint = $vsBase . 'php/profile_upload.php';
$vsCanUpload      = ($vsId > 0);

/* Badge counts, computed here so every page shows the same numbers. */
$vsBadges = ['unread' => 0, 'swaps' => 0, 'notif' => 0];

if (isset($conn) && $conn instanceof mysqli && $vsId) {
    /* Unread vendor-to-vendor messages. */
    if ($vsStmt = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN vendor_a_id = ? THEN unread_a ELSE unread_b END), 0) AS u
           FROM vendor_conversations
          WHERE vendor_a_id = ? OR vendor_b_id = ?"
    )) {
        $vsStmt->bind_param("iii", $vsId, $vsId, $vsId); $vsStmt->execute();
        $vsBadges['unread'] += (int)($vsStmt->get_result()->fetch_assoc()['u'] ?? 0);
        $vsStmt->close();
    }

    /* Pending stall swap requests this vendor is part of. */
    if ($vsStmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM stall_swap_requests
          WHERE status = 'Pending' AND (requester_vendor_id = ? OR target_vendor_id = ?)"
    )) {
        $vsStmt->bind_param("ii", $vsId, $vsId); $vsStmt->execute();
        $vsBadges['swaps'] = (int)($vsStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $vsStmt->close();
    }

    /* Unread notifications from the market office. */
    require_once __DIR__ . '/../config/notify.php';
    $vsBadges['notif'] = (int)notif_unread_count($conn, $vsId);
}

/* Active page */
$vsScript     = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$vsInMessages = isset($view)
    ? $view === 'messages'
    : strpos((string)($_SERVER['QUERY_STRING'] ?? ''), 'view=messages') !== false;

$vsItems = [
    'dashboard'     => ['href' => $vsBase . 'dashboard.php',                    'icon' => 'fas fa-gauge-high',        'label' => 'Dashboard'],
    'messages'      => ['href' => $vsBase . 'dashboard.php?view=messages',      'icon' => 'fas fa-comments',          'label' => 'Messages',     'count' => $vsBadges['unread'], 'count_id' => 'sidebarChatCount', 'swap' => $vsBadges['swaps']],
    'stall_map'     => ['href' => $vsBase . 'php/stall_map.php',                'icon' => 'fas fa-map-location-dot',  'label' => 'Stall Map'],
    'stall_apply'   => ['href' => $vsBase . 'php/stall_apply.php',              'icon' => 'fas fa-hand-paper',        'label' => 'Apply for a Stall'],
    'edit_profile'  => ['href' => $vsBase . 'php/edit_profile.php',             'icon' => 'fas fa-user-pen',          'label' => 'Edit Profile'],
    'notifications' => ['href' => $vsBase . 'php/vendor_notifications.php',     'icon' => 'fas fa-bell',              'label' => 'Notifications', 'count' => $vsBadges['notif'],  'count_id' => 'sidebarNotifCount'],
];

$vsActive = [
    'messages'      => $vsInMessages,
    'dashboard'     => ($vsScript === 'dashboard.php' && !$vsInMessages),
    'stall_map'     => ($vsScript === 'stall_map.php'),
    'stall_apply'   => ($vsScript === 'stall_apply.php'),
    'edit_profile'  => ($vsScript === 'edit_profile.php'),
    'notifications' => ($vsScript === 'vendor_notifications.php'),
];

/* The one and only vendor menu — same order and labels on every page. */
$vsGroups = [
    'Overview'    => ['dashboard', 'messages'],
    'Marketplace' => ['stall_map', 'stall_apply'],
    'Account'     => ['edit_profile', 'notifications'],
];
?>
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fas fa-leaf" aria-hidden="true"></i></div>
        <div>
            <h1>MALLENGKE</h1>
            <p>Vendor Portal</p>
        </div>
    </div>

    <div class="sidebar-scroll">
        <div class="sidebar-user">
            <?php if ($vsCanUpload): ?>
                <div class="sidebar-avatar profile-avatar-trigger<?= $vsAvatarUrl !== '' ? ' has-photo' : '' ?>"
                     data-profile-avatar
                     data-avatar-initials="<?= htmlspecialchars($vsInitials) ?>"
                     data-avatar-endpoint="<?= htmlspecialchars($vsAvatarEndpoint) ?>"
                     data-avatar-token="<?= htmlspecialchars($vsAvatarToken) ?>"
                     role="button" tabindex="0"
                     aria-label="Change profile photo" title="Change profile photo">
                    <?php if ($vsAvatarUrl !== ''): ?>
                        <img class="profile-avatar-img" src="<?= htmlspecialchars($vsAvatarUrl) ?>" alt="" />
                    <?php else: ?>
                        <span class="profile-avatar-initials"><?= htmlspecialchars($vsInitials) ?></span>
                    <?php endif; ?>
                    <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
                    <input type="file" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
                </div>
            <?php else: ?>
                <div class="sidebar-avatar"><?= htmlspecialchars($vsInitials) ?></div>
            <?php endif; ?>
            <div class="sidebar-user-info">
                <div class="name"><?= htmlspecialchars($vsUsername) ?></div>
                <div class="role">Vendor<?= !empty($vsRow['stall_number']) ? ' &middot; ' . htmlspecialchars((string)$vsRow['stall_number']) : '' ?></div>
            </div>
        </div>

        <?php foreach ($vsGroups as $vsLabel => $vsKeys): ?>
            <div class="sidebar-label"><?= $vsLabel ?></div>
            <nav class="sidebar-nav">
                <?php foreach ($vsKeys as $vsKey):
                    $vsItem = $vsItems[$vsKey];
                    $vsNum  = (int)($vsItem['count'] ?? 0);
                    $vsSwap = (int)($vsItem['swap'] ?? 0);
                ?>
                <a href="<?= $vsItem['href'] ?>" class="sidebar-link<?= $vsActive[$vsKey] ? ' active' : '' ?>"<?= $vsActive[$vsKey] ? ' aria-current="page"' : '' ?>>
                    <i class="<?= $vsItem['icon'] ?>" aria-hidden="true"></i><span><?= $vsItem['label'] ?></span>
                    <?php if (isset($vsItem['count_id'])): ?>
                        <span class="sidebar-count" id="<?= $vsItem['count_id'] ?>"<?= $vsNum > 0 ? '' : ' style="display:none;"' ?>><?= $vsNum ?></span>
                    <?php endif; ?>
                    <?php if ($vsSwap > 0): ?>
                        <span class="sidebar-count swap" title="Stall swap requests awaiting approval"><i class="fas fa-right-left" aria-hidden="true"></i> <?= $vsSwap ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <a href="<?= $vsBase ?>php/logout.php" class="sidebar-link danger">
            <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Sign Out</span>
        </a>
    </div>
</aside>

<button type="button" class="mobile-toggle" onclick="toggleSidebar()"
        aria-label="Menu" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()" aria-hidden="true"></div>
<script src="<?= $vsBase ?>assets/profile-avatar.js?v=20261021a" defer></script>
