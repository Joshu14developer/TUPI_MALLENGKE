<?php
$conn = require __DIR__ . '/../config/db.php';
session_start();

/* Auto-login from remember-me cookie */
if (!isset($_SESSION['customer_id']) && !empty($_COOKIE['customer_remember'])) {
    $hash = hash('sha256', $_COOKIE['customer_remember']);
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, username FROM customers WHERE remember_token = ? LIMIT 1");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $_SESSION['customer_id']    = $row['id'];
        $_SESSION['customer_name']  = $row['first_name'] . ' ' . $row['last_name'];
        $_SESSION['customer_email'] = $row['email'];
        $_SESSION['customer_user']  = $row['username'];
    }
}

if (isset($_SESSION['customer_id'])) {
    header('Location: ../customer_dashboard.php');
    exit;
}

$error = '';

/* ============================================================
   LOGIN FUNCTION
   ============================================================ */
function login_customer($conn, $username, $password, $remember = false)
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return 'Please enter your username/email and password.';
    }

    $stmt = $conn->prepare(
        "SELECT id, first_name, last_name, email, username, password
         FROM customers
         WHERE username = ? OR email = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer || !password_verify($password, $customer['password'])) {
        return 'Invalid username or password.';
    }

    $_SESSION['customer_id']    = $customer['id'];
    $_SESSION['customer_name']  = $customer['first_name'] . ' ' . $customer['last_name'];
    $_SESSION['customer_email'] = $customer['email'];
    $_SESSION['customer_user']  = $customer['username'];

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $stmt = $conn->prepare("UPDATE customers SET remember_token = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $customer['id']);
        $stmt->execute();
        $stmt->close();
        setcookie('customer_remember', $token, [
            'expires'  => time() + (60 * 60 * 24 * 30),
            'path'     => '/',
            'httponly' => true,
        ]);
    }

    /* Update last_login */
    $conn->query("UPDATE customers SET last_login = NOW() WHERE id = " . (int)$customer['id']);

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = login_customer(
        $conn,
        $_POST['username'] ?? '',
        $_POST['password'] ?? '',
        isset($_POST['remember'])
    );
    if ($result === true) {
        header('Location: ../customer_dashboard.php');
        exit;
    }
    $error = $result;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Customer Sign In • MALLENGKE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: url('https://1.bp.blogspot.com/-7D4K5YsIy9k/VIqMIw0eT7I/AAAAAAAAGG4/zsqaQxHJt5U/s1600/DSC09775.JP') no-repeat center center/cover;
            padding: 20px; position: relative;
        }
        body::before {
            content: ''; position: absolute; inset: 0;
            background: rgba(0,0,0,0.45); z-index: 0;
        }

        .login-wrapper {
            position: relative; z-index: 1;
            width: 100%; max-width: 440px;
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
            border-radius: 32px; padding: 40px 32px;
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
            margin-top: 4px; letter-spacing: 0.04em; text-transform: uppercase;
        }

        .form-group { margin-bottom: 18px; }
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
            width: 100%; padding: 14px 14px 14px 46px;
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

        .error-message {
            background: rgba(239,68,68,0.2);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5; padding: 12px 16px;
            border-radius: 12px; font-size: 14px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }

        .form-options {
            display: flex; justify-content: space-between; align-items: center;
            margin: 6px 0 22px; font-size: 14px;
            color: rgba(255,255,255,0.8);
        }
        .remember-me { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .remember-me input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: #fbbf24; cursor: pointer;
        }
        .forgot-link { color: #fbbf24; text-decoration: none; font-weight: 500; }
        .forgot-link:hover { text-decoration: underline; }

        .login-btn {
            width: 100%; padding: 14px;
            background: #fbbf24; border: none; border-radius: 14px;
            color: #1e293b; font-size: 15px; font-weight: 700;
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
            margin-top: 12px; line-height: 1.55;
        }
        .hint i { color: #fbbf24; margin-right: 4px; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-card">

            <div class="brand-header">
                <h1><i class="fas fa-user"></i> Customer Sign In</h1>
                <p class="subtitle">Mallengke · Tupi, South Cotabato</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="customer_login.php">

                <div class="form-group">
                    <label>Username or Email</label>
                    <div class="input-icon">
                        <span class="icon"><i class="fas fa-user"></i></span>
                        <input type="text" name="username"
                               placeholder="Enter your username or email"
                               required autofocus />
                    </div>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-icon">
                        <span class="icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password"
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

            <div class="divider">New customer?</div>

            <a href="customer_register.php" class="link-btn">
                <i class="fas fa-user-plus"></i> Create Customer Account
            </a>

            <p class="hint">
                <i class="fas fa-store"></i>
                Are you a vendor? <a href="login.php" style="color:#fbbf24;">Sign in here</a>
            </p>

        </div>
    </div>
</body>
</html>