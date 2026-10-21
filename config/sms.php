<?php
/* ============================================================
   SMS HELPERS
   ------------------------------------------------------------
   Thin, guarded wrapper around the `capcom6/android-sms-gateway`
   Composer client that ships in sms/vendor/.

   Responsibilities
     · load the Composer autoloader once per request
     · read its settings from config/sms_config.php
     · normalise loosely-typed contact numbers to E.164
     · send one message or a batch, and always return a result array
       instead of throwing, so a gateway outage can never take down
       the page that triggered the send
     · resolve the vendor audience of an announcement and text them

   Public API
     sms_settings()             resolved settings
     sms_is_enabled()           is the gateway usable right now?
     sms_normalize_number($raw) → "+639171234567" or null
     sms_format_body($text)     prefix + clamp, ready to send
     sms_preview($text)         what the vendor will actually see
     sms_send($recipients, $text)
     sms_send_one($number, $text)
     sms_vendor_numbers($conn, $vendorIds)
     sms_send_vendors($conn, $vendorIds, $text)
     sms_send_announcement($conn, $announcementId)
     sms_log_state($messageId)

   Include this file anywhere. It is self-contained: it does not
   need a $conn, and it never requires the notification helpers.
   ============================================================ */

if (!defined('SMS_GATEWAY_LOGIN')) {
    require_once __DIR__ . '/sms_config.php';
}

/* Absolute path to the Composer autoloader generated for the
   gateway client (sms/vendor/autoload.php). */
if (!defined('SMS_AUTOLOAD_PATH')) {
    define('SMS_AUTOLOAD_PATH', dirname(__DIR__) . '/sms/vendor/autoload.php');
}

/* ============================================================
   BOOTSTRAP
   ============================================================ */

/**
 * Load the Composer autoloader exactly once.
 *
 * The gateway client resolves its PSR-18 HTTP implementation through
 * php-http/discovery, so the full vendor tree (symfony/http-client,
 * nyholm/psr7) has to be registered — not just the package folder.
 *
 * @return bool true when the gateway classes are usable
 */
function sms_boot() {
    static $booted = null;
    if ($booted !== null) return $booted;

    if (!is_file(SMS_AUTOLOAD_PATH)) {
        sms_set_error('Composer autoloader not found at ' . SMS_AUTOLOAD_PATH);
        return $booted = false;
    }

    require_once SMS_AUTOLOAD_PATH;

    $booted = class_exists(\AndroidSmsGateway\Client::class);
    if (!$booted) sms_set_error('AndroidSmsGateway\\Client was not autoloaded.');
    return $booted;
}

/** Remember the last failure so a caller can report it. */
function sms_set_error($message) {
    $GLOBALS['sms_last_error'] = (string)$message;
    sms_log('ERROR: ' . $message);
    return false;
}

/** The last error recorded during this request, if any. */
function sms_last_error() {
    return (string)($GLOBALS['sms_last_error'] ?? '');
}

