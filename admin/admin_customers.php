<?php
session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/swap.php';

$adminId    = (int)$_SESSION['admin_id'];
$adminName  = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole  = $_SESSION['admin_role'] ?? 'admin';
$adminColor = $_SESSION['admin_color'] ?? '#059669';

$initials = '';
foreach (preg_split('/\s+/', trim($adminName)) as $p) {
    if ($p !== '') $initials .= strtoupper($p[0]);
    if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'A';

/* ============================================================
   Flash
   ============================================================ */
function set_flash($type, $msg) { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function get_flash() {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
}

/* ============================================================
   Log helper
   ============================================================ */
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare(
        "INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/* ============================================================
   Actions
   ============================================================ */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- Delete ---- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $info = null;
        if ($s = $conn->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS n, email FROM customers WHERE id = ? LIMIT 1")) {
            $s->bind_param("i", $id); $s->execute();
            $info = $s->get_result()->fetch_assoc(); $s->close();
        }
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            log_action($conn, $adminId, 'customer_deleted',
                $info['n'] ?? ('#' . $id),
                $info['email'] ?? '');
            set_flash('success', 'Customer deleted successfully.');
        } else {
            set_flash('error', 'Failed to delete customer.');
        }
    }
    header('Location: admin_customers.php');
    exit;
}

/* ---- Create / Update ---- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = (int)($_POST['id'] ?? 0);
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name']  ?? '');
    $email    = trim($_POST['email']      ?? '');
    $phone    = trim($_POST['phone']      ?? '');
    $address  = trim($_POST['address']    ?? '');
    $username = trim($_POST['username']   ?? '');
    $password = (string)($_POST['password'] ?? '');

    $errors = [];
    if ($first === '')  $errors[] = 'First name is required.';
    if ($last === '')   $errors[] = 'Last name is required.';
    if ($email === '')  $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email.';
    if ($username === '') $errors[] = 'Username is required.';
    elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $username)) $errors[] = 'Username must be 3-30 chars (letters, numbers, . - _).';
    if (!$id && $password === '') $errors[] = 'Password is required for new customers.';
    elseif ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    /* Uniqueness checks */
    if (empty($errors)) {
        if ($s = $conn->prepare("SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1")) {
            $s->bind_param("si", $email, $id); $s->execute();
            if ($s->get_result()->fetch_assoc()) $errors[] = 'That email is already registered.';
            $s->close();
        }
        if ($s = $conn->prepare("SELECT id FROM customers WHERE username = ? AND id <> ? LIMIT 1")) {
            $s->bind_param("si", $username, $id); $s->execute();
            if ($s->get_result()->fetch_assoc()) $errors[] = 'That username is already taken.';
            $s->close();
        }
    }

    if (!empty($errors)) {
        set_flash('error', implode(' ', $errors));
        header('Location: admin_customers.php' . ($id ? '?id=' . $id . '&edit=1' : '?action=new'));
        exit;
    }

    if ($id) {
        /* Update */
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "UPDATE customers SET first_name=?, last_name=?, email=?, phone=?, address=?, username=?, password=?
                 WHERE id=?"
            );
            $stmt->bind_param("sssssssi", $first, $last, $email, $phone, $address, $username, $hash, $id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE customers SET first_name=?, last_name=?, email=?, phone=?, address=?, username=?
                 WHERE id=?"
            );
            $stmt->bind_param("ssssssi", $first, $last, $email, $phone, $address, $username, $id);
        }
        if ($stmt) {
            if ($stmt->execute()) {
                log_action($conn, $adminId, 'customer_updated', $first . ' ' . $last, 'Email: ' . $email);
                set_flash('success', 'Customer updated successfully.');
            } else {
                set_flash('error', 'Update failed: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            set_flash('error', 'Database error: ' . $conn->error);
        }
    } else {
        /* Insert */
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "INSERT INTO customers (first_name, last_name, email, phone, address, username, password)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("sssssss", $first, $last, $email, $phone, $address, $username, $hash);
            if ($stmt->execute()) {
                $newId = (int)$stmt->insert_id;
                log_action($conn, $adminId, 'customer_created', $first . ' ' . $last, 'Email: ' . $email);
                set_flash('success', 'Customer created successfully.');
                header('Location: admin_customers.php?id=' . $newId);
                exit;
            } else {
                set_flash('error', 'Creation failed: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            set_flash('error', 'Database error: ' . $conn->error);
        }
    }
    header('Location: admin_customers.php' . ($id ? '?id=' . $id : '?action=new'));
    exit;
}

$flash = get_flash();

/* ============================================================
   Fetch data
   ============================================================ */
$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew  = (($_GET['action'] ?? '') === 'new');

$customer = null;

if ($viewId) {
    if ($s = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1")) {
        $s->bind_param("i", $viewId); $s->execute();
        $customer = $s->get_result()->fetch_assoc();
        $s->close();
    }
}

/* List filters */
$q      = trim($_GET['q'] ?? '');
$sortF  = trim($_GET['sort'] ?? 'recent');   // recent | name | active

$where = []; $params = []; $types = '';

if ($q !== '') {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR username LIKE ? OR phone LIKE ?)";
    $like = '%' . $q . '%';
    for ($i = 0; $i < 5; $i++) { $params[] = $like; $types .= 's'; }
}

