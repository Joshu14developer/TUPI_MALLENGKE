<?php
session_start();
$conn = require __DIR__ . '/../config/db.php';

/* Already signed in → go to dashboard */
if (!empty($_SESSION['admin_id'])) {
    header('Location: admin_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = (string)($_POST['password'] ?? '');
    $remember   = !empty($_POST['remember']);

    if ($identifier === '' || $password === '') {
        $error = 'Please fill in both fields.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, username, email, password_hash, full_name, role, avatar_color, is_active
               FROM admin_users
              WHERE username = ? OR email = ?
              LIMIT 1"
        );
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin) {
            $error = 'Invalid credentials.';
        } elseif ((int)$admin['is_active'] !== 1) {
            $error = 'This account has been disabled. Contact the superadmin.';
        } elseif (!password_verify($password, $admin['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            /* Success — set session */
            $_SESSION['admin_id']        = (int)$admin['id'];
            $_SESSION['admin_username']  = $admin['username'];
            $_SESSION['admin_full_name'] = $admin['full_name'];
            $_SESSION['admin_role']      = $admin['role'];
            $_SESSION['admin_color']     = $admin['avatar_color'];
            $_SESSION['admin_email']     = $admin['email'];

            /* Track login */
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $up = $conn->prepare("UPDATE admin_users SET last_login = NOW(), last_ip = ? WHERE id = ?");
            $up->bind_param("si", $ip, $admin['id']);
            $up->execute();
            $up->close();

            /* Log the sign-in */
            $log = $conn->prepare(
                "INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address)
                 VALUES (?, 'admin_login', ?, 'Administrator signed in', ?)"
            );
            if ($log) {
                $log->bind_param("iss", $admin['id'], $admin['username'], $ip);
                $log->execute();
                $log->close();
            }

            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + 60 * 60 * 24 * 30,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            header('Location: admin_dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Admin Login • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #022c22 url('https://1.bp.blogspot.com/-7D4K5YsIy9k/VIqMIw0eT7I/AAAAAAAAGG4/zsqaQxHJt5U/s1600/DSC09775.JP') no-repeat center center/cover;
        padding: 20px;
        position: relative;
        color: #ffffff;
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }

    body::before {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 0;
        pointer-events: none;
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
        font-size: 32px;
        font-weight: 700;
        color: #ffffff;
        letter-spacing: -0.5px;
        text-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }
    .brand-header h1 i { color: #fbbf24; margin-right: 8px; }
    .brand-header .subtitle {
        color: rgba(255, 255, 255, 0.8);
        font-size: 14px;
        margin-top: 4px;
        font-weight: 400;
    }
    .brand-header .brand-note {
        color: rgba(255, 255, 255, 0.68);
        font-size: 13px;
        margin-top: 12px;
        line-height: 1.55;
    }

    /* Form */
    .form-group { margin-bottom: 20px; }
    .form-group label {
        display: block;
        font-weight: 500;
        font-size: 13px;
        color: rgba(255, 255, 255, 0.9);
        margin-bottom: 6px;
    }

    .input-icon { position: relative; }
    .input-icon .icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(255, 255, 255, 0.6);
        font-size: 16px;
        pointer-events: none;
    }
    .input-icon input {
        width: 100%;
        padding: 14px 52px 14px 48px;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 14px;
        color: #ffffff;
        font-family: inherit;
        font-size: 15px;
        transition: all 0.3s;
        outline: none;
    }
    .input-icon input::placeholder { color: rgba(255, 255, 255, 0.5); }
    .input-icon input:focus {
        border-color: #fbbf24;
        background: rgba(255, 255, 255, 0.15);
        box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
    }
    .input-icon:focus-within .icon { color: #fbbf24; }

    .toggle-pw {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        width: 40px;
        height: 40px;
        border: 0;
        border-radius: 12px;
        background: transparent;
        color: rgba(255, 255, 255, 0.62);
        display: grid;
        place-items: center;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.22s;
    }
    .toggle-pw:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #ffffff;
    }

    /* Messages */
    .error-message {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #fca5a5;
        padding: 12px 16px;
        border-radius: 12px;
        font-size: 14px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
        animation: shake 0.5s;
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20% { transform: translateX(-6px); }
        40% { transform: translateX(6px); }
        60% { transform: translateX(-4px); }
        80% { transform: translateX(4px); }
    }

    /* Options */
    .form-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin: 8px 0 24px;
        font-size: 14px;
        color: rgba(255, 255, 255, 0.8);
    }
    .remember-me {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        user-select: none;
    }
    .remember-me input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #fbbf24;
        cursor: pointer;
        flex-shrink: 0;
    }
    .forgot-link {
        color: #fbbf24;
        text-decoration: none;
        font-weight: 500;
        text-align: right;
    }
    .forgot-link:hover { text-decoration: underline; }

    /* Button */
    .login-btn {
        width: 100%;
        padding: 14px;
        background: #fbbf24;
        border: none;
        border-radius: 14px;
        color: #1e293b;
        font-family: inherit;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 14px rgba(251, 191, 36, 0.35);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .login-btn:hover {
        background: #f59e0b;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(251, 191, 36, 0.45);
    }
    .login-btn:active { transform: translateY(0); }

    .login-foot {
        margin-top: 24px;
        padding-top: 18px;
        border-top: 1px solid rgba(255, 255, 255, 0.15);
        text-align: center;
        color: rgba(255, 255, 255, 0.58);
        font-size: 12px;
        line-height: 1.6;
    }
    .login-foot i { color: #fbbf24; margin-right: 4px; }

    @media (max-width: 480px) {
        .glass-card { padding: 30px 20px; }
        .brand-header h1 { font-size: 26px; }
        .brand-header .subtitle { font-size: 13px; }
        .form-options { align-items: flex-start; flex-direction: column; }
        .forgot-link { text-align: left; }
    }

    @media (prefers-reduced-motion: reduce) {
        .login-wrapper,
        .error-message {
            animation: none;
        }
        .glass-card,
        .input-icon input,
        .toggle-pw,
        .login-btn {
            transition: none;
        }
        .login-btn:hover { transform: none; }
    }
</style>
</head>
<body>

<div class="login-wrapper">
    <div class="glass-card">

        <div class="brand-header">
            <h1><i class="fas fa-shield-halved"></i> MALLENGKE</h1>
            <p class="subtitle">Admin Control Panel • Tupi, South Cotabato</p>
            <p class="brand-note">Sign in with your administrator credentials to manage the market.</p>
        </div>

        <?php if ($error): ?>
            <div class="error-message" role="alert">
                <i class="fas fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="admin_login.php" autocomplete="off">
            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <div class="input-icon">
                    <span class="icon"><i class="fas fa-user"></i></span>
                    <input type="text" id="identifier" name="identifier"
                           placeholder="Enter username"
                           value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                           autocomplete="username" required autofocus />
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-icon">
                    <span class="icon"><i class="fas fa-lock"></i></span>
                    <input type="password" id="password" name="password"
                           placeholder="Enter password"
                           autocomplete="current-password" required />
                    <button type="button" class="toggle-pw" onclick="togglePw()"
                            title="Show/hide password" aria-label="Show or hide password">
                        <i class="fas fa-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember" value="1" />
                    <span>Keep me signed in</span>
                </label>
                <a href="#" class="forgot-link" onclick="alert('Contact the superadmin to reset your password.'); return false;">
                    Forgot password?
                </a>
            </div>

            <button type="submit" class="login-btn">
                <i class="fas fa-right-to-bracket"></i>
                Sign In to Dashboard
            </button>
        </form>

        <div class="login-foot">
            <i class="fas fa-shield-halved"></i>
            Protected area · All access is logged.<br>
            &copy; <?= date('Y') ?> MALLENGKE — Tupi, South Cotabato
        </div>

    </div>
</div>

<script>
function togglePw() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('pwIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

</body>
</html>
