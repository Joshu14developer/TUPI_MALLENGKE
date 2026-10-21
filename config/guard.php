<?php
/* ============================================================
   PAGE GUARD
   ------------------------------------------------------------
   One call at the top of every vendor page:

       require_once __DIR__ . '/../config/guard.php';
       guard_vendor($conn);

   It does what each page used to do by hand — send anonymous visitors
   to the login form — and adds the second half: a vendor the market
   office has suspended, or whose login was removed, is signed out on
   their very next page load and shown why instead of a bare error.

   Include this file anywhere a $conn (mysqli) is available.
   ============================================================ */

require_once __DIR__ . '/reports.php';

/**
 * Path to a file inside the app, relative to the page asking for it.
 * Pages live at the root and one directory down, so neither can hard
 * code the other's prefix.
 */
function guard_url($target) {
    $root = str_replace('\\', '/', dirname(__DIR__));
    $here = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (!$here) return $target;

    $path = str_replace('\\', '/', $here);
    $rel  = str_replace($root, '', dirname($path) . '/' . $target);
    return '/' . ltrim(str_replace('\\', '/', $rel), '/');
}

/** Forget everything that could sign this browser back in. */
function guard_forget_vendor($conn) {
    $userId = (int)($_SESSION['user_id'] ?? 0);

    /* Revoke the stored token as well as the cookie, otherwise the
       "remember me" cookie would walk straight back in. */
    if ($userId > 0 && $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
    ]);

    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['email']);
}

/**
 * The guard itself. Returns normally when the visitor may carry on.
 *
 * @param mysqli $conn
 * @return void never returns when it redirects
 */
function guard_vendor($conn, $loginPath = 'php/login.php') {
    $loginUrl = guard_url($loginPath);

    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $loginUrl);
        exit;
    }

    /* A vendor with no vendor row yet (mid-registration) has nothing
       to sanction yet, so the page is allowed to do its own checks. */
    $vendorId = 0;
    if ($stmt = $conn->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1")) {
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $vendorId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }
    if ($vendorId <= 0) return;

    $block = vendor_access_block($conn, $vendorId);
    if (!$block) return;

    guard_forget_vendor($conn);
    $_SESSION['access_block'] = $block;

    header('Location: ' . guard_url('php/suspended.php'));
    exit;
}

/**
 * The same rule for the JSON endpoints, which must not be handed an
 * HTML page. Answers 401 when nobody is signed in and 403 when the
 * vendor has been blocked.
 *
 * @return void never returns when it refuses
 */
function guard_vendor_json($conn) {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not signed in']);
        exit;
    }

    $vendorId = 0;
    if ($stmt = $conn->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1")) {
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $vendorId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }
    if ($vendorId <= 0) return;

    $block = vendor_access_block($conn, $vendorId);
    if (!$block) return;

    guard_forget_vendor($conn);
    $_SESSION['access_block'] = $block;

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => false,
        'blocked' => true,
        'kind'    => $block['kind'],
        'error'   => $block['description'],
    ]);
    exit;
}