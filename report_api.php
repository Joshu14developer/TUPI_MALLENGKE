<?php
/* ============================================================
   VENDOR REPORT API
   ------------------------------------------------------------
   The endpoint the vendor portal posts to. Only a signed-in vendor
   can file, and the reporter is read from the session, never from
   the request.

   Actions:
     create – report another vendor (POST)
     reasons – the reason list, for the modal

   Answers are always JSON: {ok: bool, error?: string, id?: int}
   ============================================================ */

session_start();
$conn = require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/reports.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function report_jexit($data) {
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create':
        $reporter = report_reporter($conn);
        if (!$reporter) {
            http_response_code(401);
            report_jexit(['ok' => false, 'error' => 'Please sign in before reporting a vendor.']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            report_jexit(['ok' => false, 'error' => 'Use POST to file a report.']);
        }

        $res = report_create(
            $conn,
            $reporter,
            (int)($_POST['vendor_id'] ?? 0),
            (string)($_POST['reason'] ?? ''),
            (string)($_POST['details'] ?? '')
        );

        if (!$res['ok']) {
            http_response_code(422);
            report_jexit(['ok' => false, 'error' => $res['error']]);
        }
        report_jexit(['ok' => true, 'id' => $res['id']]);

    case 'reasons':
        $reporter = report_reporter($conn);
        if (!$reporter) {
            http_response_code(401);
            report_jexit(['ok' => false, 'error' => 'Not signed in']);
        }
        $out = [];
        foreach (report_reasons() as $key => $meta) {
            $out[] = ['value' => $key, 'label' => $meta['label']];
        }
        report_jexit(['ok' => true, 'reasons' => $out]);

    default:
        http_response_code(400);
        report_jexit(['ok' => false, 'error' => 'Unknown action.']);
}