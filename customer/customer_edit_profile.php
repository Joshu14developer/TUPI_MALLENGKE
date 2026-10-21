<?php
$conn = require __DIR__ . '/../config/db.php';
session_start();
require_once __DIR__ . '/../config/profile.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: customer_login.php');
    exit;
}

$customerId   = (int)$_SESSION['customer_id'];
$updateMessage = '';
$updateError   = '';
$posted        = null;

/* ============================================================
   HANDLE PROFILE UPDATE (POST)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $posted = $_POST;

    if (!profile_avatar_token_valid((string)($_POST['profile_avatar_token'] ?? ''))) {
        $updateError = 'Your session expired. Refresh the page and try again.';
    } else {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName  = trim((string)($_POST['last_name'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $phone     = trim((string)($_POST['phone'] ?? ''));
        $address   = trim((string)($_POST['address'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            $updateError = 'Please fill in your first name, last name and email address.';
        } elseif (strlen($firstName) > 80 || strlen($lastName) > 80 || strlen($email) > 190
                  || strlen($phone) > 20 || strlen($address) > 255) {
            $updateError = 'One or more fields are too long. Please shorten them and try again.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $updateError = 'Please enter a valid email address.';
        } else {
            $emailTaken = false;
            $check = $conn->prepare("SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1");
            if (!$check) {
                $updateError = 'Database error: ' . $conn->error;
            } else {
                $check->bind_param("si", $email, $customerId);
                $check->execute();
                $emailTaken = (bool)$check->get_result()->fetch_assoc();
                $check->close();

                if ($emailTaken) {
                    $updateError = 'That email address is already used by another customer.';
                }
            }

            if ($updateError === '') {
                $stmt = $conn->prepare(
                    "UPDATE customers
                        SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?
                      WHERE id = ?"
                );

                if (!$stmt) {
                    $updateError = 'Database error: ' . $conn->error;
                } else {
                    $stmt->bind_param("sssssi", $firstName, $lastName, $email, $phone, $address, $customerId);

                    if ($stmt->execute()) {
                        $_SESSION['customer_name']  = trim($firstName . ' ' . $lastName);
                        $_SESSION['customer_email'] = $email;

                        $updateMessage = 'Profile updated successfully.';
                        $posted = null;

                        /* Optional photo. The AJAX uploader normally saves this
                           before the form is submitted; this branch is the
                           no-JavaScript fallback. */
                        $photoFile = $_FILES['avatar'] ?? null;
                        if (profile_avatar_selected($photoFile)) {
                            $oldAvatar = '';
                            $oldPhotoStmt = $conn->prepare("SELECT avatar_path FROM customers WHERE id = ? LIMIT 1");
                            if ($oldPhotoStmt) {
                                $oldPhotoStmt->bind_param("i", $customerId);
                                $oldPhotoStmt->execute();
                                $oldPhotoRow = $oldPhotoStmt->get_result()->fetch_assoc() ?: [];
                                $oldPhotoStmt->close();
                                $oldAvatar = (string)($oldPhotoRow['avatar_path'] ?? '');
                            }

                            $photoError = null;
                            $newAvatar = profile_store_avatar($photoFile, 'customer_' . $customerId, $photoError);

                            if ($newAvatar === null) {
                                $updateMessage = '';
                                $updateError = 'Your details were saved, but the photo was not uploaded: '
                                             . ($photoError ?: 'unknown error');
                            } else {
                                $photoStmt = $conn->prepare("UPDATE customers SET avatar_path = ? WHERE id = ? LIMIT 1");
                                if ($photoStmt) {
                                    $photoStmt->bind_param("si", $newAvatar, $customerId);
                                    if ($photoStmt->execute()) {
                                        profile_delete_avatar($oldAvatar);
                                        $updateMessage = 'Profile and photo updated successfully.';
                                    } else {
                                        profile_delete_avatar($newAvatar);
                                        $updateMessage = '';
                                        $updateError = 'Your details were saved, but the photo could not be saved.';
                                    }
                                    $photoStmt->close();
                                } else {
                                    profile_delete_avatar($newAvatar);
                                    $updateMessage = '';
                                    $updateError = 'Your details were saved, but the photo could not be saved.';
                                }
                            }
                        }
                    } else {
                        $updateError = 'Update failed: ' . $stmt->error;
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/* ============================================================
   FETCH CUSTOMER
   ============================================================ */
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    session_destroy();
    header('Location: customer_login.php');
    exit;
}