/** Append one line to SMS_LOG_FILE when logging is switched on. */
function sms_log($line) {
    $file = (string)SMS_LOG_FILE;
    if ($file === '') return false;
    @file_put_contents(
        $file,
        '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    return true;
}

/* ============================================================
   SETTINGS
   ============================================================ */

/**
 * Read one setting: the environment wins over sms_config.php, which
 * lets a deployment inject credentials without editing the file.
 */
function sms_setting($name, $default = null) {
    $fromEnv = getenv($name);
    if ($fromEnv !== false && $fromEnv !== '') {
        return match (true) {
            is_bool($default)                    => filter_var($fromEnv, FILTER_VALIDATE_BOOLEAN),
            is_int($default)                     => (int)$fromEnv,
            is_float($default)                   => (float)$fromEnv,
            default                              => $fromEnv,
        };
    }
    return defined($name) ? constant($name) : $default;
}

/**
 * All settings in one array. This is the only place that reads the
 * constants, so the rest of the app stays free of magic values.
 *
 * @return array
 */
function sms_settings() {
    static $settings = null;
    if ($settings !== null) return $settings;

    $settings = [
        'enabled'        => (bool)sms_setting('SMS_ENABLED', true),
        'login'          => (string)sms_setting('SMS_GATEWAY_LOGIN', ''),
        'password'       => (string)sms_setting('SMS_GATEWAY_PASSWORD', ''),
        'url'            => rtrim((string)sms_setting('SMS_GATEWAY_URL', ''), '/'),
        'use_encryption' => (bool)sms_setting('SMS_USE_ENCRYPTION', false),
        'encryption_key' => (string)sms_setting('SMS_ENCRYPTION_KEY', ''),

        'country_code'   => preg_replace('/\D+/', '', (string)sms_setting('SMS_DEFAULT_COUNTRY_CODE', '63')),
        'trim_prefix'    => preg_replace('/\D+/', '', (string)sms_setting('SMS_TRIM_PREFIX', '9')),
        'ph_mobile_only' => (bool)sms_setting('SMS_REQUIRE_PH_MOBILE', true),

        'sender_prefix'  => trim((string)sms_setting('SMS_SENDER_PREFIX', '')),
        'max_length'     => max(1, (int)sms_setting('SMS_MAX_LENGTH', 320)),

        'batch_size'     => max(1, (int)sms_setting('SMS_BATCH_SIZE', 10)),
        'ttl'            => (int)sms_setting('SMS_TTL', 86400),
        'delivery_report'=> (bool)sms_setting('SMS_WITH_DELIVERY_REPORT', true),
    ];

    return $settings;
}

/**
 * Is the gateway configured well enough to send?
 *
 * @return bool
 */
function sms_is_enabled() {
    $s = sms_settings();
    if (!$s['enabled']) return false;
    if ($s['login'] === '' || $s['password'] === '' || $s['url'] === '') {
        sms_set_error('SMS gateway credentials are not configured.');
        return false;
    }
    if ($s['use_encryption'] && $s['encryption_key'] === '') {
        sms_set_error('SMS_USE_ENCRYPTION is on but SMS_ENCRYPTION_KEY is empty.');
        return false;
    }
    return sms_boot();
}

/** Empty result shape, so every caller can read the same keys. */
function sms_result(array $overrides = []) {
    return $overrides + [
        'ok'          => false,
        'sent'        => 0,
        'failed'      => 0,
        'skipped'     => 0,
        'recipients'  => 0,
        'message_ids' => [],
        'error'       => '',
    ];
}

/* ============================================================
   PHONE NUMBERS
   ============================================================ */

/**
 * Is this an E.164 number the gateway will accept?
 *
 * With SMS_REQUIRE_PH_MOBILE on (the default) only Philippine mobile
 * numbers qualify, which matches how vendors are registered.
 *
 * @param string $number expected "+639171234567"
 */
function sms_is_valid_number($number) {
    $number = (string)$number;
    if ($number === '' || $number[0] !== '+') return false;

    $digits = substr($number, 1);
    if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) return false;

    if (sms_settings()['ph_mobile_only']) {
        return (bool)preg_match('/^63' . sms_settings()['trim_prefix'] . '\d{9}$/', $digits);
    }
    return true;
}

/**
 * Normalise a stored contact number into E.164.
 *
 * Vendors type their number however they like, and the column only
 * validates /^[0-9+\-\s]{7,15}$/, so everything below shows up in
 * practice. Each shape is mapped onto +639XXXXXXXXX:
 *
 *     "09171234567"  0-prefixed local   → +639171234567
 *     "9171234567"   bare mobile        → +639171234567
 *     "639171234567" with the prefix    → +639171234567
 *     "+63 917 123 4567"                → +639171234567
 *     "0063 917…"    international dial → +639171234567
 *
 * @return string|null E.164 number, or null when it cannot be read
 */
