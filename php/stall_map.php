<?php
$conn = require __DIR__ . '/../config/db.php';
session_start();

require_once __DIR__ . '/../config/guard.php';
guard_vendor($conn, 'login.php');

require_once __DIR__ . '/../config/stalls.php';
require_once __DIR__ . '/../config/adminmsg.php';

/* Fetch the logged-in vendor */
$me = stall_vendor_from_session($conn, $_SESSION['user_id']);

/* Fetch ALL vendors with full details (used for stall occupancy + tooltips + messaging) */
$vendorInfo = [];
$occupied   = stall_occupancy($conn);
foreach ($occupied as $code => $row) {
    $ini   = strtoupper(substr((string)($row['name'] ?? 'U'), 0, 1));
    $parts = preg_split('/\s+/', trim((string)$row['name']));
    $ini   = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? '', 0, 1));

    $vendorInfo[$code] = [
        'id'        => $row['id'],
        'name'      => $row['name'],
        'business'  => $row['business'] ?: 'Independent vendor',
        'contact'   => '',
        'products'  => '',
        'years'     => 0,
        'section'   => $row['section'],
        'initials'  => $ini,
    ];
}

/* The full detail the map's tooltips and the message composer read. */
if ($res = $conn->query("SELECT stall_number, contact_number, product_type, years_in_business
                          FROM vendors
                         WHERE stall_number IS NOT NULL AND stall_number <> ''")) {
    while ($row = $res->fetch_assoc()) {
        $code = strtoupper(trim((string)$row['stall_number']));
        if ($code === '' || !isset($vendorInfo[$code])) continue;
        $vendorInfo[$code]['contact']  = (string)($row['contact_number'] ?? '');
        $vendorInfo[$code]['products'] = (string)($row['product_type'] ?? '');
        $vendorInfo[$code]['years']    = (int)($row['years_in_business'] ?? 0);
    }
}

/* Layout comes from config/stalls.php so the vendor, customer and
   office maps can never disagree about what stalls exist. */
$layout = stall_layout();
list($leftWing, $rightWing) = stall_wings();

$myStall   = $me ? strtoupper(trim((string)($me['stall_number'] ?? ''))) : '';
$mySection = ($myStall !== '' && $me) ? stall_section($myStall) : '';
$myId      = $me ? (int)$me['id'] : 0;
$initials  = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));

$totalStalls = 0;
$totalOcc    = 0;
foreach ($layout as $stalls) {
    $totalStalls += count($stalls);
    foreach ($stalls as $s) {
        if (!empty($occupied[strtoupper($s)])) $totalOcc++;
    }
}
$totalAvail = max(0, $totalStalls - $totalOcc);
$pct        = $totalStalls > 0 ? (int)round($totalOcc / $totalStalls * 100) : 0;

/* ---- Where this vendor stands ----
   The request forms themselves live on php/stall_apply.php. This page
   is the plan, so all it needs is where they are in the queue. */
$myApplication = $me ? stall_application_for_vendor($conn, $myId) : null;
$myStatus      = stall_status_for_vendor($me, $myApplication);

/* Unread office replies. The card above links to the conversation; this
   page only counts, it does not mark anything read. */
$amUnread = $me ? am_vendor_unread_count($conn, $myId) : 0;

/* How many vendors are waiting for each stall, so a free stall can say
   whether it is spoken for. From the same queue the office reads, and
   only counting requests still open. */
