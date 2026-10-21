<?php
/* ✅ FIX: 'require' so $conn is always captured */
$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/profile.php';
session_start();

/* ✅ FIX: only redirect if this user ALREADY has a vendor record.
   This prevents the infinite loop with admin / demo users. */
if (isset($_SESSION['user_id'])) {
    $check = $conn->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1");
    $check->bind_param("i", $_SESSION['user_id']);
    $check->execute();
    $check->store_result();
    $has = $check->num_rows > 0;
    $check->close();

    if ($has) {
        header('Location: ../dashboard.php');
        exit;
    }
}

$error   = '';
$success = '';
$old     = [];
$qrToken = '';
$newId   = 0;

$genders       = ['Male', 'Female', 'Other'];
$civilStatuses = ['Single', 'Married', 'Widowed', 'Separated'];
$sections      = ['Meat', 'Fish & Seafood', 'Vegetables', 'Fruits', 'Rice & Grains',
                  'Dry Goods', 'Bread & Pastries', 'Processed Foods', 'Others'];
$idTypes       = ['PhilSys / National ID', "Driver's License", 'PhilHealth ID',
                  'SSS ID', 'UMID', "Voter's ID", 'Postal ID', 'Barangay ID',
                  'Senior Citizen ID', 'Others'];

function register_vendor($conn, $data)
{
    $required = [
        'first_name','last_name','birthdate','gender','civil_status',
        'contact_number','address','market_section',
        'product_type','id_type','id_number',
        'emergency_name','emergency_relationship','emergency_contact',
        'username','email','password','confirm'
    ];
    foreach ($required as $f) {
        if (empty(trim((string)($data[$f] ?? '')))) {
            return 'Please fill in all required fields.';
        }
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        return 'Please enter a valid email address.';
    if (strlen($data['password']) < 8)
        return 'Password must be at least 8 characters long.';
    if ($data['password'] !== $data['confirm'])
        return 'Passwords do not match.';
    if (!preg_match('/^[A-Za-z0-9_.]{3,20}$/', $data['username']))
        return 'Username must be 3–20 characters (letters, numbers, dot or underscore).';
    if (!preg_match('/^[0-9+\-\s]{7,15}$/', $data['contact_number']))
        return 'Contact number looks invalid.';
    if (!preg_match('/^[0-9+\-\s]{7,15}$/', $data['emergency_contact']))
        return 'Emergency contact number looks invalid.';

    $bd = DateTime::createFromFormat('Y-m-d', $data['birthdate']);
    if (!$bd || $bd > new DateTime())
        return 'Please enter a valid birthdate.';

    /* If not logged in, the username must be free. */
    if (!isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $data['username'], $data['email']);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            return 'That username or email is already registered. Try signing in instead.';
        }
        $stmt->close();
    }

    /* Optional profile photo. Store it before the transaction and remove
       it again if the account cannot be created. */
    $avatarPath = null;
    $avatarFile = $_FILES['avatar'] ?? null;
    if (profile_avatar_selected($avatarFile)) {
        $avatarOwner = $_SESSION['username'] ?? ($data['username'] ?? 'vendor');
        $avatarError = null;
        $avatarPath = profile_store_avatar($avatarFile, 'vendor_' . $avatarOwner, $avatarError);
        if ($avatarPath === null) {
            return $avatarError ?: 'The profile photo could not be uploaded.';
        }
    }

    /* The stall is not taken here. A new vendor is created without one
       and their preference becomes a request the office answers on the
       stall map, so the check that used to live here is gone. */

    $conn->begin_transaction();

    try {
        /* If already logged in (admin/demo), use their existing user_id.
           Otherwise, create a new user account. */
        if (isset($_SESSION['user_id'])) {
            $user_id = (int) $_SESSION['user_id'];
        } else {
            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $data['username'], $data['email'], $hash);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();
        }

        $qrToken = bin2hex(random_bytes(16));

        $middle  = $data['middle_name']   ?? '';
        $bizName = $data['business_name'] ?? '';
        $years   = (int)($data['years_in_business'] ?? 0);

        /* Only the office assigns a stall, so a new vendor is written
           without one, and the stall is marked unoccupied. They ask for a
           stall themselves, from their stall map. */
        $noStall    = null;
        $unoccupied = 'Available';

        $sql = "INSERT INTO vendors (
                    user_id,
                    first_name, middle_name, last_name, birthdate, gender,
                    civil_status, contact_number, email, address,
                    business_name, stall_number, market_section,
                    product_type, years_in_business,
                    id_type, id_number,
                    emergency_name, emergency_relationship, emergency_contact,
                    permit_status, payment_status, stall_status,
                    qr_token, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'Pending', 'Unpaid', ?, ?, 'Pending'
                )";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

       $stmt->bind_param(
    "isssssssssssssisssssss",   // 22 chars: i + 13 s + i + 7 s
    $user_id,
    $data['first_name'], $middle, $data['last_name'],
    $data['birthdate'], $data['gender'], $data['civil_status'],
    $data['contact_number'], $data['email'], $data['address'],
    $bizName, $noStall, $data['market_section'],
    $data['product_type'], $years,
    $data['id_type'], $data['id_number'],
    $data['emergency_name'], $data['emergency_relationship'], $data['emergency_contact'],
    $unoccupied, $qrToken
);

        if (!$stmt->execute()) throw new Exception('Insert vendor failed: ' . $stmt->error);
        $id = $stmt->insert_id;
        $stmt->close();

        /* Attach the optional photo to the vendor record. */
        if ($avatarPath !== null) {
            $photoStmt = $conn->prepare("UPDATE vendors SET avatar_path = ? WHERE id = ?");
            if (!$photoStmt) throw new Exception('Prepare failed: ' . $conn->error);
            $photoStmt->bind_param("si", $avatarPath, $id);
            if (!$photoStmt->execute()) {
                throw new Exception('Profile photo update failed: ' . $photoStmt->error);
            }
            $photoStmt->close();
        }

        /* No stall, and no request either. Applying for a stall is the
           vendor's own step, from their stall map, once they are in. */

        /* Auto-login only when we created a new account */
        if (!isset($_SESSION['user_id'])) {
            $_SESSION['user_id']  = $user_id;
            $_SESSION['username'] = $data['username'];
            $_SESSION['email']    = $data['email'];
        }

        $conn->commit();
        return ['id' => $id, 'token' => $qrToken, 'user_id' => $user_id];

    } catch (Throwable $e) {
        $conn->rollback();
        profile_delete_avatar($avatarPath);
        $msg = $e->getMessage();
        if (str_contains($msg, 'uniq_users_username') || str_contains($msg, 'uniq_users_email')) {
            return 'That username or email is already registered. Try signing in instead.';
        }
        return 'Registration failed. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;
    $result = register_vendor($conn, $_POST);
    if (is_array($result)) {
        $newId   = $result['id'];
        $qrToken = $result['token'];
        $refNo   = 'VND-' . str_pad((string)$newId, 5, '0', STR_PAD_LEFT);
        $success = "Welcome, <strong>" . htmlspecialchars($_POST['username']) . "</strong>! "
                 . "Your vendor account <strong>{$refNo}</strong> is created. "
                 . "You do not have a stall yet — <em>apply for one from your stall map</em> "
                 . "and the market office will decide.";
        $old = [];
    } else {
        $error = $result;
    }
}

