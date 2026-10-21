<?php
$conn = require __DIR__ . '/../config/db.php';
session_start();

require_once __DIR__ . '/../config/guard.php';
guard_vendor($conn, 'login.php');
require_once __DIR__ . '/../config/profile.php';

/* ============================================================
   HANDLE PROFILE UPDATE (POST)
   ============================================================ */
$updateMessage = '';
$updateError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $data = $_POST;

    $required = ['first_name','last_name','birthdate','gender','civil_status',
                 'contact_number','address','product_type',
                 'emergency_name','emergency_relationship','emergency_contact'];
    $missing = false;
    foreach ($required as $f) {
        if (empty(trim((string)($data[$f] ?? '')))) { $missing = true; break; }
    }

    if ($missing) {
        $updateError = 'Please fill in all required fields.';
    } elseif (!preg_match('/^[0-9+\-\s]{7,15}$/', $data['contact_number'])) {
        $updateError = 'Contact number looks invalid.';
    } elseif (!preg_match('/^[0-9+\-\s]{7,15}$/', $data['emergency_contact'])) {
        $updateError = 'Emergency contact number looks invalid.';
    } else {
        $bd = DateTime::createFromFormat('Y-m-d', $data['birthdate']);
        if (!$bd || $bd > new DateTime()) {
            $updateError = 'Please enter a valid birthdate.';
        } else {
            $sql = "UPDATE vendors SET
                        first_name = ?, middle_name = ?, last_name = ?,
                        birthdate = ?, gender = ?, civil_status = ?,
                        contact_number = ?, email = ?, address = ?,
                        business_name = ?, product_type = ?, years_in_business = ?,
                        emergency_name = ?, emergency_relationship = ?, emergency_contact = ?,
                        updated_at = NOW()
                    WHERE user_id = ?";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $middle  = $data['middle_name']   ?? '';
                $bizName = $data['business_name'] ?? '';
                $email   = $data['email']         ?? '';
                $years   = (int)($data['years_in_business'] ?? 0);

                $stmt->bind_param(
                    "sssssssssssisssi",
                    $data['first_name'], $middle, $data['last_name'],
                    $data['birthdate'], $data['gender'], $data['civil_status'],
                    $data['contact_number'], $email, $data['address'],
                    $bizName, $data['product_type'], $years,
                    $data['emergency_name'], $data['emergency_relationship'], $data['emergency_contact'],
                    $_SESSION['user_id']
                );

                if ($stmt->execute()) {
                    $updateMessage = 'Profile updated successfully!';

                    /* Optional photo. The AJAX uploader normally saves this
                       before the form is submitted; this branch is also the
                       fallback when JavaScript is unavailable. */
                    $photoFile = $_FILES['avatar'] ?? null;
                    if (is_array($photoFile) && (int)($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        if (!profile_avatar_token_valid((string)($_POST['profile_avatar_token'] ?? ''))) {
                            $updateError = 'Profile saved, but the photo could not be verified. Refresh the page and try again.';
                        } else {
                            $oldAvatar = '';
                            if ($oldPhotoStmt = $conn->prepare("SELECT avatar_path FROM vendors WHERE user_id = ? LIMIT 1")) {
                                $oldPhotoStmt->bind_param("i", $_SESSION['user_id']);
                                $oldPhotoStmt->execute();
                                $oldPhotoRow = $oldPhotoStmt->get_result()->fetch_assoc() ?: [];
                                $oldPhotoStmt->close();
                                $oldAvatar = (string)($oldPhotoRow['avatar_path'] ?? '');
                            }

                            $photoError = null;
                            $newAvatar = profile_store_avatar(
                                $photoFile,
                                'vendor_' . (int)$_SESSION['user_id'],
                                $photoError
                            );

                            if ($newAvatar === null) {
                                $updateError = 'Profile saved, but the photo was not uploaded: ' . ($photoError ?: 'unknown error');
                            } else {
                                $photoStmt = $conn->prepare("UPDATE vendors SET avatar_path = ? WHERE user_id = ? LIMIT 1");
                                if ($photoStmt) {
                                    $photoStmt->bind_param("si", $newAvatar, $_SESSION['user_id']);
                                    if ($photoStmt->execute()) {
                                        profile_delete_avatar($oldAvatar);
                                        $updateMessage = 'Profile and photo updated successfully!';
                                    } else {
                                        profile_delete_avatar($newAvatar);
                                        $updateError = 'Profile saved, but the photo could not be saved.';
                                    }
                                    $photoStmt->close();
                                } else {
                                    profile_delete_avatar($newAvatar);
                                    $updateError = 'Profile saved, but the photo could not be saved.';
                                }
                            }
                        }
                    }
                } else {
                    $updateError = 'Update failed: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $updateError = 'Database error: ' . $conn->error;
            }
        }
    }
}