$stallWaiting = [];
foreach ($me ? stall_applications_list($conn, 'pending') : [] as $waiting) {
    $wanted = strtoupper(trim((string)($waiting['requested_stall'] ?? '')));
    if ($wanted === '') continue;
    $who = (int)($waiting['vendor_id'] ?? 0);
    if ($who === (int)$myId) continue;   /* counting yourself is not news */
    $stallWaiting[$wanted][$who] = true;
}
$stallWaiting = array_map('count', $stallWaiting);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Stall Map • MALLENGKE</title>
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
        .main-content:focus { outline: none; }:root{--blue:#0ea5e9;--blue-dark:#0284c7;--blue-light:#e0f2fe;}


        /* ---- asking for a stall ---- */
        .apply-card{margin:0 0 20px;background:#fff;border:1.5px solid var(--line);border-radius:18px;
            box-shadow:var(--sh-sm);overflow:hidden;}
        .apply-head{display:flex;align-items:flex-start;gap:14px;padding:18px 22px;
            background:linear-gradient(135deg,#ecfdf5,#fff);border-bottom:1px solid var(--line-soft);}
        .apply-card.tone-amber .apply-head{background:linear-gradient(135deg,#fffbeb,#fff);}
        .apply-card.tone-red .apply-head{background:linear-gradient(135deg,#fef2f2,#fff);}
        .apply-head-icon{width:42px;height:42px;border-radius:13px;flex-shrink:0;display:grid;place-items:center;
            background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-size:17px;
            box-shadow:0 8px 20px rgba(16,185,129,.32);}
        .apply-card.tone-amber .apply-head-icon{background:linear-gradient(135deg,#f59e0b,#d97706);
            box-shadow:0 8px 20px rgba(245,158,11,.32);}
        .apply-card.tone-red .apply-head-icon{background:linear-gradient(135deg,#ef4444,#b91c1c);
            box-shadow:0 8px 20px rgba(239,68,68,.32);}
        .apply-head h2{font-size:16px;font-weight:800;letter-spacing:-.3px;color:var(--ink);}
        .apply-head p{font-size:12.5px;color:var(--ink-soft);margin-top:4px;line-height:1.55;max-width:62ch;}
        .apply-body{padding:16px 22px 20px;}
        .apply-fields{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
        .apply-field{display:flex;flex-direction:column;gap:6px;}
        .apply-field.grow{flex:1;min-width:220px;}
        .apply-field label{font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);}
        .apply-field select,.apply-field input{padding:11px 13px;border:1.5px solid var(--line);border-radius:11px;
            font-family:inherit;font-size:13px;color:var(--ink);background:#fff;}
        .apply-field select:focus,.apply-field input:focus{outline:none;border-color:var(--green-400);
            box-shadow:0 0 0 3px rgba(52,211,153,.2);}
        .apply-submit{padding:12px 22px;border-radius:12px;border:none;cursor:pointer;font-family:inherit;
            font-size:13px;font-weight:800;color:#fff;background:linear-gradient(135deg,#10b981,#047857);
            box-shadow:0 8px 20px rgba(16,185,129,.3);display:inline-flex;align-items:center;gap:8px;white-space:nowrap;
            transition:transform .25s var(--ease-spring),box-shadow .25s var(--ease-spring);}
        .apply-submit:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(16,185,129,.4);}
        .apply-note{font-size:11.5px;color:var(--muted);margin-top:12px;line-height:1.55;}
        .apply-alert{display:flex;align-items:flex-start;gap:9px;padding:12px 15px;border-radius:12px;
            font-size:12.5px;margin-bottom:14px;line-height:1.55;}
        .apply-alert.error{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
        .apply-alert.ok{background:#ecfdf5;border:1.5px solid #a7f3d0;color:#065f46;}
        .apply-alert i{margin-top:2px;}
        .waiting-pill{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:999px;
            background:#fffbeb;border:1.5px solid #fde68a;color:#92400e;font-size:12px;font-weight:700;}

        /* ---- writing to the office, and the thread that follows ---- */
        .ask-office{border-top:1px dashed var(--line);margin-top:4px;padding-top:16px}
        .ask-office-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}
        .ask-office-head > i{width:36px;height:36px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;
            background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;font-size:15px;
            box-shadow:0 6px 16px rgba(2,132,199,.28)}
        .ask-office-head h3{font-size:14.5px;font-weight:800;color:var(--ink)}
        .ask-office-head p{font-size:12.5px;color:var(--ink-soft);margin-top:3px;line-height:1.55;max-width:64ch}
        .ask-office-body .apply-field textarea{padding:12px 13px;border:1.5px solid var(--line);border-radius:11px;
            font-family:inherit;font-size:13px;color:var(--ink);background:#fff;line-height:1.6;resize:vertical;width:100%}
        .ask-office-body .apply-field textarea:focus{outline:none;border-color:var(--green-400);
            box-shadow:0 0 0 3px rgba(52,211,153,.2)}
        .ask-office-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}
        .apply-submit.ghost{background:#fff;color:var(--ink-soft);border:1.5px solid var(--line);box-shadow:var(--sh-sm)}
        .apply-submit.ghost:hover{border-color:var(--green-400);color:var(--brand-dark);box-shadow:var(--sh-sm)}

        .office-thread{margin-top:18px;border:1.5px solid var(--line);border-radius:14px;overflow:hidden;background:#fbfcfd}
        .office-thread.has-unread{border-color:var(--green-400);box-shadow:0 0 0 3px rgba(52,211,153,.14)}
        .office-thread-head{display:flex;align-items:center;gap:10px;padding:13px 16px;
            background:#fff;border-bottom:1px solid var(--line-soft)}
        .office-thread-head > i{color:var(--brand)}
        .office-thread-head h3{font-size:13.5px;font-weight:800;color:var(--ink);flex:1}
        .office-thread-empty{padding:22px 16px;text-align:center;color:var(--muted)}
        .office-thread-empty i{font-size:26px;color:var(--line);display:block;margin-bottom:8px}
        .office-thread-empty p{font-size:12.5px;max-width:52ch;margin:0 auto;line-height:1.6}
        .office-thread-list{max-height:340px;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:12px}
        .office-thread-list::-webkit-scrollbar{width:6px}
        .office-thread-list::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px}
        .office-msg{border-radius:12px;padding:11px 14px;max-width:88%;border:1px solid var(--line-soft);background:#fff}
        .office-msg.from-vendor{align-self:flex-end;background:linear-gradient(135deg,#ecfdf5,#fff);border-color:var(--green-200)}
        .office-msg.from-office{align-self:flex-start;background:#fff;border-color:#bae6fd}
        .office-msg-who{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:800;color:var(--muted);margin-bottom:5px}
        .office-msg.from-vendor .office-msg-who{color:var(--green-700)}
        .office-msg-when{font-weight:600;color:var(--muted)}
        .office-msg-body{font-size:13px;color:var(--ink-soft);line-height:1.6}

        /* ============ STATS STRIP ============ */
        .map-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }@media (max-width: 720px){ .map-strip { grid-template-columns: repeat(2, 1fr); }}

        .strip-stat {
            background: #ffffff;
            padding: 16px 18px;
            border-radius: 16px;
            border: 1.5px solid var(--line);
            display: flex; align-items: center; gap: 12px;
            box-shadow: var(--sh-xs);
        }
        .strip-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: grid; place-items: center;
            color: #fff; font-size: 16px; flex-shrink: 0;
        }
        .strip-icon.gold  { background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 5px 14px rgba(16,185,129,.35); }
        .strip-icon.slate { background: linear-gradient(135deg, #64748b, #475569); box-shadow: 0 5px 14px rgba(100,116,139,.3); }
        .strip-icon.green { background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 5px 14px rgba(52,211,153,.35); }
        .strip-icon.amber { background: linear-gradient(135deg, #0d9488, #0f766e); box-shadow: 0 5px 14px rgba(13,148,136,.32); }
        .strip-num { font-size: 22px; font-weight: 900; color: var(--ink); letter-spacing: -.5px; line-height: 1.1; }
        .strip-lbl { font-size: 11px; font-weight: 800; color: var(--muted); letter-spacing: .06em; text-transform: uppercase; margin-top: 2px; }

        /* ============ MARKET BUILDING ============ */
        .market-building {
            position: relative;
            background: #ffffff;
            border-radius: 28px;
            padding: 26px;
            box-shadow:
                0 30px 70px rgba(2,44,34,.14),
                0 0 0 1.5px var(--line);
            overflow: hidden;
        }
        .market-building::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(5,150,105,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(5,150,105,.05) 1px, transparent 1px);
            background-size: 32px 32px;
            pointer-events: none;
            border-radius: 28px;
        }
        .market-building::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 6px;
            background: linear-gradient(90deg, #10b981, #047857, #10b981);
            border-radius: 28px 28px 0 0;
        }

        .building-header {
            position: relative;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 2px dashed var(--line);
        }
        .building-title { display: flex; align-items: center; gap: 14px; }
        .building-title-icon {
            width: 46px; height: 46px; border-radius: 14px;
            background: linear-gradient(135deg, #10b981, #047857);
            display: grid; place-items: center;
            color: #fff; font-size: 19px;
            box-shadow: var(--glow-soft);
            flex-shrink: 0;
        }
        .building-title h2 { font-size: 18px; font-weight: 900; color: var(--ink); letter-spacing: -.3px; }
        .building-title p {
            font-size: 12px; color: var(--muted); margin-top: 3px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .building-title p i { color: var(--brand); font-size: 11px; }

        .building-legend {
            display: flex; gap: 16px; flex-wrap: wrap;
            font-size: 12px; font-weight: 700; color: var(--ink-soft);
        }
        .legend-item { display: inline-flex; align-items: center; gap: 7px; }
        .legend-swatch {
            width: 16px; height: 16px; border-radius: 5px;
            border: 1px solid rgba(0,0,0,.06);
        }
        .swatch-mine { background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 0 0 2px rgba(16,185,129,.2); }
        .swatch-occupied { background: #cbd5e1; }
        .swatch-available { background: #ffffff; border: 1.5px dashed var(--green-300); }

        /* ---- FLOOR PLAN ---- */
        .floor-plan {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 56px 1fr;
            background: #fbfcfd;
            border-radius: 18px;
            border: 2px solid var(--line);
            overflow: hidden;
        }

        .central-aisle {
            grid-column: 2;
            grid-row: 1;
            background: repeating-linear-gradient(
                180deg,
                #ecfdf5 0px,
                #ecfdf5 8px,
                #d1fae5 8px,
                #d1fae5 16px
            );
            border-left: 2px dashed var(--green-300);
            border-right: 2px dashed var(--green-300);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .central-aisle::before {
            content: '↑  CENTRAL AISLE  ↑';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(90deg);
            font-size: 10px; font-weight: 900;
            letter-spacing: .28em;
            color: var(--green-700);
            white-space: nowrap;
            background: #ecfdf5;
            padding: 8px 8px;
            border-radius: 4px;
            border: 1px solid var(--green-200);
        }

        .wing { display: flex; flex-direction: column; }
        .wing-left { grid-column: 1; grid-row: 1; }
        .wing-right { grid-column: 3; grid-row: 1; }

        .section-block {
            position: relative;
            padding: 14px 16px;
            border-bottom: 1px dashed var(--line);
            background: #ffffff;
            transition: background .25s var(--ease-smooth);
        }
        .section-block:last-child { border-bottom: none; }
        .section-block:hover { background: var(--green-50); }
        .section-block.is-mine {
            background: linear-gradient(90deg, var(--green-50) 0%, #ffffff 60%);
            box-shadow: inset 4px 0 0 var(--green-500);
        }
        .wing-right .section-block.is-mine {
            box-shadow: inset -4px 0 0 var(--green-500);
        }

        .section-header {
            display: flex; align-items: center;
            justify-content: space-between; gap: 10px;
            margin-bottom: 8px;
        }
        .section-header-left { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .section-letter {
            width: 28px; height: 28px; border-radius: 8px;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            display: grid; place-items: center;
            color: #34d399;
            font-size: 11.5px; font-weight: 800;
            letter-spacing: .05em;
            flex-shrink: 0;
        }
        .section-block.is-mine .section-letter {
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff;
            box-shadow: 0 3px 8px rgba(16,185,129,.5);
        }
        .section-name {
            font-size: 12.5px; font-weight: 800; color: var(--ink);
            line-height: 1.15;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .section-sub {
            font-size: 10px; color: var(--muted);
            letter-spacing: .04em; text-transform: uppercase;
            font-weight: 700; margin-top: 1px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .section-badge {
            padding: 3px 9px; border-radius: 999px;
            font-size: 10px; font-weight: 800;
            background: var(--bg); color: var(--ink-soft);
            border: 1px solid var(--line);
            white-space: nowrap;
        }
        .section-block.is-mine .section-badge {
            background: var(--green-100); color: var(--green-800);
            border-color: var(--green-200);
        }

        .stalls-wrap {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 5px;
        }
        .wing-right .stalls-wrap { direction: rtl; }
        .wing-right .stalls-wrap .cell { direction: ltr; }

        .cell {
            position: relative;
            padding: 8px 3px 7px;
            text-align: center;
            border-radius: 7px;
            background: #ffffff;
            border: 1.5px dashed var(--green-200);
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .02em;
            transition: all .2s var(--ease-spring);
            cursor: default;
            user-select: none;
        }
        .cell.occupied {
            background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
            border: 1px solid #cbd5e1;
            color: var(--ink-soft);
            cursor: help;
        }
        .cell.occupied::after {
            content: '\f05a';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 3px;
            right: 3px;
            font-size: 7px;
            color: var(--green-600);
            opacity: .5;
            transition: opacity .2s;
        }
        .cell.occupied:hover::after { opacity: 1; }
        /* A free stall is something the vendor can ask for, so it has to
           look reachable — that is the whole point of this map. */
        .cell.free {
            border: 1.5px dashed var(--green-300);
            background: linear-gradient(180deg, #ffffff 0%, #f0fdfa 100%);
            color: var(--brand-dark);
            cursor: pointer;
        }
        .cell.free:hover {
            transform: translateY(-2px) scale(1.06);
            border-color: var(--green-500);
            background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%);
            box-shadow: 0 6px 16px rgba(16,185,129,.28);
        }
        /* Already chosen in one of the two forms. */
        .cell.chosen {
            border-color: var(--brand);
            border-style: solid;
            box-shadow: 0 0 0 2px rgba(5,150,105,.28);
        }
        .cell.chosen::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute; top: 3px; left: 3px;
            font-size: 7px; color: var(--brand); opacity: .85;
        }
        .cell.free:focus-visible { outline: 2.5px solid var(--brand); outline-offset: 2px; }
        .cell.mine {
            background: linear-gradient(180deg, #34d399 0%, #059669 100%);
            border: 1.5px solid var(--green-700);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(16,185,129,.45);
            transform: scale(1.06);
            z-index: 3;
            cursor: help;
        }
        .cell.mine::after {
            content: '★';
            position: absolute;
            top: -7px; right: -4px;
            font-size: 12px;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(4,120,87,.9);
        }
        .cell:hover {
            transform: translateY(-2px) scale(1.04);
            box-shadow: 0 6px 14px rgba(2,44,34,.12);
            z-index: 2;
        }
        .cell.mine:hover { transform: translateY(-2px) scale(1.1); }

        /* ============ TOOLTIP ============ */
        .stall-tooltip {
            position: fixed;
            z-index: 500;
            width: 260px;
            background: #ffffff;
            border-radius: 14px;
            border: 1.5px solid var(--green-300);
            box-shadow:
                0 20px 44px rgba(2,44,34,.18),
                0 0 0 4px rgba(16,185,129,.08);
            padding: 12px;
            opacity: 0;
            pointer-events: none;
            transform: translateY(4px) scale(.96);
            transition: opacity .18s var(--ease-smooth), transform .22s var(--ease-spring);
            overflow: hidden;
        }
        .stall-tooltip.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }
        .stall-tooltip::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, #10b981, #047857);
        }

        .tooltip-head {
            display: flex; gap: 10px; align-items: center;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--line);
            margin-bottom: 10px;
        }
        .tooltip-avatar {
            width: 38px; height: 38px; border-radius: 11px;
            background: linear-gradient(135deg, #34d399, #059669);
            display: grid; place-items: center;
            color: #ffffff; font-size: 13px; font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(16,185,129,.35);
        }
        .tooltip-head-info { min-width: 0; flex: 1; }
        .tooltip-name {
            font-size: 13px; font-weight: 800; color: var(--ink);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .tooltip-biz {
            font-size: 11px; color: var(--muted); margin-top: 1px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .tooltip-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 11.5px; color: var(--ink-soft);
            font-weight: 600;
            padding: 4px 0;
        }
        .tooltip-row i {
            width: 18px; text-align: center;
            color: var(--brand);
            font-size: 11px;
        }
        .tooltip-row span {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }


        /* A free stall offers a choice, not a vendor to look at. */
        .tooltip-free-hint { color: var(--brand-dark); font-weight: 700; }
        .tooltip-free-hint[hidden] { display: none; }
        .tooltip-actions[hidden] { display: none; }
        .tooltip-actions {
            display: flex; gap: 6px;
            margin-top: 10px;
        }
        .tooltip-btn {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            gap: 6px;
            padding: 9px 10px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 11.5px; font-weight: 800;
            cursor: pointer;
            transition: all .25s var(--ease-spring);
            white-space: nowrap;
        }
        .tooltip-btn.view {
            background: linear-gradient(135deg, #059669, #065f46);
            color: #ffffff;
            box-shadow: 0 5px 12px rgba(16,185,129,.35);
        }
        .tooltip-btn.view:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(16,185,129,.48);
        }
        .tooltip-btn.message {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #ffffff;
            box-shadow: 0 5px 12px rgba(14,165,233,.35);
        }
        .tooltip-btn.message:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(14,165,233,.48);
        }

        /* ============ VENDOR INFO MODAL ============ */
        .vendor-modal {
            position: fixed; inset: 0;
            background: rgba(2,44,34,.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 600;
            display: none;
            align-items: center; justify-content: center;
            padding: 24px;
            overflow-y: auto;
        }
        .vendor-modal.open { display: flex; }

        .modal-inner {
            background: #ffffff;
            border-radius: 22px;
            max-width: 480px; width: 100%;
            overflow: hidden;
            box-shadow: var(--sh-xl);
            animation: modalIn .35s var(--ease-spring);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(24px) scale(.94); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-head {
            position: relative;
            padding: 28px 26px 22px;
            background:
                radial-gradient(circle at 85% 20%, rgba(52,211,153,.24) 0%, transparent 52%),
                linear-gradient(135deg, #022c22 0%, #04443a 55%, #064e3b 100%);
            color: #ffffff;
            display: flex; align-items: center; gap: 16px;
        }
        .modal-avatar {
            width: 72px; height: 72px; border-radius: 20px; flex-shrink: 0;
            background: linear-gradient(135deg, #34d399, #059669);
            display: grid; place-items: center;
            color: #ffffff; font-size: 24px; font-weight: 800;
            letter-spacing: .04em;
            box-shadow: 0 12px 24px rgba(16,185,129,.4);
        }
        .modal-head-info { flex: 1; min-width: 0; }
        .modal-head-info h2 {
            font-size: 19px; font-weight: 800; color: #ffffff;
            letter-spacing: -.4px; margin-bottom: 3px;
            overflow: hidden; text-overflow: ellipsis;
        }
        .modal-head-info p { font-size: 12.5px; color: #b6c5d3; }
        .modal-close {
            position: absolute;
            top: 16px; right: 16px;
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.16);
            color: #ffffff; font-size: 13px;
            cursor: pointer;
            display: grid; place-items: center;
            transition: all .3s var(--ease-spring);
        }
        .modal-close:hover { background: rgba(255,255,255,.2); transform: rotate(90deg); }

        .modal-body { padding: 20px 26px 8px; }
        .modal-row {
            display: flex; gap: 13px;
            padding: 13px 0;
            border-bottom: 1px dashed var(--line);
            align-items: flex-start;
        }
        .modal-row:last-child { border-bottom: none; }
        .modal-row-icon {
            width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
            background: var(--green-50);
            border: 1px solid var(--green-100);
            color: var(--brand-dark);
            display: grid; place-items: center;
            font-size: 14px;
        }
        .modal-row-content { flex: 1; min-width: 0; }
        .modal-row-label {
            font-size: 10.5px; font-weight: 800;
            color: var(--muted);
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .modal-row-value {
            font-size: 14.5px; font-weight: 600;
            color: var(--ink);
            line-height: 1.5;
            word-break: break-word;
        }
        .modal-row-value.mono { letter-spacing: .04em; }

        .modal-footer {
            padding: 16px 26px 22px;
            border-top: 1px solid var(--line-soft);
            background: #fbfcfd;
            display: flex; gap: 9px; flex-wrap: wrap;
        }
        .modal-action {
            flex: 1; min-width: 120px;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px;
            padding: 13px 16px;
            border-radius: 12px;
            font-family: inherit;
            font-size: 13.5px; font-weight: 700;
            text-decoration: none; cursor: pointer;
            transition: all .25s var(--ease-spring);
            border: none;
        }
        .modal-action.message {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #ffffff;
            box-shadow: 0 5px 14px rgba(14,165,233,.35);
        }
        .modal-action.message:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(14,165,233,.48); }
        .modal-action.ghost {
            background: #ffffff; color: var(--ink-soft);
            border: 1.5px solid var(--line);
            flex: 0 0 auto; min-width: 90px;
        }
        .modal-action.ghost:hover { background: var(--bg); transform: translateY(-2px); }

        /* ============ MESSAGE COMPOSER MODAL ============ */
        .message-modal {
            position: fixed; inset: 0;
            background: rgba(2,44,34,.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 700;
            display: none;
            align-items: center; justify-content: center;
            padding: 24px;
            overflow-y: auto;
        }
        .message-modal.open { display: flex; }

        .message-inner {
            background: #ffffff;
            border-radius: 22px;
            max-width: 540px; width: 100%;
            overflow: hidden;
            box-shadow: var(--sh-xl);
            animation: modalIn .35s var(--ease-spring);
            display: flex; flex-direction: column;
            max-height: 92vh;
        }

        .msg-head {
            position: relative;
            padding: 22px 24px 20px;
            background:
                radial-gradient(circle at 85% 20%, rgba(14,165,233,.28) 0%, transparent 52%),
                linear-gradient(135deg, #022c22 0%, #04443a 55%, #064e3b 100%);
            color: #ffffff;
            display: flex; align-items: center; gap: 14px;
        }
        .msg-head-avatar {
            width: 54px; height: 54px; border-radius: 16px; flex-shrink: 0;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            display: grid; place-items: center;
            color: #ffffff; font-size: 18px; font-weight: 800;
            box-shadow: 0 10px 22px rgba(14,165,233,.42);
        }
        .msg-head-info { flex: 1; min-width: 0; }
        .msg-head-info .msg-head-label {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 10px; font-weight: 800;
            letter-spacing: .12em; text-transform: uppercase;
            color: #7dd3fc;
            margin-bottom: 4px;
        }
        .msg-head-info h3 {
            font-size: 17px; font-weight: 800; color: #ffffff;
            letter-spacing: -.3px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .msg-head-info p { font-size: 12px; color: #b6c5d3; margin-top: 2px; }
        .msg-close {
            position: absolute;
            top: 14px; right: 14px;
            width: 34px; height: 34px; border-radius: 50%;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.16);
            color: #ffffff; font-size: 12px;
            cursor: pointer;
            display: grid; place-items: center;
            transition: all .3s var(--ease-spring);
        }
        .msg-close:hover { background: rgba(255,255,255,.2); transform: rotate(90deg); }

        .msg-body {
            padding: 20px 24px 8px;
            overflow-y: auto;
            flex: 1;
        }
        .msg-body::-webkit-scrollbar { width: 6px; }
        .msg-body::-webkit-scrollbar-thumb { background: rgba(6,78,59,.16); border-radius: 3px; }

        .msg-label {
            font-size: 11px; font-weight: 800;
            color: var(--muted);
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 10px;
            display: flex; align-items: center; gap: 7px;
        }
        .msg-label i { color: var(--blue); }

        .msg-templates {
            display: flex; flex-wrap: wrap; gap: 7px;
            margin-bottom: 18px;
        }
        .msg-template {
            padding: 8px 13px;
            border-radius: 999px;
            background: var(--blue-light);
            border: 1.5px solid #bae6fd;
            color: #075985;
            font-family: inherit;
            font-size: 12px; font-weight: 700;
            cursor: pointer;
            transition: all .25s var(--ease-spring);
            display: inline-flex; align-items: center; gap: 6px;
            max-width: 100%;
        }
        .msg-template i { font-size: 10px; flex-shrink: 0; }
        .msg-template:hover {
            background: #bae6fd;
            border-color: #7dd3fc;
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(14,165,233,.2);
        }
        .msg-template.selected {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            border-color: transparent;
            color: #ffffff;
            box-shadow: 0 5px 14px rgba(14,165,233,.4);
        }

        .msg-textarea-wrap {
            position: relative;
            margin-bottom: 6px;
        }
        .msg-textarea {
            width: 100%;
            min-height: 120px;
            max-height: 240px;
            padding: 14px 15px;
            border: 1.5px solid var(--line);
            border-radius: 14px;
            background: var(--bg);
            font-family: inherit;
            font-size: 14px;
            line-height: 1.55;
            color: var(--ink);
            resize: none;
            outline: none;
            transition: all .25s var(--ease-smooth);
        }
        .msg-textarea::placeholder { color: var(--muted); }
        .msg-textarea:focus {
            background: #ffffff;
            border-color: #7dd3fc;
            box-shadow: 0 0 0 5px rgba(14,165,233,.12);
        }

        .msg-meta {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 11px; font-weight: 700;
            color: var(--muted);
            padding: 6px 2px 0;
        }
        .msg-meta .msg-topic {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--blue-dark);
        }
        .msg-meta .msg-topic i { font-size: 10px; }

        .msg-footer {
            padding: 16px 24px 20px;
            border-top: 1px solid var(--line-soft);
            background: #fbfcfd;
            display: flex; gap: 9px;
        }
        .msg-btn {
            flex: 1;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px;
            padding: 13px 16px;
            border-radius: 12px;
            border: none;
            font-family: inherit;
            font-size: 13.5px; font-weight: 800;
            cursor: pointer;
            transition: all .25s var(--ease-spring);
        }
        .msg-btn.cancel {
            background: #ffffff;
            color: var(--ink-soft);
            border: 1.5px solid var(--line);
            flex: 0 0 auto;
            min-width: 100px;
        }
        .msg-btn.cancel:hover { background: var(--bg); transform: translateY(-2px); }
        .msg-btn.send {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #ffffff;
            box-shadow: 0 5px 14px rgba(14,165,233,.35);
        }
        .msg-btn.send:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(14,165,233,.48);
        }
        .msg-btn.send:disabled {
            background: var(--bg);
            color: #94a3b8;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        /* ============ BUILDING FOOT ============ */
        .building-foot {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 14px;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px dashed var(--line);
        }
        .facilities-left, .facilities-right { display: flex; gap: 8px; flex-wrap: wrap; }
        .facilities-right { justify-content: flex-end; }

        .facility-chip {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 14px;
            border-radius: 11px;
            background: #ffffff;
            border: 1.5px solid var(--line);
            font-size: 12px; font-weight: 700;
            color: var(--ink-soft);
            transition: all .25s var(--ease-spring);
        }
        .facility-chip:hover { border-color: var(--green-300); transform: translateY(-2px); box-shadow: var(--sh-sm); }
        .facility-chip i {
            width: 22px; height: 22px; border-radius: 6px;
            display: grid; place-items: center;
            font-size: 10px;
        }
        .facility-chip.cr i { background: #e0f2fe; color: #075985; }
        .facility-chip.office i { background: #fef3c7; color: #92400e; }
        .facility-chip.loading i { background: #ede9fe; color: #5b21b6; }

        .entrance-marker {
            display: flex; flex-direction: column;
            align-items: center; gap: 4px;
            padding: 10px 24px;
            border-radius: 14px;
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff;
            box-shadow: 0 8px 24px rgba(16,185,129,.42);
            position: relative;
        }
        .entrance-marker::before {
            content: '';
            position: absolute;
            top: -10px; left: 50%;
            transform: translateX(-50%);
            width: 0; height: 0;
            border-left: 10px solid transparent;
            border-right: 10px solid transparent;
            border-bottom: 10px solid #10b981;
        }
        .entrance-marker i { font-size: 16px; }
        .entrance-marker .label { font-size: 10.5px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
        .entrance-marker .sub { font-size: 10px; font-weight: 600; opacity: .9; }

        .compass {
            position: absolute;
            top: 22px; right: 22px;
            width: 56px; height: 56px;
            border-radius: 50%;
            background: radial-gradient(circle, #ecfdf5 0%, #d1fae5 70%, #a7f3d0 100%);
            border: 1.5px solid var(--green-300);
            display: grid; place-items: center;
            box-shadow: var(--sh-sm);
            z-index: 5;
        }
        .compass::before {
            content: 'N';
            position: absolute;
            top: 2px; left: 50%;
            transform: translateX(-50%);
            font-size: 9px; font-weight: 900;
            color: var(--green-800);
        }
        .compass-needle {
            width: 0; height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-bottom: 12px solid #dc2626;
            position: relative;
            transform: translateY(-4px);
        }
        .compass-needle::after {
            content: '';
            position: absolute;
            top: 12px; left: 50%;
            transform: translateX(-50%);
            width: 0; height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 12px solid #94a3b8;
        }

        /* ============ TOAST ============ */
        .toast {
            position: fixed;
            bottom: 30px; left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: linear-gradient(135deg, #022c22, #04443a);
            color: #ffffff;
            padding: 13px 22px;
            border-radius: 14px;
            font-size: 13.5px; font-weight: 700;
            box-shadow: 0 14px 36px rgba(2,44,34,.4);
            display: flex; align-items: center; gap: 10px;
            z-index: 800;
            opacity: 0;
            transition: all .35s var(--ease-spring);
            pointer-events: none;
            border: 1px solid rgba(52,211,153,.28);
            max-width: 90%;
        }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast i { color: #34d399; font-size: 15px; }
        .toast.error { border-color: rgba(239,68,68,.4); }
        .toast.error i { color: #f87171; }
        .toast.info { border-color: rgba(14,165,233,.4); }
        .toast.info i { color: #7dd3fc; }@media (max-width: 900px){
            .market-building { padding: 20px 16px; border-radius: 22px; }}@media (max-width: 760px){
            .floor-plan { grid-template-columns: 1fr; }
            .central-aisle {
                grid-column: 1; grid-row: auto; order: 2;
                min-height: 46px;
                border-left: none; border-right: none;
                border-top: 2px dashed var(--green-300);
                border-bottom: 2px dashed var(--green-300);
            }
            .central-aisle::before { transform: translate(-50%, -50%); }
            .wing-left  { grid-column: 1; grid-row: 1; order: 1; border-bottom: 1px solid var(--line); }
            .wing-right { grid-column: 1; grid-row: auto; order: 3; }
            .building-foot { grid-template-columns: 1fr; gap: 12px; }
            .facilities-right { justify-content: flex-start; }
            .entrance-marker { order: -1; }}@media (max-width: 600px){
            .stalls-wrap { grid-template-columns: repeat(3, 1fr); }
            .building-legend { font-size: 11px; gap: 10px; }
            .legend-swatch { width: 13px; height: 13px; }
            .compass { width: 46px; height: 46px; top: 14px; right: 14px; }
            .stall-tooltip { width: 220px; }}@media (max-width: 420px){
            .stalls-wrap { grid-template-columns: repeat(2, 1fr); }}

        @media print {
            .sidebar, .mobile-toggle, .sidebar-backdrop, .map-strip,
            .stall-tooltip, .vendor-modal, .message-modal, .toast { display: none !important; }
            .main-content { margin-left: 0; width: 100%; padding: 0; }
            .market-building { box-shadow: none; border-radius: 0; }
            .market-building::before { display: none; }
        }
        /* this page's own main-content tweaks; the shell owns the rest */
        .main-content{max-width: none;}

    </style>
</head>
<body>

<?php /* A keyboard user should not have to walk the sidebar to reach the map. */ ?>
<a class="skip-link" href="#main-content">Skip to the stall map</a>

<?php require __DIR__ . '/../includes/vendor_sidebar.php'; ?>

<main class="main-content" id="main-content" tabindex="-1">

        <!-- ==================== GET A STALL ====================
             The request itself lives on its own page now, so this one is
             only the plan. Asking for a stall, and the conversation that
             follows, is php/stall_apply.php. -->
        <div class="apply-card tone-<?= htmlspecialchars($myStatus['tone']) ?>">
            <div class="apply-head">
                <div class="apply-head-icon">
                    <i class="fas <?= $myStatus['state'] === 'placed' ? 'fa-store' : ($myStatus['state'] === 'placed_declined' ? 'fa-store' : 'fa-hand-paper') ?>" aria-hidden="true"></i>
                </div>
                <div>
                    <h2><?= htmlspecialchars($myStatus['title']) ?></h2>
                    <?php if ($myStatus['body'] !== ''): ?>
                        <p><?= htmlspecialchars($myStatus['body']) ?></p>
                    <?php elseif ($myStatus['state'] === 'placed'): ?>
                        <p>The market office assigns stalls, so this one is yours until they move you.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="apply-body">
                <?php if ($amUnread > 0): ?>
                    <div class="apply-note" style="margin-top:0;">
                        <span class="waiting-pill"><i class="fas fa-envelope" aria-hidden="true"></i>
                            <?= (int)$amUnread ?> new <?= $amUnread === 1 ? 'message' : 'messages' ?> from the market office</span>
                        <a href="stall_apply.php#officeThread">Read and reply</a>
                    </div>
                <?php endif; ?>

                <?php if ($myStatus['state'] === 'placed'): ?>
                    <p class="apply-note" style="margin-top:0;">
                        You already trade at a stall, so there is nothing to apply for. You can still ask
                        the office to move you &mdash; use the button below.
                    </p>
                <?php else: ?>
                    <p class="apply-note" style="margin-top:0;">
                        Pick a free stall on the plan below, then apply for it on your stall page.
                        Only the market office assigns stalls.
                    </p>
                <?php endif; ?>

                <a href="stall_apply.php" class="apply-submit">
                    <i class="fas <?= $myStatus['state'] === 'waiting' ? 'fa-pen' : 'fa-hand-paper' ?>" aria-hidden="true"></i>
                    <?= $myStatus['state'] === 'waiting' ? 'Review or replace your request' : 'Apply for a stall' ?>
                </a>
            </div>
        </div>

        <!-- Stats strip -->
        <div class="map-strip">
            <div class="strip-stat">
                <div class="strip-icon gold"><i class="fas fa-store-alt" aria-hidden="true"></i></div>
                <div>
                    <div class="strip-num"><?= $totalStalls ?></div>
                    <div class="strip-lbl">Total Stalls</div>
                </div>
            </div>
            <div class="strip-stat">
                <div class="strip-icon slate"><i class="fas fa-user-check" aria-hidden="true"></i></div>
                <div>
                    <div class="strip-num"><?= $totalOcc ?></div>
                    <div class="strip-lbl">Occupied</div>
                </div>
            </div>
            <div class="strip-stat">
                <div class="strip-icon green"><i class="fas fa-door-open" aria-hidden="true"></i></div>
                <div>
                    <div class="strip-num"><?= $totalAvail ?></div>
                    <div class="strip-lbl">Available</div>
                </div>
            </div>
            <div class="strip-stat">
                <div class="strip-icon amber"><i class="fas fa-chart-pie" aria-hidden="true"></i></div>
                <div>
                    <div class="strip-num"><?= $pct ?>%</div>
                    <div class="strip-lbl">Occupancy</div>
                </div>
            </div>
        </div>

        <!-- MARKET BUILDING -->
        <div class="market-building">

            <div class="compass" aria-hidden="true">
                <div class="compass-needle"></div>
            </div>

            <div class="building-header">
                <div class="building-title">
                    <div class="building-title-icon"><i class="fas fa-warehouse" aria-hidden="true"></i></div>
                    <div>
                        <h2>Mallengke Market — Floor Plan</h2>
                        <p>
                            <i class="fas fa-location-dot" aria-hidden="true"></i>
                            National Highway, Brgy. Poblacion, Tupi, South Cotabato
                        </p>
                    </div>
                </div>

                <div class="building-legend">
                    <span class="legend-item">
                        <span class="legend-swatch swatch-mine"></span> My stall
                    </span>
                    <span class="legend-item">
                        <span class="legend-swatch swatch-occupied"></span> Occupied
                    </span>
                    <span class="legend-item">
                        <span class="legend-swatch swatch-available"></span> Available
                    </span>
                </div>
            </div>

            <!-- Floor plan -->
            <div class="floor-plan">

                <!-- LEFT WING -->
                <div class="wing wing-left">
                    <?php foreach ($leftWing as $item):
                        $section = $item['section'];
                        $letter  = $item['letter'];
                        $stalls  = $layout[$section] ?? [];
                        if (empty($stalls)) continue;

                        $occ = 0;
                        foreach ($stalls as $s) { if (!empty($occupied[strtoupper($s)])) $occ++; }
                        $isMy = ($section === $mySection);
                    ?>
                        <div class="section-block <?= $isMy ? 'is-mine' : '' ?>">
                            <div class="section-header">
                                <div class="section-header-left">
                                    <div class="section-letter"><?= htmlspecialchars($letter) ?></div>
                                    <div>
                                        <div class="section-name"><?= htmlspecialchars($section) ?></div>
                                        <div class="section-sub"><?= count($stalls) ?> stalls<?= $isMy ? ' · your section' : '' ?></div>
                                    </div>
                                </div>
                                <span class="section-badge"><?= $occ ?>/<?= count($stalls) ?></span>
                            </div>
                            <div class="stalls-wrap">
                                <?php foreach ($stalls as $s):
                                    $up = strtoupper($s);
                                    $isMineStall = ($myStall === $up);
                                    $isOcc = !empty($occupied[$up]);
$cls = 'cell' . ($isMineStall ? ' mine' : ($isOcc ? ' occupied' : ' free'));
                                ?>
<div class="<?= $cls ?>" data-stall="<?= htmlspecialchars($up) ?>"<?= $isOcc ? '' : ' role="button" tabindex="0" title="' . htmlspecialchars($up . ' is free - click to choose it') . '"' ?>><?= htmlspecialchars($s) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- CENTRAL AISLE -->
                <div class="central-aisle" aria-hidden="true"></div>

                <!-- RIGHT WING -->
                <div class="wing wing-right">
                    <?php foreach ($rightWing as $item):
                        $section = $item['section'];
                        $letter  = $item['letter'];
                        $stalls  = $layout[$section] ?? [];
                        if (empty($stalls)) continue;

                        $occ = 0;
                        foreach ($stalls as $s) { if (!empty($occupied[strtoupper($s)])) $occ++; }
                        $isMy = ($section === $mySection);
                    ?>
                        <div class="section-block <?= $isMy ? 'is-mine' : '' ?>">
                            <div class="section-header">
                                <div class="section-header-left">
                                    <div class="section-letter"><?= htmlspecialchars($letter) ?></div>
                                    <div>
                                        <div class="section-name"><?= htmlspecialchars($section) ?></div>
                                        <div class="section-sub"><?= count($stalls) ?> stalls<?= $isMy ? ' · your section' : '' ?></div>
                                    </div>
                                </div>
                                <span class="section-badge"><?= $occ ?>/<?= count($stalls) ?></span>
                            </div>
                            <div class="stalls-wrap">
                                <?php foreach ($stalls as $s):
                                    $up = strtoupper($s);
                                    $isMineStall = ($myStall === $up);
                                    $isOcc = !empty($occupied[$up]);
$cls = 'cell' . ($isMineStall ? ' mine' : ($isOcc ? ' occupied' : ' free'));
                                ?>
<div class="<?= $cls ?>" data-stall="<?= htmlspecialchars($up) ?>"<?= $isOcc ? '' : ' role="button" tabindex="0" title="' . htmlspecialchars($up . ' is free - click to choose it') . '"' ?>><?= htmlspecialchars($s) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
            <!-- /.floor-plan -->

            <!-- Building footer -->
            <div class="building-foot">
                <div class="facilities-left">
                    <span class="facility-chip loading"><i class="fas fa-truck" aria-hidden="true"></i> Loading Bay</span>
                    <span class="facility-chip cr"><i class="fas fa-restroom" aria-hidden="true"></i> Comfort Rooms</span>
                </div>

                <div class="entrance-marker">
                    <i class="fas fa-door-open" aria-hidden="true"></i>
                    <span class="label">Main Entrance</span>
                    <span class="sub">National Highway</span>
                </div>

                <div class="facilities-right">
                    <span class="facility-chip office"><i class="fas fa-building" aria-hidden="true"></i> Market Office</span>
                </div>
            </div>

        </div>
        <!-- /.market-building -->

    </main>

    <!-- ============ HOVER TOOLTIP ============ -->
<div class="stall-tooltip" id="stallTooltip" role="tooltip" aria-hidden="true">
        <div class="tooltip-head">
            <div class="tooltip-avatar" id="ttAvatar">--</div>
            <div class="tooltip-head-info">
                <div class="tooltip-name" id="ttName">—</div>
                <div class="tooltip-biz" id="ttBiz">—</div>
            </div>
        </div>
        <div class="tooltip-row">
            <i class="fas fa-store" aria-hidden="true"></i>
            <span id="ttStall">—</span>
        </div>
        <div class="tooltip-row">
            <i class="fas fa-phone" aria-hidden="true"></i>
            <span id="ttContact">—</span>
        </div>
        <div class="tooltip-row tooltip-free-hint" id="ttFreeHint" hidden>
            <i class="fas fa-hand-pointer" aria-hidden="true"></i>
            <span>Click to choose this stall</span>
        </div>
<div class="tooltip-actions" id="ttActions">
            <button type="button" class="tooltip-btn view" id="ttViewBtn">
                <i class="fas fa-circle-info" aria-hidden="true"></i> View
            </button>
            <button type="button" class="tooltip-btn message" id="ttMsgBtn">
                <i class="fas fa-comment-dots" aria-hidden="true"></i> Message
            </button>
        </div>
    </div>

    <!-- ============ VENDOR INFO MODAL ============ -->
<div class="vendor-modal" id="vendorModal" role="dialog" aria-modal="true" aria-labelledby="mName">
        <div class="modal-inner" onclick="event.stopPropagation()">
            <div class="modal-head">
                <div class="modal-avatar" id="mAvatar">--</div>
                <div class="modal-head-info">
                    <h2 id="mName">—</h2>
                    <p id="mBiz">—</p>
                </div>
<button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-location-dot" aria-hidden="true"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Stall Location</div>
                        <div class="modal-row-value mono" id="mStall">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-layer-group" aria-hidden="true"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Market Section</div>
                        <div class="modal-row-value" id="mSection">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-box-open" aria-hidden="true"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Products Sold</div>
                        <div class="modal-row-value" id="mProducts">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-phone" aria-hidden="true"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Contact Number</div>
                        <div class="modal-row-value mono" id="mContact">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Years in Business</div>
                        <div class="modal-row-value" id="mYears">—</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="modal-action message" id="mMsgBtn">
                    <i class="fas fa-comment-dots" aria-hidden="true"></i> Message
                </button>
                <button type="button" class="modal-action ghost" onclick="closeModal()">
                    <i class="fas fa-xmark" aria-hidden="true"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- ============ MESSAGE COMPOSER MODAL ============ -->
<div class="message-modal" id="messageModal" role="dialog" aria-modal="true" aria-labelledby="msgName">
        <div class="message-inner" onclick="event.stopPropagation()">
            <div class="msg-head">
                <div class="msg-head-avatar" id="msgAvatar">--</div>
                <div class="msg-head-info">
                    <div class="msg-head-label">
                        <i class="fas fa-right-left" aria-hidden="true"></i>
                        Stall Swap Inquiry
                    </div>
                    <h3 id="msgName">—</h3>
                    <p id="msgSub">—</p>
                </div>
<button type="button" class="msg-close" onclick="closeMessageComposer()" aria-label="Close">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="msg-body">
                <div class="msg-label">
                    <i class="fas fa-bolt" aria-hidden="true"></i>
                    Quick templates
                </div>
                <div class="msg-templates" id="msgTemplates">
                    <button type="button" class="msg-template" data-msg="Hi! I'm interested in your stall. Would you be open to swapping with mine?">
                        <i class="fas fa-right-left" aria-hidden="true"></i> Swap stalls?
                    </button>
                    <button type="button" class="msg-template" data-msg="Good day! I'd like to inquire if you're willing to relocate or exchange stalls with me. Let me know if we can discuss.">
                        <i class="fas fa-arrows-rotate" aria-hidden="true"></i> Open to relocating?
                    </button>
                    <button type="button" class="msg-template" data-msg="Hello! I noticed your stall location — would you consider a stall swap? I'm happy to negotiate.">
                        <i class="fas fa-handshake" aria-hidden="true"></i> Consider a swap?
                    </button>
                    <button type="button" class="msg-template" data-msg="Hi! Just checking if your stall is available for a swap arrangement. I can offer my stall location in return.">
                        <i class="fas fa-repeat" aria-hidden="true"></i> Swap arrangement
                    </button>
                </div>

                <div class="msg-textarea-wrap">
<textarea class="msg-textarea" id="msgTextarea" name="message" aria-label="Your message to this vendor"
                              placeholder="Write your message…"
                              maxlength="1000"></textarea>
                </div>

                <div class="msg-meta">
                    <span class="msg-topic">
                        <i class="fas fa-tag" aria-hidden="true"></i> Topic: Stall Swap
                    </span>
                    <span id="msgCharCount">0 / 1000</span>
                </div>
            </div>

            <div class="msg-footer">
                <button type="button" class="msg-btn cancel" onclick="closeMessageComposer()">
                    Cancel
                </button>
                <button type="button" class="msg-btn send" id="msgSendBtn" disabled>
                    <i class="fas fa-paper-plane" aria-hidden="true"></i>
                    <span id="msgSendLabel">Send Message</span>
                </button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
<div class="toast" id="toast" role="status" aria-live="polite">
        <i class="fas fa-check-circle" aria-hidden="true"></i>
        <span id="toastMsg">Message sent</span>
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

        /* ============================================================
           Server data
           ============================================================ */
        const stallData = <?= json_encode($vendorInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const myId      = <?= (int)$myId ?>;
        const myStall   = <?= json_encode($myStall) ?>;
        const mySection = <?= json_encode($mySection) ?>;

        /* What the plan needs that only the server knows: which stalls
           exist and are free, and how many vendors want each one.

           A foreach, not array_reduce: the card needs the section NAME
           for each stall, and reduce only ever hands over the list of
           codes, so every stall came out labelled with a whole row. */
        const freeStalls  = <?= json_encode((function () use ($layout, $occupied) {
            $out = [];
            foreach ($layout as $section => $codes) {
                foreach ($codes as $code) {
                    $up = strtoupper($code);
                    if (!isset($occupied[$up])) $out[$up] = $section;
                }
            }
            return $out;
        })(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const freeWaiting = <?= json_encode($stallWaiting, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;


        /* ============================================================
           Taking a stall to the request page
           ------------------------------------------------------------
           The forms now live on php/stall_apply.php, so a tap here is a
           hand-off rather than a selection: the stall rides along in the
           query string and the apply page preselects it.
           ============================================================ */
        function chooseStall(stall) {
            stall = (stall || '').toUpperCase();
            if (!freeStalls[stall]) return;   /* only a free stall can be asked for */

            document.querySelectorAll('.cell.chosen').forEach(c => c.classList.remove('chosen'));
            const cell = document.querySelector('.cell[data-stall="' + stall + '"]');
            if (cell) cell.classList.add('chosen');

            location.href = 'stall_apply.php?stall=' + encodeURIComponent(stall);
        }

        /* ============================================================
           Hover tooltip
           ============================================================ */
        const tooltip   = document.getElementById('stallTooltip');
        const ttAvatar  = document.getElementById('ttAvatar');
        const ttName    = document.getElementById('ttName');
        const ttBiz     = document.getElementById('ttBiz');
        const ttStall   = document.getElementById('ttStall');
        const ttContact = document.getElementById('ttContact');
        const ttViewBtn = document.getElementById('ttViewBtn');
        const ttMsgBtn  = document.getElementById('ttMsgBtn');

        let hoveredStall = null;
        let tooltipHideTimer = null;

        function positionTooltip(e) {
            const rect = tooltip.getBoundingClientRect();
            const pad  = 14;
            let x = e.clientX + pad;
            let y = e.clientY + pad;

            if (x + rect.width > window.innerWidth - pad) x = e.clientX - rect.width - pad;
            if (y + rect.height > window.innerHeight - pad) y = e.clientY - rect.height - pad;
            if (x < pad) x = pad;
            if (y < pad) y = pad;

            tooltip.style.left = x + 'px';
            tooltip.style.top  = y + 'px';
        }

        /* A free stall has no vendor to describe, so it gets its own
           card: what it is, and the one thing the vendor can do. */
        const ttContactRow = ttContact ? ttContact.closest('.tooltip-row') : null;
        const ttActions    = document.getElementById('ttActions');
        const ttFreeHint   = document.getElementById('ttFreeHint');

        function showTooltipFor(cell, e) {
            const stall = (cell.dataset.stall || '').toUpperCase();
            const data  = stallData[stall];
            const free  = !data;

            /* Nothing to say about a code the market does not have. */
            if (!data && !freeStalls[stall]) return;

            hoveredStall = stall;

            if (free) {
                ttAvatar.textContent = '+';
                ttName.textContent   = 'Stall ' + stall + ' is free';
                ttBiz.textContent    = freeStalls[stall] + ' · nobody is trading here'
                                       + (freeWaiting[stall] ? ' · ' + freeWaiting[stall] + ' waiting' : '');
                ttStall.textContent = 'Click it to choose this stall below.';
                if (ttContactRow) ttContactRow.hidden = true;
                if (ttActions)    ttActions.hidden    = true;
                if (ttFreeHint)   ttFreeHint.hidden   = false;
            } else {
                ttAvatar.textContent  = data.initials || '--';
                ttName.textContent    = data.name || 'Unnamed vendor';
                ttBiz.textContent     = data.business || 'Independent vendor';
                ttStall.textContent   = stall + (data.section ? ' · ' + data.section : '');
                ttContact.textContent = data.contact || 'Not provided';
                if (ttContactRow) ttContactRow.hidden = false;
                if (ttActions)    ttActions.hidden    = false;
                if (ttFreeHint)   ttFreeHint.hidden   = true;

                /* Hide Message button if this is my own stall */
                ttMsgBtn.style.display = (stall === myStall) ? 'none' : '';
            }

            clearTimeout(tooltipHideTimer);
            tooltip.classList.add('show');
            tooltip.setAttribute('aria-hidden', 'false');
            positionTooltip(e);
        }

        function hideTooltip() {
            tooltipHideTimer = setTimeout(() => {
                tooltip.classList.remove('show');
                tooltip.setAttribute('aria-hidden', 'true');
                hoveredStall = null;
            }, 120);
        }

        /* Every stall on the plan answers to hover and click. Taken ones
           describe the vendor; free ones offer to become the choice. */
        document.querySelectorAll('.cell').forEach(cell => {
            const isTaken = !!stallData[cell.dataset.stall];
            cell.addEventListener('mouseenter', (e) => showTooltipFor(cell, e));
            cell.addEventListener('mousemove', positionTooltip);
            cell.addEventListener('mouseleave', hideTooltip);
            cell.addEventListener('click', (e) => {
                showTooltipFor(cell, e);
                if (!isTaken) chooseStall(cell.dataset.stall);
            });
            /* Enter and Space, because a free cell behaves as a button. */
            if (!isTaken) {
                cell.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter' && e.key !== ' ') return;
                    e.preventDefault();
                    chooseStall(cell.dataset.stall);
                });
            }
        });

        /* Start with whatever they already asked for marked as chosen. */
        (function markExistingChoice() {
            const want = <?= json_encode(
                strtoupper(trim((string)($myApplication['requested_stall'] ?? ''))),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?>;
            if (!want) return;
            const cell = document.querySelector('.cell[data-stall="' + want + '"]');
            if (cell) cell.classList.add('chosen');
        })();

        function hideTooltip() {
            tooltipHideTimer = setTimeout(() => {
                tooltip.classList.remove('show');
                hoveredStall = null;
            }, 120);
        }

        document.querySelectorAll('.cell.occupied, .cell.mine').forEach(cell => {
            cell.addEventListener('mouseenter', (e) => showTooltipFor(cell, e));
            cell.addEventListener('mousemove', positionTooltip);
            cell.addEventListener('mouseleave', hideTooltip);
            cell.addEventListener('click', (e) => showTooltipFor(cell, e));
        });

        tooltip.addEventListener('mouseenter', () => clearTimeout(tooltipHideTimer));
        tooltip.addEventListener('mouseleave', hideTooltip);

        /* ============================================================
           Vendor info modal
           ============================================================ */
        const modal = document.getElementById('vendorModal');
        let currentStall = null;

        function openModalFor(stall) {
            const data = stallData[stall];
            if (!data) return;

            currentStall = stall;

            document.getElementById('mAvatar').textContent  = data.initials || '--';
            document.getElementById('mName').textContent    = data.name || 'Unnamed vendor';
            document.getElementById('mBiz').textContent     = data.business || 'Independent vendor';
            document.getElementById('mStall').textContent   = stall;
            document.getElementById('mSection').textContent = data.section || '—';
            document.getElementById('mProducts').textContent= data.products || 'Not provided';
            document.getElementById('mContact').textContent = data.contact || 'Not provided';
            document.getElementById('mYears').textContent   = data.years > 0
                ? data.years + ' year' + (data.years > 1 ? 's' : '')
                : 'Not specified';

            /* Hide Message button if it's my own stall */
            const msgAction = document.getElementById('mMsgBtn');
            msgAction.style.display = (stall === myStall) ? 'none' : '';

            modal.classList.add('open');
focusOnOpen(modal);
            document.body.style.overflow = 'hidden';
        }

        /* Focus belongs to the dialog while it is open, and goes back
           where it came from when it closes. Without this, Tab walks the
           page behind the overlay and Escape drops the caret at the top. */
        let lastFocused = null;

        function canTakeFocus(el) {
            return !!el && el.isConnected && typeof el.focus === 'function';
        }

        function restoreFocus(fallback) {
            if (canTakeFocus(lastFocused) && lastFocused !== document.body) {
                lastFocused.focus();
                if (document.activeElement === lastFocused) return;
            }
            /* Whatever the caret was on is gone or cannot hold focus, so
               put it somewhere real rather than leaving it inside a
               dialog that is no longer open. */
            const target = (fallback && canTakeFocus(fallback)) ? fallback : document.getElementById('main-content');
            if (target && typeof target.focus === 'function') target.focus();
        }

        function focusOnOpen(dialog, prefer) {
            lastFocused = document.activeElement;
            /* A dialog whose first control is "Close" is technically fine
               but reads oddly on open. Where the dialog has one obvious
               thing to do, start there instead. */
            const target = (prefer && dialog.querySelector(prefer)) || dialog.querySelector(
                'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            (target || dialog).focus();
        }

        /* Keep Tab inside the open dialog, and wrap at both ends. */
        function trapTab(e, dialog) {
            if (e.key !== 'Tab') return;
            const stops = [...dialog.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
            )].filter(el => el.offsetParent !== null || el === document.activeElement);
            if (!stops.length) return;

            const first = stops[0];
            const last  = stops[stops.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        function closeModal() {
            modal.classList.remove('open');
restoreFocus();
            document.body.style.overflow = '';
        }

        ttViewBtn.addEventListener('click', () => {
            if (hoveredStall) {
                openModalFor(hoveredStall);
                tooltip.classList.remove('show');
            }
        });

        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        /* ============================================================
           Message composer
           ============================================================ */
        const msgModal   = document.getElementById('messageModal');
        const msgAvatar  = document.getElementById('msgAvatar');
        const msgName    = document.getElementById('msgName');
        const msgSub     = document.getElementById('msgSub');
        const msgTextarea= document.getElementById('msgTextarea');
        const msgSendBtn = document.getElementById('msgSendBtn');
        const msgCharCt  = document.getElementById('msgCharCount');
        const msgSendLbl = document.getElementById('msgSendLabel');
        const templates  = document.querySelectorAll('.msg-template');

        let messageTargetStall = null;
        let messageTargetData  = null;

        function openMessageComposer(stall) {
            const data = stallData[stall];
            if (!data) return;

            messageTargetStall = stall;
            messageTargetData  = data;

            msgAvatar.textContent = data.initials || '--';
            msgName.textContent   = data.name || 'Unnamed vendor';
            msgSub.textContent    = 'Stall ' + stall + (data.section ? ' · ' + data.section : '');

            /* Reset */
            msgTextarea.value = '';
            templates.forEach(t => t.classList.remove('selected'));
            updateCharCount();
            updateSendState();

            /* Close other modals */
            closeModal();
            tooltip.classList.remove('show');

            msgModal.classList.add('open');
focusOnOpen(msgModal, '#msgTextarea');
            document.body.style.overflow = 'hidden';

        }

        function closeMessageComposer() {
            msgModal.classList.remove('open');
restoreFocus();
            document.body.style.overflow = '';
            messageTargetStall = null;
            messageTargetData  = null;
        }

        function updateCharCount() {
            const len = msgTextarea.value.length;
            msgCharCt.textContent = len + ' / 1000';
            msgCharCt.style.color = (len > 900) ? '#dc2626' : '';
        }
        function updateSendState() {
            msgSendBtn.disabled = msgTextarea.value.trim().length === 0;
        }

        msgTextarea.addEventListener('input', () => {
            updateCharCount();
            updateSendState();
            templates.forEach(t => {
                t.classList.toggle('selected', t.dataset.msg === msgTextarea.value);
            });
        });

        templates.forEach(t => {
            t.addEventListener('click', () => {
                const msg = t.dataset.msg;
                if (msgTextarea.value === msg) {
                    msgTextarea.value = '';
                    t.classList.remove('selected');
                } else {
                    msgTextarea.value = msg;
                    templates.forEach(x => x.classList.remove('selected'));
                    t.classList.add('selected');
                }
                updateCharCount();
                updateSendState();
                msgTextarea.focus();
            });
        });

        /* Open composer from tooltip */
        ttMsgBtn.addEventListener('click', () => {
            if (hoveredStall) openMessageComposer(hoveredStall);
        });

        /* Open composer from modal */
        document.getElementById('mMsgBtn').addEventListener('click', () => {
            if (currentStall) openMessageComposer(currentStall);
        });

        /* ============================================================
           Send message
           ============================================================ */
        msgSendBtn.addEventListener('click', async () => {
            if (!messageTargetStall || !messageTargetData) return;
            const body = msgTextarea.value.trim();
            if (!body) return;

            msgSendBtn.disabled = true;
            msgSendLbl.textContent = 'Sending…';

            const payload = {
                from_vendor_id: myId,
                to_vendor_id:   messageTargetData.id,
                to_stall:       messageTargetStall,
                topic:          'stall_swap',
                message:        body
            };

            try {
                const res = await fetch('send_vendor_message.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                });

                if (!res.ok) throw new Error('Send failed');

                closeMessageComposer();
                showToast('Message sent to ' + (messageTargetData.name || 'vendor'), 'success');
            } catch (err) {
                msgSendLbl.textContent = 'Send Message';
                msgSendBtn.disabled = false;
                showToast('Could not send message. Please try again.', 'error');
            }
        });

        /* Escape closes topmost overlay */
        document.addEventListener('keydown', (e) => {
            /* Escape has to be tested first: an early return whenever a
               dialog was open used to mean Escape closed nothing. */
            if (e.key === 'Tab') {
                if (msgModal.classList.contains('open')) { trapTab(e, msgModal); return; }
                if (modal.classList.contains('open'))    { trapTab(e, modal);    return; }
                return;
            }

            if (e.key === 'Escape') {
                if (msgModal.classList.contains('open')) closeMessageComposer();
                else if (modal.classList.contains('open')) closeModal();
                else tooltip.classList.remove('show');
            }
        });

        /* ============================================================
           Toast
           ============================================================ */
        let toastTimer;
        function showToast(msg, type) {
            const toast = document.getElementById('toast');
            const icon  = toast.querySelector('i');
            document.getElementById('toastMsg').textContent = msg;

            toast.classList.remove('error', 'info');
            if (type === 'error') {
                toast.classList.add('error');
                icon.className = 'fas fa-exclamation-circle';
            } else if (type === 'info') {
                toast.classList.add('info');
                icon.className = 'fas fa-info-circle';
            } else {
                icon.className = 'fas fa-check-circle';
            }

            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 2600);
        }
    </script>

</body>
</html>