function sms_normalize_number($raw) {
    $s = trim((string)$raw);
    if ($s === '') return null;

    /* 00 is the international access prefix. */
    if (strpos($s, '00') === 0) $s = '+' . substr($s, 2);

    $hasPlus = strpos($s, '+') === 0;
    $digits  = preg_replace('/\D+/', '', $s);
    if ($digits === null || $digits === '') return null;

    $cfg  = sms_settings();
    $cc   = $cfg['country_code'];
    $trim = $cfg['trim_prefix'];

    if ($hasPlus) {
        $e164 = '+' . $digits;
    } elseif ($trim !== '' && strlen($digits) === 11 && $digits[0] === '0' && $digits[1] === $trim) {
        $e164 = '+' . $cc . substr($digits, 1);
    } elseif ($trim !== '' && strlen($digits) === 10 && $digits[0] === $trim) {
        $e164 = '+' . $cc . $digits;
    } elseif ($cc !== '' && strpos($digits, $cc) === 0 && strlen($digits) >= 10) {
        $e164 = '+' . $digits;
    } else {
        /* Last resort: a bare local number such as "171234567". */
        $e164 = '+' . $cc . ltrim($digits, '0');
    }

    return sms_is_valid_number($e164) ? $e164 : null;
}

/**
 * Vendor ids → ['vendor id' => E.164 number], skipping unreadable
 * numbers and rows that have none at all.
 *
 * @param mysqli $conn
 * @param int[]  $vendorIds
 * @return array<int,string>
 */
function sms_vendor_numbers($conn, $vendorIds) {
    $ids = [];
    foreach ((array)$vendorIds as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = $id;
    }
    if (!$ids) return [];

    /* IN (…) built from integer-cast ids, so this is safe. */
    $in       = implode(',', $ids);
    $rejected = 0;
    $numbers  = [];

    if ($res = $conn->query(
        "SELECT id, contact_number FROM vendors WHERE id IN ($in)"
    )) {
        while ($r = $res->fetch_assoc()) {
            $e164 = sms_normalize_number($r['contact_number'] ?? '');
            if ($e164 === null) { $rejected++; continue; }
            $numbers[(int)$r['id']] = $e164;
        }
    }

    if ($rejected > 0) {
        sms_log($rejected . ' vendor(s) skipped: contact number missing or not a mobile line');
    }
    return $numbers;
}

/* ============================================================
   MESSAGE BODY
   ============================================================ */

/**
 * Turn raw text into the exact body that goes over the air:
 * the sender prefix is prepended and the result is clamped to
 * SMS_MAX_LENGTH so a long announcement cannot turn into a bill of
 * ten separate messages.
 */
function sms_format_body($text, array $options = []) {
    $cfg   = sms_settings();
    $text  = trim(preg_replace('/[ \t]+/', ' ', trim((string)$text)));
    $text  = preg_replace('/\n{3,}/', "\n\n", $text);

    $prefix = trim((string)($options['prefix'] ?? $cfg['sender_prefix']));
    if ($prefix !== '') $text = $prefix . ': ' . $text;

    return sms_clamp($text, (int)($options['max_length'] ?? $cfg['max_length']));
}

/** Shorten text to $max characters, marking that it was cut. */
function sms_clamp($text, $max) {
    if ($max < 1) $max = 1;
    if (mb_strlen($text) <= $max) return $text;
    return rtrim(mb_substr($text, 0, $max - 1)) . '…';
}

/**
 * Exactly what the vendor will read on their phone — used by the
 * admin composer to show a live preview next to the textarea.
 */
function sms_preview($text, array $options = []) {
    return sms_format_body($text, $options);
}

/* ============================================================
   SENDING
   ============================================================ */

/**
 * One authenticated gateway client per request.
 *
 * @return \AndroidSmsGateway\Client|null
 */
function sms_client() {
    static $client = null;
    static $failed = false;
    if ($client !== null || $failed) return $client;

    if (!sms_boot()) { $failed = true; return null; }

    $cfg = sms_settings();

    try {
        /* Everything past the credentials is optional; nulls let the
           library run its own discovery for the HTTP client. */
        $encryptor = ($cfg['use_encryption'] && $cfg['encryption_key'] !== '')
            ? new \AndroidSmsGateway\Encryptor($cfg['encryption_key'])
            : null;

        $client = new \AndroidSmsGateway\Client(
            $cfg['login'],
            $cfg['password'],
            $cfg['url'],
            null,
            $encryptor
        );
    } catch (Throwable $e) {
        /* Missing PSR-18 implementation, bad URL, … */
        sms_set_error('Could not build the gateway client: ' . $e->getMessage());
        $failed = true;
        return null;
    }

    return $client;
}

