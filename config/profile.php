<?php
/* ============================================================
   PROFILE PHOTOS
   ------------------------------------------------------------
   Shared by the vendor, customer and admin portals. A photo is
   stored under assets/uploads/avatars and only the relative path
   is written to the database, so moving the app does not break
   existing pictures.

   The uploader in assets/profile-avatar.js sends the same form to
   php/profile_upload.php. edit_profile.php also accepts a normal
   form post as a no-JavaScript fallback.
   ============================================================ */

const PROFILE_AVATAR_REL_DIR = 'assets/uploads/avatars';
const PROFILE_AVATAR_MAX_BYTES = 3145728; // 3 MB

/** One CSRF token per signed-in browser session. */
function profile_avatar_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['profile_avatar_token']) || !is_string($_SESSION['profile_avatar_token'])) {
        try {
            $_SESSION['profile_avatar_token'] = bin2hex(random_bytes(24));
        } catch (Throwable $e) {
            $_SESSION['profile_avatar_token'] = sha1(uniqid('profile-avatar-', true));
        }
    }
    return $_SESSION['profile_avatar_token'];
}

function profile_avatar_token_valid($token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    if (!is_string($token) || $token === '') return false;
    $known = $_SESSION['profile_avatar_token'] ?? '';
    return is_string($known) && $known !== '' && hash_equals($known, $token);
}

/**
 * Accept only a filename inside our own avatar folder. This keeps a
 * corrupted database value from turning into path traversal.
 */
function profile_avatar_clean_path(?string $path): string {
    $path = str_replace('\\', '/', trim((string)$path));
    $prefix = PROFILE_AVATAR_REL_DIR . '/';
    if ($path === '' || strpos($path, $prefix) !== 0) return '';

    $name = substr($path, strlen($prefix));
    if ($name === '' || $name !== basename($name)) return '';
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) return '';
    return $prefix . $name;
}

/** Public URL for a stored avatar, or '' when the file is missing. */
function profile_avatar_url(?string $path, string $base = ''): string {
    $clean = profile_avatar_clean_path($path);
    if ($clean === '') return '';

    $file = dirname(__DIR__) . '/' . $clean;
    if (!is_file($file)) return '';

    $base = $base === '' ? '' : rtrim($base, '/') . '/';
    return $base . $clean;
}

/** True when the browser actually sent a file with the request. */
function profile_avatar_selected($file): bool {
    return is_array($file)
        && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function profile_avatar_upload_error(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That photo is too large. Choose an image under 3 MB.';
        case UPLOAD_ERR_PARTIAL:
            return 'The photo upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'Choose a photo to upload.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'The server could not save the photo. Please try again.';
        default:
            return 'The photo could not be uploaded. Please try again.';
    }
}

/**
 * Validate and move one uploaded image.
 *
 * @return string|null Stored relative path, or null on failure.
 */
function profile_store_avatar(array $file, string $prefix, ?string &$error = null): ?string {
    $error = null;
    $code  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code === UPLOAD_ERR_NO_FILE) {
        $error = profile_avatar_upload_error($code);
        return null;
    }
    if ($code !== UPLOAD_ERR_OK) {
        $error = profile_avatar_upload_error($code);
        return null;
    }

    $tmp  = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $error = 'The server did not receive a valid upload.';
        return null;
    }
    if ($size <= 0 || $size > PROFILE_AVATAR_MAX_BYTES) {
        $error = 'That photo is too large. Choose an image under 3 MB.';
        return null;
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($tmp);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($tmp);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        $error = 'Please choose a JPG, PNG, WebP or GIF image.';
        return null;
    }

    $info = @getimagesize($tmp);
    if (!$info || empty($info[0]) || empty($info[1])) {
        $error = 'That file is not a readable image.';
        return null;
    }
    if ((int)$info[0] < 32 || (int)$info[1] < 32) {
        $error = 'Choose an image at least 32 x 32 pixels.';
        return null;
    }

    $dir = dirname(__DIR__) . '/' . PROFILE_AVATAR_REL_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $error = 'The avatar folder is not writable. Ask the administrator to check permissions.';
        return null;
    }

    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $prefix));
    $slug = trim($slug, '_');
    if ($slug === '') $slug = 'avatar';

    $name = $slug . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($tmp, $target)) {
        $error = 'The server could not save the photo. Please try again.';
        return null;
    }

    return PROFILE_AVATAR_REL_DIR . '/' . $name;
}

/** Best-effort deletion of a previous avatar. */
function profile_delete_avatar(?string $path): void {
    $clean = profile_avatar_clean_path($path);
    if ($clean === '') return;

    $file = dirname(__DIR__) . '/' . $clean;
    if (is_file($file)) @unlink($file);
}
