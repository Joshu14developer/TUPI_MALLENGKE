<?php
/* ============================================================
   APPLY FOR A STALL
   ------------------------------------------------------------
   The vendor's own page for asking the market office for a stall.

   This is deliberately its own file. php/stall_map.php is the map:
   what is free, who is where. This is the request: two ways to put a
   stall in front of the office, and the conversation that follows.

      Apply                a structured request the office reads on its
                           own stall map, and can grant with one click
      Write to the office  a conversation, with a letter already
                           drafted, for anything the form cannot say

   Both file against the same tables and both go through
   config/stalls.php and config/adminmsg.php, so nothing about the
   decision lives here. A vendor still never assigns themselves.
   ============================================================ */

$conn = require __DIR__ . '/../config/db.php';
session_start();

require_once __DIR__ . '/../config/guard.php';
guard_vendor($conn, 'login.php');

require_once __DIR__ . '/../config/stalls.php';
require_once __DIR__ . '/../config/adminmsg.php';

$me = stall_vendor_from_session($conn, $_SESSION['user_id']);

$layout   = stall_layout();
$occupied = stall_occupancy($conn);
$myId     = $me ? (int)$me['id'] : 0;

/* A stall tapped on the map arrives as a query string, so the choice
   survives the move from one page to the other. */
$preStall = strtoupper(trim((string)($_GET['stall'] ?? '')));
if (!stall_exists($preStall)) $preStall = '';

/* ---- ask the office for a stall ----
   This files a request. It never claims one: only stall_assign() in
   config/stalls.php writes vendors.stall_number, and only the office
   calls that. */
$applyError = '';
$applyOk    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_stall') {
    $wanted = strtoupper(trim((string)($_POST['requested_stall'] ?? '')));
    $note   = trim((string)($_POST['request_note'] ?? ''));

    if (!$me) {
        $applyError = 'Your vendor account is not ready yet.';
    } elseif (!stall_exists($wanted)) {
        $applyError = $wanted === ''
            ? 'Choose a stall first.'
            : 'Stall ' . htmlspecialchars($wanted) . ' is not part of this market.';
    } elseif (isset($occupied[$wanted]) && $occupied[$wanted]['id'] !== $myId) {
        $applyError = 'Stall ' . $wanted . ' is already taken. Choose another one.';
    } else {
        $res = stall_apply($conn, $myId, $wanted, $note);
        if ($res['ok']) {
            $applyOk = 'Your request for Stall ' . $wanted . ' is with the market office. '
                     . 'They assign stalls, so you will hear back here as soon as they decide.';
        } else {
            $applyError = $res['error'];
        }
    }
}