/**
 * Send one message to a list of recipients.
 *
 * Recipients are normalised here, invalid ones are counted as
 * skipped, and the rest travel in batches of SMS_BATCH_SIZE. A
 * batch that fails does not abort the remaining batches, so one
 * rejection cannot silently swallow the whole announcement.
 *
 * @param string[]|string $recipients one number, or a list
 * @param string          $text
 * @param array           $options    prefix / max_length overrides
 * @return array  see sms_result()
 */
function sms_send($recipients, $text, array $options = []) {
    $result = sms_result();
    if (!sms_is_enabled()) {
        $result['error'] = sms_last_error() ?: 'SMS is disabled.';
        return $result;
    }

    $body = sms_format_body($text, $options);
    if ($body === '') {
        $result['error'] = 'Nothing to send: the message body is empty.';
        return $result;
    }

    /* Normalise and de-duplicate — the same number may appear twice
       when an audience spans vendors who share a line. */
    $numbers = [];
    foreach ((array)$recipients as $raw) {
        $e164 = sms_normalize_number($raw);
        if ($e164 === null) { $result['skipped']++; continue; }
        $numbers[$e164] = $e164;
    }
    $result['recipients'] = count($numbers);

    if (!$numbers) {
        $result['error'] = $result['skipped'] > 0
            ? 'No valid mobile number in the recipient list (' . $result['skipped'] . ' skipped).'
            : 'No recipients to send to.';
        return $result;
    }

    $client = sms_client();
    if ($client === null) {
        $result['error'] = sms_last_error() ?: 'Gateway client unavailable.';
        return $result;
    }

    $cfg     = sms_settings();
    $batches = array_chunk(array_values($numbers), $cfg['batch_size']);
    $errors  = [];

    foreach ($batches as $batch) {
        try {
            $message = new \AndroidSmsGateway\Domain\Message(
                $body,
                $batch,
                null,
                $cfg['ttl'] > 0 ? $cfg['ttl'] : null,
                null,
                $cfg['delivery_report']
            );

            $state = $client->Send($message);

            $id = (string)$state->ID();
            if ($id !== '') $result['message_ids'][] = $id;

            /* The API reports per recipient, so one handset that is
               offline shows up as Failed instead of hiding the rest. */
            $batchFailed = 0;
            foreach ($state->Recipients() as $recipient) {
                if ((string)$recipient->State() !== 'Failed') continue;
                $batchFailed++;
                $errors[] = trim((string)$recipient->PhoneNumber())
                    . ' rejected'
                    . ($recipient->Error() ? ': ' . $recipient->Error() : '');
            }
            /* No per-recipient detail: fall back to the batch verdict. */
            if (!$state->Recipients()) {
                $batchFailed = ((string)$state->State() === 'Failed') ? count($batch) : 0;
            }

            $result['sent']   += count($batch) - $batchFailed;
            $result['failed'] += $batchFailed;

            sms_log('sent id=' . $id . ' state=' . (string)$state->State()
                  . ' to=' . count($batch) . ' failed=' . $batchFailed);

        } catch (Throwable $e) {
            $result['failed'] += count($batch);
            $errors[] = $e->getMessage();
            sms_log('batch error: ' . $e->getMessage());
        }
    }

    $result['ok']       = $result['sent'] > 0;
    $result['error']    = $errors ? implode('; ', array_slice(array_unique($errors), 0, 3)) : '';
    return $result;
}

/**
 * Send to a single number. Thin wrapper so callers that already hold
 * a contact number do not have to build a list.
 *
 * @return array  see sms_result()
 */
function sms_send_one($number, $text, array $options = []) {
    return sms_send([$number], $text, $options);
}

/**
 * Ask the provider how a message fared.
 *
 * @return array ['state' => string, 'recipients' => …] or ['error' => …]
 */