$orderBy = "created_at DESC";
if ($sortF === 'name')   $orderBy = "first_name ASC, last_name ASC";
if ($sortF === 'active') $orderBy = "last_login DESC, created_at DESC";

$sql = "SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.username,
               c.created_at, c.last_login
          FROM customers c";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY " . $orderBy;

$customers = [];
if ($stmt = $conn->prepare($sql)) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $customers[] = $r;
    $stmt->close();
}

/* Stats */
$stat = ['total' => 0, 'today' => 0, 'week' => 0, 'active' => 0];
if ($res = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(DATE(created_at) = CURDATE()) AS today,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS week,
        SUM(last_login IS NOT NULL AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS active
      FROM customers")) {
    $stat = array_merge($stat, $res->fetch_assoc());
}

function ini_from($name) {
    $p = preg_split('/\s+/', trim((string)$name));
    return strtoupper(substr($p[0] ?? 'U', 0, 1) . substr($p[1] ?? '', 0, 1));
}
function time_ago($ts) {
    if (!$ts) return '—';
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60)  . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
}
function h($v) { return htmlspecialchars((string)$v); }
function selected($a, $b) { return ($a === $b) ? 'selected' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Customer Management • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--blue-dark:#0284c7;}


/* Flash */
.flash{
    display:flex;align-items:center;gap:12px;
    padding:14px 20px;border-radius:14px;
    font-size:13.5px;font-weight:600;
    margin-bottom:18px;
    animation:fadeUp .35s var(--ease-spring);
}
.flash.success{background:var(--green-50);border:1.5px solid var(--green-200);color:var(--green-800);}
.flash.success i{color:var(--green-600);}
.flash.error{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
.flash.error i{color:#dc2626;}
.flash i{font-size:16px;}
@keyframes fadeUp{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.mini-stat:hover{transform:translateY(-3px);box-shadow:var(--sh-md);}
.mini-stat .icon{
    width:42px;height:42px;border-radius:12px;
    display:grid;place-items:center;color:#fff;font-size:16px;flex-shrink:0;
}
.mini-stat .icon.g1{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 5px 14px rgba(14,165,233,.35);}
.mini-stat .icon.g2{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 5px 14px rgba(16,185,129,.35);}
.mini-stat .icon.g3{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 5px 14px rgba(56,189,248,.35);}
.mini-stat .icon.g4{background:linear-gradient(135deg,#8b5cf6,#7c3aed);box-shadow:0 5px 14px rgba(124,58,237,.35);}
.mini-stat .num{font-size:22px;font-weight:900;color:var(--ink);letter-spacing:-.4px;line-height:1.1;}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px;}

/* Toolbar */
.toolbar{
    background:#fff;border:1.5px solid var(--line);border-radius:16px;
    padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
    box-shadow:var(--sh-sm);margin-bottom:18px;
}
.toolbar .search-wrap{position:relative;flex:1;min-width:220px;}
.toolbar .search-wrap i{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    color:var(--muted);font-size:13px;pointer-events:none;
}
.toolbar input[type="text"]{
    width:100%;padding:11px 16px 11px 40px;
    border:1.5px solid var(--line);border-radius:12px;
    background:var(--bg);color:var(--ink);
    font-family:inherit;font-size:13.5px;outline:none;
    transition:all .2s;
}
.toolbar input[type="text"]:focus{background:#fff;border-color:#7dd3fc;box-shadow:0 0 0 4px rgba(14,165,233,.13);}

.toolbar select{
    padding:11px 38px 11px 14px;border:1.5px solid var(--line);border-radius:12px;
    background:#fff;font-family:inherit;font-size:13.5px;font-weight:600;color:var(--ink-soft);
    outline:none;cursor:pointer;appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230ea5e9' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat:no-repeat;background-position:right 12px center;background-size:14px;
    transition:all .2s;
}
.toolbar select:focus{border-color:#7dd3fc;box-shadow:0 0 0 4px rgba(14,165,233,.13);}

/* Table */
.table-card{
    background:#fff;border:1.5px solid var(--line);border-radius:18px;
    overflow:hidden;box-shadow:var(--sh-sm);
}
.table-wrap{overflow-x:auto;}
table{
    width:100%;border-collapse:collapse;
    font-size:13.5px;
    min-width:900px;
}
thead th{
    padding:14px 16px;
    text-align:left;
    font-size:11px;font-weight:800;
    color:var(--muted);
    letter-spacing:.08em;text-transform:uppercase;
    background:#f0f9ff;
    border-bottom:1.5px solid var(--line);
    white-space:nowrap;
}
tbody td{
    padding:14px 16px;
    border-bottom:1px solid var(--line-soft);
    vertical-align:middle;
}
tbody tr:last-child td{border-bottom:none;}
tbody tr{transition:background .15s;}
tbody tr:hover{background:#f0f9ff;}

.customer-cell{display:flex;align-items:center;gap:12px;}
.customer-avatar{
    width:40px;height:40px;border-radius:50%;flex-shrink:0;
    display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;
    background:linear-gradient(135deg,#38bdf8,#0284c7);
    box-shadow:0 4px 10px rgba(2,132,199,.28);
    position:relative;
}
.customer-avatar::after{
    content:'';position:absolute;bottom:0;right:0;
    width:11px;height:11px;border-radius:50%;
    background:#10b981;border:2px solid #fff;
}
.customer-avatar.offline::after{background:#cbd5e1;}
.customer-name{font-size:13.5px;font-weight:800;color:var(--ink);}
.customer-email{font-size:11.5px;color:var(--muted);margin-top:2px;}

.username-chip{
    display:inline-flex;align-items:center;gap:6px;
    padding:4px 10px;border-radius:999px;
    font-size:11.5px;font-weight:800;
    background:#f0f9ff;color:var(--blue-dark);
    border:1px solid #bae6fd;
    white-space:nowrap;
}
.username-chip i{font-size:9px;}

.conv-pill{
    display:inline-flex;align-items:center;gap:6px;
    padding:4px 10px;border-radius:999px;
    font-size:11.5px;font-weight:800;
    background:var(--bg);color:var(--ink-soft);
    border:1px solid var(--line);
    white-space:nowrap;
}
.conv-pill i{font-size:10px;color:var(--green-600);}
.conv-pill.zero{opacity:.5;}

.row-actions{display:flex;gap:6px;justify-content:flex-end;}
.row-btn{
    width:34px;height:34px;border-radius:10px;
    border:1.5px solid var(--line);background:#fff;
    display:grid;place-items:center;cursor:pointer;
    font-size:13px;color:var(--ink-soft);
    transition:all .2s var(--ease-spring);
    text-decoration:none;
}
.row-btn:hover{border-color:#7dd3fc;color:var(--blue-dark);background:#f0f9ff;transform:translateY(-2px);}
.row-btn.view:hover{background:#e0f2fe;}
.row-btn.edit:hover{background:#fef3c7;border-color:#fcd34d;color:#92400e;}
.row-btn.delete:hover{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}

.empty-state{
    padding:64px 24px;text-align:center;color:var(--muted);
}
.empty-state i{font-size:52px;color:#bae6fd;display:block;margin-bottom:14px;}
.empty-state h3{font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.empty-state p{font-size:13.5px;margin-bottom:18px;}

/* Modals */
.modal-backdrop{
    position:fixed;inset:0;background:rgba(2,44,34,.55);
    backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
    z-index:600;display:none;align-items:center;justify-content:center;
    padding:24px;overflow-y:auto;
}
.modal-backdrop.open{display:flex;}
.modal{
    background:#fff;border-radius:22px;
    width:100%;max-width:560px;
    box-shadow:var(--sh-xl);
    overflow:hidden;
    animation:modalIn .35s var(--ease-spring);
    max-height:92vh;display:flex;flex-direction:column;
}
.modal.wide{max-width:820px;}
@keyframes modalIn{
    from{opacity:0;transform:translateY(24px) scale(.94);}
    to{opacity:1;transform:translateY(0) scale(1);}
}
.modal-head{
    position:relative;
    padding:26px 26px 22px;
    background:
        radial-gradient(circle at 85% 20%, rgba(56,189,248,.24) 0%, transparent 52%),
        linear-gradient(135deg, #022c22 0%, #04443a 55%, #064e3b 100%);
    color:#fff;
    display:flex;align-items:center;gap:16px;
    flex-shrink:0;
}
.modal-head-icon{
    width:60px;height:60px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,#38bdf8,#0284c7);
    display:grid;place-items:center;
    color:#fff;font-size:22px;
    box-shadow:0 10px 22px rgba(2,132,199,.4);
}
.modal-head-info{flex:1;min-width:0;}
.modal-head-info h2{
    font-size:19px;font-weight:900;color:#fff;
    letter-spacing:-.4px;margin-bottom:3px;
    overflow:hidden;text-overflow:ellipsis;
}
.modal-head-info p{font-size:12.5px;color:#b6c5d3;}
.modal-close{
    position:absolute;top:16px;right:16px;
    width:36px;height:36px;border-radius:50%;
    background:rgba(255,255,255,.1);
    border:1px solid rgba(255,255,255,.16);
    color:#fff;font-size:13px;cursor:pointer;
    display:grid;place-items:center;
    transition:all .3s var(--ease-spring);
}
.modal-close:hover{background:rgba(255,255,255,.2);transform:rotate(90deg);}
.modal-body{
    padding:22px 26px;
    overflow-y:auto;flex:1;
}
.modal-footer{
    padding:16px 26px 22px;
    border-top:1px solid var(--line-soft);
    background:#fbfcfd;
    display:flex;gap:9px;flex-wrap:wrap;
    flex-shrink:0;
}
.view-stat:hover{transform:translateY(-2px);box-shadow:var(--sh-sm);}
.view-stat .num{font-size:22px;font-weight:900;color:var(--blue-dark);letter-spacing:-.4px;line-height:1.1;}
.view-stat .lbl{font-size:10.5px;font-weight:800;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;margin-top:3px;}

/* Section heads */
.form-section{margin-bottom:22px;}
.form-section:last-child{margin-bottom:0;}
.form-section-head{
    display:flex;align-items:center;gap:10px;
    margin-bottom:14px;
    padding-bottom:10px;
    border-bottom:1px dashed var(--line);
}
.form-section-head i{
    width:32px;height:32px;border-radius:10px;
    background:#e0f2fe;color:var(--blue-dark);
    display:grid;place-items:center;font-size:13px;
}
.form-section-head h3{font-size:14px;font-weight:800;color:var(--ink);}
.form-section-head p{font-size:11.5px;color:var(--muted);margin-top:1px;}

.form-grid{display:grid;gap:12px;}
.form-grid.c2{grid-template-columns:1fr 1fr;}
.form-grid.c3{grid-template-columns:1fr 1fr 1fr;}@media(max-width:640px){.form-grid.c2,.form-grid.c3{grid-template-columns:1fr;}}

.field{display:flex;flex-direction:column;gap:6px;}
.field.full{grid-column:1/-1;}
.field label{
    font-size:12px;font-weight:800;color:var(--ink-soft);
    letter-spacing:.02em;
    display:flex;align-items:center;gap:5px;
}
.field label .req{color:#dc2626;margin-left:2px;}
.field label .optional{font-weight:600;color:var(--muted);font-size:11px;margin-left:auto;}

.field input,
.field textarea{
    width:100%;padding:11px 14px;
    border:1.5px solid var(--line);border-radius:11px;
    background:#fff;color:var(--ink);
    font-family:inherit;font-size:13.5px;
    outline:none;
    transition:all .2s;
}
.field textarea{resize:vertical;min-height:72px;line-height:1.5;}
.field input:focus,
.field textarea:focus{
    border-color:#7dd3fc;
    box-shadow:0 0 0 4px rgba(14,165,233,.13);
}
.field .hint{font-size:11px;color:var(--muted);margin-top:2px;}

/* Password wrap */
.pw-wrap{position:relative;}
.pw-wrap input{padding-right:44px;}
.pw-toggle{
    position:absolute;right:8px;top:50%;transform:translateY(-50%);
    width:32px;height:32px;border-radius:9px;border:none;
    background:transparent;cursor:pointer;
    color:var(--muted);display:grid;place-items:center;
    transition:all .2s;
}
.pw-toggle:hover{background:#e0f2fe;color:var(--blue-dark);}

/* Info rows */
.info-row{
    display:flex;gap:14px;padding:12px 0;
    border-bottom:1px dashed var(--line);
    align-items:flex-start;
}
.info-row:last-child{border-bottom:none;}
.info-icon{
    width:38px;height:38px;border-radius:11px;flex-shrink:0;
    background:#f0f9ff;border:1px solid #bae6fd;
    color:var(--blue-dark);
    display:grid;place-items:center;font-size:14px;
}
.info-content{flex:1;min-width:0;}
.info-label{
    font-size:10.5px;font-weight:800;color:var(--muted);
    letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;
}
.info-value{
    font-size:14px;font-weight:600;color:var(--ink);
    line-height:1.5;word-break:break-word;
}

/* Conversations list inside view modal */
.conv-list{display:flex;flex-direction:column;gap:8px;margin-top:8px;}
.conv-item{
    display:flex;align-items:center;gap:12px;
    padding:12px 14px;border-radius:12px;
    background:#fbfcfd;border:1.5px solid var(--line);
    text-decoration:none;color:inherit;
    transition:all .25s var(--ease-spring);
}
.conv-item:hover{background:#f0f9ff;border-color:#bae6fd;transform:translateX(3px);}
.conv-avatar{
    width:38px;height:38px;border-radius:11px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px;
    background:linear-gradient(135deg,#34d399,#059669);
}
.conv-meta{flex:1;min-width:0;}
.conv-name{font-size:13px;font-weight:800;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.conv-preview{font-size:11.5px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.conv-time{font-size:11px;color:var(--muted);font-weight:700;flex-shrink:0;}

/* Buttons */
.btn-form{
    display:inline-flex;align-items:center;gap:8px;
    padding:12px 22px;border-radius:12px;
    font-family:inherit;font-size:13.5px;font-weight:800;
    cursor:pointer;border:none;
    transition:all .25s var(--ease-spring);
    text-decoration:none;
}
.btn-form.primary{
    background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;
    box-shadow:var(--glow-blue);
}
.btn-form.primary:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(14,165,233,.48);}
.btn-form.ghost{background:var(--bg);color:var(--ink-soft);border:1.5px solid var(--line);}
.btn-form.ghost:hover{background:var(--line-soft);}
.btn-form.danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 6px 14px rgba(220,38,38,.35);}
.btn-form.danger:hover{transform:translateY(-2px);box-shadow:0 12px 24px rgba(220,38,38,.48);}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <!-- HEADER -->
    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-users"></i></span>
                Customer Management
            </h1>
            <p>View, search, and manage all registered market customers.</p>
        </div>
        <div class="header-actions">
            <a href="admin_dashboard.php" class="btn-header ghost">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="admin_customers.php?action=new" class="btn-header primary">
                <i class="fas fa-plus"></i> Add New Customer
            </a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>">
            <i class="fas <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= h($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stat-row">
        <div class="mini-stat">
            <div class="icon g1"><i class="fas fa-users"></i></div>
            <div>
                <div class="num"><?= (int)$stat['total'] ?></div>
                <div class="lbl">Total Customers</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon g2"><i class="fas fa-user-plus"></i></div>
            <div>
                <div class="num"><?= (int)$stat['today'] ?></div>
                <div class="lbl">Joined Today</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon g3"><i class="fas fa-calendar-week"></i></div>
            <div>
                <div class="num"><?= (int)$stat['week'] ?></div>
                <div class="lbl">This Week</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon g4"><i class="fas fa-bolt"></i></div>
            <div>
                <div class="num"><?= (int)$stat['active'] ?></div>
                <div class="lbl">Active (30d)</div>
            </div>
        </div>
    </div>

    <!-- TOOLBAR -->
    <form class="toolbar" method="get" action="admin_customers.php">
        <div class="search-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by name, email, username, or phone…" />
        </div>

        <select name="sort">
            <option value="recent" <?= selected($sortF, 'recent') ?>>Newest first</option>
            <option value="active" <?= selected($sortF, 'active') ?>>Recently active</option>
            <option value="name"   <?= selected($sortF, 'name') ?>>Name A→Z</option>
        </select>

        <button type="submit" class="btn-toolbar primary"><i class="fas fa-filter"></i> Apply</button>
        <?php if ($q !== '' || $sortF !== 'recent'): ?>
            <a href="admin_customers.php" class="btn-toolbar ghost"><i class="fas fa-rotate-left"></i> Reset</a>
        <?php endif; ?>
    </form>

    <!-- TABLE -->
    <div class="table-card">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No customers found</h3>
                <p><?= ($q !== '') ? 'Try a different search keyword.' : 'Get started by adding your first customer.' ?></p>
                <a href="admin_customers.php?action=new" class="btn-toolbar primary">
                    <i class="fas fa-plus"></i> Add New Customer
                </a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Username</th>
                            <th>Phone</th>
                            <th>Joined</th>
                            <th>Last Active</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customers as $c):
                        $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                        $online = !empty($c['last_login']) && (time() - strtotime($c['last_login'])) < 7 * 86400;
                    ?>
                        <tr>
                            <td>
                                <div class="customer-cell">
                                    <div class="customer-avatar <?= $online ? '' : 'offline' ?>"><?= h(ini_from($fullName)) ?></div>
                                    <div>
                                        <div class="customer-name"><?= h($fullName) ?></div>
                                        <div class="customer-email"><?= h($c['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="username-chip">
                                    <i class="fas fa-at"></i>
                                    <?= h($c['username']) ?>
                                </span>
                            </td>
                            <td><?= h($c['phone'] ?: '—') ?></td>
                            <td><?= h(date('M j, Y', strtotime($c['created_at']))) ?></td>
                            <td><?= h(time_ago($c['last_login'])) ?></td>
                            <td>
                                <div class="row-actions">
                                    <a href="admin_customers.php?id=<?= (int)$c['id'] ?>" class="row-btn view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="admin_customers.php?id=<?= (int)$c['id'] ?>&edit=1" class="row-btn edit" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <button type="button" class="row-btn delete" title="Delete" onclick="confirmDelete(<?= (int)$c['id'] ?>, '<?= h(addslashes($fullName)) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- ============================================================
     VIEW MODAL
     ============================================================ -->
<?php if ($customer && empty($_GET['edit'])): ?>
<div class="modal-backdrop open" id="viewModal">
    <div class="modal wide" onclick="event.stopPropagation()">
        <div class="modal-head">
            <div class="modal-head-icon"><?= h(ini_from(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')))) ?></div>
            <div class="modal-head-info">
                <h2><?= h(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))) ?></h2>
                <p>@<?= h($customer['username']) ?> · <?= h($customer['email']) ?></p>
            </div>
            <button type="button" class="modal-close" onclick="window.location='admin_customers.php'"><i class="fas fa-xmark"></i></button>
        </div>

        <div class="modal-body">

            <!-- Personal info -->
            <div class="form-section">
                <div class="form-section-head">
                    <i class="fas fa-user"></i>
                    <div>
                        <h3>Account Information</h3>
                        <p>Customer account details</p>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-at"></i></div>
                    <div class="info-content">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= h($customer['username']) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-envelope"></i></div>
                    <div class="info-content">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= h($customer['email']) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-phone"></i></div>
                    <div class="info-content">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?= h($customer['phone'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="info-content">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?= h($customer['address'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-calendar"></i></div>
                    <div class="info-content">
                        <div class="info-label">Registered / Last Active</div>
                        <div class="info-value">
                            <?= h(date('M j, Y', strtotime($customer['created_at']))) ?>
                            ·
                            <?= h(time_ago($customer['last_login'])) ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="modal-footer">
            <a href="admin_customers.php?id=<?= (int)$customer['id'] ?>&edit=1" class="btn-form primary">
                <i class="fas fa-pen"></i> Edit Customer
            </a>
            <button type="button" class="btn-form danger" onclick="confirmDelete(<?= (int)$customer['id'] ?>, '<?= h(addslashes(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')))) ?>')">
                <i class="fas fa-trash"></i> Delete
            </button>
            <a href="admin_customers.php" class="btn-form ghost" style="margin-left:auto;">
                <i class="fas fa-xmark"></i> Close
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     EDIT / CREATE MODAL
     ============================================================ -->
<?php if ($isNew || (!empty($_GET['edit']) && $customer)): ?>
<div class="modal-backdrop open" id="editModal">
    <div class="modal wide" onclick="event.stopPropagation()">
        <form method="post" action="admin_customers.php" style="display:contents;">
            <input type="hidden" name="action" value="save" />
            <input type="hidden" name="id" value="<?= (int)($customer['id'] ?? 0) ?>" />

            <div class="modal-head">
                <div class="modal-head-icon">
                    <i class="fas <?= $isNew ? 'fa-user-plus' : 'fa-pen' ?>"></i>
                </div>
                <div class="modal-head-info">
                    <h2><?= $isNew ? 'Add New Customer' : 'Edit Customer' ?></h2>
                    <p><?= $isNew ? 'Create a new customer account' : 'Update this customer\'s details' ?></p>
                </div>
                <button type="button" class="modal-close" onclick="window.location='admin_customers.php'"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">

                <!-- Personal -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-user"></i>
                        <div><h3>Personal Information</h3><p>Basic customer details</p></div>
                    </div>
                    <div class="form-grid c2">
                        <div class="field">
                            <label>First Name <span class="req">*</span></label>
                            <input type="text" name="first_name" value="<?= h($customer['first_name'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Last Name <span class="req">*</span></label>
                            <input type="text" name="last_name" value="<?= h($customer['last_name'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" name="email" value="<?= h($customer['email'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Phone <span class="optional">optional</span></label>
                            <input type="text" name="phone" value="<?= h($customer['phone'] ?? '') ?>" />
                        </div>
                        <div class="field full">
                            <label>Address <span class="optional">optional</span></label>
                            <input type="text" name="address" value="<?= h($customer['address'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- Login -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-key"></i>
                        <div><h3>Login Credentials</h3><p>Account sign-in details</p></div>
                    </div>
                    <div class="form-grid c2">
                        <div class="field full">
                            <label>Username <span class="req">*</span></label>
                            <input type="text" name="username" value="<?= h($customer['username'] ?? '') ?>" required
                                   pattern="[A-Za-z0-9_.\-]{3,30}" />
                            <span class="hint">3–30 characters. Letters, numbers, dot, dash, underscore only.</span>
                        </div>
                        <div class="field full">
                            <label>
                                Password
                                <?php if (!$isNew): ?>
                                    <span class="optional">leave blank to keep current</span>
                                <?php else: ?>
                                    <span class="req">*</span>
                                <?php endif; ?>
                            </label>
                            <div class="pw-wrap">
                                <input type="password" name="password" id="pwInput"
                                       placeholder="<?= $isNew ? 'At least 6 characters' : 'Enter new password to change' ?>"
                                       <?= $isNew ? 'required' : '' ?>
                                       minlength="6" />
                                <button type="button" class="pw-toggle" onclick="togglePw()" title="Show/hide">
                                    <i class="fas fa-eye" id="pwIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <a href="admin_customers.php" class="btn-form ghost">
                    <i class="fas fa-xmark"></i> Cancel
                </a>
                <button type="submit" class="btn-form primary" style="margin-left:auto;">
                    <i class="fas fa-floppy-disk"></i>
                    <?= $isNew ? 'Create Customer' : 'Save Changes' ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     DELETE MODAL
     ============================================================ -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal" onclick="event.stopPropagation()" style="max-width:420px;">
        <form method="post" action="admin_customers.php">
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="id" id="deleteId" value="" />

            <div class="modal-head">
                <div class="modal-head-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:18px;">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="modal-head-info">
                    <h2>Delete Customer?</h2>
                    <p>This action cannot be undone</p>
                </div>
                <button type="button" class="modal-close" onclick="closeDelete()"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">
                <p style="font-size:14px;color:var(--ink-soft);line-height:1.6;">
                    You're about to permanently delete
                    <strong id="deleteName" style="color:var(--ink);">this customer</strong>.
                    Their account and personal details will also be removed.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-form ghost" onclick="closeDelete()">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn-form danger" style="margin-left:auto;">
                    <i class="fas fa-trash"></i> Yes, Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarBackdrop').classList.toggle('show');
}

function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = '"' + name + '"';
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() {
    document.getElementById('deleteModal').classList.remove('open');
}

function togglePw() {
    const input = document.getElementById('pwInput');
    const icon  = document.getElementById('pwIcon');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

/* Backdrop click closes standalone modals */
document.querySelectorAll('.modal-backdrop').forEach(b => {
    b.addEventListener('click', e => {
        if (e.target === b && b.id !== 'viewModal' && b.id !== 'editModal') {
            b.classList.remove('open');
        }
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const del = document.getElementById('deleteModal');
        if (del.classList.contains('open')) del.classList.remove('open');
    }
});
</script>

</body>
</html>