function val($key, $old, $default = '') {
    return htmlspecialchars($old[$key] ?? $default);
}
function sel($key, $old, $value) {
    return (($old[$key] ?? '') === $value) ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Vendor Registration • MALLENGKE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/registration-photo.css?v=20261021a" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            padding: 32px 20px 60px;
            line-height: 1.5;
            font-size: 15px;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            max-width: 1200px;
            margin: 0 auto 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .page-header .brand { display: flex; align-items: center; gap: 14px; }
        .page-header .brand-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 22px;
            box-shadow: 0 6px 16px rgba(251,191,36,0.35);
        }
        .page-header .brand-text h1 {
            font-size: 20px; font-weight: 700; color: #0f172a; letter-spacing: -0.3px;
        }
        .page-header .brand-text p { font-size: 13px; color: #64748b; margin-top: 2px; }

        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-outline {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 10px;
            background: #fff; border: 1px solid #e2e8f0;
            color: #334155; font-size: 14px; font-weight: 600;
            text-decoration: none; transition: all 0.18s;
        }
        .btn-outline:hover {
            border-color: #cbd5e1; background: #f8fafc;
            transform: translateY(-1px); box-shadow: 0 4px 10px rgba(15,23,42,0.06);
        }
        .btn-outline.primary {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1e293b; border-color: transparent;
            box-shadow: 0 4px 12px rgba(245,158,11,0.35);
        }
        .btn-outline.primary:hover { transform: translateY(-2px); }

        /* ===== CONTAINER ===== */
        .container {
            max-width: 1200px; margin: 0 auto; background: #fff;
            border-radius: 20px; overflow: hidden;
            box-shadow: 0 4px 24px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04);
        }

        /* ===== TITLE BAR ===== */
        .title-bar {
            padding: 28px 40px; border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #fffbeb 0%, #ffffff 100%);
            display: flex; justify-content: space-between;
            align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .title-bar .title-group h2 {
            font-size: 22px; font-weight: 700; color: #0f172a;
            letter-spacing: -0.3px; display: flex; align-items: center; gap: 12px;
        }
        .title-bar .title-group h2 i { color: #f59e0b; font-size: 20px; }
        .title-bar .title-group p { font-size: 14px; color: #64748b; margin-top: 4px; }
        .title-bar .badge-info {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; border-radius: 999px;
            font-size: 13px; font-weight: 600;
            background: #fef3c7; color: #92400e; border: 1px solid #fde68a;
        }

        /* ===== ALERTS ===== */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 20px; border-radius: 12px;
            font-size: 14px; font-weight: 500;
            margin: 24px 40px 0; line-height: 1.5;
        }
        .alert i { font-size: 18px; margin-top: 1px; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-error i { color: #dc2626; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-success i { color: #059669; }
        .alert-success strong { color: #92400e; }
        .alert-success em { font-style: normal; color: #065f46; font-weight: 600; }

        /* ===== QR SUCCESS PANEL ===== */
        .qr-panel {
            margin: 24px 40px 0;
            padding: 26px;
            border-radius: 16px;
            background: linear-gradient(135deg, #fffbeb, #ffffff);
            border: 1px solid #fde68a;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 26px;
            align-items: center;
        }
        .qr-panel .qr-box {
            width: 180px; height: 180px; padding: 12px;
            background: #ffffff; border-radius: 12px;
            border: 2px dashed #fbbf24;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .qr-panel .qr-text h3 {
            font-size: 18px; font-weight: 700; color: #0f172a;
            margin-bottom: 6px; display: flex; align-items: center; gap: 9px;
        }
        .qr-panel .qr-text h3 i { color: #059669; }
        .qr-panel .qr-text p { font-size: 14px; color: #475569; margin-bottom: 14px; line-height: 1.6; }
        .qr-panel .qr-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .qr-panel .qr-actions a {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; border-radius: 9px;
            font-size: 13px; font-weight: 600;
            text-decoration: none; transition: all 0.18s;
        }
        .qr-panel .qr-actions .link-primary {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1e293b; box-shadow: 0 4px 12px rgba(245,158,11,0.35);
        }
        .qr-panel .qr-actions .link-primary:hover { transform: translateY(-2px); }
        .qr-panel .qr-actions .link-secondary {
            background: #fff; border: 1px solid #e2e8f0; color: #334155;
        }
        .qr-panel .qr-actions .link-secondary:hover { border-color: #cbd5e1; background: #f8fafc; }

        /* ===== FORM BODY ===== */
        .form-body {
            padding: 32px 40px 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
        }
        .form-column { display: flex; flex-direction: column; gap: 24px; }

        /* ===== FORM SECTIONS ===== */
        .form-section {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 16px; padding: 24px 24px 8px;
            transition: all 0.2s;
        }
        .form-section:hover { border-color: #fcd34d; }
        .form-section.highlight {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: #fcd34d;
        }

        .section-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 20px; padding-bottom: 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        .section-header .icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 15px; flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(251,191,36,0.3);
        }
        .section-header h3 {
            font-size: 15px; font-weight: 700; color: #0f172a; letter-spacing: -0.2px;
        }
        .section-header p { font-size: 12px; color: #64748b; margin-top: 1px; }

        /* ===== FIELDS ===== */
        .form-grid { display: grid; gap: 16px; margin-bottom: 20px; }
        .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        .field label {
            font-size: 12.5px; font-weight: 600;
            color: #334155; letter-spacing: 0.02em;
        }
        .field label .req { color: #dc2626; margin-left: 2px; }

        .field input, .field select {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 10px;
            background: #fff; color: #0f172a;
            font-family: inherit; font-size: 14px;
            transition: all 0.18s; outline: none;
        }
        .field input::placeholder { color: #94a3b8; }
        .field input:hover, .field select:hover { border-color: #cbd5e1; }
        .field input:focus, .field select:focus {
            border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.15);
        }

        .field input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer; opacity: 0.6;
        }

        .field select {
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 15px;
            padding-right: 40px;
            cursor: pointer;
        }

        .field .hint {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.4;
        }

        /* ===== FORM ACTIONS ===== */
        .form-actions {
            grid-column: 1 / -1;
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap;
            padding-top: 24px; border-top: 1px solid #e2e8f0;
            margin-top: 8px;
        }
        .form-actions .note {
            font-size: 13px;
            color: #64748b;
        }
        .form-actions .note a {
            color: #f59e0b;
            font-weight: 600;
            text-decoration: none;
        }
        .form-actions .note a:hover { text-decoration: underline; }
        .form-actions .btn-group {
            display: flex; gap: 12px; flex-wrap: wrap;
        }

        .btn-cancel {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 13px 26px; border-radius: 10px;
            font-size: 14px; font-weight: 600; color: #475569;
            background: #fff; border: 1.5px solid #e2e8f0;
            text-decoration: none; cursor: pointer;
            transition: all 0.18s; font-family: inherit;
        }
        .btn-cancel:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }

        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 13px 28px; border-radius: 10px;
            font-family: inherit; font-size: 14px; font-weight: 700;
            color: #1e293b;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            border: none; cursor: pointer; transition: all 0.2s;
            box-shadow: 0 6px 16px rgba(245,158,11,0.35);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(245,158,11,0.45); }
        .btn-primary:active { transform: translateY(0); }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1000px) {
            .form-body { grid-template-columns: 1fr; gap: 20px; }
            .qr-panel { grid-template-columns: 1fr; text-align: center; }
            .qr-panel .qr-box { margin: 0 auto; }
            .qr-panel .qr-actions { justify-content: center; }
        }
        @media (max-width: 720px) {
            body { padding: 20px 12px 40px; }
            .form-grid.cols-2 { grid-template-columns: 1fr; }
            .title-bar, .form-body { padding-left: 20px; padding-right: 20px; }
            .alert, .qr-panel { margin-left: 20px; margin-right: 20px; }
            .title-bar h2 { font-size: 18px; }
            .form-actions { flex-direction: column-reverse; align-items: stretch; }
            .form-actions .btn-group { flex-direction: column-reverse; width: 100%; }
            .btn-cancel, .btn-primary { width: 100%; }
            .page-header { flex-direction: column; align-items: stretch; }
            .header-actions { width: 100%; }
            .btn-outline { flex: 1; justify-content: center; }
        }
    </style>
</head>
<body>

    <header class="page-header">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-store"></i></div>
            <div class="brand-text">
                <h1>MALLENGKE</h1>
                <p>Vendor Monitoring &amp; Management System · Tupi, South Cotabato</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="login.php" class="btn-outline"><i class="fas fa-right-to-bracket"></i> Sign In</a>
            <a href="../index.php" class="btn-outline primary"><i class="fas fa-home"></i> Home</a>
        </div>
    </header>

    <main class="container">

        <div class="title-bar">
            <div class="title-group">
                <h2><i class="fas fa-user-plus"></i> Vendor Registration</h2>
                <p>Register at Mallengke, Poblacion, Tupi, South Cotabato. You will apply for a stall once you are in.</p>
            </div>
            <span class="badge-info">
                <i class="fas fa-qrcode"></i> QR Passport issued on registration
            </span>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-circle-exclamation"></i>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <span><?= $success ?></span>
            </div>

            <!-- QR PASSPORT PANEL -->
            <div class="qr-panel">
                <div class="qr-box" id="qrcode"></div>
                <div class="qr-text">
                    <h3><i class="fas fa-id-badge"></i> Your Smart Vendor QR Status Passport</h3>
                    <p>
                        Your account is ready! Scan or open your QR passport to view your stall
                        location, products, permit status, payment status, and upcoming deadlines.
                        The passport updates as soon as the market office assigns you a stall.
                    </p>
                    <div class="qr-actions">
                        <a href="stall_map.php" class="link-primary">
                            <i class="fas fa-location-dot"></i> Apply for a Stall
                        </a>
                        <a href="vendor_passport.php?t=<?= htmlspecialchars($qrToken) ?>" target="_blank" class="link-secondary">
                            <i class="fas fa-external-link-alt"></i> View My Passport
                        </a>
                        <a href="../dashboard.php" class="link-secondary">
                            <i class="fas fa-gauge-high"></i> Go to Dashboard
                        </a>
                        <a href="logout.php" class="link-secondary">
                            <i class="fas fa-right-from-bracket"></i> Sign Out
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="vendor_register.php" enctype="multipart/form-data">
            <div class="form-body">

                <!-- ============ LEFT COLUMN ============ -->
                <div class="form-column">

                    <!-- Account Credentials -->
                    <section class="form-section highlight">
                        <div class="section-header">
                            <div class="icon"><i class="fas fa-user-lock"></i></div>
                            <div>
                                <h3>Account Credentials</h3>
                                <p>Your login details for the vendor portal</p>
                            </div>
                        </div>

                        <div class="reg-photo" data-reg-photo>
                            <div class="reg-photo-preview" data-reg-photo-preview role="button" tabindex="0" aria-label="Choose a profile photo">
                                <i class="fas fa-camera" data-reg-photo-placeholder></i>
                                <img data-reg-photo-img alt="Profile photo preview" hidden />
                            </div>
                            <div class="reg-photo-copy">
                                <strong>Profile photo <span>Optional</span></strong>
                                <p>Add a face to your account now — you can change it later.</p>
                                <div class="reg-photo-actions">
                                    <label class="reg-photo-btn" for="reg_avatar">
                                        <i class="fas fa-arrow-up-from-bracket"></i> Choose photo
                                    </label>
                                    <button type="button" class="reg-photo-remove" data-reg-photo-remove hidden>Remove</button>
                                    <span class="reg-photo-name" data-reg-photo-name>No photo selected</span>
                                </div>
                                <input class="reg-photo-input" type="file" id="reg_avatar" name="avatar"
                                       accept="image/jpeg,image/png,image/webp,image/gif" data-reg-photo-input />
                                <p class="reg-photo-hint">JPG, PNG, WebP or GIF · up to 3 MB</p>
                                <p class="reg-photo-error" data-reg-photo-error role="alert" hidden></p>
                            </div>
                        </div>

                        <div class="form-grid cols-2">
                            <div class="field full">
                                <label>Username <span class="req">*</span></label>
                                <input type="text" name="username" value="<?= val('username',$old) ?>"
                                       placeholder="Choose a username (3–20 characters)"
                                       pattern="[A-Za-z0-9_.]{3,20}"
                                       title="Letters, numbers, dot or underscore. 3–20 characters."
                                       required />
                                <span class="hint">You'll use this to sign in to your vendor dashboard.</span>
                            </div>
                            <div class="field full">
                                <label>Email Address <span class="req">*</span></label>
                                <input type="email" name="email" value="<?= val('email',$old) ?>"
                                       placeholder="your@email.com" required />
                                <span class="hint">We'll send your approval notice here.</span>
                            </div>
                            <div class="field">
                                <label>Password <span class="req">*</span></label>
                                <input type="password" name="password" minlength="8"
                                       placeholder="At least 8 characters" required />
                            </div>
                            <div class="field">
                                <label>Confirm Password <span class="req">*</span></label>
                                <input type="password" name="confirm" minlength="8"
                                       placeholder="Re-enter password" required />
                            </div>
                        </div>
                    </section>

                    <!-- Personal Information -->
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
                                <label>First Name <span class="req">*</span></label>
                                <input type="text" name="first_name" value="<?= val('first_name',$old) ?>" required />
                            </div>
                            <div class="field">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name" value="<?= val('middle_name',$old) ?>" />
                            </div>
                            <div class="field">
                                <label>Last Name <span class="req">*</span></label>
                                <input type="text" name="last_name" value="<?= val('last_name',$old) ?>" required />
                            </div>
                            <div class="field">
                                <label>Birthdate <span class="req">*</span></label>
                                <input type="date" name="birthdate" value="<?= val('birthdate',$old) ?>" required />
                            </div>
                            <div class="field">
                                <label>Gender <span class="req">*</span></label>
                                <select name="gender" required>
                                    <option value="">Select gender</option>
                                    <?php foreach ($genders as $g): ?>
                                        <option value="<?= $g ?>" <?= sel('gender',$old,$g) ?>><?= $g ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Civil Status <span class="req">*</span></label>
                                <select name="civil_status" required>
                                    <option value="">Select civil status</option>
                                    <?php foreach ($civilStatuses as $c): ?>
                                        <option value="<?= $c ?>" <?= sel('civil_status',$old,$c) ?>><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field full">
                                <label>Contact Number <span class="req">*</span></label>
                                <input type="text" name="contact_number" value="<?= val('contact_number',$old) ?>"
                                       placeholder="09XX XXX XXXX" required />
                            </div>
                            <div class="field full">
                                <label>Complete Address <span class="req">*</span></label>
                                <input type="text" name="address" value="<?= val('address',$old) ?>"
                                       placeholder="House No., Street, Barangay, Municipality" required />
                            </div>
                        </div>
                    </section>

                    <!-- Valid Identification -->
                    <section class="form-section">
                        <div class="section-header">
                            <div class="icon"><i class="fas fa-id-card"></i></div>
                            <div>
                                <h3>Valid Identification</h3>
                                <p>Government-issued ID for verification</p>
                            </div>
                        </div>
                        <div class="form-grid cols-2">
                            <div class="field">
                                <label>ID Type <span class="req">*</span></label>
                                <select name="id_type" required>
                                    <option value="">Select ID type</option>
                                    <?php foreach ($idTypes as $t): ?>
                                        <option value="<?= $t ?>" <?= sel('id_type',$old,$t) ?>><?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>ID Number <span class="req">*</span></label>
                                <input type="text" name="id_number" value="<?= val('id_number',$old) ?>"
                                       placeholder="Enter ID number" required />
                            </div>
                        </div>
                    </section>

                </div>

                <!-- ============ RIGHT COLUMN ============ -->
                <div class="form-column">

                    <!-- Your Business -->
                    <section class="form-section">
                        <div class="section-header">
                            <div class="icon"><i class="fas fa-store-alt"></i></div>
                            <div>
                                <h3>Your Business</h3>
                                <p>What you sell. You will not pick a stall here — the market office assigns
                                   that, and you apply for it from your stall map after signing up.</p>
                            </div>
                        </div>
                        <div class="form-grid cols-2">
                            <div class="field full">
                                <label>Business Name</label>
                                <input type="text" name="business_name" value="<?= val('business_name',$old) ?>"
                                       placeholder="(optional — leave blank if none)" />
                            </div>
                            <div class="field">
                                <label>Years in Business</label>
                                <input type="number" name="years_in_business" min="0" max="80"
                                       value="<?= val('years_in_business',$old,'0') ?>" />
                            </div>
                            <div class="field">
                                <label>Market Section <span class="req">*</span></label>
                                <select name="market_section" required>
                                    <option value="">Select market section</option>
                                    <?php foreach ($sections as $s): ?>
                                        <option value="<?= $s ?>" <?= sel('market_section',$old,$s) ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="hint">What you trade in. The office may place your stall in this section.</span>
                            </div>
                            <div class="field full">
                                <label>Type of Products to Sell <span class="req">*</span></label>
                                <input type="text" name="product_type" value="<?= val('product_type',$old) ?>"
                                       placeholder="e.g. Fresh pork, chicken, beef" required />
                            </div>
                        </div>
                    </section>

                    <!-- Emergency Contact -->
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
                                <label>Full Name <span class="req">*</span></label>
                                <input type="text" name="emergency_name" value="<?= val('emergency_name',$old) ?>" required />
                            </div>
                            <div class="field">
                                <label>Relationship <span class="req">*</span></label>
                                <input type="text" name="emergency_relationship"
                                       value="<?= val('emergency_relationship',$old) ?>"
                                       placeholder="e.g. Spouse, Parent" required />
                            </div>
                            <div class="field">
                                <label>Contact Number <span class="req">*</span></label>
                                <input type="text" name="emergency_contact"
                                       value="<?= val('emergency_contact',$old) ?>"
                                       placeholder="09XX XXX XXXX" required />
                            </div>
                        </div>
                    </section>

                </div>

                <!-- ============ ACTIONS ============ -->
                <div class="form-actions">
                    <span class="note">
                        Already have an account? <a href="login.php">Sign in here</a>
                    </span>
                    <div class="btn-group">
                        <a href="../index.php" class="btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-user-plus"></i> Register as Vendor
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </main>

    <?php if ($qrToken): ?>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        const passportUrl = "<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/vendor_passport.php?t=' . $qrToken) ?>";
        new QRCode(document.getElementById("qrcode"), {
            text: passportUrl,
            width: 156,
            height: 156,
            colorDark: "#0f172a",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    </script>
    <?php endif; ?>

    <script src="../assets/registration-photo.js?v=20261021a" defer></script>
</body>
</html>