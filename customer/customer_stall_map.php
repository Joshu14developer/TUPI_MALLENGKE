<?php
$conn = require __DIR__ . '/../config/db.php';
session_start();

/* Customer must be signed in */
if (!isset($_SESSION['customer_id'])) {
    header('Location: customer_login.php');
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    session_destroy();
    header('Location: customer_login.php');
    exit;
}

$customerName = trim($me['first_name'] . ' ' . $me['last_name']);
$initials     = strtoupper(substr($me['first_name'], 0, 1) . substr($me['last_name'], 0, 1));

/* ============================================================
   Fetch ALL vendors with full details
   ============================================================ */
$vendorInfo = [];
$occupied   = [];
$res = $conn->query("SELECT id, stall_number, first_name, middle_name, last_name,
                            business_name, contact_number, product_type,
                            years_in_business, market_section
                       FROM vendors
                      WHERE stall_number IS NOT NULL AND stall_number <> ''");
while ($row = $res->fetch_assoc()) {
    $key = strtoupper($row['stall_number']);
    $occupied[$key] = true;

    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $ini      = strtoupper(substr($row['first_name'] ?? 'U', 0, 1) . substr($row['last_name'] ?? '', 0, 1));

    $vendorInfo[$key] = [
        'id'        => (int)$row['id'],
        'name'      => $fullName ?: 'Unnamed vendor',
        'business'  => $row['business_name'] ?: 'Independent vendor',
        'contact'   => $row['contact_number'] ?: '',
        'products'  => $row['product_type'] ?: '',
        'years'     => (int)($row['years_in_business'] ?? 0),
        'section'   => $row['market_section'] ?: '',
        'initials'  => $ini,
    ];
}

/* Layout and wings come from config/stalls.php, the same source the
   vendor and office maps read, so the three can never drift apart. */
require_once __DIR__ . '/../config/stalls.php';

$layout     = stall_layout();
list($leftWing, $rightWing) = stall_wings();

/* ============================================================
   Deep link: Browse Market sends the customer here as ?stall=A-12
   so they land on that vendor's stall instead of hunting for it.
   Upper-cased because that is how stall codes are keyed below, and
   checked against the real plan so a stale link cannot spotlight a
   stall that does not exist.
   ============================================================ */
$focusStall = strtoupper(trim((string)($_GET['stall'] ?? '')));
if ($focusStall !== '') {
    $focusKnown = false;
    foreach ($layout as $codes) {
        if (in_array($focusStall, array_map('strtoupper', $codes), true)) { $focusKnown = true; break; }
    }
    if (!$focusKnown) $focusStall = '';
}

/* Section meta */
$sectionMeta = [
    'Meat'                => ['icon' => 'fa-drumstick-bite', 'color' => '#dc2626', 'class' => 'meat'],
    'Fish & Seafood'      => ['icon' => 'fa-fish',           'color' => '#0284c7', 'class' => 'fish'],
    'Vegetables'          => ['icon' => 'fa-carrot',         'color' => '#059669', 'class' => 'veg'],
    'Fruits'              => ['icon' => 'fa-apple-whole',    'color' => '#f59e0b', 'class' => 'fruit'],
    'Flowers'             => ['icon' => 'fa-fan',            'color' => '#db2777', 'class' => 'flower'],
    'Rice & Grains'       => ['icon' => 'fa-wheat-awn',      'color' => '#a16207', 'class' => 'rice'],
    'Dry Goods'           => ['icon' => 'fa-box-open',       'color' => '#7c3aed', 'class' => 'dry'],
    'Bread & Pastries'    => ['icon' => 'fa-bread-slice',    'color' => '#ea580c', 'class' => 'bread'],
    'Processed Foods'     => ['icon' => 'fa-jar',            'color' => '#0891b2', 'class' => 'proc'],
    'Eatery / Carinderia' => ['icon' => 'fa-bowl-food',      'color' => '#b91c1c', 'class' => 'eatery'],
    'Others'              => ['icon' => 'fa-shop',           'color' => '#64748b', 'class' => 'others'],
];

$totalStalls = 0;
$totalOcc    = 0;
foreach ($layout as $stalls) {
    $totalStalls += count($stalls);
    foreach ($stalls as $s) {
        if (!empty($occupied[strtoupper($s)])) $totalOcc++;
    }
}
$totalAvail = $totalStalls - $totalOcc;
$pct        = $totalStalls > 0 ? round(($totalOcc / $totalStalls) * 100) : 0;
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
:root{--blue:#0ea5e9;--blue-dark:#0284c7;--blue-light:#e0f2fe;}


        html { scroll-behavior: smooth; }
        .sidebar-link:hover .sidebar-count{transform:scale(1.12)}

        .page-title { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .page-title-icon {
            width: 44px; height: 44px; border-radius: 14px; flex-shrink: 0;
            background: linear-gradient(135deg, #10b981, #047857);
            display: grid; place-items: center;
            color: #fff; font-size: 18px;
            box-shadow: var(--glow-soft);
            transition: transform .35s var(--ease-spring);
        }
        .page-title:hover .page-title-icon { transform: rotate(-8deg) scale(1.06); }
        .page-title h1 { font-size: 21px; font-weight: 900; color: var(--ink); letter-spacing: -.5px; }
        .page-title p { font-size: 12.5px; color: var(--muted); margin-top: 2px; font-weight: 500; }
        .stat-pill i { font-size: 12px; }
        .stat-pill strong { color: var(--ink); font-weight: 900; }
        .stat-pill.occupied i { color: var(--green-700); }
        .stat-pill.available i { color: #0ea5e9; }
        .customer-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 16px; border-radius: 12px;
            background: linear-gradient(135deg, #059669, #065f46);
            border: 1px solid rgba(4,120,87,.2);
            color: #ffffff; text-decoration: none;
            font-size: 12.5px; font-weight: 800; letter-spacing: .02em;
            box-shadow: var(--glow-soft);
            transition: all .25s var(--ease-spring);
            white-space: nowrap;
        }
        .customer-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        /* ============ CHIP BAR ============ */
        .chip-bar-wrap {
            position: sticky; top: 71px; z-index: 45;
            background: rgba(248,250,252,.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 12px 28px 14px;
            border-bottom: 1px solid var(--line);
        }
        .chip-bar {
            display: flex; gap: 8px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 2px;
            -webkit-overflow-scrolling: touch;
        }
        .chip-bar::-webkit-scrollbar { display: none; }
        .chip {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px;
            border-radius: 12px;
            background: #ffffff;
            border: 1.5px solid var(--line);
            color: var(--ink-soft);
            font-family: inherit;
            font-size: 13px; font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all .25s var(--ease-spring);
            box-shadow: var(--sh-xs);
        }
        .chip i.chip-icon { font-size: 12px; transition: transform .3s var(--ease-spring); }
        .chip .chip-count {
            font-size: 10.5px; font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--bg);
            color: var(--muted);
            transition: all .25s;
        }
        .chip:hover {
            border-color: var(--green-400);
            color: var(--brand-dark);
            transform: translateY(-2px);
            box-shadow: var(--sh-sm);
        }
        .chip:hover i.chip-icon { transform: scale(1.15) rotate(-6deg); }
        .chip.active {
            background: linear-gradient(135deg, #10b981, #047857);
            border-color: transparent;
            color: #ffffff;
            box-shadow: var(--glow-soft);
        }
        .chip.active .chip-count { background: rgba(255,255,255,.25); color: #ffffff; }
        .chip.active i.chip-icon { color: #fff; }

        /* ============ MARKET BUILDING ============ */
        .content-area {
            padding: 22px 28px 48px;
            flex: 1;
            width: 100%;
        }
        .market-building {
            position: relative;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 28px;
            padding: 30px 34px;
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

        /* --- Legend as clickable buttons --- */
        .building-legend {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding: 4px;
            background: var(--bg);
            border-radius: 14px;
            border: 1px solid var(--line);
        }
        .legend-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px;
            border-radius: 10px;
            background: transparent;
            border: none;
            color: var(--ink-soft);
            font-family: inherit;
            font-size: 12.5px; font-weight: 800;
            letter-spacing: .02em;
            cursor: pointer;
            transition: all .25s var(--ease-spring);
            white-space: nowrap;
        }
        .legend-btn .legend-swatch {
            width: 14px; height: 14px; border-radius: 4px;
            border: 1px solid rgba(0,0,0,.06);
            flex-shrink: 0;
            transition: transform .25s var(--ease-spring);
        }
        .legend-btn:hover {
            background: #ffffff;
            color: var(--ink);
            box-shadow: var(--sh-xs);
        }
        .legend-btn:hover .legend-swatch { transform: scale(1.15); }
        .legend-btn.active {
            background: #ffffff;
            color: var(--ink);
            box-shadow: var(--sh-sm);
        }
        .legend-btn.active[data-view="occupied"] {
            background: linear-gradient(135deg, #059669, #065f46);
            color: #ffffff;
        }
        .legend-btn.active[data-view="available"] {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #ffffff;
        }
        .legend-btn .legend-count {
            font-size: 10.5px; font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(0,0,0,.06);
            color: var(--muted);
            transition: all .25s;
        }
        .legend-btn.active .legend-count {
            background: rgba(255,255,255,.25);
            color: #ffffff;
        }

        .swatch-occupied { background: linear-gradient(135deg, #10b981, #059669); }
        .swatch-available {
            background: #ffffff;
            border: 1.5px dashed #0ea5e9;
        }

        /* ---- FLOOR PLAN ---- */
        .floor-plan {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 56px 1fr;
            background: #fbfcfd;
            border-radius: 18px;
            border: 2px solid var(--line);
            overflow: hidden;
            min-height: 540px;
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
            padding: 16px 18px;
            border-bottom: 1px dashed var(--line);
            background: #ffffff;
            transition: background .25s var(--ease-smooth);
        }
        .section-block:last-child { border-bottom: none; }
        .section-block:hover { background: var(--green-50); }

        .section-header {
            display: flex; align-items: center;
            justify-content: space-between; gap: 10px;
            margin-bottom: 10px;
        }
        .section-header-left { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .section-letter {
            width: 30px; height: 30px; border-radius: 9px;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            display: grid; place-items: center;
            color: #34d399;
            font-size: 12px; font-weight: 800;
            letter-spacing: .05em;
            flex-shrink: 0;
        }
        .section-name {
            font-size: 13px; font-weight: 800; color: var(--ink);
            line-height: 1.15;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .section-sub {
            font-size: 10.5px; color: var(--muted);
            letter-spacing: .04em; text-transform: uppercase;
            font-weight: 700; margin-top: 1px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .section-badge {
            padding: 4px 10px; border-radius: 999px;
            font-size: 10.5px; font-weight: 800;
            background: var(--bg); color: var(--ink-soft);
            border: 1px solid var(--line);
            white-space: nowrap;
        }

        .stalls-wrap {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
        }
        .wing-right .stalls-wrap { direction: rtl; }
        .wing-right .stalls-wrap .cell { direction: ltr; }

        /* Wide layout optimization — more columns on larger screens */
        @media (min-width: 1280px) {
            .stalls-wrap { grid-template-columns: repeat(5, 1fr); }
        }
        @media (min-width: 1500px) {
            .stalls-wrap { grid-template-columns: repeat(6, 1fr); }
            .cell { padding: 10px 4px 9px; font-size: 11px; }
            .section-block { padding: 18px 20px; }
        }
        @media (min-width: 1900px) {
            .floor-plan { grid-template-columns: 1fr 64px 1fr; }
            .stalls-wrap { grid-template-columns: repeat(8, 1fr); }
            .cell { padding: 12px 5px 11px; font-size: 11.5px; }
            .section-block { padding: 20px 22px; }
            .market-building { padding: 36px 40px; }
        }

        .cell {
            position: relative;
            padding: 9px 4px 8px;
            text-align: center;
            border-radius: 8px;
            background: #ffffff;
            border: 1.5px dashed var(--green-200);
            color: var(--muted);
            font-size: 10.5px;
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
            cursor: pointer;
        }
        .cell.occupied::after {
            content: '\f05a';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 3px; right: 3px;
            font-size: 7px;
            color: var(--green-600);
            opacity: .5;
            transition: opacity .2s;
        }
        .cell.occupied:hover::after { opacity: 1; }
        .cell:hover {
            transform: translateY(-2px) scale(1.04);
            box-shadow: 0 6px 14px rgba(2,44,34,.12);
            z-index: 2;
        }

        /* Highlight the current filter's matching cells */
        .cell.is-match {
            box-shadow: 0 0 0 2px rgba(16,185,129,.28);
        }
        .cell.is-available-match {
            box-shadow: 0 0 0 2px rgba(14,165,233,.28);
        }

        /* The stall the customer was sent here to find (deep link from
           Browse Market). It draws the eye, then settles. */
        .cell.is-spotlight {
            z-index: 3;
            background: linear-gradient(135deg, #10b981, #047857);
            border-color: #047857;
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(16,185,129,.35), 0 10px 24px rgba(2,44,34,.28);
            animation: spotlightPulse 1.6s var(--ease-spring) 3;
        }
        .cell.is-spotlight::after { color: #ffffff; opacity: 1; }
        @keyframes spotlightPulse {
            0%, 100% { transform: scale(1);    box-shadow: 0 0 0 3px rgba(16,185,129,.35), 0 10px 24px rgba(2,44,34,.28); }
            50%      { transform: scale(1.14); box-shadow: 0 0 0 9px rgba(16,185,129,.14), 0 12px 30px rgba(2,44,34,.32); }
        }
        @media (prefers-reduced-motion: reduce) {
            .cell.is-spotlight { animation: none; }
        }

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
        .tooltip-btn.view:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(16,185,129,.48); }

        /* ============ MODAL ============ */
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
        .modal-action.ghost {
            background: #ffffff; color: var(--ink-soft);
            border: 1.5px solid var(--line);
            flex: 0 0 auto; min-width: 90px;
        }
        .modal-action.ghost:hover { background: var(--bg); transform: translateY(-2px); }

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
        .toast.info { border-color: rgba(14,165,233,.4); }
        .toast.info i { color: #7dd3fc; }@media (max-width: 900px){
            .top-bar, .chip-bar-wrap { padding-left: 16px; padding-right: 16px; }
            .content-area { padding: 16px 14px 40px; }
            .market-building { padding: 20px 14px; border-radius: 22px; }
            .page-title h1 { font-size: 18px; }}@media (max-width: 760px){
            .floor-plan { grid-template-columns: 1fr; min-height: auto; }
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
            .entrance-marker { order: -1; }
            .building-legend { width: 100%; }}@media (max-width: 600px){
            .stalls-wrap { grid-template-columns: repeat(3, 1fr); }
            .compass { width: 46px; height: 46px; top: 14px; right: 14px; }
            .stall-tooltip { width: 220px; }
            .legend-btn { flex: 1; justify-content: center; padding: 8px 10px; font-size: 11.5px; }}@media (max-width: 420px){
            .stalls-wrap { grid-template-columns: repeat(2, 1fr); }}

        @media print {
            .sidebar, .mobile-toggle, .sidebar-backdrop, .map-strip,
            .stall-tooltip, .vendor-modal, .toast, .chip-bar-wrap { display: none !important; }
            .main-content { margin-left: 0; width: 100%; }
        }
        /* this page's own main-content tweaks; the shell owns the rest */
        .main-content{display: flex;flex-direction: column;}

    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/customer_sidebar.php'; ?>

    <main class="main-content">

        <!-- TOP BAR -->
        <header class="top-bar" id="topBar">
            <div class="page-title">
                <div class="page-title-icon"><i class="fas fa-map-location-dot"></i></div>
                <div>
                    <h1>Stall Map</h1>
                    <p>Mallengke Public Market · Poblacion, Tupi, South Cotabato</p>
                </div>
            </div>

            <div class="top-stats">
                <span class="stat-pill occupied">
                    <i class="fas fa-store"></i>
                    <strong><?= $totalOcc ?></strong> Occupied
                </span>
                <span class="stat-pill available">
                    <i class="fas fa-door-open"></i>
                    <strong><?= $totalAvail ?></strong> Available
                </span>
            </div>

            <div class="top-actions">
                <a href="../customer_dashboard.php" class="customer-btn" title="Customer dashboard">
                    <i class="fas fa-user"></i>
                    <span>Customer</span>
                </a>
                <a href="../customer_dashboard.php" class="icon-btn" title="Back to dashboard">
                    <i class="fas fa-gauge-high"></i>
                </a>
            </div>
        </header>

        <!-- CHIP BAR -->
        <div class="chip-bar-wrap">
            <div class="chip-bar" id="categoryChips">
                <button type="button" class="chip active" data-filter="all">
                    <i class="fas fa-border-all chip-icon"></i>
                    <span>All Sections</span>
                    <span class="chip-count"><?= $totalOcc ?></span>
                </button>
                <?php foreach ($sectionMeta as $name => $meta):
                    if (!isset($layout[$name])) continue;
                    $cnt = 0;
                    foreach ($layout[$name] as $s) if (!empty($occupied[strtoupper($s)])) $cnt++;
                ?>
                    <button type="button" class="chip" data-filter="<?= htmlspecialchars($name) ?>">
                        <i class="fas <?= $meta['icon'] ?> chip-icon" style="color:<?= $meta['color'] ?>;"></i>
                        <span><?= htmlspecialchars($name) ?></span>
                        <span class="chip-count"><?= $cnt ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- MARKET BUILDING -->
        <div class="content-area">
            <div class="market-building">

                <div class="compass" aria-hidden="true">
                    <div class="compass-needle"></div>
                </div>

                <div class="building-header">
                    <div class="building-title">
                        <div class="building-title-icon"><i class="fas fa-warehouse"></i></div>
                        <div>
                            <h2>Mallengke Market — Floor Plan</h2>
                            <p>
                                <i class="fas fa-location-dot"></i>
                                Hover a stall to see the vendor · Click the legend to filter
                            </p>
                        </div>
                    </div>

                    <!-- Legend as clickable filter buttons -->
                    <div class="building-legend" id="viewLegend">
                        <button type="button" class="legend-btn active" data-view="all">
                            <i class="fas fa-border-all" style="font-size:11px;color:var(--green-600);"></i>
                            <span>All Stalls</span>
                            <span class="legend-count"><?= $totalStalls ?></span>
                        </button>
                        <button type="button" class="legend-btn" data-view="occupied">
                            <span class="legend-swatch swatch-occupied"></span>
                            <span>Occupied</span>
                            <span class="legend-count"><?= $totalOcc ?></span>
                        </button>
                        <button type="button" class="legend-btn" data-view="available">
                            <span class="legend-swatch swatch-available"></span>
                            <span>Available</span>
                            <span class="legend-count"><?= $totalAvail ?></span>
                        </button>
                    </div>
                </div>

                <!-- FLOOR PLAN -->
                <div class="floor-plan">

                    <!-- LEFT WING -->
                    <div class="wing wing-left">
                        <?php foreach ($leftWing as $item):
                            $section = $item['section'];
                            $letter  = $item['letter'];
                            $stalls  = $layout[$section] ?? [];
                            if (empty($stalls)) continue;

                            $occ = 0;
                            foreach ($stalls as $s) if (!empty($occupied[strtoupper($s)])) $occ++;
                        ?>
                            <div class="section-block">
                                <div class="section-header">
                                    <div class="section-header-left">
                                        <div class="section-letter"><?= htmlspecialchars($letter) ?></div>
                                        <div>
                                            <div class="section-name"><?= htmlspecialchars($section) ?></div>
                                            <div class="section-sub"><?= count($stalls) ?> stalls</div>
                                        </div>
                                    </div>
                                    <span class="section-badge"><?= $occ ?>/<?= count($stalls) ?></span>
                                </div>
                                <div class="stalls-wrap">
                                    <?php foreach ($stalls as $s):
                                        $up = strtoupper($s);
                                        $isOcc = !empty($occupied[$up]);
                                        $cls = 'cell' . ($isOcc ? ' occupied' : '');
                                    ?>
                                        <div class="<?= $cls ?>" data-stall="<?= htmlspecialchars($up) ?>" data-section="<?= htmlspecialchars($section) ?>" data-state="<?= $isOcc ? 'occupied' : 'available' ?>"><?= htmlspecialchars($s) ?></div>
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
                            foreach ($stalls as $s) if (!empty($occupied[strtoupper($s)])) $occ++;
                        ?>
                            <div class="section-block">
                                <div class="section-header">
                                    <div class="section-header-left">
                                        <div class="section-letter"><?= htmlspecialchars($letter) ?></div>
                                        <div>
                                            <div class="section-name"><?= htmlspecialchars($section) ?></div>
                                            <div class="section-sub"><?= count($stalls) ?> stalls</div>
                                        </div>
                                    </div>
                                    <span class="section-badge"><?= $occ ?>/<?= count($stalls) ?></span>
                                </div>
                                <div class="stalls-wrap">
                                    <?php foreach ($stalls as $s):
                                        $up = strtoupper($s);
                                        $isOcc = !empty($occupied[$up]);
                                        $cls = 'cell' . ($isOcc ? ' occupied' : '');
                                    ?>
                                        <div class="<?= $cls ?>" data-stall="<?= htmlspecialchars($up) ?>" data-section="<?= htmlspecialchars($section) ?>" data-state="<?= $isOcc ? 'occupied' : 'available' ?>"><?= htmlspecialchars($s) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
                <!-- /.floor-plan -->

                <!-- FOOTER -->
                <div class="building-foot">
                    <div class="facilities-left">
                        <span class="facility-chip loading"><i class="fas fa-truck"></i> Loading Bay</span>
                        <span class="facility-chip cr"><i class="fas fa-restroom"></i> Comfort Rooms</span>
                    </div>

                    <div class="entrance-marker">
                        <i class="fas fa-door-open"></i>
                        <span class="label">Main Entrance</span>
                        <span class="sub">National Highway</span>
                    </div>

                    <div class="facilities-right">
                        <span class="facility-chip office"><i class="fas fa-building"></i> Market Office</span>
                    </div>
                </div>

            </div>
            <!-- /.market-building -->
        </div>
    </main>

    <!-- TOOLTIP -->
    <div class="stall-tooltip" id="stallTooltip">
        <div class="tooltip-head">
            <div class="tooltip-avatar" id="ttAvatar">--</div>
            <div class="tooltip-head-info">
                <div class="tooltip-name" id="ttName">—</div>
                <div class="tooltip-biz" id="ttBiz">—</div>
            </div>
        </div>
        <div class="tooltip-row">
            <i class="fas fa-store"></i>
            <span id="ttStall">—</span>
        </div>
        <div class="tooltip-row">
            <i class="fas fa-box-open"></i>
            <span id="ttProducts">—</span>
        </div>
        <div class="tooltip-actions">
            <button type="button" class="tooltip-btn view" id="ttViewBtn">
                <i class="fas fa-circle-info"></i> View
            </button>
        </div>
    </div>

    <!-- VENDOR INFO MODAL -->
    <div class="vendor-modal" id="vendorModal">
        <div class="modal-inner" onclick="event.stopPropagation()">
            <div class="modal-head">
                <div class="modal-avatar" id="mAvatar">--</div>
                <div class="modal-head-info">
                    <h2 id="mName">—</h2>
                    <p id="mBiz">—</p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal()">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Stall Location</div>
                        <div class="modal-row-value mono" id="mStall">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-layer-group"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Market Section</div>
                        <div class="modal-row-value" id="mSection">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-box-open"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Products Sold</div>
                        <div class="modal-row-value" id="mProducts">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-phone"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Contact Number</div>
                        <div class="modal-row-value mono" id="mContact">—</div>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="modal-row-icon"><i class="fas fa-clock-rotate-left"></i></div>
                    <div class="modal-row-content">
                        <div class="modal-row-label">Years in Business</div>
                        <div class="modal-row-value" id="mYears">—</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="modal-action ghost" onclick="closeModal()">
                    <i class="fas fa-xmark"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMsg">Showing available stalls</span>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarBackdrop').classList.toggle('show');
        }

        /* ============================================================
           State
           ============================================================ */
        const stallData = <?= json_encode($vendorInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* The stall we were sent here to find, if any. */
const FOCUS_STALL = <?= json_encode($focusStall, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        let activeSection = 'all';   // 'all' | section name
        let activeView    = 'all';   // 'all' | 'occupied' | 'available'

        const topBar = document.getElementById('topBar');

        /* ============================================================
           Scroll shadow
           ============================================================ */
        window.addEventListener('scroll', () => {
            topBar.classList.toggle('scrolled', window.scrollY > 8);
        }, { passive: true });

        /* ============================================================
           Section chips
           ============================================================ */
        document.querySelectorAll('.chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                activeSection = chip.dataset.filter;
                applyFilters();
                chip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            });
        });

        /* ============================================================
           Legend view buttons (All / Occupied / Available)
           ============================================================ */
        const legendButtons = document.querySelectorAll('#viewLegend .legend-btn');

        legendButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const newView = btn.dataset.view;
                if (newView === activeView) return;

                legendButtons.forEach(b => b.classList.toggle('active', b === btn));
                activeView = newView;

                applyFilters();

                /* Toast feedback */
                if (activeView === 'occupied') {
                    showToast('Showing occupied stalls only', 'fas fa-store', 'info');
                } else if (activeView === 'available') {
                    showToast('Showing available stalls only', 'fas fa-door-open', 'info');
                } else {
                    showToast('Showing all stalls', 'fas fa-border-all', 'info');
                }
            });
        });

        /* ============================================================
           Combined filter logic (section + view)
           ============================================================ */
        function applyFilters() {
            document.querySelectorAll('.cell').forEach(cell => {
                const section   = cell.dataset.section;
                const state     = cell.dataset.state;    // 'occupied' | 'available'
                const matchesSection = (activeSection === 'all') || (section === activeSection);

                let matchesView = true;
                if (activeView === 'occupied')  matchesView = (state === 'occupied');
                else if (activeView === 'available') matchesView = (state === 'available');

                const show = matchesSection && matchesView;

                cell.style.opacity       = show ? '' : '.12';
                cell.style.pointerEvents = show ? '' : 'none';
                cell.classList.toggle('is-match',             show && activeView === 'occupied' && state === 'occupied');
                cell.classList.toggle('is-available-match',   show && activeView === 'available' && state === 'available');
            });
        }

        /* ============================================================
           Hover tooltip
           ============================================================ */
        const tooltip   = document.getElementById('stallTooltip');
        const ttAvatar  = document.getElementById('ttAvatar');
        const ttName    = document.getElementById('ttName');
        const ttBiz     = document.getElementById('ttBiz');
        const ttStall   = document.getElementById('ttStall');
        const ttProducts= document.getElementById('ttProducts');
        const ttViewBtn = document.getElementById('ttViewBtn');

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

        function showTooltipFor(cell, e) {
            const stall = (cell.dataset.stall || '').toUpperCase();
            const data  = stallData[stall];
            if (!data) return;
            hoveredStall = stall;

            ttAvatar.textContent  = data.initials || '--';
            ttName.textContent    = data.name || 'Unnamed vendor';
            ttBiz.textContent     = data.business || 'Independent vendor';
            ttStall.textContent   = stall + (data.section ? ' · ' + data.section : '');
            ttProducts.textContent= data.products || 'Not provided';

            clearTimeout(tooltipHideTimer);
            tooltip.classList.add('show');
            positionTooltip(e);
        }

        function hideTooltip() {
            tooltipHideTimer = setTimeout(() => {
                tooltip.classList.remove('show');
                hoveredStall = null;
            }, 120);
        }

        document.querySelectorAll('.cell.occupied').forEach(cell => {
            cell.addEventListener('mouseenter', (e) => showTooltipFor(cell, e));
            cell.addEventListener('mousemove', positionTooltip);
            cell.addEventListener('mouseleave', hideTooltip);
            cell.addEventListener('click', (e) => showTooltipFor(cell, e));
        });

        tooltip.addEventListener('mouseenter', () => clearTimeout(tooltipHideTimer));
        tooltip.addEventListener('mouseleave', hideTooltip);

        /* ============================================================
           Modal
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

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        /* Buttons inside tooltip */
        ttViewBtn.addEventListener('click', () => {
            if (hoveredStall) {
                openModalFor(hoveredStall);
                tooltip.classList.remove('show');
            }
        });
        /* Escape */
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (modal.classList.contains('open')) closeModal();
                else tooltip.classList.remove('show');
            }
        });

        /* ============================================================
           Toast
           ============================================================ */
        let toastTimer;
        function showToast(msg, iconClass, type) {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            const i = toast.querySelector('i');
            i.className = iconClass || 'fas fa-check-circle';
            toast.classList.remove('info');
            if (type) toast.classList.add(type);
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
        }

        /* ============================================================
           Init
           ============================================================ */
        applyFilters();

        /* ============================================================
           Deep link from Browse Market
           ------------------------------------------------------------
           The customer clicked Map on a vendor card, so bring that
           stall into view and open its vendor card. Runs last, once
           the filters, the tooltip and the modal are all wired.
           ============================================================ */
        (function spotlightFocusStall() {
            if (!FOCUS_STALL) return;

            const cell = document.querySelector('.cell[data-stall="' + FOCUS_STALL + '"]');
            if (!cell) return;

            /* A section filter must never hide the stall we just
               sent them to, so drop back to "All Sections". */
            if (activeSection !== 'all' && cell.dataset.section !== activeSection) {
                document.querySelector('.chip[data-filter="all"]')?.click();
            }

            cell.classList.add('is-spotlight');
            cell.scrollIntoView({ behavior: 'smooth', block: 'center' });

            /* Open the vendor card once the scroll has settled. */
            if (stallData[FOCUS_STALL]) {
                setTimeout(() => openModalFor(FOCUS_STALL), 700);
            }

            /* Drop the pulse once they close the card, or after a
               while if they never open it. */
            const stop = () => cell.classList.remove('is-spotlight');
            modal.addEventListener('transitionend', stop, { once: true });
            setTimeout(stop, 8000);
        })();
    </script>

</body>
</html>