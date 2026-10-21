<?php
$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/reports.php';
session_start();

/**
 * Sign a vendor in only while the office lets them in. Checked before
 * any session key is written, so posting the form again does not get
 * around it.
 *
 * @return true|string true, or the reason to show instead
 */
function allow_vendor_access($conn, $userId)
{
    $block = access_block_for_user($conn, $userId);
    return $block ? $block['description'] : true;
}

/** Drop the "remember me" cookie and its stored token. */
function forget_remember_cookie($conn, $userId = 0)
{
    if ($userId > 0 && $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
    ]);
}

/**
 * The access block for a login, or null when that vendor may sign in.
 *
 * $fallbackName is only used when the vendor row cannot be read, which
 * happens for a login whose credential was destroyed with the record.
 *
 * @return array|null
 */
function access_block_for_user($conn, $userId, $fallbackName = '')
{
    $vendorId = 0;
    if ($stmt = $conn->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1")) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $vendorId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }

    if ($vendorId <= 0) {
        if (vendor_credential_gone($conn, $userId)) {
            return [
                'kind'        => 'removed',
                'name'        => (string)$fallbackName,
                'stall'       => '',
                'description' => 'Your login account has been removed by the market office. '
                               . 'Please visit the market office if you think this is a mistake.',
            ];
        }
        return null;
    }

    return vendor_access_block($conn, $vendorId);
}

/**
 * Send a blocked vendor to the suspended screen with their reason.
 * Never returns.
 */
function redirect_to_suspended($conn, $userId, $fallbackName = '', $description = '')
{
    forget_remember_cookie($conn, $userId);

    $block = access_block_for_user($conn, $userId, $fallbackName);
    if (!$block) {
        /* Nothing on record blocks them, so the reason the caller was
           given stands — it is never blank. */
        $block = [
            'kind'        => 'suspended',
            'name'        => (string)$fallbackName,
            'stall'       => '',
            'description' => (string)$description !== ''
                ? (string)$description
                : 'Your stall access has been suspended by the market office.',
        ];
    }

    $_SESSION['access_block'] = $block;
    header('Location: suspended.php');
    exit;
}

/* Auto-login from "remember me" cookie */
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
    $hash = hash('sha256', $_COOKIE['remember_token']);
    $stmt = $conn->prepare(
        "SELECT id, username, email FROM users WHERE remember_token = ? LIMIT 1"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        /* Same rule as the form: a blocked vendor never gets in, not
           even through the cookie. */
        if (allow_vendor_access($conn, (int)$user['id']) === true) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email']    = $user['email'];
        } else {
            redirect_to_suspended($conn, (int)$user['id'], $user['username']);
        }
    }
}

if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard.php');
    exit;
}

$error = '';

/* ============================================================
   HELPER: log a user in from a vendor's user_id
   ------------------------------------------------------------
   Returns true, or the reason the login was refused.
   ============================================================ */
function login_by_user_id($conn, $user_id)
{
    $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) return false;

    /* A removed credential cannot be signed into from anywhere, the
       QR passport included. */
    if (vendor_credential_gone($conn, (int)$user['id'])) {
        return 'That login has been removed by the market office. Please contact the market office.';
    }

    $allowed = allow_vendor_access($conn, (int)$user['id']);
    if ($allowed !== true) return $allowed;

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email']    = $user['email'];
    return true;
}

/* ============================================================
   LOGIN FUNCTION (username + password)
   ============================================================ */
function login_user($conn, $username, $password, $remember = false)
{
    $username = trim($username);

    if ($username === '' || $password === '') {
        return 'Please enter username and password.';
    }

    $stmt = $conn->prepare(
        "SELECT id, username, email, password 
         FROM users 
         WHERE username = ? OR email = ? 
         LIMIT 1"
    );
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return 'Invalid username or password.';
    }

    /* A login whose password the office destroyed still has to be
       recognised, otherwise the vendor would only ever see "invalid
       password" and never learn why. They are shown the suspended
       screen instead. */
    if (!password_verify($password, $user['password'])) {
        if (vendor_credential_gone($conn, (int)$user['id'])) {
            redirect_to_suspended($conn, (int)$user['id'], $user['username']);
        }
        return 'Invalid username or password.';
    }

    /* The office can suspend a stall or remove a login; that has to
       hold here too. */
    $allowed = allow_vendor_access($conn, (int)$user['id']);
    if ($allowed !== true) {
        redirect_to_suspended($conn, (int)$user['id'], $user['username'], $allowed);
    }

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email']    = $user['email'];

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);

        $stmt = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $user['id']);
        $stmt->execute();
        $stmt->close();

        setcookie('remember_token', $token, [
            'expires'  => time() + (60 * 60 * 24 * 30),
            'path'     => '/',
            'httponly' => true,
        ]);
    }

    return true;
}

