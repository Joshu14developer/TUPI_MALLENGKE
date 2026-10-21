<?php
/* ============================================================
   PROFILE PHOTO ENDPOINT
   ------------------------------------------------------------
   One endpoint for all three portals: the session decides whether
   the photo belongs to an admin, a customer or a vendor. Always
   answers with JSON so assets/profile-avatar.js can update every
   copy of the avatar on the page without a reload.
   ============================================================ */

ini_set('display_errors', '0');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function profile_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function profile_public_url(string $relativePath): string {
    $script  = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/php/profile_upload.php'));
    $phpDir  = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $appBase = rtrim(str_replace('\\', '/', dirname($phpDir)), '/');
    if ($appBase === '.' || $appBase === '/') $appBase = '';
    return $appBase . '/' . ltrim($relativePath, '/');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    profile_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/profile.php';

$token = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_PROFILE_TOKEN'] ?? ''));
if (!profile_avatar_token_valid($token)) {
    profile_json(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 403);
}

$adminId      = (int)($_SESSION['admin_id'] ?? 0);
$customerId   = (int)($_SESSION['customer_id'] ?? 0);
$vendorUserId = (int)($_SESSION['user_id'] ?? 0);

$table  = '';
$id     = 0;
$prefix = '';
$oldPath = '';

if ($adminId > 0) {
    $table  = 'admin_users';
    $id     = $adminId;
    $prefix = 'admin_' . $adminId;
} elseif ($customerId > 0) {
    $table  = 'customers';
    $id     = $customerId;
    $prefix = 'customer_' . $customerId;
} elseif ($vendorUserId > 0) {
    $lookup = $conn->prepare("SELECT id, avatar_path FROM vendors WHERE user_id = ? LIMIT 1");
    if (!$lookup) {
        profile_json(['ok' => false, 'error' => 'Your vendor profile could not be loaded.'], 500);
    }
    $lookup->bind_param('i', $vendorUserId);
    $lookup->execute();
    $vendorRow = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$vendorRow) {
        profile_json(['ok' => false, 'error' => 'Complete your vendor registration before adding a photo.'], 404);
    }

    $table   = 'vendors';
    $id      = (int)$vendorRow['id'];
    $prefix  = 'vendor_' . $id;
    $oldPath = (string)($vendorRow['avatar_path'] ?? '');
} else {
    profile_json(['ok' => false, 'error' => 'Please sign in again.'], 401);
}

/* Read the previous path for the two session-only roles too. */
if ($table !== 'vendors') {
    $lookup = $conn->prepare("SELECT avatar_path FROM `$table` WHERE id = ? LIMIT 1");
    if (!$lookup) {
        profile_json(['ok' => false, 'error' => 'Your profile could not be loaded.'], 500);
    }
    $lookup->bind_param('i', $id);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$row) {
        profile_json(['ok' => false, 'error' => 'Your profile could not be found.'], 404);
    }
    $oldPath = (string)($row['avatar_path'] ?? '');
}

$action = (string)($_POST['action'] ?? 'upload');

if ($action === 'remove') {
    $update = $conn->prepare("UPDATE `$table` SET avatar_path = NULL WHERE id = ? LIMIT 1");
    if (!$update) {
        profile_json(['ok' => false, 'error' => 'The photo could not be removed.'], 500);
    }
    $update->bind_param('i', $id);
    if (!$update->execute()) {
        $update->close();
        profile_json(['ok' => false, 'error' => 'The photo could not be removed.'], 500);
    }
    $update->close();

    profile_delete_avatar($oldPath);
    profile_json(['ok' => true, 'url' => '', 'message' => 'Profile photo removed.']);
}

if ($action !== 'upload') {
    profile_json(['ok' => false, 'error' => 'Unknown photo action.'], 422);
}

$uploadError = null;
$newPath = profile_store_avatar($_FILES['avatar'] ?? [], $prefix, $uploadError);
if ($newPath === null) {
    profile_json(['ok' => false, 'error' => $uploadError ?: 'The photo could not be uploaded.'], 422);
}

$update = $conn->prepare("UPDATE `$table` SET avatar_path = ? WHERE id = ? LIMIT 1");
if (!$update) {
    profile_delete_avatar($newPath);
    profile_json(['ok' => false, 'error' => 'The photo could not be saved.'], 500);
}
$update->bind_param('si', $newPath, $id);
if (!$update->execute()) {
    $update->close();
    profile_delete_avatar($newPath);
    profile_json(['ok' => false, 'error' => 'The photo could not be saved.'], 500);
}
$update->close();

/* Only after the database points at the new file is it safe to remove
   the old one. A failure here leaves an unreferenced file, never a
   broken profile picture. */
profile_delete_avatar($oldPath);

profile_json([
    'ok'      => true,
    'url'     => profile_public_url($newPath),
    'message' => 'Profile photo updated.',
]);