$form = $posted !== null ? array_merge($customer, $posted) : $customer;

$firstName = trim((string)($form['first_name'] ?? ''));
$lastName  = trim((string)($form['last_name'] ?? ''));
$fullName  = trim($firstName . ' ' . $lastName);
$initials  = strtoupper(substr($firstName !== '' ? $firstName : 'C', 0, 1)
                      . substr($lastName, 0, 1));

$avatarUrl   = profile_avatar_url($customer['avatar_path'] ?? '', '../');
$avatarToken = profile_avatar_token();

$memberSince = !empty($customer['created_at'])
    ? date('M j, Y', strtotime($customer['created_at']))
    : '—';
$lastLogin = !empty($customer['last_login'])
    ? date('M j, Y · g:i A', strtotime($customer['last_login']))
    : 'Not recorded yet';

function ce_value(array $row, string $key, string $default = ''): string {
    return htmlspecialchars((string)($row[$key] ?? $default), ENT_QUOTES, 'UTF-8');
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
        .main-content { display: flex; flex-direction: column; }
        @media (max-width: 960px) {
            /* The shell's mobile rule adds top padding for the fixed menu
               button; this page's top bar already carries that offset. */
            .main-content.flush { padding: 0 !important; }
        }

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

        .content-area { flex: 1; padding: 22px 28px 48px; }
        .panel {
            max-width: 1080px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 24px;
            border: 1.5px solid var(--line);
            box-shadow: var(--sh-md);
            overflow: hidden;
        }

        .panel-header {
            padding: 22px 28px 18px;
            border-bottom: 1px solid var(--line-soft);
            background: linear-gradient(180deg, #fbfcfd 0%, #ffffff 100%);
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
        }
        .panel-header-left { display: flex; align-items: center; gap: 14px; }
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
        .alert-error { background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; }
        .alert-error i { color: #dc2626; }
        .alert-success { background: var(--green-50); border: 1.5px solid var(--green-200); color: var(--green-800); }
        .alert-success i { color: var(--green-600); }

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

        .form-body {
            padding: 24px 28px 28px;
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr);
            gap: 18px;
        }
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

        .field { display: flex; flex-direction: column; gap: 5px; position: relative; margin-bottom: 0; }
        .field.full { grid-column: 1 / -1; }
        .field label {
            font-size: 12px; font-weight: 700;
            color: var(--ink-soft); letter-spacing: .02em;
            display: flex; align-items: center; gap: 4px;
        }
        .field label .req { color: #dc2626; margin-left: 1px; }
        .field label .optional {
            margin-left: auto; font-size: 10px; font-weight: 800;
            color: var(--muted); letter-spacing: .06em; text-transform: uppercase;
        }
        .field input, .field select, .field textarea {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid var(--line); border-radius: 11px;
            background: #fff; color: var(--ink);
            font-family: inherit; font-size: 14px;
            transition: all .22s var(--ease-smooth);
            outline: none;
        }
        .field textarea { min-height: 88px; resize: vertical; line-height: 1.55; }
        .field input::placeholder, .field textarea::placeholder { color: var(--muted); }
        .field input:hover:not(:disabled),
        .field select:hover,
        .field textarea:hover:not(:disabled) { border-color: var(--green-300); }
        .field input:focus, .field select:focus, .field textarea:focus {
            border-color: var(--green-400);
            box-shadow: 0 0 0 4px rgba(16,185,129,.13);
        }
        .field input:disabled, .field select:disabled {
            background: #f8fafc; color: var(--muted);
            cursor: not-allowed;
            border-color: var(--line-soft);
        }
        .field .hint {
            font-size: 11px; color: var(--muted);
            margin-top: 3px;
            display: flex; align-items: center; gap: 5px;
            font-weight: 500;
        }
        .field .hint i { color: var(--brand); font-size: 10px; }

        .profile-note {
            border-radius: 18px; padding: 22px 20px; color: #fff;
            background:
                radial-gradient(circle at 92% 8%, rgba(74,222,128,.35), transparent 42%),
                linear-gradient(135deg, #04231a, #0a3d29 58%, #14532d);
            box-shadow: 0 16px 34px rgba(5,46,22,.2);
            position: relative; overflow: hidden;
        }
        .profile-note::after {
            content: ''; position: absolute; right: -30px; bottom: -40px;
            width: 130px; height: 130px; border-radius: 50%;
            background: radial-gradient(circle, rgba(74,222,128,.24), transparent 70%);
        }
        .profile-note-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: rgba(74,222,128,.16); border: 1px solid rgba(74,222,128,.3);
            display: grid; place-items: center;
            color: #4ade80; font-size: 16px; margin-bottom: 14px;
        }
        .profile-note h3 { font-size: 15px; font-weight: 900; letter-spacing: -.2px; position: relative; z-index: 2; }
        .profile-note p { font-size: 12.5px; color: #a9cbb9; line-height: 1.6; margin-top: 7px; position: relative; z-index: 2; }
        .profile-note ul {
            list-style: none; margin: 16px 0 0; padding: 0;
            display: flex; flex-direction: column; gap: 9px;
            position: relative; z-index: 2;
        }
        .profile-note li {
            display: flex; align-items: flex-start; gap: 9px;
            font-size: 12px; color: #d1fae5; font-weight: 600; line-height: 1.45;
        }
        .profile-note li i { color: #4ade80; font-size: 11px; margin-top: 3px; flex-shrink: 0; }

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

        @media (max-width: 900px) {
            .content-area { padding: 16px 14px 40px; }
            .panel-header { padding: 18px 20px 16px; }
            .form-body { padding: 20px; grid-template-columns: 1fr; }
            .alert { margin-left: 20px; margin-right: 20px; }
        }
        @media (max-width: 600px) {
            .form-grid.cols-2 { grid-template-columns: 1fr; }
            .page-title h1 { font-size: 18px; }
            .panel-header h2 { font-size: 15px; }
            .form-actions { justify-content: stretch; }
            .form-actions .btn-primary,
            .form-actions .btn-cancel {
                flex: 1; justify-content: center;
                padding-left: 14px; padding-right: 14px;
                white-space: nowrap;
            }
        }
        @media print {
            .sidebar, .mobile-toggle, .sidebar-backdrop, .top-bar, .form-actions { display: none !important; }
            .main-content { margin-left: 0; width: 100%; }
            .content-area { padding: 0; }
            .panel { box-shadow: none; }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/customer_sidebar.php'; ?>

<main class="main-content flush">

    <header class="top-bar" id="topBar">
        <div class="page-title">
            <div class="page-title-icon"><i class="fas fa-user-pen"></i></div>
            <div>
                <h1>Edit Profile</h1>
                <p>Keep your customer details up to date</p>
            </div>
        </div>

        <div class="top-actions">
            <a href="../customer_dashboard.php" class="icon-btn" title="Back to dashboard" aria-label="Back to dashboard">
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
                        <h2>My Customer Profile</h2>
                        <p>Update the details vendors and the market office can use to reach you.</p>
                    </div>
                </div>
                <a href="../customer_dashboard.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>

            <?php if ($updateError): ?>
                <div class="alert alert-error" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($updateError) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($updateMessage): ?>
                <div class="alert alert-success" role="status">
                    <i class="fas fa-circle-check"></i>
                    <span><?= htmlspecialchars($updateMessage) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="customer_edit_profile.php" id="customerProfileForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile" />
                <input type="hidden" name="profile_avatar_token" value="<?= htmlspecialchars($avatarToken) ?>" />

                <div class="form-body">

                    <section class="profile-photo-card" data-profile-uploader>
                        <div class="photo-avatar profile-avatar-trigger<?= $avatarUrl !== '' ? ' has-photo' : '' ?>"
                             data-profile-avatar
                             data-avatar-initials="<?= htmlspecialchars($initials) ?>"
                             data-avatar-endpoint="../php/profile_upload.php"
                             data-avatar-token="<?= htmlspecialchars($avatarToken) ?>"
                             role="button" tabindex="0"
                             aria-label="Change profile photo" title="Change profile photo">
                            <?php if ($avatarUrl !== ''): ?>
                                <img class="profile-avatar-img" src="<?= htmlspecialchars($avatarUrl) ?>" alt="" />
                            <?php else: ?>
                                <span class="profile-avatar-initials"><?= htmlspecialchars($initials) ?></span>
                            <?php endif; ?>
                            <span class="profile-avatar-camera" aria-hidden="true"><i class="fas fa-camera"></i></span>
                            <input type="file" name="avatar" class="profile-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
                        </div>
                        <div class="profile-photo-copy">
                            <span class="profile-photo-eyebrow">Profile photo</span>
                            <h3>Put a face to your profile</h3>
                            <p>Click your photo or choose a file. The new picture appears in your sidebar and on your dashboard right away.</p>
                            <div class="profile-photo-actions">
                                <button type="button" class="btn-photo" data-profile-avatar-pick>
                                    <i class="fas fa-camera"></i> Choose photo
                                </button>
                                <button type="button" class="btn-photo ghost" data-profile-avatar-remove<?= $avatarUrl === '' ? ' hidden' : '' ?>>
                                    <i class="fas fa-trash-can"></i> Remove
                                </button>
                            </div>
                            <span class="profile-photo-hint">JPG, PNG, WebP or GIF &middot; Maximum 3 MB</span>
                        </div>
                    </section>

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
                                    <label for="first_name">First Name <span class="req">*</span></label>
                                    <input type="text" id="first_name" name="first_name"
                                           value="<?= ce_value($form, 'first_name') ?>"
                                           maxlength="80" autocomplete="given-name" required />
                                </div>
                                <div class="field">
                                    <label for="last_name">Last Name <span class="req">*</span></label>
                                    <input type="text" id="last_name" name="last_name"
                                           value="<?= ce_value($form, 'last_name') ?>"
                                           maxlength="80" autocomplete="family-name" required />
                                </div>
                                <div class="field full">
                                    <label for="email">Email Address <span class="req">*</span></label>
                                    <input type="email" id="email" name="email"
                                           value="<?= ce_value($form, 'email') ?>"
                                           maxlength="190" autocomplete="email" required />
                                </div>
                                <div class="field full">
                                    <label for="phone">Phone Number <span class="optional">Optional</span></label>
                                    <input type="tel" id="phone" name="phone"
                                           value="<?= ce_value($form, 'phone') ?>"
                                           maxlength="20" autocomplete="tel"
                                           placeholder="e.g. 0917 123 4567" />
                                </div>
                                <div class="field full">
                                    <label for="address">Complete Address <span class="optional">Optional</span></label>
                                    <textarea id="address" name="address" rows="3" maxlength="255"
                                              autocomplete="street-address"
                                              placeholder="House, street, barangay, city"><?= ce_value($form, 'address') ?></textarea>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="form-column">
                        <section class="form-section locked">
                            <div class="section-header">
                                <div class="icon locked"><i class="fas fa-lock"></i></div>
                                <div>
                                    <h3>Account Information</h3>
                                    <p>Managed by the market office</p>
                                </div>
                            </div>
                            <div class="form-grid cols-2">
                                <div class="field full">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" value="<?= ce_value($customer, 'username') ?>" disabled />
                                    <span class="hint" id="usernameHint">
                                        <i class="fas fa-circle-info"></i>
                                        Your username is used to sign in and cannot be changed here.
                                    </span>
                                </div>
                                <div class="field">
                                    <label for="member_since">Member since</label>
                                    <input type="text" id="member_since" value="<?= htmlspecialchars($memberSince) ?>" disabled />
                                </div>
                                <div class="field">
                                    <label for="last_login">Last sign-in</label>
                                    <input type="text" id="last_login" value="<?= htmlspecialchars($lastLogin) ?>" disabled />
                                </div>
                            </div>
                        </section>

                        <aside class="profile-note">
                            <div class="profile-note-icon"><i class="fas fa-shield-halved"></i></div>
                            <h3>Your details, your control</h3>
                            <p>Your email doubles as a sign-in option. Keep it current and use an address only you can access.</p>
                            <ul>
                                <li><i class="fas fa-check"></i> Profile details save when you click Save Changes.</li>
                                <li><i class="fas fa-check"></i> Your photo appears across the customer portal.</li>
                                <li><i class="fas fa-lock"></i> Your username stays locked.</li>
                            </ul>
                        </aside>
                    </div>

                    <div class="form-actions">
                        <a href="../customer_dashboard.php" class="btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>

                </div>
            </form>

        </div>
    </div>
</main>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

const topBar = document.getElementById('topBar');
window.addEventListener('scroll', function () {
    topBar.classList.toggle('scrolled', window.scrollY > 8);
}, { passive: true });
</script>

</body>
</html>
