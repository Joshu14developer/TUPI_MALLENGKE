<?php
/**
 * Shared customer sidebar.
 *
 * Every customer page includes this file so the navigation buttons, their
 * order, labels, icons and badge counts are always identical:
 *
 *     customer_dashboard.php          -> require __DIR__ . '/includes/customer_sidebar.php';
 *     customer/customer_browse.php    -> require __DIR__ . '/../includes/customer_sidebar.php';
 *
 * Needs: session with customer_id, and $conn (mysqli).
 * Optional: $customer row / $customerName, $view, $sidebarBase override.
 */

require_once __DIR__ . '/../config/profile.php';

/* Prefix that gets us back to the app root from this page. */
$csBase = $sidebarBase ?? (
    basename(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))))
        === basename(dirname(__DIR__))
    ? '' : '../'
);

/* Signed-in customer */
$csScript     = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$csId         = (int)($_SESSION['customer_id'] ?? 0);
$csName       = '';
$csInitials   = '';
$csAvatarPath = '';

if (isset($conn) && $conn instanceof mysqli && $csId) {
    $csStmt = $conn->prepare("SELECT first_name, last_name, avatar_path FROM customers WHERE id = ? LIMIT 1");
    if ($csStmt) {
        $csStmt->bind_param("i", $csId);
        $csStmt->execute();
        $csRow = $csStmt->get_result()->fetch_assoc() ?: [];
        $csStmt->close();
        if ($csRow) {
            $csName       = trim(($csRow['first_name'] ?? '') . ' ' . ($csRow['last_name'] ?? ''));
            $csInitials   = strtoupper(substr($csRow['first_name'] ?? 'C', 0, 1) . substr($csRow['last_name'] ?? '', 0, 1));
            $csAvatarPath = (string)($csRow['avatar_path'] ?? '');
        }
    }
}

/* Fall back to whatever the page already resolved. */
if ($csName === '') {
    if (!empty($customerName)) {
        $csName = (string)$customerName;
    } elseif (!empty($_SESSION['customer_name'])) {
        $csName = (string)$_SESSION['customer_name'];
    } elseif (!empty($fullName) && $csScript === 'customer_dashboard.php') {
        /* customer_dashboard.php names the customer in $fullName; customer_stall_map.php
           reuses that variable for vendor rows, so it is only trusted here. */
        $csName = (string)$fullName;
    }
}
if ($csInitials === '') {
    $csInitials = strtoupper(substr($csName !== '' ? explode(' ', $csName)[0] : 'C', 0, 1));
}
if ($csName === '') $csName = 'Customer';

$csAvatarUrl      = profile_avatar_url($csAvatarPath, $csBase);
$csAvatarToken    = profile_avatar_token();
$csAvatarEndpoint = $csBase . 'php/profile_upload.php';
$csCanUpload      = ($csId > 0);

/* Active page */
/* The one and only customer menu — same order and labels on every page,
   grouped like the vendor and admin menus. */
$csItems = [
    'dashboard'  => ['href' => $csBase . 'customer_dashboard.php',              'icon' => 'fas fa-gauge-high',       'label' => 'Dashboard'],
    'browse'     => ['href' => $csBase . 'customer/customer_browse.php',        'icon' => 'fas fa-magnifying-glass', 'label' => 'Browse Market'],
    'stall_map'  => ['href' => $csBase . 'customer/customer_stall_map.php',     'icon' => 'fas fa-map-location-dot', 'label' => 'Stall Map'],
    'favorites'  => ['href' => $csBase . 'customer/customer_favorites.php',      'icon' => 'fas fa-heart',            'label' => 'My Favorites'],
    'edit_profile' => ['href' => $csBase . 'customer/customer_edit_profile.php', 'icon' => 'fas fa-user-pen',         'label' => 'Edit Profile'],
];

$csActive = [
    'dashboard' => ($csScript === 'customer_dashboard.php'),
    'browse'    => ($csScript === 'customer_browse.php'),
    'stall_map' => ($csScript === 'customer_stall_map.php'),
    'favorites' => ($csScript === 'customer_favorites.php'),
    'edit_profile' => ($csScript === 'customer_edit_profile.php'),
];

$csGroups = [
    'Overview'    => ['dashboard'],
    'Marketplace' => ['browse', 'stall_map', 'favorites'],
    'Account'     => ['edit_profile'],
];
?>
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fas fa-leaf" aria-hidden="true"></i></div>
        <div>
            <h1>MALLENGKE</h1>
            <p>Customer Portal</p>
        </div>
    </div>

    <div class="sidebar-scroll">
        <div class="sidebar-user">
            <?php if ($csCanUpload): ?>
                <div class="sidebar-avatar profile-avatar-trigger<?= $csAvatarUrl !== '' ? ' has-photo' : '' ?>"
                     data-profile-avatar
                     data-avatar-initials="<?= htmlspecialchars($csInitials) ?>"
                     data-avatar-endpoint="<?= htmlspecialchars($csAvatarEndpoint) ?>"
                     data-avatar-token="<?= htmlspecialchars($csAvatarToken) ?>"
                     role="button" tabindex="0"
                     aria-label="Change profile photo" title="Change profile photo">
                    <?php if ($csAvatarUrl !== ''): ?>
                        <img class="profile-avatar-img" src="<?= htmlspecialchars($csAvatarUrl) ?>" alt="" />
                    <?php else: ?>
                        <span class="profile-avatar-initials"><?= htmlspecialchars($csInitials) ?></span>
                    <?php endif; ?>
                    <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
                    <input type="file" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
                </div>
            <?php else: ?>
                <div class="sidebar-avatar"><?= htmlspecialchars($csInitials) ?></div>
            <?php endif; ?>
            <div class="sidebar-user-info">
                <div class="name"><?= htmlspecialchars($csName) ?></div>
                <div class="role">Customer</div>
            </div>
        </div>

        <?php foreach ($csGroups as $csLabel => $csKeys): ?>
            <div class="sidebar-label"><?= $csLabel ?></div>
            <nav class="sidebar-nav">
                <?php foreach ($csKeys as $csKey):
                    $csItem = $csItems[$csKey];
                    $csNum  = (int)($csItem['count'] ?? 0);
                ?>
                <a href="<?= $csItem['href'] ?>" class="sidebar-link<?= $csActive[$csKey] ? ' active' : '' ?>"<?= $csActive[$csKey] ? ' aria-current="page"' : '' ?>>
                    <i class="<?= $csItem['icon'] ?>" aria-hidden="true"></i><span><?= $csItem['label'] ?></span>
                    <?php if ($csKey === 'favorites'): ?>
                        <span class="sidebar-count" id="sidebarFavCount">0</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <a href="<?= $csBase ?>customer/customer_logout.php" class="sidebar-link danger">
            <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Sign Out</span>
        </a>
    </div>
</aside>

<button type="button" class="mobile-toggle" onclick="toggleSidebar()"
        aria-label="Menu" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()" aria-hidden="true"></div>

<script>
/* Favorites live in localStorage, so every customer page can show the count. */
(function () {
    var el = document.getElementById('sidebarFavCount');
    if (!el) return;
    var n = 0;
    try {
        var raw = localStorage.getItem('mallengke_customer_favorites');
        n = raw ? (JSON.parse(raw) || []).length : 0;
    } catch (e) { n = 0; }
    el.textContent = n;
})();
</script>
<script src="<?= $csBase ?>assets/profile-avatar.js?v=20261021a" defer></script>