/* ---- or write to them instead ---- */
$msgError = '';
$msgOk    = '';
$amStall  = strtoupper(trim((string)($_POST['requested_stall'] ?? '')));
$amBody   = trim((string)($_POST['am_message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'message_office') {
    if (!$me) {
        $msgError = 'Your vendor account is not ready yet.';
    } elseif ($amStall !== '' && !stall_exists($amStall)) {
        $msgError = 'Stall ' . htmlspecialchars($amStall) . ' is not part of this market.';
    } else {
        $res = am_send_from_vendor($conn, $myId, $amBody, $amStall);
        if ($res['ok']) {
            $msgOk = 'Your message is with the market office. They will answer here, '
                   . 'and you will also get a private message.';
            $amBody = '';   /* the box empties, ready to write again */
        } else {
            $msgError = $res['error'];
        }
    }
}

/* Where this vendor stands, and their latest request. */
$myApplication = $me ? stall_application_for_vendor($conn, $myId) : null;
$myStatus      = stall_status_for_vendor($me, $myApplication);

/* The conversation, oldest first so it reads in order. */
$amThread = $me ? am_thread($conn, $myId) : [];
$amUnread = $me ? am_vendor_unread_count($conn, $myId) : 0;

/* Opening the page is what counts as reading the office's replies. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $amUnread > 0) {
    am_mark_read($conn, $myId, 'vendor');
    $amUnread = 0;
}

/* Pre-fill the letter from what they picked, so it says the right thing
   before they have typed anything. */
$amPrefill = '';
if ($me) {
    $amPrefill = am_default_text(
        $me,
        $amStall !== ''
            ? $amStall
            : ($preStall !== '' ? $preStall : (string)($myApplication['requested_stall'] ?? ''))
    );
}

/* Which stalls are still free, offered by section. Both forms and the
   grid read from this, so they can never disagree about what is
   available. */
$freeBySection = [];
$freeCount = 0;
foreach ($layout as $section => $codes) {
    $free = [];
    foreach ($codes as $code) {
        $up = strtoupper($code);
        if (empty($occupied[$up]) || ($occupied[$up]['id'] ?? 0) === $myId) {
            $free[] = $code;
            $freeCount++;
        }
    }
    if (!empty($free)) $freeBySection[$section] = $free;
}

/* How many other vendors want each stall, so a card can say whether it
   is spoken for. From the same queue the office reads, and only counting
   requests still open. Counting yourself is not news. */
$stallWaiting = [];
foreach ($me ? stall_applications_list($conn, 'pending') : [] as $waiting) {
    $wanted = strtoupper(trim((string)($waiting['requested_stall'] ?? '')));
    if ($wanted === '') continue;
    $who = (int)($waiting['vendor_id'] ?? 0);
    if ($who === $myId) continue;
    $stallWaiting[$wanted][$who] = true;
}
$stallWaiting = array_map('count', $stallWaiting);

/* The one stall the page opens on: an explicit choice, else what they
   already asked for, else nothing. Anything no longer free is dropped,
   so the grid never preselects a stall that is not in it. */
$chosen = $preStall !== ''
    ? $preStall
    : strtoupper(trim((string)($myApplication['requested_stall'] ?? '')));

$chosenIsFree = false;
foreach ($freeBySection as $codes) {
    if (in_array($chosen, $codes, true)) { $chosenIsFree = true; break; }
}
if (!$chosenIsFree) $chosen = '';
$chosenSection = $chosen !== '' ? stall_section($chosen) : '';
$chosenWaiting = $chosen !== '' ? (int)($stallWaiting[$chosen] ?? 0) : 0;

/* Flat list for the filter, with the data the cards need. */
$gridStalls = [];
foreach ($freeBySection as $section => $codes) {
    foreach ($codes as $code) {
        $up = strtoupper($code);
        $gridStalls[] = [
            'code'    => $up,
            'section' => $section,
            'mine'    => ($occupied[$up]['id'] ?? 0) === $myId,
            'waiting' => (int)($stallWaiting[$up] ?? 0),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#022c22" />
    <title>Apply for a Stall &bull; MALLENGKE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
        <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>


        /* Off screen until it is the thing being tabbed to, then pinned
           to the top left so it is visible where focus went. */
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 1000;
            padding: 12px 20px;
            background: var(--brand-dark);
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
            border-radius: 0 0 10px 0;
            text-decoration: none;
        }
        .skip-link:focus { left: 0; }

        /* The skip link jumps here, so this has to be able to take focus
           without a visible outline of its own. */
        .main-content:focus { outline: none; }:root{--blue:#0ea5e9;--blue-dark:#0284c7;--blue-light:#e0f2fe;--amber:#d97706;--amber-bg:#fffbeb;--amber-line:#fde68a;--red:#dc2626;--red-bg:#fef2f2;--red-line:#fecaca;/* Every tappable thing is at least this tall, so a vendor
               working one-handed on a phone can hit it. */
            --tap:44px;}

        .page-wrap{max-width:900px;margin:0 auto;}

        /* ---- page heading ---- */
        .page-head{margin-bottom:18px}
        .link-back{
            display:inline-flex;align-items:center;gap:7px;min-height:32px;
            font-size:12.5px;font-weight:700;color:var(--brand-dark);
            text-decoration:none;margin-bottom:8px;
        }
        .link-back:hover{text-decoration:underline}
        .page-head-top{display:flex;align-items:flex-start;gap:14px}
        .page-head-icon{
            width:46px;height:46px;border-radius:14px;flex-shrink:0;
            background:linear-gradient(135deg,#10b981,#047857);
            display:grid;place-items:center;color:#fff;font-size:19px;
            box-shadow:0 8px 20px rgba(16,185,129,.32);
        }
        .page-head h1{font-size:21px;font-weight:900;letter-spacing:-.4px;color:var(--ink);line-height:1.25}
        .page-head p{font-size:13px;color:var(--ink-soft);margin-top:4px;line-height:1.55;max-width:62ch}
        .card-head{
            display:flex;align-items:flex-start;gap:13px;
            padding:16px 20px;border-bottom:1px solid var(--line-soft);
            background:linear-gradient(135deg,#ecfdf5,#fff);
        }
        .card-head.blue{background:linear-gradient(135deg,#f0f9ff,#fff)}
        .card-head.plain{background:#fff}
        .card-head-icon{
            width:40px;height:40px;border-radius:12px;flex-shrink:0;
            display:grid;place-items:center;color:#fff;font-size:16px;
            background:linear-gradient(135deg,#10b981,#047857);
            box-shadow:0 6px 16px rgba(16,185,129,.28);
        }
        .card-head.blue .card-head-icon{background:linear-gradient(135deg,#0ea5e9,#0284c7);
            box-shadow:0 6px 16px rgba(2,132,199,.28)}
        .card-head-icon.amber{background:linear-gradient(135deg,#f59e0b,#d97706);
            box-shadow:0 6px 16px rgba(245,158,11,.3)}
        .card-head-icon.red{background:linear-gradient(135deg,#ef4444,#b91c1b);
            box-shadow:0 6px 16px rgba(239,68,68,.28)}
        .card-head-icon.grey{background:linear-gradient(135deg,#64748b,#475569);
            box-shadow:0 6px 16px rgba(71,85,105,.28)}
        .card-head h2{font-size:15.5px;font-weight:800;letter-spacing:-.2px;color:var(--ink);line-height:1.3}
        .card-head p{font-size:12.5px;color:var(--ink-soft);margin-top:3px;line-height:1.55;max-width:60ch}
        .card-body{padding:18px 20px 20px}

        /* ---- step numbers, so the order is obvious on a phone ---- */
        .step{
            display:inline-grid;place-items:center;
            width:24px;height:24px;border-radius:8px;flex-shrink:0;
            background:var(--ink);color:#fff;
            font-size:12px;font-weight:800;
        }
        .card-head:has(.step) .card-head-icon{display:none}

        /* ---- the choice summary: always says what is picked ---- */
        .picked{
            display:flex;align-items:center;gap:12px;flex-wrap:wrap;
            padding:13px 16px;margin-bottom:16px;
            border-radius:14px;border:1.5px dashed var(--green-300);
            background:linear-gradient(135deg,#f0fdfa,#fff);
        }
        .picked.empty{border-color:var(--line);background:var(--bg)}
        .picked-code{
            font-size:17px;font-weight:900;color:var(--brand-dark);
            letter-spacing:-.3px;white-space:nowrap;
        }
        .picked.empty .picked-code{font-size:13px;font-weight:700;color:var(--muted);letter-spacing:0}
        .picked-meta{font-size:12.5px;color:var(--ink-soft);min-width:0}
        .picked-meta strong{color:var(--ink);font-weight:800}
        .picked-warn{
            display:inline-flex;align-items:center;gap:6px;
            font-size:11.5px;font-weight:800;color:var(--amber);
            background:var(--amber-bg);border:1px solid var(--amber-line);
            padding:4px 10px;border-radius:999px;white-space:nowrap;
        }
        .picked-clear{
            margin-left:auto;min-height:34px;
            display:inline-flex;align-items:center;gap:6px;
            padding:7px 13px;border-radius:10px;cursor:pointer;
            font-family:inherit;font-size:12px;font-weight:800;
            color:var(--muted);background:#fff;border:1.5px solid var(--line);
            transition:all .2s var(--ease-smooth);
        }
        .picked-clear:hover{color:var(--ink-soft);border-color:var(--muted)}

        /* ---- search + section filter ---- */
        .picker-tools{display:flex;flex-direction:column;gap:12px;margin-bottom:16px}
        .search-box > i{
            position:absolute;left:14px;top:50%;transform:translateY(-50%);
            color:var(--muted);font-size:13px;pointer-events:none;
        }
        .search-clear{
            position:absolute;right:8px;top:50%;transform:translateY(-50%);
            width:28px;height:28px;border-radius:8px;border:none;cursor:pointer;
            background:transparent;color:var(--muted);display:none;
            place-items:center;font-size:13px;
        }
        .search-box.filled .search-clear{display:grid}

        .chips{display:flex;gap:7px;flex-wrap:wrap}
        .chip-btn{
            min-height:36px;padding:7px 13px;border-radius:999px;cursor:pointer;
            font-family:inherit;font-size:12px;font-weight:800;
            color:var(--ink-soft);background:#fff;border:1.5px solid var(--line);
            transition:all .2s var(--ease-spring);white-space:nowrap;
        }
        .chip-btn:hover{border-color:var(--green-300);color:var(--brand-dark)}
        .chip-btn[aria-pressed="true"]{
            background:linear-gradient(135deg, #059669, #065f46);
            color:#fff;border-color:transparent;
            box-shadow:0 6px 16px rgba(16,185,129,.3);
        }
        .chip-btn .n{opacity:.65;margin-left:5px;font-weight:700}

        /* ---- the stall grid ----
           Fills whatever width it is given, so the same markup works on a
           360px phone and a wide desktop. */
        .stall-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(132px,1fr));
            gap:10px;
        }
        .stall{
            position:relative;display:block;cursor:pointer;
            min-height:var(--tap);
            /* The real control stays in the flow for keyboard and AT. */
        }
        .stall input{
            position:absolute;opacity:0;width:1px;height:1px;margin:0;pointer-events:none;
        }
        .stall-face{
            display:flex;flex-direction:column;align-items:flex-start;gap:2px;
            height:100%;padding:10px 12px;border-radius:13px;
            border:1.5px dashed var(--green-300);
            background:linear-gradient(180deg,#ffffff 0%,#f0fdfa 100%);
            transition:all .2s var(--ease-spring);
        }
        .stall:hover .stall-face{transform:translateY(-2px);border-color:var(--green-500);
            box-shadow:0 8px 18px rgba(16,185,129,.22)}
        .stall-code{font-size:14.5px;font-weight:900;color:var(--brand-dark);letter-spacing:-.2px;line-height:1.2}
        .stall-sec{font-size:11px;font-weight:700;color:var(--muted);line-height:1.3;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}

        .stall input:checked + .stall-face{
            border-style:solid;
            border-color:var(--green-600);
            background:linear-gradient(135deg,#34d399,#059669);
            box-shadow:0 10px 24px rgba(16,185,129,.4);
        }
        .stall input:checked + .stall-face .stall-code{color:#fff}
        .stall input:checked + .stall-face .stall-sec{color:rgba(255,255,255,.85)}
        .stall input:focus-visible + .stall-face{outline:3px solid var(--brand-dark);outline-offset:2px}

        .stall-busy{
            display:inline-flex;align-items:center;gap:4px;
            font-size:10px;font-weight:800;color:var(--amber);
            background:var(--amber-bg);border:1px solid var(--amber-line);
            padding:1px 7px;border-radius:999px;margin-top:3px;
        }
        .stall input:checked + .stall-face .stall-busy{
            color:#fff;background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.35);
        }
        .stall-mine{
            display:inline-flex;align-items:center;gap:4px;
            font-size:10px;font-weight:800;color:#fff;
            background:var(--brand-dark);padding:1px 7px;border-radius:999px;margin-top:3px;
        }
        .stall input:checked + .stall-face .stall-mine{background:rgba(255,255,255,.24)}

        /* Nothing matched, or nothing free at all. */
        .grid-empty{
            grid-column:1/-1;text-align:center;padding:28px 16px;
            color:var(--muted);font-size:13px;line-height:1.6;
            border:1.5px dashed var(--line);border-radius:14px;background:var(--bg);
        }
        .grid-empty i{font-size:22px;color:var(--line);display:block;margin-bottom:8px}

        /* ---- the two ways to ask: one at a time ----
           Both panels are real forms; the tabs just hide one of them, so
           nothing is lost when JavaScript is off. */
        .tabs{
            display:flex;gap:6px;padding:5px;margin-bottom:16px;
            background:var(--bg);border-radius:13px;
        }
        .tab{
            flex:1;min-height:40px;padding:9px 12px;border:none;border-radius:10px;
            cursor:pointer;font-family:inherit;
            font-size:12.5px;font-weight:800;color:var(--ink-soft);
            display:inline-flex;align-items:center;justify-content:center;gap:7px;
            transition:all .2s var(--ease-smooth);
        }
        .tab:hover{color:var(--ink)}
        .tab[aria-selected="true"]{
            background:#fff;color:var(--brand-dark);
            box-shadow:var(--sh-sm);
        }
        .tab i{font-size:13px}
        .panel[hidden]{display:none}

        /* ---- fields ---- */
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
        .field:last-child{margin-bottom:0}
        .field label{
            font-size:10.5px;font-weight:800;letter-spacing:.1em;
            text-transform:uppercase;color:var(--muted);
        }
        .field select,.field input,.field textarea{
            width:100%;padding:12px 13px;
            border:1.5px solid var(--line);border-radius:12px;
            font-family:inherit;font-size:13.5px;color:var(--ink);background:#fff;
            transition:border-color .2s var(--ease-smooth),box-shadow .2s var(--ease-smooth);
        }
        .field select{min-height:var(--tap);cursor:pointer;
            appearance:none;-webkit-appearance:none;-moz-appearance:none;
            padding-right:38px;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5 6 6.5l5-5' fill='none' stroke='%236b8a7a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat:no-repeat;background-position:right 14px center;background-size:11px 8px;
        }
        .field textarea{min-height:170px;line-height:1.65;resize:vertical}
        .field select:hover,.field input:hover,.field textarea:hover{border-color:var(--green-200)}
        .field select:focus,.field input:focus,.field textarea:focus{
            outline:none;border-color:var(--green-400);box-shadow:0 0 0 3px rgba(52,211,153,.2);
        }
        .field input::placeholder,.field textarea::placeholder{color:#94a3b8}

        .field-foot{
            display:flex;align-items:center;justify-content:space-between;gap:10px;
            font-size:11.5px;color:var(--muted);
        }
        .counter{font-variant-numeric:tabular-nums}
        .counter.warn{color:var(--amber);font-weight:800}

        /* ---- buttons ---- */
        .btn{
            min-height:var(--tap);padding:12px 20px;border-radius:13px;border:none;
            cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:800;
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            white-space:nowrap;transition:transform .25s var(--ease-spring),box-shadow .25s var(--ease-spring);
        }
        .btn i{font-size:13px;flex-shrink:0}
        .btn-primary{color:#fff;background:linear-gradient(135deg, #059669, #065f46);
            box-shadow:0 8px 20px rgba(16,185,129,.3)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(16,185,129,.4)}
        .btn-primary:disabled{
            background:var(--line);color:#fff;box-shadow:none;cursor:not-allowed;transform:none;
        }
        .btn-ghost{background:#fff;color:var(--ink-soft);border:1.5px solid var(--line);box-shadow:var(--sh-sm)}
        .btn-ghost:hover{border-color:var(--green-400);color:var(--brand-dark)}
        .btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .btn-primary.grow{flex:1 1 200px}

        .note{
            display:flex;align-items:flex-start;gap:8px;
            font-size:11.5px;color:var(--muted);line-height:1.55;margin-top:14px;
        }
        .note i{margin-top:2px;flex-shrink:0;color:var(--brand)}
        .note b{color:var(--ink-soft)}

        /* ---- alerts ---- */
        .alert{
            display:flex;align-items:flex-start;gap:9px;
            padding:12px 15px;border-radius:12px;margin-bottom:14px;
            font-size:12.5px;line-height:1.55;
        }
        .alert i{margin-top:2px;flex-shrink:0}
        .alert.error{background:var(--red-bg);border:1.5px solid var(--red-line);color:#991b1b}
        .alert.ok{background:var(--green-50);border:1.5px solid var(--green-200);color:#065f46}
        .alert.warn{background:var(--amber-bg);border:1.5px solid var(--amber-line);color:#92400e}

        .pill{
            display:inline-flex;align-items:center;gap:7px;
            padding:7px 13px;border-radius:999px;font-size:12px;font-weight:800;
        }
        .pill i{font-size:11px}
        .pill.amber{background:var(--amber-bg);border:1.5px solid var(--amber-line);color:#92400e}
        .pill.blue{background:var(--blue-light);border:1.5px solid #bae6fd;color:#075985}
        .pill.green{background:var(--green-100);border:1.5px solid var(--green-200);color:var(--green-800)}

        /* ---- the conversation ---- */
        .thread{display:flex;flex-direction:column;gap:12px;
            max-height:420px;overflow-y:auto;padding:16px 20px}
        .thread::-webkit-scrollbar{width:6px}
        .thread::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px}
        .msg{border-radius:13px;padding:11px 14px;max-width:88%;
            border:1px solid var(--line-soft);background:#fff}
        .msg.mine{align-self:flex-end;background:linear-gradient(135deg,#ecfdf5,#fff);border-color:var(--green-200)}
        .msg.theirs{align-self:flex-start;background:#fff;border-color:#bae6fd}
        .msg-who{display:flex;align-items:center;gap:7px;flex-wrap:wrap;
            font-size:11px;font-weight:800;color:var(--muted);margin-bottom:5px}
        .msg.mine .msg-who{color:var(--green-700)}
        .msg-when{font-weight:600;color:var(--muted);font-size:10.5px}
        .msg-body{font-size:13px;color:var(--ink-soft);line-height:1.6;
            overflow-wrap:anywhere}
        .msg-wants{
            padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;
            background:#ede9fe;color:#5b21b6;
        }
        .thread-empty{padding:26px 20px;text-align:center;color:var(--muted)}
        .thread-empty i{font-size:26px;color:var(--line);display:block;margin-bottom:8px}
        .thread-empty p{font-size:12.5px;max-width:46ch;margin:0 auto;line-height:1.6}

        /* ---- nothing free: say so plainly ---- */
        .sold-out{
            text-align:center;padding:26px 20px;
        }
        .sold-out-icon{
            width:56px;height:56px;border-radius:18px;margin:0 auto 12px;
            display:grid;place-items:center;font-size:22px;
            background:var(--amber-bg);color:var(--amber);
            border:1.5px solid var(--amber-line);
        }
        .sold-out h3{font-size:15px;font-weight:800;color:var(--ink);margin-bottom:5px}
        .sold-out p{font-size:12.5px;color:var(--ink-soft);line-height:1.6;max-width:44ch;margin:0 auto}

        /* ---- toast ---- */
        .toast{
            position:fixed;left:50%;bottom:24px;z-index:800;
            transform:translateX(-50%) translateY(18px);
            background:#022c22;color:#ecfdf5;
            padding:13px 20px;border-radius:14px;
            font-size:13.5px;font-weight:700;
            box-shadow:0 14px 36px rgba(2,44,34,.4);
            display:flex;align-items:center;gap:10px;
            opacity:0;transition:all .35s var(--ease-spring);
            pointer-events:none;border:1px solid rgba(52,211,153,.28);
            max-width:calc(100% - 32px);
        }
        .toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
        .toast i{color:#34d399;font-size:15px;flex-shrink:0}
        .toast.error{border-color:rgba(239,68,68,.4)}
        .toast.error i{color:#f87171}@media (max-width: 900px){
            .page-head h1{font-size:19px}
            .card-head{padding:14px 16px}
            .card-body{padding:16px}
            .thread{padding:14px 16px}}@media (max-width: 640px){
            /* One stall per row is too sparse; two reads as a list and
               keeps the code large enough to tap accurately. */
            .stall-grid{grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px}
            .tabs{flex-direction:column}
            .tab{justify-content:flex-start}
            .btn-row{gap:8px}
            .btn-row .btn{flex:1 1 100%}
            .picked-clear{margin-left:0;width:100%;justify-content:center}
            .msg{max-width:94%}
            /* The heading icon gets in the way of the title on a phone. */
            .page-head-icon{display:none}}@media (max-width: 400px){
            .stall-grid{grid-template-columns:repeat(2,1fr)}}

        @media (prefers-reduced-motion: reduce) {
            *,*::before,*::after{
                animation-duration:.001ms!important;animation-iteration-count:1!important;
                transition-duration:.001ms!important;scroll-behavior:auto!important;
            }
        }

        @media print {
            .sidebar, .mobile-toggle, .sidebar-backdrop, .tabs,
            .btn-row, .picker-tools, .toast { display: none !important; }
            .main-content { margin-left: 0; width: 100%; padding: 0; }
            .card { box-shadow: none; page-break-inside: avoid; }
            .panel[hidden]{display:block!important}
        }
    </style>
</head>
<body>

<?php /* A keyboard user should not have to walk the sidebar to get here. */ ?>
<a class="skip-link" href="#main-content">Skip to the application</a>

<?php require __DIR__ . '/../includes/vendor_sidebar.php'; ?>

<main class="main-content" id="main-content" tabindex="-1">
<div class="page-wrap">

    <!-- ==================== HEADING ==================== -->
    <div class="page-head">
        <a href="stall_map.php" class="link-back">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to the stall map
        </a>
        <div class="page-head-top">
            <div class="page-head-icon"><i class="fas fa-hand-paper" aria-hidden="true"></i></div>
            <div>
                <h1>Apply for a stall</h1>
                <p>You choose the stall. The market office decides who gets it.</p>
            </div>
        </div>
    </div>

    <!-- ==================== WHERE YOU STAND ====================
         One card, and the tone says whether this is a problem. -->
    <div class="card">
        <div class="card-head<?= $myStatus['tone'] === 'green' ? '' : ' plain' ?>">
            <div class="card-head-icon <?= $myStatus['tone'] === 'green' ? '' : ($myStatus['tone'] === 'red' ? 'red' : 'amber') ?>">
                <i class="fas <?= $myStatus['state'] === 'placed' ? 'fa-store' : ($myStatus['state'] === 'placed_declined' ? 'fa-store' : ($myStatus['state'] === 'declined' ? 'fa-circle-xmark' : ($myStatus['state'] === 'waiting' ? 'fa-hourglass-half' : 'fa-hand-paper'))) ?>" aria-hidden="true"></i>
            </div>
            <div>
                <h2><?= htmlspecialchars($myStatus['title']) ?></h2>
                <?php if ($myStatus['body'] !== ''): ?>
                    <p><?= htmlspecialchars($myStatus['body']) ?></p>
                <?php elseif ($myStatus['state'] === 'placed'): ?>
                    <p>The market office assigns stalls, so this one is yours until they move you.</p>
                <?php endif; ?>
                <?php if ($myStatus['state'] === 'waiting'): ?>
                    <p style="margin-top:8px;">
                        <span class="pill amber"><i class="fas fa-hourglass-half" aria-hidden="true"></i> Waiting for the office</span>
                        <span style="font-size:12.5px;color:var(--ink-soft);">Sending another request replaces this one.</span>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==================== STEP 1: CHOOSE ====================
         The grid is the picker. The real radio inputs underneath mean
         this works with a keyboard, a screen reader and no JavaScript. -->
    <div class="card">
        <div class="card-head">
            <span class="step" aria-hidden="true">1</span>
            <div>
                <h2>Choose a stall</h2>
                <p>
                    <?php if ($freeCount > 0): ?>
                        <?= (int)$freeCount ?> <?= $freeCount === 1 ? 'stall is' : 'stalls are' ?> free right now.
                        Tap one to put it in your request.
                    <?php else: ?>
                        Nothing is free at the moment.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="card-body">
            <?php if ($freeCount > 0): ?>

                <?php if ($applyError !== ''): ?>
                    <div class="alert error" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($applyError) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($applyOk !== ''): ?>
                    <div class="alert ok" role="status">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($applyOk) ?></span>
                    </div>
                <?php endif; ?>

                <!-- What is picked right now, said in one line. The value
                     lives in the radio group; this only mirrors it. -->
                <div class="picked<?= $chosen === '' ? ' empty' : '' ?>" id="picked">
                    <span class="picked-code" id="pickedCode"><?= htmlspecialchars($chosen !== '' ? $chosen : 'No stall chosen yet') ?></span>
                    <span class="picked-meta" id="pickedMeta">
                        <?= $chosen !== ''
                            ? 'in ' . htmlspecialchars($chosenSection)
                            : htmlspecialchars('Pick one below to continue.') ?>
                    </span>
                    <?php if ($chosen !== '' && $chosenWaiting > 0): ?>
                        <span class="picked-warn" id="pickedWarn">
                            <i class="fas fa-users" aria-hidden="true"></i>
                            <?= (int)$chosenWaiting ?> also <?= $chosenWaiting === 1 ? 'wants' : 'want' ?> this
                        </span>
                    <?php else: ?>
                        <span class="picked-warn" id="pickedWarn" hidden></span>
                    <?php endif; ?>
                    <button type="button" class="picked-clear" id="pickedClear"
                            <?= $chosen === '' ? 'hidden' : '' ?>>
                        <i class="fas fa-xmark" aria-hidden="true"></i> Clear
                    </button>
                </div>

                <div class="picker-tools">
                    <div class="search-box" id="searchBox">
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                        <label for="stallSearch" class="visually-hidden">Search stalls</label>
                        <input type="search" id="stallSearch" placeholder="Search a stall code, e.g. A-01"
                               autocomplete="off" spellcheck="false" />
                        <button type="button" class="search-clear" id="searchClear" aria-label="Clear search">
                            <i class="fas fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="chips" role="group" aria-label="Filter by section">
                        <button type="button" class="chip-btn" data-section="" aria-pressed="true">
                            All<span class="n"><?= (int)$freeCount ?></span>
                        </button>
                        <?php foreach ($freeBySection as $sec => $codes): ?>
                            <button type="button" class="chip-btn" data-section="<?= htmlspecialchars($sec) ?>" aria-pressed="false">
                                <?= htmlspecialchars($sec) ?><span class="n"><?= count($codes) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- One form spans the grid AND the Apply panel in step 2.
                     Two nested forms would be invalid HTML and the outer one
                     would swallow the inner one's action, so the note and the
                     submit button live inside it and step 2 only holds the
                     mirror of the choice. -->
                <form method="post" action="stall_apply.php" id="applyForm">
                    <input type="hidden" name="action" value="apply_stall" />

                    <div class="stall-grid" id="stallGrid" role="radiogroup" aria-labelledby="gridLabel">
                        <span id="gridLabel" class="visually-hidden">Available stalls</span>
                        <?php foreach ($gridStalls as $s): ?>
                            <label class="stall">
                                <input type="radio" name="requested_stall"
                                       value="<?= htmlspecialchars($s['code']) ?>"
                                       data-section="<?= htmlspecialchars($s['section']) ?>"
                                       <?= $chosen === $s['code'] ? 'checked' : '' ?> />
                                <span class="stall-face">
                                    <span class="stall-code"><?= htmlspecialchars($s['code']) ?></span>
                                    <span class="stall-sec"><?= htmlspecialchars($s['section']) ?></span>
                                    <?php if ($s['mine']): ?>
                                        <span class="stall-mine"><i class="fas fa-star" aria-hidden="true"></i> yours</span>
                                    <?php elseif ($s['waiting'] > 0): ?>
                                        <span class="stall-busy"><i class="fas fa-users" aria-hidden="true"></i>
                                            <?= (int)$s['waiting'] ?> waiting</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <p class="grid-empty" id="gridEmpty" hidden>
                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                            No stall matches that search. Try a different code, or clear the search.
                        </p>
                    </div>

                    <div class="field" style="margin-top:18px;">
                        <label for="applyNote">Anything the office should know <span style="text-transform:none;letter-spacing:0;">(optional)</span></label>
                        <input type="text" name="request_note" id="applyNote" maxlength="500"
                               placeholder="e.g. I sell fish and seafood, and I can start next week." />
                        <div class="field-foot">
                            <span>What you sell, or when you can start trading.</span>
                        </div>
                    </div>
                </form>

                <!-- Not disabled in the markup: without JavaScript the
                     button must still work, and the server answers with a
                     proper message if no stall was picked. JS enables and
                     disables it as the choice changes. -->
                <div class="btn-row" style="margin-top:16px;">
                    <button type="submit" class="btn btn-primary grow" id="applyBtn"
                            form="applyForm">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                        <?= $myStatus['state'] === 'waiting' ? 'Send a new request' : 'Apply for this stall' ?>
                    </button>
                    <a href="stall_map.php" class="btn btn-ghost">
                        <i class="fas fa-map-location-dot" aria-hidden="true"></i> See the floor plan
                    </a>
                </div>

                <p class="note">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    <span>The office decides who gets each stall. You will see your stall on your
                    map, and get a private message, as soon as it is assigned.</span>
                </p>

            <?php else: ?>
                <div class="sold-out">
                    <div class="sold-out-icon"><i class="fas fa-store" aria-hidden="true"></i></div>
                    <h3>Every stall is taken right now</h3>
                    <p>There is nothing free to ask for at this moment. Write to the market office
                       below and they will tell you when one opens &mdash; or check back later.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== STEP 2: SEND IT ====================
         Two ways to put the same choice in front of the office. Only one
         is on screen at a time so the page does not read as a wall. -->
    <div class="card">
        <div class="card-head blue">
            <span class="step" aria-hidden="true">2</span>
            <div>
                <h2>Send it to the office</h2>
                <p>Apply and it lands in their queue. Write to them and it starts a conversation.</p>
            </div>
        </div>

        <div class="card-body">
            <div class="tabs" role="tablist" aria-label="How to send your request">
                <button type="button" class="tab" role="tab" id="tabApply"
                        aria-controls="panelApply" aria-selected="true">
                    <i class="fas fa-clipboard-check" aria-hidden="true"></i> Apply for the stall
                </button>
                <button type="button" class="tab" role="tab" id="tabWrite"
                        aria-controls="panelWrite" aria-selected="false">
                    <i class="fas fa-envelope-open-text" aria-hidden="true"></i> Write a message
                </button>
            </div>

            <!-- ---- Apply ----
                     There is no form here on purpose. The apply form is
                     up in step 1, and this panel only restates the choice
                     so the vendor sees what they are about to send. The
                     button there carries form="applyForm", so one submission
                     is all there is. -->
            <div class="panel" id="panelApply" role="tabpanel" aria-labelledby="tabApply">
                <div class="alert warn" id="applyNudge" hidden>
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    <span>Choose a stall in step 1 first.</span>
                </div>

                <p class="note" style="margin-top:0;">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    <span id="applySummary">Sends your request for <b>no stall yet</b>. Pick one above first.</span>
                </p>

                <p class="note">
                    <i class="fas fa-shield-halved" aria-hidden="true"></i>
                    <span>The office is the only one who can assign a stall, so this is a request
                    &mdash; it never claims one for you.</span>
                </p>
            </div>

            <!-- ---- Write ---- -->
            <div class="panel" id="panelWrite" role="tabpanel" aria-labelledby="tabWrite" hidden>
                <form method="post" action="stall_apply.php" id="writeForm">
                    <input type="hidden" name="action" value="message_office" />

                    <?php if ($msgError !== ''): ?>
                        <div class="alert error" role="alert">
                            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($msgError) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($msgOk !== ''): ?>
                        <div class="alert ok" role="status">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($msgOk) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="field">
                        <label for="msgStall">About which stall</label>
                        <select name="requested_stall" id="msgStall">
                            <option value="">Any stall &mdash; I will take what is available</option>
                            <?php foreach ($freeBySection as $sec => $codes): ?>
                                <optgroup label="<?= htmlspecialchars($sec) ?>">
                                    <?php foreach ($codes as $code):
                                        $up = strtoupper($code);
                                        $isMine = ($occupied[$up]['id'] ?? 0) === $myId;
                                    ?>
                                        <option value="<?= htmlspecialchars($up) ?>"
                                            <?= $chosen === $up ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($code) ?><?= $isMine ? ' &mdash; your current stall' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="amMessage">Your message to the market office</label>
                        <textarea name="am_message" id="amMessage" rows="8" maxlength="2000"
                                  placeholder="Tell the market office what you need&hellip;"><?= htmlspecialchars($amBody !== '' ? $amBody : $amPrefill) ?></textarea>
                        <div class="field-foot">
                            <span>We wrote a starting letter. Change anything you like.</span>
                            <span class="counter" id="amCounter">0 / 2000</span>
                        </div>
                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane" aria-hidden="true"></i> Send to the market office
                        </button>
                        <button type="button" class="btn btn-ghost" id="amReset">
                            <i class="fas fa-rotate-left" aria-hidden="true"></i> Reset the letter
                        </button>
                    </div>

                    <p class="note">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <span>The office answers here on this page, and sends you a private message too.</span>
                    </p>
                </form>
            </div>
        </div>
    </div>

    <!-- ==================== THE CONVERSATION ==================== -->
    <div class="card">
        <div class="card-head plain">
            <div class="card-head-icon <?= $amUnread > 0 ? '' : 'grey' ?>">
                <i class="fas fa-comments" aria-hidden="true"></i>
            </div>
            <div>
                <h2>Your messages with the market office</h2>
                <?php if ($amUnread > 0): ?>
                    <p><span class="pill blue"><i class="fas fa-envelope" aria-hidden="true"></i>
                        <?= (int)$amUnread ?> new <?= $amUnread === 1 ? 'reply' : 'replies' ?></span></p>
                <?php else: ?>
                    <p>Everything you have written to the office, and what they said back.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($amThread)): ?>
            <div class="thread-empty">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <p>You have not written to the market office yet. Choose a stall and use
                   &ldquo;Write a message&rdquo; above, and their reply will appear here.</p>
            </div>
        <?php else: ?>
            <div class="thread" id="officeThread">
                <?php foreach ($amThread as $m):
                    $fromOffice = ($m['direction'] === 'admin');
                ?>
                    <div class="msg <?= $fromOffice ? 'theirs' : 'mine' ?>">
                        <div class="msg-who">
                            <i class="fas <?= $fromOffice ? 'fa-user-tie' : 'fa-store' ?>" aria-hidden="true"></i>
                            <?= $fromOffice
                                ? 'Market Office' . (!empty($m['admin_name']) ? ' &middot; ' . htmlspecialchars($m['admin_name']) : '')
                                : 'You' ?>
                            <span class="msg-when"><?= htmlspecialchars(date('M j, Y g:ia', strtotime((string)$m['created_at']))) ?></span>
                            <?php if (!empty($m['stall_requested'])): ?>
                                <span class="msg-wants">wants <?= htmlspecialchars($m['stall_requested']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="msg-body"><?= nl2br(htmlspecialchars((string)$m['body'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== OUTRO ==================== -->
    <p class="note" style="justify-content:center;text-align:center;">
        <i class="fas fa-map-location-dot" aria-hidden="true"></i>
        <span>Not sure which stall to want?
        <a href="stall_map.php" style="color:var(--brand-dark);font-weight:800;">Look at the floor plan</a> first.</span>
    </p>

</div>
</main>

<!-- TOAST -->
<div class="toast" id="toast" role="status" aria-live="polite">
    <i class="fas fa-check-circle" aria-hidden="true"></i>
    <span id="toastMsg">Stall chosen</span>
</div>

<script>
    function toggleSidebar() {
        const sidebar  = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const open     = sidebar.classList.toggle('open');
        backdrop.classList.toggle('show', open);

        /* The button that opened the drawer is the one that closes it,
           so it has to say which way round things are. */
        const toggle = document.querySelector('.mobile-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Menu');
        }
    }

    function showToast(message, kind) {
        const toast = document.getElementById('toast');
        const text  = document.getElementById('toastMsg');
        if (!toast || !text) return;
        text.textContent = message;
        toast.classList.remove('error');
        if (kind === 'error') toast.classList.add('error');
        toast.classList.add('show');
        clearTimeout(window.__applyToast);
        window.__applyToast = setTimeout(() => toast.classList.remove('show'), 2600);
    }

    /* Only needed by a screen reader. */
    (function () {
        const s = document.createElement('style');
        s.textContent = '.visually-hidden{position:absolute!important;width:1px;height:1px;' +
            'padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}';
        document.head.appendChild(s);
    })();

    /* ============================================================
       What the server already knows, so the page does not have to be
       told twice.
       ============================================================ */
    const SECTIONS = <?= json_encode($layout, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const VENDOR   = <?= json_encode([
            'name'     => $me ? (trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Vendor') : 'Vendor',
            'business' => (string)($me['business_name'] ?? ''),
            'products' => (string)($me['product_type'] ?? ''),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const WAITING  = <?= json_encode($stallWaiting, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function sectionOf(code) {
        for (const sec in SECTIONS) {
            if (SECTIONS[sec].indexOf(code) !== -1) return sec;
        }
        return '';
    }

    /* ============================================================
       Tabs. Both panels are real forms; this only decides which one is
       on screen, and it keeps working if the buttons are never wired.
       ============================================================ */
    (function () {
        const tabs   = Array.prototype.slice.call(document.querySelectorAll('.tab'));
        const panels = { tabApply: 'panelApply', tabWrite: 'panelWrite' };
        if (!tabs.length) return;

        function show(tab) {
            tabs.forEach(t => t.setAttribute('aria-selected', t === tab ? 'true' : 'false'));
            Object.keys(panels).forEach(id => {
                const el = document.getElementById(panels[id]);
                if (el) el.hidden = (id !== tab);
            });
            /* Roving tabindex, so arrow keys move between the tabs the
               way a tablist is supposed to work. */
            tabs.forEach(t => t.tabIndex = (t === tab ? 0 : -1));
            try { sessionStorage.setItem('applyTab', tab.id); } catch (e) { /* private mode */ }
        }

        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => show(tab));
            tab.addEventListener('keydown', (e) => {
                let next = null;
                if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
                if (e.key === 'ArrowLeft')  next = tabs[(i - 1 + tabs.length) % tabs.length];
                if (e.key === 'Home')       next = tabs[0];
                if (e.key === 'End')        next = tabs[tabs.length - 1];
                if (!next) return;
                e.preventDefault();
                show(next);
                next.focus();
            });
        });

        /* Come back to the tab they were last on, unless a POST just
           happened and the server wants their attention. */
        let remembered = null;
        try { remembered = sessionStorage.getItem('applyTab'); } catch (e) { /* ignore */ }
        const posted = <?= json_encode($_SERVER['REQUEST_METHOD'] === 'POST') ?>;
        show(tabs.find(t => t.id === remembered && !posted) || tabs[0]);
    })();

    /* ============================================================
       Step 1: the grid is the picker. One chosen code is mirrored into
       everything else on the page so there is never a second place to
       look for it.
       ============================================================ */
    (function () {
        const grid      = document.getElementById('stallGrid');
        if (!grid) return;

        const radios    = Array.prototype.slice.call(grid.querySelectorAll('input[type=radio]'));
        const empty     = document.getElementById('gridEmpty');
        const picked    = document.getElementById('picked');
        const codeEl    = document.getElementById('pickedCode');
        const metaEl    = document.getElementById('pickedMeta');
        const warnEl    = document.getElementById('pickedWarn');
        const clearEl   = document.getElementById('pickedClear');
        const search    = document.getElementById('stallSearch');
        const searchBox = document.getElementById('searchBox');
        const searchClr = document.getElementById('searchClear');
        const chips     = Array.prototype.slice.call(document.querySelectorAll('.chip-btn'));

        const summary   = document.getElementById('applySummary');
        const msgStall  = document.getElementById('msgStall');
        const buttons   = Array.prototype.slice.call(document.querySelectorAll('#applyBtn'));
        const nudge     = document.getElementById('applyNudge');

        let section = '';
        let query   = '';

        function chosenCode() {
            const on = radios.find(r => r.checked);
            return on ? on.value : '';
        }

        function paintSummary(code) {
            const has = code !== '';
            if (picked) picked.classList.toggle('empty', !has);
            if (codeEl) codeEl.textContent = has ? code : 'No stall chosen yet';
            if (metaEl) metaEl.innerHTML = has
                ? 'in ' + sectionOf(code)
                : 'Pick one below to continue.';
            if (warnEl) {
                const n = has ? (WAITING[code] || 0) : 0;
                warnEl.hidden = n < 1;
                if (n >= 1) {
                    warnEl.innerHTML = '<i class="fas fa-users" aria-hidden="true"></i> ' + n
                        + (n === 1 ? ' also wants this' : ' also want this');
                }
            }
            if (clearEl) clearEl.hidden = !has;
            if (summary) {
                summary.innerHTML = has
                    ? 'Sends your request for <b>' + code + '</b>' + (sectionOf(code) ? ' in ' + sectionOf(code) : '') + '.'
                    : 'Sends your request for <b>no stall yet</b>. Pick one above first.';
            }
            buttons.forEach(b => { b.disabled = !has; });
            if (nudge) nudge.hidden = has;
            if (msgStall && has) {
                if (Array.prototype.some.call(msgStall.options, o => o.value === code)) {
                    msgStall.value = code;
                }
            }
        }

        function filter() {
            let shown = 0;
            radios.forEach(r => {
                const inSection = section === '' || r.dataset.section === section;
                const inQuery   = query === '' || r.value.toLowerCase().indexOf(query) !== -1
                                  || r.dataset.section.toLowerCase().indexOf(query) !== -1;
                const show      = inSection && inQuery;
                r.closest('.stall').hidden = !show;
                if (show) shown++;
            });
            if (empty) empty.hidden = shown > 0;
        }

        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                paintSummary(radio.value);
                showToast('Stall ' + radio.value + ' chosen');
            });
        });

        chips.forEach(chip => {
            chip.addEventListener('click', () => {
                chips.forEach(c => c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'));
                section = chip.dataset.section || '';
                filter();
            });
        });

        if (search) {
            search.addEventListener('input', () => {
                query = search.value.trim().toLowerCase();
                if (searchBox) searchBox.classList.toggle('filled', query !== '');
                filter();
            });
        }
        if (searchClr) {
            searchClr.addEventListener('click', () => {
                if (!search) return;
                search.value = '';
                query = '';
                searchBox.classList.remove('filled');
                search.focus();
                filter();
            });
        }
        if (clearEl) {
            clearEl.addEventListener('click', () => {
                radios.forEach(r => { r.checked = false; });
                paintSummary('');
                showToast('Choice cleared');
            });
        }

        paintSummary(chosenCode());
        filter();
    })();

    /* ============================================================
       The letter to the market office
       ------------------------------------------------------------
       Written server-side by am_default_text(), so the page says the
       right thing with no JavaScript at all. Here it is only kept in
       step with the stall picker, and only while the vendor has not
       edited it — their own words are never overwritten.
       ============================================================ */
    (function () {
        const box   = document.getElementById('amMessage');
        const picker = document.getElementById('msgStall');
        const reset = document.getElementById('amReset');
        const count = document.getElementById('amCounter');
        if (!box) return;

        function letterFor(code) {
            const biz  = VENDOR.business || 'my business';
            const sell = VENDOR.products || 'my products';
            let want;
            if (code) {
                const sec = sectionOf(code);
                want = 'I would like to get Stall ' + code
                     + (sec ? ' in the ' + sec + ' section' : '') + ' for my stall at Mallengke Market.';
            } else {
                want = 'I would like to get a stall for my business at Mallengke Market. '
                     + 'I am open to whichever stall is available.';
            }
            return 'Hello Market Office.\n\n'
                 + 'My name is ' + VENDOR.name + ' and I run ' + biz + '. ' + want + '\n\n'
                 + 'I sell ' + sell + '. '
                 + 'Please let me know which stall is available for me, and what I need to do '
                 + 'to start trading.\n\n'
                 + 'Thank you.';
        }

        let touched = false;
        const asRendered = box.value;

        function paintCount() {
            if (!count) return;
            const n = box.value.length;
            count.textContent = n + ' / 2000';
            count.classList.toggle('warn', n > 1800);
        }

        box.addEventListener('input', () => { touched = true; paintCount(); });
        paintCount();

        if (picker) {
            picker.addEventListener('change', () => { if (!touched) box.value = letterFor(picker.value); });
        }
        if (reset) {
            reset.addEventListener('click', () => {
                box.value = letterFor(picker ? picker.value : '');
                touched = false;
                paintCount();
            });
        }

        /* If the box arrived holding the vendor's own words, keep that
           knowledge so the picker does not overwrite them. */
        if (box.value !== asRendered) touched = true;
    })();
</script>
</body>
</html>