/* ============================================================
   FETCH VENDOR
   ============================================================ */
$stmt = $conn->prepare("SELECT * FROM vendors WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ============================================================
   HELPERS
   ============================================================ */
$initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));
if ($vendor) {
    $initials = strtoupper(substr($vendor['first_name'] ?? 'U', 0, 1) .
                          substr($vendor['last_name'] ?? '', 0, 1));
}

$vendorAvatarUrl   = $vendor ? profile_avatar_url($vendor['avatar_path'] ?? '', '../') : '';
$vendorAvatarToken = profile_avatar_token();

$genders       = ['Male', 'Female', 'Other'];
$civilStatuses = ['Single', 'Married', 'Widowed', 'Separated'];

function val($key, $src, $default = '') {
    return htmlspecialchars($src[$key] ?? $default);
}
function sel($key, $src, $value) {
    return (($src[$key] ?? '') === $value) ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Profile • MALLENGKE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
        <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>


        html { scroll-behavior: smooth; }

        .page-title { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .page-title-icon {
            width: 44px; height: 44px; border-radius: 14px; flex-shrink: 0;
            background: linear-gradient(135deg, #10b981, #047857);
            display: grid; place-items: center;
            color: #fff; font-size: 18px;
            box-shadow: var(--glow-soft);
            transition: transform .35s var(--ease-spring);
        }
        .page-title:hover .page-title-icon { transform: rotate(-8deg) scale(1.06); }
        .page-title h1 { font-size: 21px; font-weight: 900; color: var(--ink); letter-spacing: -.5px; }
        .page-title p { font-size: 12.5px; color: var(--muted); margin-top: 2px; font-weight: 500; }

        .progress-pill {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 8px 14px;
            border-radius: 12px;
            background: #ffffff;
            border: 1.5px solid var(--line);
            box-shadow: var(--sh-xs);
            font-size: 12px; font-weight: 800;
            color: var(--ink-soft);
            flex-shrink: 0;
        }
        .progress-pill .ring {
            width: 34px; height: 34px;
            position: relative;
            display: grid; place-items: center;
        }
        .progress-pill .ring svg { transform: rotate(-90deg); }
        .progress-pill .ring circle {
            fill: none;
            stroke-width: 3.5;
            stroke-linecap: round;
        }
        .progress-pill .ring .bg { stroke: var(--line); }
        .progress-pill .ring .fg { stroke: var(--green-500); transition: stroke-dashoffset .5s var(--ease-smooth); }
        .progress-pill .ring .pct {
            position: absolute;
            font-size: 9px; font-weight: 900;
            color: var(--green-700);
            letter-spacing: -.02em;
        }
        .progress-pill .label {
            display: flex; flex-direction: column; line-height: 1.15;
        }
        .progress-pill .label strong { font-size: 12.5px; color: var(--ink); font-weight: 900; }
        .progress-pill .label span { font-size: 10px; color: var(--muted); font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }

        /* ============ PANEL ============ */
        .content-area {
            flex: 1;
            padding: 22px 28px 48px;
        }
        .panel {
            max-width: 1180px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 24px;
            border: 1.5px solid var(--line);
            box-shadow: var(--sh-md);
            overflow: hidden;
        }

        /* Header inside panel */
        .panel-header {
            padding: 22px 28px 18px;
            border-bottom: 1px solid var(--line-soft);
            background: linear-gradient(180deg, #fbfcfd 0%, #ffffff 100%);
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
        }
        .panel-header-left {
            display: flex; align-items: center; gap: 14px;
        }
        .panel-header-icon {
            width: 46px; height: 46px; border-radius: 14px;
            background: linear-gradient(135deg, #10b981, #047857);
            display: grid; place-items: center;
            color: #fff; font-size: 19px;
            box-shadow: var(--glow-soft);
            flex-shrink: 0;
        }
        .panel-header h2 { font-size: 17px; font-weight: 900; color: var(--ink); letter-spacing: -.3px; }
        .panel-header p { font-size: 12px; color: var(--muted); margin-top: 3px; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 12px;
            background: #fff; border: 1.5px solid var(--line);
            color: var(--ink-soft); font-size: 13px; font-weight: 700;
            text-decoration: none; transition: all .25s var(--ease-spring);
            font-family: inherit;
        }
        .btn-back:hover {
            border-color: var(--green-400);
            color: var(--brand-dark);
            transform: translateY(-2px);
            box-shadow: var(--sh-sm);
        }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 20px; border-radius: 14px;
            font-size: 13.5px; font-weight: 600;
            margin: 18px 28px 0;
            animation: slideDown .4s var(--ease-expo);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert-error   { background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; }
        .alert-error i { color: #dc2626; }
        .alert-success { background: var(--green-50); border: 1.5px solid var(--green-200); color: var(--green-800); }
        .alert-success i { color: var(--green-600); }

        /* Profile photo */
        .profile-photo-card {
            grid-column: 1 / -1;
            display: flex; align-items: center; gap: 20px;
            padding: 18px 20px;
            background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 72%);
            border: 1.5px solid var(--green-200);
            border-radius: 18px;
        }
        .photo-avatar {
            width: 94px; height: 94px; border-radius: 28px; flex-shrink: 0;
            background: linear-gradient(135deg, #34d399, #047857);
            display: grid; place-items: center;
            font-size: 32px; font-weight: 900; color: #fff;
            box-shadow: 0 14px 30px rgba(16,185,129,.35);
            transition: transform .4s var(--ease-spring), box-shadow .3s;
        }
        .photo-avatar:hover { transform: scale(1.05) rotate(-2deg); box-shadow: 0 18px 38px rgba(16,185,129,.45); }
        .profile-photo-copy { flex: 1; min-width: 0; }
        .profile-photo-eyebrow {
            display: block; font-size: 10.5px; font-weight: 900;
            letter-spacing: .12em; text-transform: uppercase;
            color: var(--green-700); margin-bottom: 5px;
        }
        .profile-photo-copy h3 { font-size: 16px; font-weight: 900; color: var(--ink); letter-spacing: -.25px; }
        .profile-photo-copy p { font-size: 12.5px; color: var(--muted); margin-top: 5px; line-height: 1.5; }
        .profile-photo-actions { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-top: 12px; }
        .btn-photo {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 9px 15px; border-radius: 11px;
            border: 1.5px solid transparent; cursor: pointer;
            background: linear-gradient(135deg, #10b981, #047857);
            color: #fff; font-family: inherit; font-size: 12.5px; font-weight: 800;
            box-shadow: 0 7px 18px rgba(16,185,129,.28);
            transition: transform .25s var(--ease-spring), box-shadow .25s;
        }
        .btn-photo:hover { transform: translateY(-2px); box-shadow: 0 11px 24px rgba(16,185,129,.38); }
        .btn-photo.ghost {
            background: #fff; border-color: var(--line);
            color: var(--ink-soft); box-shadow: none;
        }
        .btn-photo.ghost:hover { border-color: #fecaca; color: #b91c1c; background: #fff7f7; }
        .profile-photo-hint { display: block; font-size: 11px; color: var(--muted); margin-top: 9px; font-weight: 600; }
        @media (max-width: 640px) {
            .profile-photo-card { flex-direction: column; text-align: center; }
            .profile-photo-actions { justify-content: center; }
        }

        /* Form body */
        .form-body {
            padding: 24px 28px 28px;
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 18px;
        }@media (max-width: 860px){ .form-body { grid-template-columns: 1fr; }}

        .form-column { display: flex; flex-direction: column; gap: 18px; }

        .form-section {
            background: #ffffff;
            border: 1.5px solid var(--line);
            border-radius: 18px;
            padding: 20px 20px 6px;
            transition: all .3s var(--ease-smooth);
            position: relative;
        }
        .form-section:hover { border-color: var(--green-300); }
        .form-section.locked {
            background: linear-gradient(135deg, #f8fafc 0%, #f8fafc 100%);
            border-color: var(--line);
        }

        .section-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 18px; padding-bottom: 14px;
            border-bottom: 1.5px dashed var(--line);
        }
        .section-header .icon {
            width: 38px; height: 38px; border-radius: 12px;
            background: linear-gradient(135deg, #10b981, #047857);
            display: grid; place-items: center;
            color: #fff; font-size: 15px; flex-shrink: 0;
            box-shadow: var(--glow-soft);
            transition: transform .35s var(--ease-spring);
        }
        .form-section:hover .icon { transform: scale(1.06) rotate(-5deg); }
        .section-header .icon.locked {
            background: linear-gradient(135deg, #94a3b8, #64748b);
            box-shadow: 0 4px 10px rgba(100,116,139,.3);
        }
        .section-header h3 { font-size: 14px; font-weight: 800; color: var(--ink); letter-spacing: -.1px; }
        .section-header p { font-size: 11.5px; color: var(--muted); margin-top: 2px; font-weight: 500; }

        .form-grid { display: grid; gap: 13px; margin-bottom: 16px; }
        .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }

        .field { display: flex; flex-direction: column; gap: 5px; position: relative; }
        .field.full { grid-column: 1 / -1; }
        .field label {
            font-size: 12px; font-weight: 700;
            color: var(--ink-soft); letter-spacing: .02em;
            display: flex; align-items: center; gap: 4px;
        }
        .field label .req { color: #dc2626; margin-left: 1px; }
        .field label .ok {
            margin-left: auto;
            font-size: 10px; font-weight: 800;
            color: var(--green-600);
            display: none; align-items: center; gap: 4px;
        }
        .field.filled label .ok { display: inline-flex; }
        .field input, .field select {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid var(--line); border-radius: 11px;
            background: #fff; color: var(--ink);
            font-family: inherit; font-size: 14px;
            transition: all .22s var(--ease-smooth);
            outline: none;
        }
        .field input::placeholder { color: var(--muted); }
        .field input:hover:not(:disabled),
        .field select:hover { border-color: var(--green-300); }
        .field input:focus, .field select:focus {
            border-color: var(--green-400);
            box-shadow: 0 0 0 4px rgba(16,185,129,.13);
        }
        .field.filled input:not(:focus),
        .field.filled select:not(:focus) {
            border-color: var(--green-200);
            background: #fbfcfd;
        }
        .field input:disabled, .field select:disabled {
            background: #f8fafc; color: var(--muted);
            cursor: not-allowed;
            border-color: var(--line-soft);
        }
        .field select {
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 13px center;
            background-size: 14px;
            padding-right: 40px;
            cursor: pointer;
        }
        .field .hint {
            font-size: 11px; color: var(--muted);
            margin-top: 3px;
            display: flex; align-items: center; gap: 5px;
            font-weight: 500;
        }
        .field .hint i { color: var(--brand); font-size: 10px; }

        /* Form actions (in-panel) */
        .form-actions {
            grid-column: 1 / -1;
            display: flex; justify-content: flex-end; gap: 10px;
            padding-top: 20px;
            border-top: 1.5px dashed var(--line);
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .btn-cancel {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px; border-radius: 12px;
            font-size: 13.5px; font-weight: 700; color: var(--ink-soft);
            background: #fff; border: 1.5px solid var(--line);
            text-decoration: none; cursor: pointer;
            transition: all .25s var(--ease-spring);
            font-family: inherit;
        }
        .btn-cancel:hover { background: var(--bg); border-color: var(--green-300); transform: translateY(-2px); }
        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 26px; border-radius: 12px;
            font-family: inherit; font-size: 13.5px; font-weight: 800;
            color: #ffffff;
            background: linear-gradient(135deg, #059669, #065f46);
            border: none; cursor: pointer;
            transition: all .25s var(--ease-spring);
            box-shadow: var(--glow-soft);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: var(--glow); }
        .btn-primary:active { transform: translateY(0) scale(.98); }

        /* Info banner */
        .info-banner {
            padding: 40px 32px; border-radius: 20px;
            background: linear-gradient(135deg, var(--green-50), #ffffff);
            border: 1.5px dashed var(--green-200); text-align: center;
            margin: 30px 28px;
        }
        .info-banner i.big {
            font-size: 44px; color: var(--green-400);
            margin-bottom: 14px; display: block;
        }
        .info-banner h3 { font-size: 17px; font-weight: 900; color: var(--ink); margin-bottom: 8px; letter-spacing: -.3px; }
        .info-banner p { font-size: 14px; color: var(--muted); margin-bottom: 20px; line-height: 1.6; }
        .info-banner a {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 26px; border-radius: 12px;
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff; font-size: 13.5px; font-weight: 800;
            text-decoration: none;
            box-shadow: var(--glow);
            transition: all .25s var(--ease-spring);
        }
        .info-banner a:hover { transform: translateY(-2px); box-shadow: 0 16px 34px rgba(16,185,129,.5); }@media (max-width: 900px){
            .content-area { padding: 16px 14px 40px; }
            .panel-header { padding: 18px 20px 16px; }
            .form-body { padding: 20px; }
            .alert { margin-left: 20px; margin-right: 20px; }
            .progress-pill .label { display: none; }}@media (max-width: 600px){
            .form-grid.cols-2 { grid-template-columns: 1fr; }
            .page-title h1 { font-size: 18px; }
            .panel-header h2 { font-size: 15px; }
            .form-actions { justify-content: stretch; }
            .form-actions .btn-primary,
            .form-actions .btn-cancel { flex: 1; justify-content: center; }}

        /* Print */
        @media print {
            .sidebar, .mobile-toggle, .sidebar-backdrop, .top-bar, .form-actions { display: none !important; }
            .main-content { margin-left: 0; width: 100%; }
            .content-area { padding: 0; }
            .panel { box-shadow: none; }
        }
        /* this page's own main-content tweaks; the shell owns the rest */
        .main-content{display: flex;flex-direction: column;}

    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/vendor_sidebar.php'; ?>

    <main class="main-content">

        <!-- STICKY TOP BAR -->
        <header class="top-bar" id="topBar">
            <div class="page-title">
                <div class="page-title-icon"><i class="fas fa-user-pen"></i></div>
                <div>
                    <h1>Edit Profile</h1>
                    <p>Update your vendor details</p>
                </div>
            </div>

            <?php if ($vendor): ?>
            <div class="progress-pill" id="progressPill">
                <div class="ring">
                    <svg width="34" height="34" viewBox="0 0 34 34">
                        <circle class="bg" cx="17" cy="17" r="14"></circle>
                        <circle class="fg" id="progressRing" cx="17" cy="17" r="14"
                                stroke-dasharray="87.96"
                                stroke-dashoffset="87.96"></circle>
                    </svg>
                    <span class="pct" id="progressPct">0%</span>
                </div>
                <div class="label">
                    <strong>Profile</strong>
                    <span>Completeness</span>
                </div>
            </div>
            <?php endif; ?>

            <div class="top-actions">
                <a href="../dashboard.php" class="icon-btn" title="Back to dashboard">
                    <i class="fas fa-gauge-high"></i>
                </a>
            </div>
        </header>

        <div class="content-area">
            <div class="panel">

                <div class="panel-header">
                    <div class="panel-header-left">
                        <div class="panel-header-icon"><i class="fas fa-id-card"></i></div>
                        <div>
                            <h2>My Vendor Information</h2>
                            <p>Update most details here. Stall number and ID are managed by the market office.</p>
                        </div>
                    </div>
                    <a href="../dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                </div>

                <?php if ($updateError): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($updateError) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($updateMessage): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-circle-check"></i>
                        <span><?= htmlspecialchars($updateMessage) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($vendor): ?>
                <form method="POST" action="edit_profile.php" id="profileForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="update_profile" />
                    <input type="hidden" name="profile_avatar_token" value="<?= htmlspecialchars($vendorAvatarToken) ?>" />
                    <div class="form-body">

                        <section class="profile-photo-card" data-profile-uploader>
                            <div class="photo-avatar profile-avatar-trigger<?= $vendorAvatarUrl !== '' ? ' has-photo' : '' ?>"
                                 data-profile-avatar
                                 data-avatar-initials="<?= htmlspecialchars($initials) ?>"
                                 data-avatar-endpoint="../php/profile_upload.php"
                                 data-avatar-token="<?= htmlspecialchars($vendorAvatarToken) ?>"
                                 role="button" tabindex="0"
                                 aria-label="Change profile photo" title="Change profile photo">
                                <?php if ($vendorAvatarUrl !== ''): ?>
                                    <img class="profile-avatar-img" src="<?= htmlspecialchars($vendorAvatarUrl) ?>" alt="" />
                                <?php else: ?>
                                    <span class="profile-avatar-initials"><?= htmlspecialchars($initials) ?></span>
                                <?php endif; ?>
                                <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
                                <input type="file" name="avatar" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
                            </div>
                            <div class="profile-photo-copy">
                                <span class="profile-photo-eyebrow">Profile photo</span>
                                <h3>Put a face to your stall</h3>
                                <p>Click your photo or choose a file. The new picture appears in your sidebar and on your dashboard right away.</p>
                                <div class="profile-photo-actions">
                                    <button type="button" class="btn-photo" data-profile-avatar-pick>
                                        <i class="fas fa-camera"></i> Choose photo
                                    </button>
                                    <button type="button" class="btn-photo ghost" data-profile-avatar-remove<?= $vendorAvatarUrl === '' ? ' hidden' : '' ?>>
                                        <i class="fas fa-trash-can"></i> Remove
                                    </button>
                                </div>
                                <span class="profile-photo-hint">JPG, PNG, WebP or GIF &middot; Maximum 3 MB</span>
                            </div>
                        </section>

                        <!-- LEFT COLUMN -->
                        <div class="form-column">

                            <section class="form-section">
                                <div class="section-header">
                                    <div class="icon"><i class="fas fa-user"></i></div>
                                    <div>
                                        <h3>Personal Information</h3>
                                        <p>Basic details about you</p>
                                    </div>
                                </div>
                                <div class="form-grid cols-2">
                                    <div class="field">
                                        <label>First Name <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="first_name" value="<?= val('first_name',$vendor) ?>" required />
                                    </div>
                                    <div class="field">
                                        <label>Middle Name</label>
                                        <input type="text" name="middle_name" value="<?= val('middle_name',$vendor) ?>" />
                                    </div>
                                    <div class="field">
                                        <label>Last Name <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="last_name" value="<?= val('last_name',$vendor) ?>" required />
                                    </div>
                                    <div class="field">
                                        <label>Birthdate <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="date" name="birthdate" value="<?= val('birthdate',$vendor) ?>" required />
                                    </div>
                                    <div class="field">
                                        <label>Gender <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <select name="gender" required>
                                            <option value="">Select gender</option>
                                            <?php foreach ($genders as $g): ?>
                                                <option value="<?= $g ?>" <?= sel('gender',$vendor,$g) ?>><?= $g ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Civil Status <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <select name="civil_status" required>
                                            <option value="">Select civil status</option>
                                            <?php foreach ($civilStatuses as $c): ?>
                                                <option value="<?= $c ?>" <?= sel('civil_status',$vendor,$c) ?>><?= $c ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field full">
                                        <label>Contact Number <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="contact_number" value="<?= val('contact_number',$vendor) ?>" required />
                                    </div>
                                    <div class="field full">
                                        <label>Email Address</label>
                                        <input type="email" name="email" value="<?= val('email',$vendor) ?>" />
                                    </div>
                                    <div class="field full">
                                        <label>Complete Address <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="address" value="<?= val('address',$vendor) ?>" required />
                                    </div>
                                </div>
                            </section>

                            <section class="form-section">
                                <div class="section-header">
                                    <div class="icon"><i class="fas fa-phone"></i></div>
                                    <div>
                                        <h3>Emergency Contact</h3>
                                        <p>Person to contact in case of emergency</p>
                                    </div>
                                </div>
                                <div class="form-grid cols-2">
                                    <div class="field full">
                                        <label>Full Name <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="emergency_name" value="<?= val('emergency_name',$vendor) ?>" required />
                                    </div>
                                    <div class="field">
                                        <label>Relationship <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="emergency_relationship" value="<?= val('emergency_relationship',$vendor) ?>" required />
                                    </div>
                                    <div class="field">
                                        <label>Contact Number <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="emergency_contact" value="<?= val('emergency_contact',$vendor) ?>" required />
                                    </div>
                                </div>
                            </section>

                        </div>

                        <!-- RIGHT COLUMN -->
                        <div class="form-column">

                            <section class="form-section">
                                <div class="section-header">
                                    <div class="icon"><i class="fas fa-store-alt"></i></div>
                                    <div>
                                        <h3>Business Information</h3>
                                        <p>Your products and business details</p>
                                    </div>
                                </div>
                                <div class="form-grid cols-2">
                                    <div class="field full">
                                        <label>Business Name</label>
                                        <input type="text" name="business_name" value="<?= val('business_name',$vendor) ?>"
                                               placeholder="(optional)" />
                                    </div>
                                    <div class="field full">
                                        <label>Type of Products Sold <span class="req">*</span><span class="ok"><i class="fas fa-check"></i> ok</span></label>
                                        <input type="text" name="product_type" value="<?= val('product_type',$vendor) ?>" required />
                                    </div>
                                    <div class="field full">
                                        <label>Years in Business</label>
                                        <input type="number" name="years_in_business" min="0" max="80"
                                               value="<?= val('years_in_business',$vendor,'0') ?>" />
                                    </div>
                                </div>
                            </section>

                            <section class="form-section locked">
                                <div class="section-header">
                                    <div class="icon locked"><i class="fas fa-lock"></i></div>
                                    <div>
                                        <h3>Locked Information</h3>
                                        <p>Contact the market office to change these</p>
                                    </div>
                                </div>
                                <div class="form-grid cols-2">
                                    <div class="field">
                                        <label>Stall Number</label>
                                        <input type="text" value="<?= val('stall_number',$vendor) ?: 'Not assigned yet — the market office assigns your stall' ?>" disabled />
                                    </div>
                                    <div class="field">
                                        <label>Market Section</label>
                                        <input type="text" value="<?= val('market_section',$vendor) ?>" disabled />
                                    </div>
                                    <div class="field">
                                        <label>ID Type</label>
                                        <input type="text" value="<?= val('id_type',$vendor) ?>" disabled />
                                    </div>
                                    <div class="field">
                                        <label>ID Number</label>
                                        <input type="text" value="<?= val('id_number',$vendor) ?>" disabled />
                                    </div>
                                    <div class="field full">
                                        <span class="hint">
                                            <i class="fas fa-circle-info"></i>
                                            These fields are managed by the market office.
                                        </span>
                                    </div>
                                </div>
                            </section>

                        </div>

                        <!-- Actions -->
                        <div class="form-actions">
                            <a href="../dashboard.php" class="btn-cancel">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>

                    </div>
                </form>

                <?php else: ?>

                    <div class="info-banner">
                        <i class="fas fa-store-slash big"></i>
                        <h3>No vendor record yet</h3>
                        <p>Register as a vendor first to edit your profile.</p>
                        <a href="vendor_register.php">
                            <i class="fas fa-user-plus"></i> Register as Vendor
                        </a>
                    </div>

                <?php endif; ?>

            </div>
        </div>
    </main>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarBackdrop').classList.toggle('show');
        }

        /* Scroll shadow on top bar */
        const topBar = document.getElementById('topBar');
        window.addEventListener('scroll', () => {
            topBar.classList.toggle('scrolled', window.scrollY > 8);
        }, { passive: true });

        /* ============================================================
           Live completeness tracker + filled-field checkmark
           ============================================================ */
        (function() {
            const form = document.getElementById('profileForm');
            if (!form) return;

            const trackedFields = [
                'first_name','last_name','birthdate','gender','civil_status',
                'contact_number','address','product_type',
                'emergency_name','emergency_relationship','emergency_contact'
            ];

            const ring   = document.getElementById('progressRing');
            const pctEl  = document.getElementById('progressPct');
            const CIRC   = 2 * Math.PI * 14; // r = 14

            function updateProgress() {
                let filled = 0;
                trackedFields.forEach(name => {
                    const input = form.querySelector('[name="' + name + '"]');
                    const field = input ? input.closest('.field') : null;
                    if (!input || !field) return;

                    const hasVal = input.value.trim() !== '';
                    field.classList.toggle('filled', hasVal);
                    if (hasVal) filled++;
                });

                const pct = Math.round((filled / trackedFields.length) * 100);
                pctEl.textContent = pct + '%';
                ring.style.strokeDashoffset = CIRC * (1 - pct / 100);
            }

            /* Attach listeners */
            trackedFields.forEach(name => {
                const input = form.querySelector('[name="' + name + '"]');
                if (!input) return;
                input.addEventListener('input', updateProgress);
                input.addEventListener('change', updateProgress);
            });

            /* Initial state */
            updateProgress();
        })();
    </script>

</body>
</html>