/* ============================================================
   HANDLE SUBMIT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? 'login';

    /* ---------- QR LOGIN ---------- */
    if ($action === 'qr_login') {
        $token = trim($_POST['qr_token'] ?? '');

        if ($token === '') {
            $error = 'No QR code detected. Please try again.';
        } else {
            /* Look up vendor by qr_token */
            $stmt = $conn->prepare("SELECT user_id FROM vendors WHERE qr_token = ? LIMIT 1");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $vendor = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$vendor || empty($vendor['user_id'])) {
                $error = 'QR code not recognized. Please contact the market office.';
            } else {
                $signedIn = login_by_user_id($conn, (int)$vendor['user_id']);
                if ($signedIn === true) {
                    header('Location: ../dashboard.php');
                    exit;
                }
                $error = is_string($signedIn)
                    ? $signedIn
                    : 'Linked account not found. Please contact the market office.';
            }
        }
    }

    /* ---------- NORMAL LOGIN ---------- */
    if ($action === 'login') {
        $result = login_user(
            $conn,
            $_POST['username'] ?? '',
            $_POST['password'] ?? '',
            isset($_POST['remember'])
        );

        if ($result === true) {
            header('Location: ../dashboard.php');
            exit;
        }
        $error = $result;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sign In • MALLENGKE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: url('https://1.bp.blogspot.com/-7D4K5YsIy9k/VIqMIw0eT7I/AAAAAAAAGG4/zsqaQxHJt5U/s1600/DSC09775.JP') no-repeat center center/cover;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: 0;
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            animation: fadeUp 0.6s ease;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border-radius: 32px;
            padding: 40px 32px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            transition: border-color 0.3s;
        }

        .glass-card:hover { border-color: rgba(255, 255, 255, 0.45); }

        /* Brand */
        .brand-header { text-align: center; margin-bottom: 30px; }
        .brand-header h1 {
            font-size: 32px; font-weight: 700; color: #ffffff;
            letter-spacing: -0.5px; text-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .brand-header h1 i { color: #fbbf24; margin-right: 8px; }
        .brand-header .subtitle {
            color: rgba(255,255,255,0.8); font-size: 14px;
            margin-top: 4px; font-weight: 400;
        }

        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; font-weight: 500; font-size: 13px;
            color: rgba(255,255,255,0.9); margin-bottom: 6px;
        }

        .input-icon { position: relative; }
        .input-icon .icon {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.6); font-size: 16px;
        }
        .input-icon input {
            width: 100%; padding: 14px 14px 14px 48px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 14px; color: #ffffff;
            font-size: 15px; transition: all 0.3s; outline: none;
        }
        .input-icon input::placeholder { color: rgba(255,255,255,0.5); }
        .input-icon input:focus {
            border-color: #fbbf24;
            background: rgba(255,255,255,0.15);
            box-shadow: 0 0 0 4px rgba(251,191,36,0.15);
        }

        /* Messages */
        .error-message {
            background: rgba(239,68,68,0.2);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5; padding: 12px 16px;
            border-radius: 12px; font-size: 14px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 8px;
        }
        .success-message {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7; padding: 12px 16px;
            border-radius: 12px; font-size: 14px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 8px;
        }

        /* Options */
        .form-options {
            display: flex; justify-content: space-between; align-items: center;
            margin: 8px 0 24px 0; font-size: 14px;
            color: rgba(255,255,255,0.8);
        }
        .remember-me { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .remember-me input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: #fbbf24; cursor: pointer;
        }
        .forgot-link {
            color: #fbbf24; text-decoration: none; font-weight: 500;
        }
        .forgot-link:hover { text-decoration: underline; }

        /* Buttons */
        .login-btn {
            width: 100%; padding: 14px;
            background: #fbbf24; border: none; border-radius: 14px;
            color: #1e293b; font-size: 16px; font-weight: 700;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 4px 14px rgba(251,191,36,0.35);
            display: inline-flex; align-items: center;
            justify-content: center; gap: 8px; text-decoration: none;
            font-family: inherit;
        }
        .login-btn:hover {
            background: #f59e0b; transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(251,191,36,0.45);
        }
        .login-btn:active { transform: translateY(0); }

        /* Divider */
        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 24px 0 18px; color: rgba(255,255,255,0.5);
            font-size: 12px; font-weight: 500;
            text-transform: uppercase; letter-spacing: 0.08em;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px;
            background: rgba(255,255,255,0.2);
        }

        /* Register button */
        .register-btn {
            width: 100%; padding: 14px;
            background: rgba(255,255,255,0.1);
            border: 1.5px solid rgba(255,255,255,0.3);
            border-radius: 14px; color: #ffffff;
            font-family: inherit; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.25s;
            display: inline-flex; align-items: center;
            justify-content: center; gap: 9px; text-decoration: none;
        }
        .register-btn i { color: #fbbf24; }
        .register-btn:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(251,191,36,0.6);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        /* QR scan button */
        .qr-btn {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #059669, #065f46);
            border: none; border-radius: 14px;
            color: #ffffff; font-family: inherit;
            font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.25s;
            display: inline-flex; align-items: center;
            justify-content: center; gap: 9px;
            box-shadow: 0 4px 14px rgba(16,185,129,0.35);
        }
        .qr-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(16,185,129,0.5);
        }
        .qr-btn i { font-size: 16px; }

        .register-hint {
            text-align: center; font-size: 12.5px;
            color: rgba(255,255,255,0.65);
            margin-top: 12px; line-height: 1.55;
        }
        .register-hint i { color: #fbbf24; margin-right: 4px; }

        /* Demo */
        .demo-credentials {
            margin-top: 24px; padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.15);
            text-align: center; font-size: 13px;
            color: rgba(255,255,255,0.6);
        }
        .demo-credentials strong {
            color: #ffffff; background: rgba(255,255,255,0.1);
            padding: 2px 10px; border-radius: 6px; font-weight: 600;
        }

        /* ====== QR SCANNER PANEL ====== */
        .qr-panel {
            display: none;
            margin-top: 4px;
        }
        .qr-panel.active { display: block; }

        .qr-back-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; margin-bottom: 14px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 10px; color: #ffffff;
            font-family: inherit; font-size: 13px; font-weight: 600;
            cursor: pointer; transition: 0.18s;
        }
        .qr-back-btn:hover { background: rgba(255,255,255,0.2); }

        .qr-title {
            text-align: center;
            color: #ffffff; font-size: 15px; font-weight: 600;
            margin-bottom: 6px;
        }
        .qr-sub {
            text-align: center;
            color: rgba(255,255,255,0.65);
            font-size: 12.5px; margin-bottom: 14px;
        }

        #qr-reader {
            width: 100%;
            border-radius: 16px;
            overflow: hidden;
            background: #000;
            min-height: 240px;
            border: 1px solid rgba(255,255,255,0.2);
        }

        #qr-reader video {
            width: 100% !important;
            height: auto !important;
            display: block;
            border-radius: 16px;
        }

        /* Hide html5-qrcode's default branding/buttons */
        #qr-reader__dashboard_section_csr button {
            background: #fbbf24 !important;
            color: #1e293b !important;
            border: none !important;
            padding: 8px 14px !important;
            border-radius: 8px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
        }
        #qr-reader img[alt="Info icon"] { display: none !important; }
        #qr-reader__dashboard_section_swaplink { display: none !important; }

        .qr-status {
            text-align: center;
            color: rgba(255,255,255,0.75);
            font-size: 13px; margin-top: 12px;
            min-height: 20px;
        }

        .qr-status.ok { color: #6ee7b7; font-weight: 600; }
        .qr-status.err { color: #fca5a5; font-weight: 600; }

        @media (max-width: 480px) {
            .glass-card { padding: 30px 20px; }
            .brand-header h1 { font-size: 26px; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-card">

            <div class="brand-header">
                <h1><i class="fas fa-store"></i> MALLENGKE</h1>
                <p class="subtitle">TUPI • South Cotabato</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- ================= SIGN IN VIEW ================= -->
            <div id="signin-view">

                <form method="POST" action="login.php">
                    <input type="hidden" name="action" value="login" />

                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-user"></i></span>
                            <input type="text" id="username" name="username"
                                   placeholder="Enter your username or email"
                                   required autofocus />
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-lock"></i></span>
                            <input type="password" id="password" name="password"
                                   placeholder="Enter your password" required />
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" /> Remember me
                        </label>
                        <a href="#" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="login-btn">
                        <i class="fas fa-right-to-bracket"></i> Sign In
                    </button>
                </form>

                <div class="divider">Quick Access</div>

                <button type="button" class="qr-btn" onclick="startScanner()">
                    <i class="fas fa-qrcode"></i> Scan QR to Login
                </button>

                <p class="register-hint" style="margin-top:10px;">
                    <i class="fas fa-camera"></i>
                    Show your vendor QR code to the camera to log in instantly.
                </p>

                <div class="divider">New here?</div>

                <a href="register.php" class="register-btn">
                    <i class="fas fa-user-plus"></i>
                    Create an Account
                </a>

                <p class="register-hint">
                    <i class="fas fa-circle-info"></i>
                    You must complete your personal info, ID, and stall details to enter the system.
                </p>

                <div class="demo-credentials">
                    <p>Demo: <strong>demo</strong> / <strong>demo123</strong></p>
                </div>

            </div>

            <!-- ================= QR SCANNER VIEW ================= -->
            <div id="qr-view" class="qr-panel">
                <button type="button" class="qr-back-btn" onclick="stopScanner()">
                    <i class="fas fa-arrow-left"></i> Back to Sign In
                </button>

                <div class="qr-title">Scan your Vendor QR Code</div>
                <p class="qr-sub">Point the QR at the camera to log in automatically.</p>

                <div id="qr-reader"></div>
                <div id="qr-status" class="qr-status">Waiting for camera…</div>

                <form method="POST" action="login.php" id="qr-form" style="display:none;">
                    <input type="hidden" name="action" value="qr_login" />
                    <input type="hidden" name="qr_token" id="qr_token" />
                </form>
            </div>

        </div>
    </div>

    <!-- =========== html5-qrcode library =========== -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        let html5QrCode = null;
        let isScanning = false;

        /* View switching */
        function showQrView() {
            document.getElementById('signin-view').style.display = 'none';
            document.getElementById('qr-view').classList.add('active');
        }
        function showSigninView() {
            document.getElementById('signin-view').style.display = 'block';
            document.getElementById('qr-view').classList.remove('active');
        }

        /* Extract token from scanned URL */
        function extractToken(text) {
            try {
                // Full URL case: http://.../vendor_passport.php?t=abc
                if (text.includes('t=')) {
                    const url = new URL(text, window.location.origin);
                    return url.searchParams.get('t') || '';
                }
                // Raw token case (hex 32 chars)
                if (/^[a-f0-9]{16,64}$/i.test(text.trim())) {
                    return text.trim();
                }
            } catch (e) { /* fall through */ }
            return '';
        }

        /* Start camera + scanning */
        function startScanner() {
            showQrView();
            document.getElementById('qr-status').textContent = 'Starting camera…';
            document.getElementById('qr-status').className = 'qr-status';

            html5QrCode = new Html5Qrcode("qr-reader");
            const config = { fps: 10, qrbox: { width: 220, height: 220 } };

            html5QrCode.start(
                { facingMode: "environment" },   // rear camera
                config,
                onScanSuccess,
                onScanError
            )
            .then(() => {
                isScanning = true;
                document.getElementById('qr-status').textContent = 'Point your QR code at the camera…';
            })
            .catch(err => {
                document.getElementById('qr-status').textContent =
                    'Cannot access camera: ' + err;
                document.getElementById('qr-status').className = 'qr-status err';
            });
        }

        /* Success: extract token and submit */
        function onScanSuccess(decodedText) {
            const token = extractToken(decodedText);

            if (!token) {
                document.getElementById('qr-status').textContent =
                    'QR code not recognized. Please scan a MALLENGKE vendor QR.';
                document.getElementById('qr-status').className = 'qr-status err';
                return;
            }

            document.getElementById('qr-status').textContent = '✓ QR detected — logging in…';
            document.getElementById('qr-status').className = 'qr-status ok';

            // Stop camera then submit
            if (html5QrCode && isScanning) {
                html5QrCode.stop().finally(() => {
                    document.getElementById('qr_token').value = token;
                    document.getElementById('qr-form').submit();
                });
            } else {
                document.getElementById('qr_token').value = token;
                document.getElementById('qr-form').submit();
            }
        }

        /* Ignore scan miss errors — they fire every frame */
        function onScanError(err) { /* no-op */ }

        /* Back button */
        function stopScanner() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    isScanning = false;
                    showSigninView();
                }).catch(() => {
                    isScanning = false;
                    showSigninView();
                });
            } else {
                showSigninView();
            }
        }
    </script>
</body>
</html>