function sms_log_state($messageId) {
    $messageId = (string)$messageId;
    if ($messageId === '') return ['error' => 'No message id given.'];

    $client = sms_client();
    if ($client === null) return ['error' => sms_last_error() ?: 'Gateway client unavailable.'];

    try {
        $state = $client->GetState($messageId);
        $out   = [
            'state'      => (string)$state->State(),
            'recipients' => [],
            'error'      => '',
        ];
        foreach ($state->Recipients() as $r) {
            $out['recipients'][] = ['phone' => (string)$r->PhoneNumber(), 'state' => (string)$r->State()];
        }
        return $out;
    } catch (Throwable $e) {
        return ['state' => '', 'recipients' => [], 'error' => $e->getMessage()];
    }
}

/* ============================================================
   VENDOR AUDIENCES
   ------------------------------------------------------------
   The one place a "text these vendors" request turns into real
   phone numbers. Both the bulletin board (announcements) and the
   private notices in config/notify.php come through here, so they
   skip and count vendors the same way.
   ============================================================ */

/**
 * Text a list of vendors.
 *
 * Numbers come from vendors.contact_number, so a vendor whose line
 * is missing or is not a mobile number is never handed to the
 * gateway: it counts as skipped rather than failed, because nothing
 * was rejected by the provider either.
 *
 * @param mysqli  $conn
 * @param int[]   $vendorIds
 * @param string  $text    message as typed, before prefix + clamp
 * @param array   $options prefix / max_length overrides
 * @return array  see sms_result()
 */
function sms_send_vendors($conn, $vendorIds, $text, array $options = []) {
    $ids = [];
    foreach ((array)$vendorIds as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = $id;
    }
    if (!$ids) {
        return sms_result(['error' => 'No vendor to text.']);
    }

    $numbers = sms_vendor_numbers($conn, $ids);
    $result  = sms_send(array_values($numbers), $text, $options);

    $result['skipped'] += max(0, count($ids) - count($numbers));
    return $result;
}

/* ============================================================
   ANNOUNCEMENTS
   ------------------------------------------------------------
   Bridge between the bulletin board and the gateway. The audience
   rules live in config/notify.php (announcement_recipients) — this
   only reuses them and adds the text layer, so the vendor receives
   exactly the announcement that was published.
   ============================================================ */

/**
 * Text an announcement's audience.
 *
 * @param mysqli $conn
 * @param int    $announcementId
 * @return array  see sms_result(), plus 'enabled'
 */
function sms_send_announcement($conn, $announcementId) {
    $announcementId = (int)$announcementId;
    $result = sms_result(['enabled' => sms_is_enabled()]);

    if ($announcementId <= 0) {
        $result['error'] = 'Invalid announcement id.';
        return $result;
    }

    if (!isset($announcement_recipients)) {
        require_once __DIR__ . '/notify.php';
    }

    if (!$result['enabled']) {
        $result['error'] = sms_last_error() ?: 'SMS is disabled.';
        return $result;
    }

    /* The announcement itself is the message — same title, same body. */
    if (!$stmt = $conn->prepare("SELECT title, body, audience, section FROM announcements WHERE id = ? LIMIT 1")) {
        $result['error'] = 'Could not load announcement #' . $announcementId . '.';
        return $result;
    }
    $stmt->bind_param("i", $announcementId);
    $stmt->execute();
    $ann = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ann) {
        $result['error'] = 'Announcement #' . $announcementId . ' no longer exists.';
        return $result;
    }

    $vendorIds = announcement_recipients($conn, (string)$ann['audience'], (string)($ann['section'] ?? ''));
    if (!$vendorIds) {
        $result['error'] = 'No vendor matches that audience.';
        return $result;
    }

    /* The announcement itself is the message — same title, same body. */
    $text   = trim((string)$ann['title']) . "\n" . trim((string)$ann['body']);
    $result = sms_send_vendors($conn, $vendorIds, $text) + ['enabled' => true];

    sms_log('announcement #' . $announcementId . ' sms=' . json_encode([
        'sent' => $result['sent'], 'failed' => $result['failed'], 'skipped' => $result['skipped'],
    ]));

    return $result;
}