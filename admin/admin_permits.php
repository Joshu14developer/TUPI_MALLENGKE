<?php
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: admin_login.php'); exit; }

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/swap.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator';
$adminRole = $_SESSION['admin_role'] ?? 'admin';

$initials = '';
foreach (preg_split('/\s+/', trim($adminName)) as $p) {
    if ($p !== '') $initials .= strtoupper($p[0]);
    if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'A';

/* ---------- Flash ---------- */
function set_flash($t, $m) { $_SESSION['flash'] = ['type'=>$t,'msg'=>$m]; }
function get_flash() { if (empty($_SESSION['flash'])) return null; $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }

/* ---------- Log helper ---------- */
function log_action($conn, $adminId, $action, $target = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_activity_log (admin_id, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("issss", $adminId, $action, $target, $details, $ip); $stmt->execute(); $stmt->close(); }
}

/* ============================================================
   ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? '';

/* ---- Renew permit ---- */
if ($action === 'renew_permit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = (int)($_POST['id'] ?? 0);
    $months   = (int)($_POST['months'] ?? 12);
    $fee      = (float)($_POST['fee'] ?? 0);
    $orNo     = trim($_POST['or_number'] ?? '');
    $remarks  = trim($_POST['remarks'] ?? '');
    $method   = PAYMENT_METHOD;  /* always settled over the counter */

    if ($months < 1) $months = 1;
    if ($months > 60) $months = 60;

    if ($id) {
        /* Extend from today or from current expiry, whichever is later */
        $stmt = $conn->prepare("SELECT permit_expiry, stall_number, CONCAT_WS(' ', first_name, last_name) AS n FROM vendors WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id); $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($v) {
            $base = (!empty($v['permit_expiry']) && strtotime($v['permit_expiry']) > time())
                  ? $v['permit_expiry'] : date('Y-m-d');
            $newExpiry = date('Y-m-d', strtotime($base . ' +' . $months . ' months'));

            $stmt = $conn->prepare("UPDATE vendors SET permit_status = 'Valid', permit_expiry = ?,
                                    payment_status = 'Paid', last_payment_date = CURDATE(),
                                    payment_due = DATE_ADD(?, INTERVAL 1 MONTH), updated_at = NOW()
                                    WHERE id = ?");
            $stmt->bind_param("ssi", $newExpiry, $newExpiry, $id);

            if ($stmt->execute()) {
                $stmt->close();
                log_action($conn, $adminId, 'permit_renewed', $v['n'] . ' (Stall ' . $v['stall_number'] . ')',
                    'New expiry: ' . $newExpiry . ' · ' . $months . 'mo · ₱' . number_format($fee,2) . ' · ' . $method . ($orNo ? ' · OR# ' . $orNo : ''));

                /* Notify the vendor */
                $firstName = trim(explode(' ', (string)$v['n'])[0]);
                $greet = $firstName !== '' ? 'Hi ' . $firstName . ', ' : '';
                $body = $greet . 'your business permit has been renewed and is now valid until '
                      . date('F j, Y', strtotime($newExpiry)) . '.';
                if ($fee > 0) {
                    $body .= "\n\nAmount paid: ₱" . number_format($fee, 2) . ' (' . $months . ' month'
                          . ($months == 1 ? '' : 's') . ') · ' . $method
                          . ($orNo ? ' · OR# ' . $orNo : '');
                }
                $body .= "\n\nNext payment due: " . date('F j, Y', strtotime($newExpiry)) . '.'
                      . "\nPlease display your permit at your stall at all times.";

                notify_vendor($conn, $id, 'permit',
                    'Your business permit has been renewed',
                    $body, 'dashboard.php', $adminId, $adminName);

                set_flash('success', 'Permit renewed until ' . date('M j, Y', strtotime($newExpiry)) . '. Vendor notified.');
            } else {
                set_flash('error', 'Failed to renew permit.');
            }
        }
    }
    header('Location: admin_permits.php?id=' . $id); exit;
}

/* ---- Record walk-in payment ---- */
if ($action === 'record_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = (int)($_POST['id'] ?? 0);
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = PAYMENT_METHOD;  /* always settled over the counter */
    $orNo    = trim($_POST['or_number'] ?? '');
    $period  = trim($_POST['period'] ?? '');  /* e.g. "October 2025" */
    $notes   = trim($_POST['notes'] ?? '');

    if ($id && $amount > 0) {
        /* Look up vendor */
        $stmt = $conn->prepare("SELECT stall_number, CONCAT_WS(' ', first_name, last_name) AS n FROM vendors WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id); $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($v) {
            /* Mark as paid, set next due 1 month out */
            $stmt = $conn->prepare("UPDATE vendors SET payment_status = 'Paid',
                                    last_payment_date = CURDATE(),
                                    payment_due = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
                                    updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute(); $stmt->close();

            log_action($conn, $adminId, 'payment_recorded', $v['n'] . ' (Stall ' . $v['stall_number'] . ')',
                '₱' . number_format($amount,2) . ' via ' . $method
                . ($orNo ? ' · OR# ' . $orNo : '')
                . ($period ? ' · Period: ' . $period : '')
                . ($notes ? ' · ' . $notes : ''));

            /* Notify the vendor */
            $firstName = trim(explode(' ', (string)$v['n'])[0]);
            $greet = $firstName !== '' ? 'Hi ' . $firstName . ', ' : '';
            $body = $greet . 'we have received your stall rental payment of ₱' . number_format($amount, 2) . '.\n\n'
                  . '• Amount: ₱' . number_format($amount, 2)
                  . "\n• Settled at: Market Office (" . $method . ')'
                  . ($orNo   ? "\n• OR Number: " . $orNo : '')
                  . ($period ? "\n• Period: " . $period : '')
                  . "\n• Next due: " . date('F j, Y', strtotime('+1 month'))
                  . ($notes  ? "\n\nNote: " . $notes : '')
                  . "\n\nThank you! Your account is now marked as Paid.";

            notify_vendor($conn, $id, 'payment',
                'Payment received — ₱' . number_format($amount, 2),
                $body, 'dashboard.php', $adminId, $adminName);

            set_flash('success', 'Walk-in payment recorded — ₱' . number_format($amount,2) . ' for ' . $v['n'] . '. Vendor notified.');
        }
    }
    header('Location: admin_permits.php?id=' . $id); exit;
}

/* ---- Suspend / reactivate permit ---- */
if (($action === 'suspend' || $action === 'reactivate') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int)($_POST['id'] ?? 0);
    $new = ($action === 'suspend') ? 'Suspended' : 'Valid';

    if ($id) {
        /* Grab name/stall for the notice */
        $vname = 'Vendor';
        if ($s = $conn->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS n, stall_number FROM vendors WHERE id = ? LIMIT 1")) {
            $s->bind_param("i", $id); $s->execute();
            $info = $s->get_result()->fetch_assoc(); $s->close();
            if ($info) $vname = trim($info['n'] ?: 'Vendor');
        }

        $stmt = $conn->prepare("UPDATE vendors SET permit_status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $new, $id);
        $stmt->execute(); $stmt->close();
        log_action($conn, $adminId, 'permit_' . $action, '#' . $id, 'New status: ' . $new);

        /* Notify the vendor */
        $firstName = trim(explode(' ', (string)$vname)[0]);
        $greet = $firstName !== '' ? 'Hi ' . $firstName . ', ' : '';
        if ($action === 'suspend') {
            notify_vendor($conn, $id, 'permit',
                'Your business permit has been suspended',
                $greet . 'your business permit is now suspended and you cannot operate at your stall until it is reinstated. '
                . 'Please visit the market office to settle the outstanding issue.',
                'dashboard.php', $adminId, $adminName);
        } else {
            notify_vendor($conn, $id, 'permit',
                'Your business permit is active again',
                $greet . 'your business permit has been reinstated and is valid again. You may resume operations at your stall. Thank you!',
                'dashboard.php', $adminId, $adminName);
        }

        set_flash('success', 'Permit ' . ($action === 'suspend' ? 'suspended' : 'reactivated') . '. Vendor notified.');
    }
    header('Location: admin_permits.php?id=' . $id); exit;
}

/* ---- Auto-flag expired permits ---- */
$conn->query("UPDATE vendors SET permit_status = 'Expired'
               WHERE permit_expiry IS NOT NULL
                 AND permit_expiry < CURDATE()
                 AND permit_status = 'Valid'");

$flash = get_flash();

/* ============================================================
   DATA
   ============================================================ */
$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* Filters */
$q        = trim($_GET['q'] ?? '');
$permitF  = trim($_GET['permit'] ?? '');
$payF     = trim($_GET['pay'] ?? '');
$sortF    = trim($_GET['sort'] ?? 'expiry');   /* expiry | name | stall */

$where = []; $params = []; $types = '';
if ($q !== '') {
    $where[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR business_name LIKE ? OR stall_number LIKE ? OR contact_number LIKE ?)";
    $like = '%' . $q . '%';
    for ($i=0;$i<6;$i++){ $params[]=$like; $types.='s'; }
}
if ($permitF !== '' && in_array($permitF, ['Valid','Pending','Expired','Suspended'], true)) {
    $where[] = "permit_status = ?"; $params[] = $permitF; $types .= 's';
}
if ($payF !== '' && in_array($payF, ['Paid','Partial','Unpaid'], true)) {
    $where[] = "payment_status = ?"; $params[] = $payF; $types .= 's';
}

$orderBy = "permit_expiry IS NULL, permit_expiry ASC";
if ($sortF === 'name')  $orderBy = "first_name ASC, last_name ASC";
if ($sortF === 'stall') $orderBy = "stall_number ASC";

$sql = "SELECT id, first_name, middle_name, last_name, business_name,
               stall_number, market_section, contact_number,
               permit_status, permit_expiry,
               payment_status, payment_due, last_payment_date,
               status
          FROM vendors";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY " . $orderBy;

$vendors = [];
if ($stmt = $conn->prepare($sql)) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $vendors[] = $r;
    $stmt->close();
}

/* Summary stats */
$stat = ['total'=>0,'valid'=>0,'expiring'=>0,'expired'=>0,'suspended'=>0,'pending'=>0,'unpaid'=>0,'due_soon'=>0];
if ($res = $conn->query("SELECT
        COUNT(*) AS total,
        SUM(permit_status='Valid') AS valid,
        SUM(permit_status='Pending') AS pending,
        SUM(permit_status='Expired') AS expired,
        SUM(permit_status='Suspended') AS suspended,
        SUM(permit_status='Valid' AND permit_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring,
        SUM(payment_status='Unpaid') AS unpaid,
        SUM(payment_due IS NOT NULL AND payment_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS due_soon
      FROM vendors")) {
    $stat = array_merge($stat, $res->fetch_assoc());
}

/* Single vendor */
$vendor = null;
if ($viewId) {
    $stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $viewId); $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

function h($v) { return htmlspecialchars((string)$v); }
function ini_from($name) { $p = preg_split('/\s+/', trim((string)$name)); return strtoupper(substr($p[0]??'U',0,1) . substr($p[1]??'',0,1)); }
function days_left($date) { if (!$date) return null; return (int)floor((strtotime($date) - time()) / 86400); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Permits & Payments • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--blue:#0ea5e9;--amber:#f59e0b;--red:#ef4444;--purple:#8b5cf6;}


.flash{display:flex;align-items:center;gap:12px;padding:14px 20px;border-radius:14px;font-size:13.5px;font-weight:600;margin-bottom:18px;animation:fadeUp .35s var(--ease-spring)}
.flash.success{background:var(--green-50);border:1.5px solid var(--green-200);color:var(--green-800)}
.flash.success i{color:var(--green-600)}
.flash.error{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.flash.error i{color:#dc2626}
.flash i{font-size:16px}
@keyframes fadeUp{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.mini-stat:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.mini-stat .icon{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;color:#fff;font-size:17px;flex-shrink:0}
.mini-stat .icon.i1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 6px 16px rgba(16,185,129,.35)}
.mini-stat .icon.i2{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 16px rgba(217,119,6,.32)}
.mini-stat .icon.i3{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 6px 16px rgba(220,38,38,.32)}
.mini-stat .icon.i4{background:linear-gradient(135deg,#0ea5e9,#0284c7);box-shadow:0 6px 16px rgba(14,165,233,.32)}
.mini-stat .num{font-size:23px;font-weight:900;color:var(--ink);letter-spacing:-.5px;line-height:1.1}
.mini-stat .lbl{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-top:2px}

.toolbar{background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:var(--sh-sm);margin-bottom:18px}
.toolbar .search-wrap{position:relative;flex:1;min-width:220px}
.toolbar .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
.toolbar input[type="text"]{width:100%;padding:11px 16px 11px 40px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg);color:var(--ink);font-family:inherit;font-size:13.5px;outline:none;transition:all .2s}
.toolbar input[type="text"]:focus{background:#fff;border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13)}
.toolbar select{padding:11px 38px 11px 14px;border:1.5px solid var(--line);border-radius:12px;background:#fff;font-family:inherit;font-size:13.5px;font-weight:600;color:var(--ink-soft);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:14px}

.table-card{background:#fff;border:1.5px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:var(--sh-sm)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:1000px}
thead th{padding:14px 16px;text-align:left;font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;background:var(--green-50);border-bottom:1.5px solid var(--line);white-space:nowrap}
tbody td{padding:14px 16px;border-bottom:1px solid var(--line-soft);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background .15s}
tbody tr:hover{background:var(--green-50)}

.v-cell{display:flex;align-items:center;gap:12px}
.v-avatar{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 4px 10px rgba(16,185,129,.28)}
.v-name{font-size:13.5px;font-weight:800;color:var(--ink)}
.v-biz{font-size:11.5px;color:var(--muted);margin-top:2px}

.chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;background:var(--bg);color:var(--ink-soft);border:1px solid var(--line);white-space:nowrap}
.chip i{font-size:9px;color:var(--green-600)}

.badge{display:inline-flex;align-items:center;padding:4px 11px;border-radius:999px;font-size:10.5px;font-weight:800;text-transform:capitalize;letter-spacing:.03em;white-space:nowrap}
.badge.valid,.badge.approved,.badge.paid{background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200)}
.badge.pending,.badge.partial{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge.expired,.badge.unpaid,.badge.rejected,.badge.suspended{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.badge.expiring{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge.due{background:#fef3c7;color:#92400e;border:1px solid #fde68a}

.expiry-cell{display:flex;flex-direction:column;gap:3px}
.expiry-date{font-size:12.5px;font-weight:700;color:var(--ink)}
.expiry-days{font-size:11px;font-weight:700}
.expiry-days.ok{color:var(--green-700)}
.expiry-days.warn{color:#d97706}
.expiry-days.danger{color:#dc2626}

.row-actions{display:flex;gap:6px;justify-content:flex-end}
.row-btn{width:34px;height:34px;border-radius:10px;border:1.5px solid var(--line);background:#fff;display:grid;place-items:center;cursor:pointer;font-size:13px;color:var(--ink-soft);transition:all .2s var(--ease-spring);text-decoration:none}
.row-btn:hover{border-color:var(--green-400);color:var(--brand-dark);background:var(--green-50);transform:translateY(-2px)}
.row-btn.renew:hover{background:#fef3c7;border-color:#fcd34d;color:#92400e}
.row-btn.pay:hover{background:#e0f2fe;border-color:#7dd3fc;color:#0369a1}
.row-btn.suspend:hover{background:#fee2e2;border-color:#fca5a5;color:#dc2626}

.empty-state{padding:64px 24px;text-align:center;color:var(--muted)}
.empty-state i{font-size:52px;color:var(--green-200);display:block;margin-bottom:14px}
.empty-state h3{font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px}
.empty-state p{font-size:13.5px}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,44,34,.55);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:600;display:none;align-items:center;justify-content:center;padding:24px;overflow-y:auto}
.modal-backdrop.open{display:flex}
.modal{background:#fff;border-radius:22px;width:100%;max-width:560px;box-shadow:var(--sh-xl);overflow:hidden;animation:modalIn .35s var(--ease-spring);max-height:92vh;display:flex;flex-direction:column}
@keyframes modalIn{from{opacity:0;transform:translateY(24px) scale(.94)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-head{position:relative;padding:26px 26px 22px;background:radial-gradient(circle at 85% 20%, rgba(52,211,153,.24) 0%, transparent 52%),linear-gradient(135deg,#022c22 0%,#04443a 55%,#064e3b 100%);color:#fff;display:flex;align-items:center;gap:16px;flex-shrink:0}
.modal-head-icon{width:60px;height:60px;border-radius:18px;flex-shrink:0;background:linear-gradient(135deg,#34d399,#059669);display:grid;place-items:center;color:#fff;font-size:22px;box-shadow:0 10px 22px rgba(16,185,129,.4)}
.modal-head-icon.amber{background:linear-gradient(135deg,#fbbf24,#d97706);box-shadow:0 10px 22px rgba(217,119,6,.4)}
.modal-head-icon.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);box-shadow:0 10px 22px rgba(2,132,199,.4)}
.modal-head-icon.red{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 10px 22px rgba(220,38,38,.4)}
.modal-head-info{flex:1;min-width:0}
.modal-head-info h2{font-size:19px;font-weight:900;color:#fff;letter-spacing:-.4px;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis}
.modal-head-info p{font-size:12.5px;color:#b6c5d3}
.modal-close{position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);color:#fff;font-size:13px;cursor:pointer;display:grid;place-items:center;transition:all .3s var(--ease-spring)}
.modal-close:hover{background:rgba(255,255,255,.2);transform:rotate(90deg)}
.modal-body{padding:22px 26px;overflow-y:auto;flex:1}
.modal-footer{padding:16px 26px 22px;border-top:1px solid var(--line-soft);background:#fbfcfd;display:flex;gap:9px;flex-wrap:wrap;flex-shrink:0}

.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.field:last-child{margin-bottom:0}
.field label{font-size:12px;font-weight:800;color:var(--ink-soft);letter-spacing:.02em;display:flex;align-items:center;gap:5px}
.field label .req{color:#dc2626}
.field label .optional{font-weight:600;color:var(--muted);font-size:11px;margin-left:auto}
.field input,.field select,.field textarea{width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);font-family:inherit;font-size:13.5px;outline:none;transition:all .2s}
.field textarea{resize:vertical;min-height:72px;line-height:1.5}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13)}
/* Read-only stand-in for a value the admin cannot choose (payment method) */
.fixed-field{display:flex;align-items:center;gap:8px;padding:11px 14px;border:1.5px dashed var(--line);
    border-radius:11px;background:var(--bg);color:var(--ink-soft);font-size:13px;font-weight:700}
.fixed-field i{color:var(--brand)}
.field select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:13px;padding-right:38px;cursor:pointer}

.form-grid{display:grid;gap:12px}
.form-grid.c2{grid-template-columns:1fr 1fr}@media(max-width:640px){.form-grid.c2{grid-template-columns:1fr}}

.btn-form{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;font-family:inherit;font-size:13.5px;font-weight:800;cursor:pointer;border:none;transition:all .25s var(--ease-spring);text-decoration:none}
.btn-form.primary{background:linear-gradient(135deg, #059669, #065f46);color:#fff;box-shadow:var(--glow-soft)}
.btn-form.primary:hover{transform:translateY(-2px);box-shadow:var(--glow)}
.btn-form.amber{background:linear-gradient(135deg,#fbbf24,#d97706);color:#022c22;box-shadow:0 8px 22px rgba(217,119,6,.35)}
.btn-form.amber:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(217,119,6,.5)}
.btn-form.blue{background:linear-gradient(135deg,#38bdf8,#0284c7);color:#fff;box-shadow:0 8px 22px rgba(2,132,199,.32)}
.btn-form.blue:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(2,132,199,.5)}
.btn-form.danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 8px 22px rgba(220,38,38,.32)}
.btn-form.danger:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(220,38,38,.5)}
.btn-form.ghost{background:var(--bg);color:var(--ink-soft);border:1.5px solid var(--line)}
.btn-form.ghost:hover{background:var(--line-soft);transform:translateY(-2px)}

.v-summary{background:var(--green-50);border:1.5px solid var(--green-200);border-radius:14px;padding:16px;margin-bottom:18px}
.v-summary .row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px;border-bottom:1px dashed var(--green-200)}
.v-summary .row:last-child{border-bottom:none}
.v-summary .label{color:var(--ink-soft);font-weight:600}
.v-summary .value{color:var(--ink);font-weight:800}
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <header class="top-header">
        <div class="top-header-left">
            <h1><span class="h1-icon"><i class="fas fa-file-shield"></i></span> Permits & Payments</h1>
            <p>Renew permits, record walk-in payments, and monitor compliance.</p>
        </div>
        <div class="header-actions">
            <a href="admin_dashboard.php" class="btn-header ghost"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>">
            <i class="fas <?= $flash['type']==='success'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
            <span><?= h($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary stats -->
    <div class="stat-row">
        <div class="mini-stat">
            <div class="icon i1"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="num"><?= (int)$stat['valid'] ?></div>
                <div class="lbl">Valid Permits</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon i2"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="num"><?= (int)$stat['expiring'] ?></div>
                <div class="lbl">Expiring ≤30d</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon i3"><i class="fas fa-circle-xmark"></i></div>
            <div>
                <div class="num"><?= (int)$stat['expired'] ?></div>
                <div class="lbl">Expired</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon i4"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="num"><?= (int)$stat['unpaid'] ?></div>
                <div class="lbl">Unpaid</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form class="toolbar" method="get" action="admin_permits.php">
        <div class="search-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search vendor, business, stall, or contact…" />
        </div>
        <select name="permit">
            <option value="">All permit statuses</option>
            <option value="Valid"      <?= $permitF==='Valid'?'selected':'' ?>>Valid</option>
            <option value="Pending"    <?= $permitF==='Pending'?'selected':'' ?>>Pending</option>
            <option value="Expired"    <?= $permitF==='Expired'?'selected':'' ?>>Expired</option>
            <option value="Suspended"  <?= $permitF==='Suspended'?'selected':'' ?>>Suspended</option>
        </select>
        <select name="pay">
            <option value="">All payment statuses</option>
            <option value="Paid"    <?= $payF==='Paid'?'selected':'' ?>>Paid</option>
            <option value="Partial" <?= $payF==='Partial'?'selected':'' ?>>Partial</option>
            <option value="Unpaid"  <?= $payF==='Unpaid'?'selected':'' ?>>Unpaid</option>
        </select>
        <select name="sort">
            <option value="expiry" <?= $sortF==='expiry'?'selected':'' ?>>Sort: Permit expiry</option>
            <option value="name"   <?= $sortF==='name'?'selected':'' ?>>Sort: Vendor name</option>
            <option value="stall"  <?= $sortF==='stall'?'selected':'' ?>>Sort: Stall number</option>
        </select>
        <button type="submit" class="btn-toolbar primary"><i class="fas fa-filter"></i> Apply</button>
        <?php if ($q !== '' || $permitF !== '' || $payF !== '' || $sortF !== 'expiry'): ?>
            <a href="admin_permits.php" class="btn-toolbar ghost"><i class="fas fa-rotate-left"></i> Reset</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="table-card">
        <?php if (empty($vendors)): ?>
            <div class="empty-state">
                <i class="fas fa-file-slash"></i>
                <h3>No vendors found</h3>
                <p>Try adjusting your filters.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Stall</th>
                        <th>Section</th>
                        <th>Contact</th>
                        <th>Permit</th>
                        <th>Payment</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vendors as $v):
                    $name = trim(($v['first_name']??'').' '.($v['middle_name']??'').' '.($v['last_name']??''));
                    $dl = days_left($v['permit_expiry']);

                    /* Determine permit pill */
                    $permitLabel = $v['permit_status'] ?: 'Pending';
                    $permitClass = strtolower($permitLabel);
                    if ($permitLabel === 'Valid' && $dl !== null && $dl >= 0 && $dl <= 30) {
                        $permitClass = 'expiring';
                        $permitLabel = 'Expiring';
                    }
                ?>
                    <tr>
                        <td>
                            <div class="v-cell">
                                <div class="v-avatar"><?= h(ini_from($name)) ?></div>
                                <div>
                                    <div class="v-name"><?= h($name) ?></div>
                                    <div class="v-biz"><?= h($v['business_name'] ?: 'Independent vendor') ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="chip"><i class="fas fa-location-dot"></i> <?= h($v['stall_number'] ?: '—') ?></span></td>
                        <td><?= h($v['market_section'] ?: '—') ?></td>
                        <td><?= h($v['contact_number'] ?: '—') ?></td>
                        <td>
                            <div class="expiry-cell">
                                <span class="badge <?= $permitClass ?>"><?= h($permitLabel) ?></span>
                                <?php if ($v['permit_expiry']): ?>
                                    <span class="expiry-date"><?= date('M j, Y', strtotime($v['permit_expiry'])) ?></span>
                                    <span class="expiry-days <?= $dl < 0 ? 'danger' : ($dl <= 30 ? 'warn' : 'ok') ?>">
                                        <?= $dl < 0 ? abs($dl).'d overdue' : ($dl === 0 ? 'Today' : $dl.'d left') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="expiry-date">No expiry set</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="expiry-cell">
                                <span class="badge <?= strtolower($v['payment_status'] ?: 'unpaid') ?>"><?= h($v['payment_status'] ?: 'Unpaid') ?></span>
                                <?php if ($v['payment_due']): ?>
                                    <?php $ddl = days_left($v['payment_due']); ?>
                                    <span class="expiry-days <?= $ddl < 0 ? 'danger' : ($ddl <= 7 ? 'warn' : 'ok') ?>">
                                        Due <?= date('M j', strtotime($v['payment_due'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="row-btn renew"
                                        onclick='openRenew(<?= (int)$v["id"] ?>, <?= json_encode($name) ?>, <?= json_encode($v["stall_number"] ?: "—") ?>, <?= json_encode($v["permit_expiry"] ?: "") ?>)'
                                        title="Renew permit"><i class="fas fa-rotate-right"></i></button>
                                <button type="button" class="row-btn pay"
                                        onclick='openPay(<?= (int)$v["id"] ?>, <?= json_encode($name) ?>, <?= json_encode($v["stall_number"] ?: "—") ?>, <?= json_encode($v["payment_status"] ?: "Unpaid") ?>)'
                                        title="Record walk-in payment"><i class="fas fa-money-bill-wave"></i></button>
                                <?php if ($v['permit_status'] === 'Suspended'): ?>
                                    <button type="button" class="row-btn"
                                            onclick='openAction("reactivate",<?= (int)$v["id"] ?>,<?= json_encode($name) ?>)'
                                            title="Reactivate permit"><i class="fas fa-circle-check"></i></button>
                                <?php else: ?>
                                    <button type="button" class="row-btn suspend"
                                            onclick='openAction("suspend",<?= (int)$v["id"] ?>,<?= json_encode($name) ?>)'
                                            title="Suspend permit"><i class="fas fa-ban"></i></button>
                                <?php endif; ?>
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

<!-- ============ RENEW MODAL ============ -->
<div class="modal-backdrop" id="renewModal">
    <div class="modal" onclick="event.stopPropagation()">
        <form method="post" action="admin_permits.php">
            <input type="hidden" name="action" value="renew_permit" />
            <input type="hidden" name="id" id="renewId" value="" />

            <div class="modal-head">
                <div class="modal-head-icon amber"><i class="fas fa-rotate-right"></i></div>
                <div class="modal-head-info">
                    <h2>Renew Permit</h2>
                    <p><span id="renewName">—</span> · Stall <span id="renewStall">—</span></p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('renewModal')"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">
                <div class="v-summary">
                    <div class="row"><span class="label">Current expiry</span><span class="value" id="renewCurrent">—</span></div>
                </div>

                <div class="form-grid c2">
                    <div class="field">
                        <label>Renew for <span class="req">*</span></label>
                        <select name="months" id="renewMonths">
                            <option value="1">1 month</option>
                            <option value="3">3 months</option>
                            <option value="6">6 months</option>
                            <option value="12" selected>1 year</option>
                            <option value="24">2 years</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Fee (₱) <span class="req">*</span></label>
                        <input type="number" name="fee" id="renewFee" min="0" step="0.01" value="0.00" required />
                    </div>
                    <div class="field">
                        <label>Payment method</label>
                        <div class="fixed-field"><i class="fas fa-person-walking"></i> <?= h(PAYMENT_METHOD) ?> — paid at the market office</div>
                    </div>
                    <div class="field">
                        <label>OR number <span class="optional">optional</span></label>
                        <input type="text" name="or_number" placeholder="e.g. 2024-00123" />
                    </div>
                </div>

                <div class="field">
                    <label>Remarks <span class="optional">optional</span></label>
                    <textarea name="remarks" placeholder="Any notes about this renewal…"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-form ghost" onclick="closeModal('renewModal')"><i class="fas fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn-form amber" style="margin-left:auto;"><i class="fas fa-circle-check"></i> Confirm Renewal</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ PAYMENT MODAL ============ -->
<div class="modal-backdrop" id="payModal">
    <div class="modal" onclick="event.stopPropagation()">
        <form method="post" action="admin_permits.php">
            <input type="hidden" name="action" value="record_payment" />
            <input type="hidden" name="id" id="payId" value="" />

            <div class="modal-head">
                <div class="modal-head-icon blue"><i class="fas fa-money-bill-wave"></i></div>
                <div class="modal-head-info">
                    <h2>Record Walk-in Payment</h2>
                    <p><span id="payName">—</span> · Stall <span id="payStall">—</span></p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('payModal')"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">
                <div class="v-summary">
                    <div class="row"><span class="label">Current payment status</span><span class="value" id="payCurrent">—</span></div>
                </div>

                <div class="form-grid c2">
                    <div class="field">
                        <label>Amount (₱) <span class="req">*</span></label>
                        <input type="number" name="amount" min="0" step="0.01" placeholder="0.00" required />
                    </div>
                    <div class="field">
                        <label>Payment method</label>
                        <div class="fixed-field"><i class="fas fa-person-walking"></i> <?= h(PAYMENT_METHOD) ?> — paid at the market office</div>
                    </div>
                    <div class="field">
                        <label>OR number <span class="optional">optional</span></label>
                        <input type="text" name="or_number" placeholder="e.g. 2024-00123" />
                    </div>
                    <div class="field">
                        <label>Covered period <span class="optional">optional</span></label>
                        <input type="text" name="period" placeholder="e.g. November 2025" />
                    </div>
                </div>

                <div class="field">
                    <label>Notes <span class="optional">optional</span></label>
                    <textarea name="notes" placeholder="Any notes about this payment…"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-form ghost" onclick="closeModal('payModal')"><i class="fas fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn-form blue" style="margin-left:auto;"><i class="fas fa-receipt"></i> Record Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ SUSPEND / REACTIVATE MODAL ============ -->
<div class="modal-backdrop" id="actionModal">
    <div class="modal" onclick="event.stopPropagation()" style="max-width:440px;">
        <form method="post" action="admin_permits.php">
            <input type="hidden" name="action" id="actType" value="" />
            <input type="hidden" name="id" id="actId" value="" />

            <div class="modal-head">
                <div class="modal-head-icon red" id="actIcon"><i class="fas fa-ban"></i></div>
                <div class="modal-head-info">
                    <h2 id="actTitle">Suspend Permit</h2>
                    <p id="actSub">—</p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('actionModal')"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">
                <p id="actMessage" style="font-size:14px;color:var(--ink-soft);line-height:1.6;">
                    Are you sure you want to change this vendor's permit status?
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-form ghost" onclick="closeModal('actionModal')"><i class="fas fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn-form danger" id="actConfirm" style="margin-left:auto;">
                    <i class="fas fa-check"></i> Confirm
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
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

/* RENEW */
function openRenew(id, name, stall, currentExpiry) {
    document.getElementById('renewId').value = id;
    document.getElementById('renewName').textContent = name;
    document.getElementById('renewStall').textContent = stall;
    document.getElementById('renewCurrent').textContent = currentExpiry
        ? new Date(currentExpiry).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
        : 'No expiry set';
    document.getElementById('renewModal').classList.add('open');
}

/* PAY */
function openPay(id, name, stall, currentStatus) {
    document.getElementById('payId').value = id;
    document.getElementById('payName').textContent = name;
    document.getElementById('payStall').textContent = stall;
    document.getElementById('payCurrent').textContent = currentStatus;
    document.getElementById('payModal').classList.add('open');
}

/* SUSPEND / REACTIVATE */
function openAction(type, id, name) {
    const icon = document.getElementById('actIcon');
    const title = document.getElementById('actTitle');
    const msg = document.getElementById('actMessage');
    const btn = document.getElementById('actConfirm');
    const sub = document.getElementById('actSub');

    document.getElementById('actType').value = type;
    document.getElementById('actId').value = id;
    sub.textContent = name;

    if (type === 'suspend') {
        title.textContent = 'Suspend Permit';
        msg.textContent = 'This vendor will be marked as Suspended. They won\'t be able to operate until reactivated.';
        icon.style.background = 'linear-gradient(135deg,#ef4444,#dc2626)';
        icon.innerHTML = '<i class="fas fa-ban"></i>';
        btn.className = 'btn-form danger';
        btn.innerHTML = '<i class="fas fa-ban"></i> Suspend Permit';
    } else {
        title.textContent = 'Reactivate Permit';
        msg.textContent = 'This vendor\'s permit will be marked as Valid again.';
        icon.style.background = 'linear-gradient(135deg,#10b981,#047857)';
        icon.innerHTML = '<i class="fas fa-circle-check"></i>';
        btn.className = 'btn-form primary';
        btn.innerHTML = '<i class="fas fa-circle-check"></i> Reactivate';
    }

    document.getElementById('actionModal').classList.add('open');
}

/* Close modals on backdrop + escape */
document.querySelectorAll('.modal-backdrop').forEach(b => {
    b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(b => b.classList.remove('open'));
});
</script>

</body>
</html>