<?php
/* ============================================================
   SMS CONFIGURATION
   ------------------------------------------------------------
   Every setting the SMS gateway needs, in one place. Nothing
   else in the app may hard-code a login, a password or a number
   format — it all comes from here.

   Provider: "SMS Gateway for Android" (capcom6/android-sms-gateway)
   A cloud-hosted Android phone acts as the modem, so the login and
   password below are the credentials of that cloud app.

   Each constant can be overridden by an environment variable of the
   same name, which is what makes a deployment configurable without
   touching the code (see sms_setting() in config/sms.php).

   Include this file anywhere; it only declares constants.
   ============================================================ */

/* ---------- Master switch ----------
   Turn this to false and every SMS call short-circuits: the admin
   console keeps working, it just stops texting vendors. */
define('SMS_ENABLED', true);

/* ---------- Gateway credentials ---------- */
define('SMS_GATEWAY_LOGIN',    'CBR4R4');
define('SMS_GATEWAY_PASSWORD', 'joshuatumogbong141526');

/* API base. Only change this if you self-host the gateway. */
define('SMS_GATEWAY_URL', 'https://sms.capcom.me/api/3rdparty/v1');

/* ---------- Encryption (optional) ----------
   When enabled, the message body and the recipient numbers are
   AES-256 encrypted on the way out and decrypted on the delivery
   report. The passphrase must be identical to the one configured
   in the cloud app, otherwise delivery reports come back garbled. */
define('SMS_USE_ENCRYPTION', false);
define('SMS_ENCRYPTION_KEY', '');

/* ---------- Philippine phone numbers ----------
   Contact numbers are stored exactly as the vendor typed them, so
   the helper normalises them to E.164 before sending.

   SMS_DEFAULT_COUNTRY_CODE  dialled prefix without the '+'
   SMS_TRIM_PREFIX           leading digit of a local mobile number
                            ('9' for 0917… / 917…, '0' if you also
                            accept the 0-prefixed form)
   SMS_REQUIRE_PH_MOBILE     when true only +639XXXXXXXXX is
                            accepted; set false to also allow
                            landlines and other E.164 numbers */
define('SMS_DEFAULT_COUNTRY_CODE', '63');
define('SMS_TRIM_PREFIX',        '9');
define('SMS_REQUIRE_PH_MOBILE',  true);

/* ---------- Message body ----------
   SMS_SENDER_PREFIX is prepended to every message so a vendor
   recognises the market office on their phone. Leave it empty to
   send the text verbatim.

   SMS_MAX_LENGTH counts the finished body, prefix included. One
   SMS part carries 160 characters on the GSM alphabet and 70 on
   Unicode; the gateway re-segments longer text itself, so this cap
   only exists to stop a whole article from being blasted out. */
define('SMS_SENDER_PREFIX', 'MALLENGKE');
define('SMS_MAX_LENGTH',     320);

/* ---------- Delivery options ---------- */

/* How many recipients travel in a single API call. The gateway
   accepts a list, but one bad number in a large batch makes the
   whole response harder to read, so we send in small groups. */
define('SMS_BATCH_SIZE', 10);

/* Seconds the modem keeps retrying before it gives up. */
define('SMS_TTL', 86400);

/* Ask the provider for a delivery report per message. */
define('SMS_WITH_DELIVERY_REPORT', true);

/* ---------- Diagnostics ----------
   Set SMS_LOG_FILE to a writable path to append one line per
   outbound message. Leave empty to stay silent. */
define('SMS_LOG_FILE', '');