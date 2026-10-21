<?php
/* ============================================================
   NOTIFICATION API  (vendor side)
   ------------------------------------------------------------
   POST actions:
     mark_read      { id }
     mark_all_read  {}
     delete         { id }
     clear_all      {}
     count          {}
   Returns JSON.
   ============================================================ */
session_start();
header('Content-Type: application/json; charset=utf-8');

$conn = require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/guard.php';
require_once __DIR__ . '/../config/notify.php';

/* Signs the vendor out on 401, and answers a blocked vendor with the
   reason instead of a redirect to an HTML page. */
guard_vendor_json($conn);

/* Resolve the vendor behind this session */
$stmt = $conn->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$row  = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'No vendor record linked to this account']);
    exit;
}
$vendorId = (int)$row['id'];

$action = $_POST['action'] ?? ($_GET['action'] ?? 'count');

switch ($action) {

    case 'mark_read':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        notif_mark_read($conn, $vendorId, $id);
        echo json_encode([
            'ok'     => true,
            'unread' => notif_unread_count($conn, $vendorId),
        ]);
        exit;

    case 'mark_all_read':
        $affected = notif_mark_all_read($conn, $vendorId);
        echo json_encode([
            'ok'       => true,
            'updated'  => $affected,
            'unread'   => 0,
        ]);
        exit;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        notif_delete($conn, $vendorId, $id);
        echo json_encode([
            'ok'     => true,
            'unread' => notif_unread_count($conn, $vendorId),
        ]);
        exit;

    case 'clear_all':
        $removed = notif_clear_all($conn, $vendorId);
        echo json_encode(['ok' => true, 'removed' => $removed, 'unread' => 0]);
        exit;

    case 'count':
    default:
        echo json_encode([
            'ok'     => true,
            'unread' => notif_unread_count($conn, $vendorId),
        ]);
        exit;
}
