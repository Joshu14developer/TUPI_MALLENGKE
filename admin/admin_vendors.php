<?php
session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/swap.php';
require_once __DIR__ . '/../config/reports.php';

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
   Flash messaging
   ============================================================ */
function set_flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function get_flash() {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
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
   Handle actions
   ============================================================ */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- Delete ---- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        /* Grab stall + name for the log */
        $info = null;
        if ($s = $conn->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS n, stall_number FROM vendors WHERE id = ? LIMIT 1")) {
            $s->bind_param("i", $id); $s->execute();
            $info = $s->get_result()->fetch_assoc(); $s->close();
        }
        $stmt = $conn->prepare("DELETE FROM vendors WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            log_action($conn, $adminId, 'vendor_deleted',
                $info['n'] ?? ('#' . $id),
                'Stall: ' . ($info['stall_number'] ?? '—'));
            set_flash('success', 'Vendor deleted successfully.');
        } else {
            set_flash('error', 'Failed to delete vendor.');
        }
    }
    header('Location: admin_vendors.php');
    exit;
}

/* ---- Account access: suspend, restore, remove login, re-issue ----
   The same four levers the reports queue offers, so an admin working
   from a vendor's record can act without going through a report. */
if (in_array($action, ['suspend', 'restore', 'remove_access', 'reissue'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $id          = (int)($_POST['id'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));

    if ($action === 'suspend') {
        $res = vendor_suspend($conn, $id, $description, $adminName);
        if ($res['ok']) log_action($conn, $adminId, 'vendor_suspended', '#' . $id, $description);
    } elseif ($action === 'restore') {
        $res = vendor_restore($conn, $id, $description, $adminName);
        if ($res['ok']) log_action($conn, $adminId, 'vendor_restored', '#' . $id, $description);
    } elseif ($action === 'remove_access') {
        $res = vendor_remove_access($conn, $id, $description);
        if ($res['ok']) log_action($conn, $adminId, 'vendor_access_removed', '#' . $id, $description);
    } else {
        $res = vendor_reissue_login($conn, $id, (string)($_POST['new_password'] ?? ''), $adminName);
        if ($res['ok']) log_action($conn, $adminId, 'vendor_login_reissued', '#' . $id, 'New password set by the office');
    }

    if ($res['ok']) {
        $flashes = [
            'suspend'       => 'Vendor suspended. They cannot sign in, and will read the description on their suspended screen.',
            'restore'       => 'Vendor restored — they can sign in again.',
            'remove_access' => 'Login removed. Their stall, permit, payments and message history are untouched.',
            'reissue'       => 'New password set. Tell the vendor what it is at the counter.',
        ];
        set_flash('success', $flashes[$action]);
    } else {
        set_flash('error', $res['error']);
    }

    header('Location: admin_vendors.php?id=' . $id);
    exit;
}

/* ---- Approve / Reject ---- */
if (($action === 'approve' || $action === 'reject') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';

    if ($id) {
        /* Grab name/stall before updating so we can personalise the notice */
        $vname = 'Vendor';
        $blocked = 0;
        $accessKind = 'none';
        if ($s = $conn->prepare(
            "SELECT CONCAT_WS(' ', first_name, last_name) AS n, stall_number, access_blocked, access_kind
               FROM vendors WHERE id = ? LIMIT 1"
        )) {
            $s->bind_param("i", $id); $s->execute();
            $info = $s->get_result()->fetch_assoc(); $s->close();
            if ($info) {
                $vname      = trim($info['n'] ?: 'Vendor');
                $blocked    = (int)($info['access_blocked'] ?? 0);
                $accessKind = (string)($info['access_kind'] ?? 'none');
            }
        }

        if ($remarks !== '') {
            $stmt = $conn->prepare("UPDATE vendors SET status = ?, remarks = ? WHERE id = ?");
            $stmt->bind_param("ssi", $newStatus, $remarks, $id);
        } else {
            $stmt = $conn->prepare("UPDATE vendors SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $newStatus, $id);
        }
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
            log_action($conn, $adminId, 'vendor_' . $action,
                '#' . $id,
                'Status → ' . $newStatus . ($remarks ? ' · ' . $remarks : ''));

            /* Approving a blocked vendor is how the office puts them back,
               so the block has to go with the status — otherwise they would
               read "Approved" and still be refused at the login form. */
            $after = '';
            if ($newStatus === 'Approved' && $blocked === 1) {
                if ($accessKind === 'removed') {
                    /* Nothing to lift: the password is gone. */
                    $after = ' Their login was removed, so set a new password'
                           . ' on their record before they can sign in.';
                } else {
                    vendor_restore($conn, $id, $remarks !== '' ? $remarks : 'Reinstated by the market office.', $adminName);
                    $after = ' Their suspension was lifted.';
                }
            }

            /* Notify the vendor */
            if ($newStatus === 'Approved') {
                notify_vendor($conn, $id, 'approval',
                    'Your vendor application has been approved',
                    'Congratulations ' . $vname . '! Your application was approved by the market office. '
                    . 'You may now start operating at your assigned stall. Please bring a copy of your ID when you visit the office.'
                    . ($remarks ? "\n\nNote from the office: " . $remarks : ''),
                    'dashboard.php', $adminId, $adminName);
            } else {
                notify_vendor($conn, $id, 'approval',
                    'Action needed on your vendor application',
                    'Hi ' . $vname . ', we could not approve your application at this time. '
                    . 'Please visit the market office to clarify the requirements and submit your application again.'
                    . ($remarks ? "\n\nReason: " . $remarks : ''),
                    'dashboard.php', $adminId, $adminName);
            }

            set_flash('success', 'Vendor marked as ' . $newStatus . ' and notified.' . $after);
        } else {
            set_flash('error', 'Failed to update status.');
        }
    }
    header('Location: admin_vendors.php?id=' . $id);
    exit;
}

/* ---- Create / Update ---- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

    $fields = [
        'first_name','middle_name','last_name','birthdate','gender','civil_status',
        'contact_number','email','address',
        'business_name','stall_number','market_section','product_type','years_in_business',
        'id_type','id_number',
        'emergency_name','emergency_relationship','emergency_contact',
        'status','remarks',
        'permit_status','permit_expiry',
        'payment_status','payment_due','last_payment_date',
    ];

    $data = [];
    foreach ($fields as $f) $data[$f] = trim((string)($_POST[$f] ?? ''));

    /* Null-able date fields */
    foreach (['birthdate','permit_expiry','payment_due','last_payment_date'] as $df) {
        if ($data[$df] === '') $data[$df] = null;
    }

    /* Validation */
    $errors = [];
    if ($data['first_name'] === '') $errors[] = 'First name is required.';
    if ($data['last_name']  === '') $errors[] = 'Last name is required.';
    if ($data['stall_number'] === '') $errors[] = 'Stall number is required.';
    if ($data['market_section'] === '') $errors[] = 'Market section is required.';
    if ($data['product_type'] === '') $errors[] = 'Product type is required.';
    if ($data['contact_number'] === '') $errors[] = 'Contact number is required.';
    if ($data['gender'] === '') $errors[] = 'Gender is required.';
    if ($data['civil_status'] === '') $errors[] = 'Civil status is required.';
    if (empty($data['birthdate'])) $errors[] = 'Birthdate is required.';

    if (!empty($errors)) {
        set_flash('error', implode(' ', $errors));
        header('Location: admin_vendors.php' . ($id ? '?id=' . $id : '?action=new'));
        exit;
    }

    /* Check stall_number uniqueness (excluding self) */
    if ($s = $conn->prepare("SELECT id FROM vendors WHERE stall_number = ? AND id <> ? LIMIT 1")) {
        $s->bind_param("si", $data['stall_number'], $id); $s->execute();
        if ($s->get_result()->fetch_assoc()) {
            set_flash('error', 'That stall number is already assigned to another vendor.');
            header('Location: admin_vendors.php' . ($id ? '?id=' . $id : '?action=new'));
            exit;
        }
        $s->close();
    }

    if ($id) {
        /* ---- UPDATE ---- */
        /* Snapshot the current values so we can tell the vendor what changed */
        $before = [];
        if ($s = $conn->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1")) {
            $s->bind_param("i", $id); $s->execute();
            $before = $s->get_result()->fetch_assoc() ?: [];
            $s->close();
        }

        $sql = "UPDATE vendors SET
                    first_name=?, middle_name=?, last_name=?,
                    birthdate=?, gender=?, civil_status=?,
                    contact_number=?, email=?, address=?,
                    business_name=?, stall_number=?, market_section=?,
                    product_type=?, years_in_business=?,
                    id_type=?, id_number=?,
                    emergency_name=?, emergency_relationship=?, emergency_contact=?,
                    status=?, remarks=?,
                    permit_status=?, permit_expiry=?,
                    payment_status=?, payment_due=?, last_payment_date=?,
                    updated_at=NOW()
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $years = (int)$data['years_in_business'];
            /* 13 strings, then years_in_business, then 12 more strings, then the id */
            $types = str_repeat('s', 13) . 'i' . str_repeat('s', 12) . 'i';
            $stmt->bind_param(
                $types,
                $data['first_name'], $data['middle_name'], $data['last_name'],
                $data['birthdate'], $data['gender'], $data['civil_status'],
                $data['contact_number'], $data['email'], $data['address'],
                $data['business_name'], $data['stall_number'], $data['market_section'],
                $data['product_type'], $years,
                $data['id_type'], $data['id_number'],
                $data['emergency_name'], $data['emergency_relationship'], $data['emergency_contact'],
                $data['status'], $data['remarks'],
                $data['permit_status'], $data['permit_expiry'],
                $data['payment_status'], $data['payment_due'], $data['last_payment_date'],
                $id
            );
            if ($stmt->execute()) {
                log_action($conn, $adminId, 'vendor_updated', $data['first_name'] . ' ' . $data['last_name'], 'Stall ' . $data['stall_number']);

                /* Build a human summary of what the office changed */
                $changes = [];
                $labels = [
                    'stall_number'     => 'Stall number',
                    'market_section'   => 'Market section',
                    'product_type'     => 'Products sold',
                    'business_name'    => 'Business name',
                    'contact_number'   => 'Contact number',
                    'email'            => 'Email',
                    'address'          => 'Address',
                    'permit_status'    => 'Permit status',
                    'permit_expiry'    => 'Permit expiry',
                    'payment_status'   => 'Payment status',
                    'payment_due'      => 'Payment due',
                    'last_payment_date'=> 'Last payment date',
                    'status'           => 'Account status',
                ];
                foreach ($labels as $col => $label) {
                    $old = trim((string)($before[$col] ?? ''));
                    $new = trim((string)($data[$col] ?? ''));
                    if ($old !== $new) {
                        $changes[] = '• ' . $label . ': '
                                   . ($old !== '' ? $old : '—') . ' → '
                                   . ($new !== '' ? $new : '—');
                    }
                }

                /* Only notify when something the vendor cares about moved */
                $notifyKeys = ['stall_number','market_section','permit_status','permit_expiry',
                               'payment_status','payment_due','last_payment_date','status'];
                $worthNotifying = false;
                foreach ($notifyKeys as $k) {
                    if (trim((string)($before[$k] ?? '')) !== trim((string)($data[$k] ?? ''))) {
                        $worthNotifying = true; break;
                    }
                }
                $newRemarks = $data['remarks'];
                $oldRemarks = trim((string)($before['remarks'] ?? ''));
                if (!$worthNotifying && $newRemarks !== '' && $newRemarks !== $oldRemarks) {
                    $worthNotifying = true;
                }

                if ($worthNotifying) {
                    $body = 'The market office updated your vendor record.';
                    if ($changes) $body .= "\n\n" . implode("\n", $changes);
                    if ($newRemarks !== '' && $newRemarks !== $oldRemarks) {
                        $body .= "\n\nRemarks: " . $newRemarks;
                    }

                    /* Only call it an approval when the status actually moved */
                    $statusChanged = trim((string)($before['status'] ?? '')) !== trim((string)$data['status']);
                    $type = ($statusChanged && in_array($data['status'], ['Approved','Rejected'], true))
                        ? 'approval' : 'stall';

                    $title = ($statusChanged && $data['status'] === 'Approved')
                        ? 'Your vendor application has been approved'
                        : (($statusChanged && $data['status'] === 'Rejected')
                            ? 'Action needed on your vendor application'
                            : 'Your vendor record was updated');

                    notify_vendor($conn, $id, $type, $title, $body, 'dashboard.php', $adminId, $adminName);
                }

                set_flash('success', 'Vendor updated successfully.'
                    . ($worthNotifying ? ' The vendor has been notified.' : ''));
            } else {
                set_flash('error', 'Failed to update vendor: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            set_flash('error', 'Database error: ' . $conn->error);
        }
    } else {
        /* ---- INSERT ---- */
        $sql = "INSERT INTO vendors
                (first_name, middle_name, last_name,
                 birthdate, gender, civil_status,
                 contact_number, email, address,
                 business_name, stall_number, market_section,
                 product_type, years_in_business,
                 id_type, id_number,
                 emergency_name, emergency_relationship, emergency_contact,
                 status, remarks,
                 permit_status, permit_expiry,
                 payment_status, payment_due, last_payment_date,
                 qr_token, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $years = (int)$data['years_in_business'];
            $qr = bin2hex(random_bytes(16));
            $stmt->bind_param(
                /* 13 strings, years_in_business, then 13 more strings (qr_token last) */
                str_repeat('s', 13) . 'i' . str_repeat('s', 13),
                $data['first_name'], $data['middle_name'], $data['last_name'],
                $data['birthdate'], $data['gender'], $data['civil_status'],
                $data['contact_number'], $data['email'], $data['address'],
                $data['business_name'], $data['stall_number'], $data['market_section'],
                $data['product_type'], $years,
                $data['id_type'], $data['id_number'],
                $data['emergency_name'], $data['emergency_relationship'], $data['emergency_contact'],
                $data['status'], $data['remarks'],
                $data['permit_status'], $data['permit_expiry'],
                $data['payment_status'], $data['payment_due'], $data['last_payment_date'],
                $qr
            );
            if ($stmt->execute()) {
                $newId = (int)$stmt->insert_id;
                log_action($conn, $adminId, 'vendor_created',
                    $data['first_name'] . ' ' . $data['last_name'],
                    'Stall ' . $data['stall_number']);
                set_flash('success', 'Vendor created successfully.');
                header('Location: admin_vendors.php?id=' . $newId);
                exit;
            } else {
                set_flash('error', 'Failed to create vendor: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            set_flash('error', 'Database error: ' . $conn->error);
        }
    }
    header('Location: admin_vendors.php' . ($id ? '?id=' . $id : '?action=new'));
    exit;
}

$flash = get_flash();

/* ============================================================
   Fetch data
   ============================================================ */
$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew  = (($_GET['action'] ?? '') === 'new');

/* Single vendor */
$vendor = null;
if ($viewId) {
    if ($s = $conn->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1")) {
        $s->bind_param("i", $viewId); $s->execute();
        $vendor = $s->get_result()->fetch_assoc();
        $s->close();
    }
}

/* Filters for list */
$q          = trim($_GET['q'] ?? '');
$statusF    = trim($_GET['status'] ?? '');
$sectionF   = trim($_GET['section'] ?? '');

$where = []; $params = []; $types = '';

if ($q !== '') {
    $where[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR business_name LIKE ? OR stall_number LIKE ? OR contact_number LIKE ?)";
    $like = '%' . $q . '%';
    for ($i = 0; $i < 6; $i++) { $params[] = $like; $types .= 's'; }
}
if ($statusF !== '' && in_array($statusF, ['Pending','Approved','Rejected','Suspended'], true)) {
    $where[] = "status = ?";
    $params[] = $statusF; $types .= 's';
}
if ($sectionF !== '') {
    $where[] = "market_section = ?";
    $params[] = $sectionF; $types .= 's';
}

$sql = "SELECT id, first_name, middle_name, last_name, business_name,
               stall_number, market_section, product_type, contact_number,
               status, permit_status, payment_status, access_kind, created_at
          FROM vendors";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY created_at DESC";

$vendors = [];
if ($stmt = $conn->prepare($sql)) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $vendors[] = $r;
    $stmt->close();
}

/* Sections list for filter */
$sections = [];
if ($res = $conn->query("SELECT DISTINCT market_section FROM vendors WHERE market_section <> '' ORDER BY market_section")) {
    while ($r = $res->fetch_assoc()) $sections[] = $r['market_section'];
}

/* Stats */
$stat = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
if ($res = $conn->query("SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending')  AS pending,
        SUM(status = 'Approved') AS approved,
        SUM(status = 'Rejected') AS rejected
      FROM vendors")) {
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
function selected($a, $b) { return ($a === $b) ? 'selected' : ''; }
function h($v) { return htmlspecialchars((string)$v); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Vendor Management • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>

.btn-header.danger{background:#fee2e2;color:#991b1b;border:1.5px solid #fecaca;}
.btn-header.danger:hover{background:#fecaca;}

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
.mini-stat .icon.g1{background:linear-gradient(135deg,#10b981,#047857);box-shadow:0 5px 14px rgba(16,185,129,.35);}
.mini-stat .icon.g2{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 5px 14px rgba(217,119,6,.32);}
.mini-stat .icon.g3{background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 5px 14px rgba(52,211,153,.35);}
.mini-stat .icon.g4{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 5px 14px rgba(220,38,38,.32);}
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
.toolbar input[type="text"]:focus{background:#fff;border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13);}

.toolbar select{
    padding:11px 38px 11px 14px;border:1.5px solid var(--line);border-radius:12px;
    background:#fff;font-family:inherit;font-size:13.5px;font-weight:600;color:var(--ink-soft);
    outline:none;cursor:pointer;appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat:no-repeat;background-position:right 12px center;background-size:14px;
    transition:all .2s;
}
.toolbar select:focus{border-color:var(--green-400);box-shadow:0 0 0 4px rgba(16,185,129,.13);}

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
    background:var(--green-50);
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
tbody tr:hover{background:var(--green-50);}

.vendor-cell{display:flex;align-items:center;gap:12px;}
.vendor-avatar{
    width:40px;height:40px;border-radius:12px;flex-shrink:0;
    display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;
    background:linear-gradient(135deg,#34d399,#059669);
    box-shadow:0 4px 10px rgba(16,185,129,.28);
}
.vendor-name{font-size:13.5px;font-weight:800;color:var(--ink);}
.vendor-biz{font-size:11.5px;color:var(--muted);margin-top:2px;}

.stall-chip{
    display:inline-flex;align-items:center;gap:6px;
    padding:4px 10px;border-radius:999px;
    font-size:11.5px;font-weight:800;
    background:var(--bg);color:var(--ink-soft);
    border:1px solid var(--line);
    white-space:nowrap;
}
.stall-chip i{font-size:9px;color:var(--green-600);}

.badge{
    display:inline-flex;align-items:center;
    padding:4px 11px;border-radius:999px;
    font-size:10.5px;font-weight:800;
    text-transform:capitalize;letter-spacing:.03em;
    white-space:nowrap;
}
.badge.pending {background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge.approved{background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200);}
.badge.rejected{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
    .badge.suspended{background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;}
.badge.valid   {background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200);}
.badge.expired {background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.badge.paid    {background:var(--green-100);color:var(--green-800);border:1px solid var(--green-200);}
.badge.partial {background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge.unpaid  {background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

.row-actions{display:flex;gap:6px;justify-content:flex-end;}
.row-btn{
    width:34px;height:34px;border-radius:10px;
    border:1.5px solid var(--line);background:#fff;
    display:grid;place-items:center;cursor:pointer;
    font-size:13px;color:var(--ink-soft);
    transition:all .2s var(--ease-spring);
    text-decoration:none;
}
.row-btn:hover{border-color:var(--green-400);color:var(--brand-dark);background:var(--green-50);transform:translateY(-2px);}
.row-btn.view:hover{background:var(--green-100);}
.row-btn.edit:hover{background:#fef3c7;border-color:#fcd34d;color:#92400e;}
.row-btn.delete:hover{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}

.empty-state{
    padding:64px 24px;text-align:center;color:var(--muted);
}
.empty-state i{font-size:52px;color:var(--green-200);display:block;margin-bottom:14px;}
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
        radial-gradient(circle at 85% 20%, rgba(52,211,153,.24) 0%, transparent 52%),
        linear-gradient(135deg, #022c22 0%, #04443a 55%, #064e3b 100%);
    color:#fff;
    display:flex;align-items:center;gap:16px;
    flex-shrink:0;
}
.modal-head-icon{
    width:60px;height:60px;border-radius:18px;flex-shrink:0;
    background:linear-gradient(135deg,#34d399,#059669);
    display:grid;place-items:center;
    color:#fff;font-size:22px;
    box-shadow:0 10px 22px rgba(16,185,129,.4);
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

/* Form grid */
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
    background:var(--green-100);color:var(--green-700);
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

.field input,
.field select,
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
.field select:focus,
.field textarea:focus{
    border-color:var(--green-400);
    box-shadow:0 0 0 4px rgba(16,185,129,.13);
}
.field select{
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat:no-repeat;background-position:right 12px center;background-size:13px;
    padding-right:38px;cursor:pointer;
}

.btn-form{
    display:inline-flex;align-items:center;gap:8px;
    padding:12px 22px;border-radius:12px;
    font-family:inherit;font-size:13.5px;font-weight:800;
    cursor:pointer;border:none;
    transition:all .25s var(--ease-spring);
    text-decoration:none;
}
.btn-form.primary{
    background:linear-gradient(135deg, #059669, #065f46);color:#fff;
    box-shadow:var(--glow-soft);
}
.btn-form.primary:hover{transform:translateY(-2px);box-shadow:var(--glow);}
.btn-form.ghost{background:var(--bg);color:var(--ink-soft);border:1.5px solid var(--line);}
.btn-form.ghost:hover{background:var(--line-soft);}
.btn-form.danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 6px 14px rgba(220,38,38,.35);}
.btn-form.danger:hover{transform:translateY(-2px);box-shadow:0 12px 24px rgba(220,38,38,.48);}

/* View mode rows */
.info-row{
    display:flex;gap:14px;padding:12px 0;
    border-bottom:1px dashed var(--line);
    align-items:flex-start;
}
.info-row:last-child{border-bottom:none;}
.info-icon{
    width:38px;height:38px;border-radius:11px;flex-shrink:0;
    background:var(--green-50);border:1px solid var(--green-100);
    color:var(--brand-dark);
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
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar.php'; ?>

<main class="main-content">

    <!-- HEADER -->
    <header class="top-header">
        <div class="top-header-left">
            <h1>
                <span class="h1-icon"><i class="fas fa-store"></i></span>
                Vendor Management
            </h1>
            <p>Create, view, edit, approve, and manage all market vendors.</p>
        </div>
        <div class="header-actions">
            <a href="admin_dashboard.php" class="btn-header ghost">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="admin_vendors.php?action=new" class="btn-header primary">
                <i class="fas fa-plus"></i> Add New Vendor
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
            <div class="icon g1"><i class="fas fa-store"></i></div>
            <div>
                <div class="num"><?= (int)$stat['total'] ?></div>
                <div class="lbl">Total Vendors</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon g2"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="num"><?= (int)$stat['pending'] ?></div>
                <div class="lbl">Pending</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon g3"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="num"><?= (int)$stat['approved'] ?></div>
                <div class="lbl">Approved</div>
            </div>
        </div>
        <div class="mini-stat">
            <div class="icon g4"><i class="fas fa-circle-xmark"></i></div>
            <div>
                <div class="num"><?= (int)$stat['rejected'] ?></div>
                <div class="lbl">Rejected</div>
            </div>
        </div>
    </div>

    <!-- TOOLBAR -->
    <form class="toolbar" method="get" action="admin_vendors.php">
        <div class="search-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by name, business, stall, or contact…" />
        </div>

        <select name="status">
            <option value="">All statuses</option>
            <option value="Pending"  <?= selected($statusF,'Pending')  ?>>Pending</option>
            <option value="Approved" <?= selected($statusF,'Approved') ?>>Approved</option>
            <option value="Rejected" <?= selected($statusF,'Rejected') ?>>Rejected</option>
            <option value="Suspended" <?= selected($statusF,'Suspended') ?>>Suspended</option>
        </select>

        <select name="section">
            <option value="">All sections</option>
            <?php foreach ($sections as $s): ?>
                <option value="<?= h($s) ?>" <?= selected($sectionF, $s) ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-toolbar primary"><i class="fas fa-filter"></i> Apply</button>
        <?php if ($q !== '' || $statusF !== '' || $sectionF !== ''): ?>
            <a href="admin_vendors.php" class="btn-toolbar ghost"><i class="fas fa-rotate-left"></i> Reset</a>
        <?php endif; ?>
    </form>

    <!-- TABLE -->
    <div class="table-card">
        <?php if (empty($vendors)): ?>
            <div class="empty-state">
                <i class="fas fa-store-slash"></i>
                <h3>No vendors found</h3>
                <p><?= ($q !== '' || $statusF !== '' || $sectionF !== '') ? 'Try adjusting your filters or search keywords.' : 'Get started by adding your first vendor.' ?></p>
                <a href="admin_vendors.php?action=new" class="btn-toolbar primary">
                    <i class="fas fa-plus"></i> Add New Vendor
                </a>
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
                            <th>Status</th>
                            <th>Permit</th>
                            <th>Payment</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendors as $v):
                        $fullName = trim(($v['first_name'] ?? '') . ' ' . ($v['middle_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <div class="vendor-cell">
                                    <div class="vendor-avatar"><?= h(ini_from($fullName)) ?></div>
                                    <div>
                                        <div class="vendor-name"><?= h($fullName) ?></div>
                                        <div class="vendor-biz"><?= h($v['business_name'] ?: 'Independent vendor') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="stall-chip">
                                    <i class="fas fa-location-dot"></i>
                                    <?= h($v['stall_number'] ?: '—') ?>
                                </span>
                            </td>
                            <td><?= h($v['market_section'] ?: '—') ?></td>
                            <td><?= h($v['contact_number'] ?: '—') ?></td>
                            <td>
                                <span class="badge <?= h(strtolower($v['status'])) ?>"><?= h($v['status']) ?></span>
                                <?php /* A removed login leaves the status
                                       Approved, so it needs its own mark. */ ?>
                                <?php if (($v['access_kind'] ?? 'none') === 'removed'): ?>
                                    <span class="badge suspended" title="Login removed — no password can sign in"><i class="fas fa-ban"></i> Login removed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= h(strtolower($v['permit_status'] ?? 'pending')) ?>"><?= h($v['permit_status'] ?? 'Pending') ?></span>
                            </td>
                            <td>
                                <span class="badge <?= h(strtolower($v['payment_status'] ?? 'unpaid')) ?>"><?= h($v['payment_status'] ?? 'Unpaid') ?></span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a href="admin_vendors.php?id=<?= (int)$v['id'] ?>" class="row-btn view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="admin_vendors.php?id=<?= (int)$v['id'] ?>&edit=1" class="row-btn edit" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <button type="button" class="row-btn delete" title="Delete" onclick="confirmDelete(<?= (int)$v['id'] ?>, '<?= h(addslashes($fullName)) ?>')">
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
<?php if ($vendor && empty($_GET['edit'])): ?>
<div class="modal-backdrop open" id="viewModal">
    <div class="modal wide" onclick="event.stopPropagation()">
        <div class="modal-head">
            <div class="modal-head-icon"><?= h(ini_from(trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['last_name'] ?? '')))) ?></div>
            <div class="modal-head-info">
                <h2><?= h(trim(($vendor['first_name'] ?? '') . ' ' . ($vendor['middle_name'] ?? '') . ' ' . ($vendor['last_name'] ?? ''))) ?></h2>
                <p><?= h($vendor['business_name'] ?: 'Independent vendor') ?></p>
            </div>
            <button type="button" class="modal-close" onclick="window.location='admin_vendors.php'"><i class="fas fa-xmark"></i></button>
        </div>

        <div class="modal-body">

            <div class="form-section">
                <div class="form-section-head">
                    <i class="fas fa-user"></i>
                    <div>
                        <h3>Personal Information</h3>
                        <p>Basic vendor details</p>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-cake-candles"></i></div>
                    <div class="info-content">
                        <div class="info-label">Birthdate / Gender / Civil Status</div>
                        <div class="info-value">
                            <?= h($vendor['birthdate'] ?: '—') ?> ·
                            <?= h($vendor['gender'] ?: '—') ?> ·
                            <?= h($vendor['civil_status'] ?: '—') ?>
                        </div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-phone"></i></div>
                    <div class="info-content">
                        <div class="info-label">Contact</div>
                        <div class="info-value"><?= h($vendor['contact_number'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-envelope"></i></div>
                    <div class="info-content">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= h($vendor['email'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="info-content">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?= h($vendor['address'] ?: '—') ?></div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <i class="fas fa-store-alt"></i>
                    <div>
                        <h3>Business Information</h3>
                        <p>Stall assignment & products</p>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="info-content">
                        <div class="info-label">Stall / Section</div>
                        <div class="info-value"><?= h($vendor['stall_number']) ?> · <?= h($vendor['market_section']) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-box-open"></i></div>
                    <div class="info-content">
                        <div class="info-label">Products</div>
                        <div class="info-value"><?= h($vendor['product_type']) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-clock-rotate-left"></i></div>
                    <div class="info-content">
                        <div class="info-label">Years in Business</div>
                        <div class="info-value"><?= (int)$vendor['years_in_business'] ?> year(s)</div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-id-card"></i></div>
                    <div class="info-content">
                        <div class="info-label">ID</div>
                        <div class="info-value"><?= h($vendor['id_type'] ?: '—') ?> · <?= h($vendor['id_number'] ?: '—') ?></div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <i class="fas fa-heart-pulse"></i>
                    <div>
                        <h3>Emergency Contact</h3>
                        <p>Person to contact in emergencies</p>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-user"></i></div>
                    <div class="info-content">
                        <div class="info-label">Name / Relationship</div>
                        <div class="info-value"><?= h($vendor['emergency_name'] ?: '—') ?> · <?= h($vendor['emergency_relationship'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-phone"></i></div>
                    <div class="info-content">
                        <div class="info-label">Contact</div>
                        <div class="info-value"><?= h($vendor['emergency_contact'] ?: '—') ?></div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <i class="fas fa-file-shield"></i>
                    <div>
                        <h3>Permit & Payment</h3>
                        <p>Stall rental compliance</p>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-file"></i></div>
                    <div class="info-content">
                        <div class="info-label">Permit Status / Expiry</div>
                        <div class="info-value">
                            <span class="badge <?= h(strtolower($vendor['permit_status'] ?? 'pending')) ?>"><?= h($vendor['permit_status'] ?? 'Pending') ?></span>
                            · <?= h($vendor['permit_expiry'] ?: '—') ?>
                        </div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-wallet"></i></div>
                    <div class="info-content">
                        <div class="info-label">Payment Status</div>
                        <div class="info-value">
                            <span class="badge <?= h(strtolower($vendor['payment_status'] ?? 'unpaid')) ?>"><?= h($vendor['payment_status'] ?? 'Unpaid') ?></span>
                            <?php if (!empty($vendor['payment_due'])): ?>
                                · Next due <?= h($vendor['payment_due']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($vendor['remarks'])): ?>
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-note-sticky"></i>
                        <div>
                            <h3>Remarks</h3>
                            <p>Notes about this vendor</p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-note-sticky"></i></div>
                        <div class="info-content">
                            <div class="info-value"><?= nl2br(h($vendor['remarks'])) ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- ACCOUNT ACCESS: can this vendor sign in? -->
        <?php
        $accKind  = (string)($vendor['access_kind'] ?? 'none');
        $accGone  = $accKind === 'removed';
        $accSusp  = $accKind === 'suspended' || ((int)($vendor['access_blocked'] ?? 0) === 1 && !$accGone);
        ?>
        <form method="post" action="admin_vendors.php" style="border-top:1px solid var(--line-soft);padding:18px 24px;background:#fbfcfd;">
            <input type="hidden" name="id" value="<?= (int)$vendor['id'] ?>" />
            <?php /* The buttons carry their own action; this is only what
                   pressing Enter in a field submits, so it has to match the
                   state the form is actually in. */ ?>
            <input type="hidden" name="action" value="<?= $accGone ? 'reissue' : 'suspend' ?>" />

            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;">
                <div style="width:34px;height:34px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;
                            background:<?= ($accGone || $accSusp) ? '#ffedd5' : '#f1f5f9' ?>;
                            color:<?= ($accGone || $accSusp) ? '#9a3412' : '#64748b' ?>;">
                    <i class="fas fa-user-lock"></i>
                </div>
                <div>
                    <div style="font-size:13px;font-weight:800;color:var(--ink);">
                        Account access
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);line-height:1.5;margin-top:2px;">
                        <?php if ($accGone): ?>
                            Their password was destroyed, so this login can never be used again.
                            Set a new one below to let them back in.
                        <?php elseif ($accSusp): ?>
                            Suspended. They cannot sign in, and they read your description on the
                            suspended screen.
                            <?php if (!empty($vendor['access_by'])): ?>
                                <br />Set by <?= h($vendor['access_by']) ?> on
                                <?= h(date('M j, Y g:ia', strtotime((string)$vendor['access_at']))) ?>.
                            <?php endif; ?>
                        <?php else: ?>
                            This vendor can sign in normally. Suspending stops them at the login form
                            without touching their stall or their history.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($vendor['access_note'])): ?>
                <div style="font-size:12px;color:var(--ink-soft);background:#fff7ed;border:1px solid #fed7aa;
                            border-left:3px solid #f59e0b;border-radius:9px;padding:9px 12px;margin-bottom:12px;">
                    <i class="fas fa-note-sticky"></i> <?= h($vendor['access_note']) ?>
                </div>
            <?php endif; ?>

            <?php if ($accGone): ?>
                <input type="password" name="new_password" minlength="8" autocomplete="new-password"
                       placeholder="New password (at least 8 characters)"
                       style="width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:10px;
                              font-family:inherit;font-size:13px;margin-bottom:10px;" />
                <button type="submit" name="action" value="reissue" class="btn-form primary" style="width:100%;"
                        onclick="return confirm('Set a new password for <?= h($vendor['first_name']) ?> and let them back in?');">
                    <i class="fas fa-key"></i> Re-issue login
                </button>
            <?php else: ?>
                <input type="text" name="description" maxlength="500"
                       placeholder="<?= $accSusp ? 'Why are they being restored? (kept on the record)' : 'Why are they being suspended? (the vendor reads this)' ?>"
                       value="<?= h((string)($vendor['access_note'] ?? '')) ?>"
                       style="width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:10px;
                              font-family:inherit;font-size:13px;margin-bottom:10px;" />

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" name="action" value="suspend" class="btn-form danger" style="flex:1;min-width:150px;"
                            onclick="return confirm('Suspend <?= h($vendor['first_name']) ?> <?= h($vendor['last_name']) ?>? They will not be able to sign in.');">
                        <i class="fas fa-pause-circle"></i> <?= $accSusp ? 'Suspend again' : 'Suspend' ?>
                    </button>

                    <?php if ($accSusp): ?>
                        <button type="submit" name="action" value="restore" class="btn-form primary" style="flex:1;min-width:150px;"
                                onclick="return confirm('Restore <?= h($vendor['first_name']) ?> <?= h($vendor['last_name']) ?>? They can sign in again.');">
                            <i class="fas fa-unlock"></i> Restore
                        </button>
                    <?php endif; ?>

                    <button type="submit" name="action" value="remove_access" class="btn-form ghost" style="flex:1;min-width:150px;"
                            onclick="return confirm('Remove the login for <?= h($vendor['first_name']) ?> <?= h($vendor['last_name']) ?>?\n\nTheir password is destroyed and the account can never sign in again. Their stall, permit, payments and message history stay.');">
                        <i class="fas fa-trash-can"></i> Remove login
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <div class="modal-footer">
            <a href="admin_notifications.php?vendor=<?= (int)$vendor['id'] ?>#compose" class="btn-form ghost">
                <i class="fas fa-bell"></i> Notify
            </a>
            <?php if ($vendor['status'] !== 'Approved'): ?>
                <form method="post" action="admin_vendors.php" style="flex:1;display:flex;gap:8px;">
                    <input type="hidden" name="action" value="approve" />
                    <input type="hidden" name="id" value="<?= (int)$vendor['id'] ?>" />
                    <button type="submit" class="btn-form primary" style="flex:1;">
                        <i class="fas fa-circle-check"></i> Approve Vendor
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($vendor['status'] !== 'Rejected'): ?>
                <button type="button" class="btn-form danger" onclick="openReject()">
                    <i class="fas fa-circle-xmark"></i> Reject
                </button>
            <?php endif; ?>
            <a href="admin_vendors.php?id=<?= (int)$vendor['id'] ?>&edit=1" class="btn-form ghost">
                <i class="fas fa-pen"></i> Edit
            </a>
            <a href="admin_vendors.php" class="btn-form ghost">
                <i class="fas fa-xmark"></i> Close
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     EDIT / CREATE MODAL
     ============================================================ -->
<?php if ($isNew || (!empty($_GET['edit']) && $vendor)): ?>
<div class="modal-backdrop open" id="editModal">
    <div class="modal wide" onclick="event.stopPropagation()">
        <form method="post" action="admin_vendors.php" style="display:contents;">
            <input type="hidden" name="action" value="save" />
            <input type="hidden" name="id" value="<?= (int)($vendor['id'] ?? 0) ?>" />

            <div class="modal-head">
                <div class="modal-head-icon">
                    <i class="fas <?= $isNew ? 'fa-plus' : 'fa-pen' ?>"></i>
                </div>
                <div class="modal-head-info">
                    <h2><?= $isNew ? 'Add New Vendor' : 'Edit Vendor' ?></h2>
                    <p><?= $isNew ? 'Create a new vendor record' : 'Update this vendor\'s details' ?></p>
                </div>
                <button type="button" class="modal-close" onclick="window.location='admin_vendors.php'"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">

                <!-- Personal -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-user"></i>
                        <div><h3>Personal Information</h3><p>Basic details about the vendor</p></div>
                    </div>
                    <div class="form-grid c3">
                        <div class="field">
                            <label>First Name <span class="req">*</span></label>
                            <input type="text" name="first_name" value="<?= h($vendor['first_name'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" value="<?= h($vendor['middle_name'] ?? '') ?>" />
                        </div>
                        <div class="field">
                            <label>Last Name <span class="req">*</span></label>
                            <input type="text" name="last_name" value="<?= h($vendor['last_name'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Birthdate <span class="req">*</span></label>
                            <input type="date" name="birthdate" value="<?= h($vendor['birthdate'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Gender <span class="req">*</span></label>
                            <select name="gender" required>
                                <option value="">Select</option>
                                <option value="Male"   <?= selected($vendor['gender'] ?? '', 'Male') ?>>Male</option>
                                <option value="Female" <?= selected($vendor['gender'] ?? '', 'Female') ?>>Female</option>
                                <option value="Other"  <?= selected($vendor['gender'] ?? '', 'Other') ?>>Other</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Civil Status <span class="req">*</span></label>
                            <select name="civil_status" required>
                                <option value="">Select</option>
                                <option value="Single"    <?= selected($vendor['civil_status'] ?? '', 'Single') ?>>Single</option>
                                <option value="Married"   <?= selected($vendor['civil_status'] ?? '', 'Married') ?>>Married</option>
                                <option value="Widowed"   <?= selected($vendor['civil_status'] ?? '', 'Widowed') ?>>Widowed</option>
                                <option value="Separated" <?= selected($vendor['civil_status'] ?? '', 'Separated') ?>>Separated</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Contact Number <span class="req">*</span></label>
                            <input type="text" name="contact_number" value="<?= h($vendor['contact_number'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Email</label>
                            <input type="email" name="email" value="<?= h($vendor['email'] ?? '') ?>" />
                        </div>
                        <div class="field full">
                            <label>Address <span class="req">*</span></label>
                            <input type="text" name="address" value="<?= h($vendor['address'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- Business -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-store-alt"></i>
                        <div><h3>Business Information</h3><p>Stall details and products</p></div>
                    </div>
                    <div class="form-grid c2">
                        <div class="field full">
                            <label>Business Name</label>
                            <input type="text" name="business_name" value="<?= h($vendor['business_name'] ?? '') ?>" placeholder="(optional)" />
                        </div>
                        <div class="field">
                            <label>Stall Number <span class="req">*</span></label>
                            <input type="text" name="stall_number" value="<?= h($vendor['stall_number'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Market Section <span class="req">*</span></label>
                            <input type="text" name="market_section" value="<?= h($vendor['market_section'] ?? '') ?>" required />
                        </div>
                        <div class="field full">
                            <label>Products Sold <span class="req">*</span></label>
                            <input type="text" name="product_type" value="<?= h($vendor['product_type'] ?? '') ?>" required />
                        </div>
                        <div class="field">
                            <label>Years in Business</label>
                            <input type="number" name="years_in_business" min="0" max="80" value="<?= (int)($vendor['years_in_business'] ?? 0) ?>" />
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <option value="Pending"  <?= selected($vendor['status'] ?? 'Pending', 'Pending')  ?>>Pending</option>
                                <option value="Approved" <?= selected($vendor['status'] ?? '', 'Approved') ?>>Approved</option>
                                <option value="Rejected" <?= selected($vendor['status'] ?? '', 'Rejected') ?>>Rejected</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- IDs -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-id-card"></i>
                        <div><h3>ID Verification</h3><p>Government-issued ID</p></div>
                    </div>
                    <div class="form-grid c2">
                        <div class="field">
                            <label>ID Type</label>
                            <input type="text" name="id_type" value="<?= h($vendor['id_type'] ?? '') ?>" />
                        </div>
                        <div class="field">
                            <label>ID Number</label>
                            <input type="text" name="id_number" value="<?= h($vendor['id_number'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- Emergency -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-heart-pulse"></i>
                        <div><h3>Emergency Contact</h3><p>Who to contact in emergencies</p></div>
                    </div>
                    <div class="form-grid c2">
                        <div class="field full">
                            <label>Full Name</label>
                            <input type="text" name="emergency_name" value="<?= h($vendor['emergency_name'] ?? '') ?>" />
                        </div>
                        <div class="field">
                            <label>Relationship</label>
                            <input type="text" name="emergency_relationship" value="<?= h($vendor['emergency_relationship'] ?? '') ?>" />
                        </div>
                        <div class="field">
                            <label>Contact Number</label>
                            <input type="text" name="emergency_contact" value="<?= h($vendor['emergency_contact'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- Permit & Payment -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-file-shield"></i>
                        <div><h3>Permit & Payment</h3><p>Compliance tracking</p></div>
                    </div>
                    <div class="form-grid c2">
                        <div class="field">
                            <label>Permit Status</label>
                            <select name="permit_status">
                                <option value="Pending"   <?= selected($vendor['permit_status'] ?? 'Pending', 'Pending') ?>>Pending</option>
                                <option value="Valid"     <?= selected($vendor['permit_status'] ?? '', 'Valid') ?>>Valid</option>
                                <option value="Expired"   <?= selected($vendor['permit_status'] ?? '', 'Expired') ?>>Expired</option>
                                <option value="Suspended" <?= selected($vendor['permit_status'] ?? '', 'Suspended') ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Permit Expiry</label>
                            <input type="date" name="permit_expiry" value="<?= h($vendor['permit_expiry'] ?? '') ?>" />
                        </div>
                        <div class="field">
                            <label>Payment Status</label>
                            <select name="payment_status">
                                <option value="Unpaid"  <?= selected($vendor['payment_status'] ?? 'Unpaid', 'Unpaid') ?>>Unpaid</option>
                                <option value="Partial" <?= selected($vendor['payment_status'] ?? '', 'Partial') ?>>Partial</option>
                                <option value="Paid"    <?= selected($vendor['payment_status'] ?? '', 'Paid') ?>>Paid</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Payment Due Date</label>
                            <input type="date" name="payment_due" value="<?= h($vendor['payment_due'] ?? '') ?>" />
                        </div>
                        <div class="field full">
                            <label>Last Payment Date</label>
                            <input type="date" name="last_payment_date" value="<?= h($vendor['last_payment_date'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- Remarks -->
                <div class="form-section">
                    <div class="form-section-head">
                        <i class="fas fa-note-sticky"></i>
                        <div><h3>Remarks</h3><p>Optional notes visible to the vendor</p></div>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <textarea name="remarks" placeholder="Add any notes about this vendor…"><?= h($vendor['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <a href="admin_vendors.php" class="btn-form ghost">
                    <i class="fas fa-xmark"></i> Cancel
                </a>
                <button type="submit" class="btn-form primary" style="margin-left:auto;">
                    <i class="fas fa-floppy-disk"></i>
                    <?= $isNew ? 'Create Vendor' : 'Save Changes' ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     REJECT REMARKS MODAL
     ============================================================ -->
<?php if ($vendor && empty($_GET['edit'])): ?>
<div class="modal-backdrop" id="rejectModal">
    <div class="modal" onclick="event.stopPropagation()">
        <form method="post" action="admin_vendors.php">
            <input type="hidden" name="action" value="reject" />
            <input type="hidden" name="id" value="<?= (int)$vendor['id'] ?>" />

            <div class="modal-head">
                <div class="modal-head-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
                    <i class="fas fa-circle-xmark"></i>
                </div>
                <div class="modal-head-info">
                    <h2>Reject Vendor</h2>
                    <p>Provide a reason (optional)</p>
                </div>
                <button type="button" class="modal-close" onclick="closeReject()"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label>Remarks / Reason</label>
                        <textarea name="remarks" placeholder="e.g. Incomplete documents, invalid stall number…"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-form ghost" onclick="closeReject()">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn-form danger" style="margin-left:auto;">
                    <i class="fas fa-circle-xmark"></i> Confirm Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     DELETE CONFIRM MODAL
     ============================================================ -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal" onclick="event.stopPropagation()" style="max-width:420px;">
        <form method="post" action="admin_vendors.php">
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="id" id="deleteId" value="" />

            <div class="modal-head">
                <div class="modal-head-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="modal-head-info">
                    <h2>Delete Vendor?</h2>
                    <p>This action cannot be undone</p>
                </div>
                <button type="button" class="modal-close" onclick="closeDelete()"><i class="fas fa-xmark"></i></button>
            </div>

            <div class="modal-body">
                <p style="font-size:14px;color:var(--ink-soft);line-height:1.6;">
                    You're about to permanently delete
                    <strong id="deleteName" style="color:var(--ink);">this vendor</strong>.
                    All associated data will be removed.
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
function openReject() {
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() {
    document.getElementById('rejectModal').classList.remove('open');
}

/* Close on backdrop / escape */
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
        const rej = document.getElementById('rejectModal');
        if (del.classList.contains('open')) del.classList.remove('open');
        else if (rej && rej.classList.contains('open')) rej.classList.remove('open');
    }
});
</script>

</body>
</html>