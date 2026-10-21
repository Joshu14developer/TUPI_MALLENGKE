<?php
$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/profile.php';
session_start();

/* Already signed in as a customer? → dashboard */
if (isset($_SESSION['customer_id'])) {
    header('Location: ../customer_dashboard.php');
    exit;
}

$error   = '';
$success = '';
$old     = [];

/* ============================================================
   REGISTER FUNCTION
   ============================================================ */
function register_customer($conn, $data)
{
    $required = ['first_name','last_name','email','username','password','confirm'];
    foreach ($required as $f) {
        if (empty(trim((string)($data[$f] ?? '')))) {
            return 'Please fill in all required fields.';
        }
    }

    $first    = trim($data['first_name']);
    $last     = trim($data['last_name']);
    $email    = trim($data['email']);
    $username = trim($data['username']);
    $password = $data['password'];
    $confirm  = $data['confirm'];
    $phone    = trim($data['phone']   ?? '');
    $address  = trim($data['address'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if (!preg_match('/^[A-Za-z0-9_.]{3,20}$/', $username)) {
        return 'Username must be 3–20 characters (letters, numbers, dot or underscore).';
    }
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        return 'Passwords do not match.';
    }

    /* Duplicate check */
    $stmt = $conn->prepare(
        "SELECT id FROM customers WHERE username = ? OR email = ? LIMIT 1"
    );
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        return 'That username or email is already registered. Try signing in instead.';
    }
    $stmt->close();

    /* Optional profile photo. Validate and store it before writing the
       account so an invalid image never creates a half-finished sign-up. */
    $avatarPath = null;
    $avatarFile = $_FILES['avatar'] ?? null;
    if (profile_avatar_selected($avatarFile)) {
        $avatarError = null;
        $avatarPath = profile_store_avatar($avatarFile, 'customer_' . $username, $avatarError);
        if ($avatarPath === null) {
            return $avatarError ?: 'The profile photo could not be uploaded.';
        }
    }

    /* Insert */
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO customers
            (first_name, last_name, email, phone, address, username, password, avatar_path, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        profile_delete_avatar($avatarPath);
        return 'Registration failed. Please try again.';
    }
    $stmt->bind_param("ssssssss", $first, $last, $email, $phone, $address, $username, $hash, $avatarPath);

    try {
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }
        $err = $stmt->error;
        $stmt->close();
        profile_delete_avatar($avatarPath);
        return 'Registration failed: ' . $err;
    } catch (Throwable $e) {
        $stmt->close();
        profile_delete_avatar($avatarPath);
        return 'Registration failed. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;
    $result = register_customer($conn, $_POST);
    if ($result === true) {
        $success = 'Account created successfully! You can now sign in.';
        $old = [];
    } else {
        $error = $result;
    }
}

function val($key, $old, $default = '') {
    return htmlspecialchars($old[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Customer Registration • MALLENGKE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/registration-photo.css?v=20261021a" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: url('https://1.bp.blogspot.com/-7D4K5YsIy9k/VIqMIw0eT7I/AAAAAAAAGG4/zsqaQxHJt5U/s1600/DSC09775.JP') no-repeat center center/cover;
            padding: 20px;
            position: relative;
        }
        body::before {
            content: '';
            position: absolute; inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 0;
        }

        .login-wrapper {
            position: relative; z-index: 1;
            width: 100%; max-width: 480px;
            animation: fadeUp 0.6s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .glass-card {
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border-radius: 32px;
            padding: 40px 32px;
            border: 1px solid rgba(255,255,255,0.25);
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }

        .brand-header { text-align: center; margin-bottom: 26px; }
        .brand-header h1 {
            font-size: 28px; font-weight: 800; color: #ffffff;
            letter-spacing: -0.5px;
        }
        .brand-header h1 i { color: #fbbf24; margin-right: 8px; }
        .brand-header .subtitle {
            color: rgba(255,255,255,0.8); font-size: 13px;
            margin-top: 4px; font-weight: 400;
            letter-spacing: 0.04em; text-transform: uppercase;
        }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-weight: 500; font-size: 13px;
            color: rgba(255,255,255,0.9); margin-bottom: 6px;
        }
        .input-icon { position: relative; }
        .input-icon .icon {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.6); font-size: 15px;
        }
        .input-icon input {
            width: 100%; padding: 13px 14px 13px 46px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 14px; color: #ffffff;
            font-size: 15px; transition: all 0.3s; outline: none;
            font-family: inherit;
        }
        .input-icon input::placeholder { color: rgba(255,255,255,0.5); }
        .input-icon input:focus {
            border-color: #fbbf24;
            background: rgba(255,255,255,0.15);
            box-shadow: 0 0 0 4px rgba(251,191,36,0.15);
        }

        .row-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }
        @media (max-width: 480px) { .row-2 { grid-template-columns: 1fr; } }

        .error-message {
            background: rgba(239,68,68,0.2);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5; padding: 12px 16px;
            border-radius: 12px; font-size: 14px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }
        .success-message {
            background: rgba(16,185,129,0.2);
            border: 1px solid rgba(16,185,129,0.3);
            color: #6ee7b7; padding: 12px 16px;
            border-radius: 12px; font-size: 14px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }

        .login-btn {
            width: 100%; padding: 14px;
            background: #fbbf24; border: none; border-radius: 14px;
            color: #1e293b; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 4px 14px rgba(251,191,36,0.35);
            display: inline-flex; align-items: center;
            justify-content: center; gap: 8px; text-decoration: none;
            font-family: inherit; margin-top: 8px;
        }
        .login-btn:hover {
            background: #f59e0b; transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(251,191,36,0.45);
        }

        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 22px 0 14px; color: rgba(255,255,255,0.5);
            font-size: 12px; font-weight: 500;
            text-transform: uppercase; letter-spacing: 0.08em;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px;
            background: rgba(255,255,255,0.2);
        }

        .link-btn {
            width: 100%; padding: 13px;
            background: rgba(255,255,255,0.1);
            border: 1.5px solid rgba(255,255,255,0.3);
            border-radius: 14px; color: #ffffff;
            font-family: inherit; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: all 0.25s;
            display: inline-flex; align-items: center;
            justify-content: center; gap: 9px; text-decoration: none;
        }
        .link-btn i { color: #fbbf24; }
        .link-btn:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(251,191,36,0.6);
            transform: translateY(-2px);
        }

        .hint {
            text-align: center; font-size: 12.5px;
            color: rgba(255,255,255,0.65);
            margin-top: 10px; line-height: 1.55;
        }
        .hint i { color: #fbbf24; margin-right: 4px; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-card">

            <div class="brand-header">
                <h1><i class="fas fa-user-plus"></i> Customer Sign Up</h1>
                <p class="subtitle">Mallengke · Tupi, South Cotabato</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="customer_register.php" enctype="multipart/form-data">

                <div class="reg-photo reg-photo--glass" data-reg-photo>
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

                <div class="row-2">
                    <div class="form-group">
                        <label>First Name *</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-user"></i></span>
                            <input type="text" name="first_name" value="<?= val('first_name',$old) ?>" required />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-user"></i></span>
                            <input type="text" name="last_name" value="<?= val('last_name',$old) ?>" required />
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <div class="input-icon">
                        <span class="icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" value="<?= val('email',$old) ?>"
                               placeholder="you@email.com" required />
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>Phone</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-phone"></i></span>
                            <input type="text" name="phone" value="<?= val('phone',$old) ?>"
                                   placeholder="09XX XXX XXXX" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>City / Address</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-location-dot"></i></span>
                            <input type="text" name="address" value="<?= val('address',$old) ?>"
                                   placeholder="Tupi, South Cotabato" />
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Username *</label>
                    <div class="input-icon">
                        <span class="icon"><i class="fas fa-at"></i></span>
                        <input type="text" name="username" value="<?= val('username',$old) ?>"
                               placeholder="Pick a username (3–20 characters)"
                               pattern="[A-Za-z0-9_.]{3,20}"
                               title="Letters, numbers, dot or underscore. 3–20 characters."
                               required />
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>Password *</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" minlength="8"
                                   placeholder="8+ characters" required />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <div class="input-icon">
                            <span class="icon"><i class="fas fa-check-circle"></i></span>
                            <input type="password" name="confirm" minlength="8"
                                   placeholder="Re-enter password" required />
                        </div>
                    </div>
                </div>

                <button type="submit" class="login-btn">
                    <i class="fas fa-user-plus"></i> Create Customer Account
                </button>
            </form>

            <div class="divider">Already have an account?</div>

            <a href="customer_login.php" class="link-btn">
                <i class="fas fa-right-to-bracket"></i> Sign In as Customer
            </a>

            <p class="hint">
                <i class="fas fa-store"></i>
                Are you a vendor? <a href="login.php" style="color:#fbbf24;">Sign in here</a>
            </p>

        </div>
    </div>
    <script src="../assets/registration-photo.js?v=20261021a" defer></script>
</body>
</html>