<?php
$conn = require __DIR__ . '/../config/db.php';
session_start();

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

$stmt = $conn->query(
    "SELECT id, first_name, middle_name, last_name,
            business_name, stall_number, market_section,
            product_type, contact_number, years_in_business, status
       FROM vendors
      WHERE stall_number IS NOT NULL AND stall_number <> ''
      ORDER BY market_section, stall_number"
);
$vendors = $stmt->fetch_all(MYSQLI_ASSOC);

$bySection = [];
foreach ($vendors as $v) {
    $bySection[$v['market_section']][] = $v;
}

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

$totalVendors  = count($vendors);
$totalSections = count($bySection);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browse Market • MALLENGKE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
        <link rel="stylesheet" href="../assets/mallengke-app.css?v=20261021h" />
<style>
:root{--brand-light:#34d399;}


        html { scroll-behavior: smooth; }
        .sidebar-link:hover .sidebar-count{transform:scale(1.12)}
        .search-box.has-value kbd { opacity: 0; }
        .search-box.has-value .search-clear { display: inline-flex; }
        .stat-pill i { color: var(--brand); font-size: 12px; }
        .stat-pill strong { color: var(--ink); font-weight: 900; }
        .icon-btn.fav-active { background: #fef2f2; color: #dc2626; border-color: #fca5a5; }

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
        .chip i.chip-icon {
            font-size: 12px;
            transition: transform .3s var(--ease-spring);
        }
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
        .chip.active .chip-count {
            background: rgba(255,255,255,.25);
            color: #ffffff;
        }
        .chip.active i.chip-icon { color: #fff; }

        /* ============ TOOLBAR ============ */
        .toolbar {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 28px 12px;
            flex-wrap: wrap;
        }
        .toolbar .count {
            font-size: 13.5px; font-weight: 600;
            color: var(--muted);
            display: flex; align-items: center; gap: 8px;
        }
        .toolbar .count strong {
            color: var(--ink); font-weight: 900;
            display: inline-block;
            transition: transform .3s var(--ease-spring);
        }
        .toolbar .count strong.bump { transform: scale(1.25); color: var(--brand-dark); }
        .toolbar .count .filter-tag {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px;
            background: var(--green-100); color: var(--green-800);
            font-size: 11.5px; font-weight: 800;
            animation: fadeIn .3s var(--ease-expo);
        }
        @keyframes fadeIn { from { opacity: 0; transform: scale(.9); } to { opacity: 1; transform: scale(1); } }

        .toolbar-right { margin-left: auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        .sort-wrap {
            position: relative;
            display: inline-flex; align-items: center;
        }
        .sort-wrap i {
            position: absolute; left: 14px;
            color: var(--brand); font-size: 12px; pointer-events: none;
        }
        .sort-select {
            appearance: none; -webkit-appearance: none;
            padding: 10px 38px 10px 36px;
            border-radius: 11px;
            border: 1.5px solid var(--line);
            background: #ffffff;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 13px center;
            background-size: 13px;
            font-family: inherit;
            font-size: 13px; font-weight: 700;
            color: var(--ink);
            cursor: pointer;
            outline: none;
            box-shadow: var(--sh-xs);
            transition: all .2s;
        }
        .sort-select:focus { border-color: var(--green-400); box-shadow: 0 0 0 4px rgba(16,185,129,.14); }

        .view-toggle {
            display: inline-flex;
            background: #ffffff;
            border: 1.5px solid var(--line);
            border-radius: 11px;
            overflow: hidden;
            box-shadow: var(--sh-xs);
        }
        .view-toggle button {
            width: 40px; height: 40px;
            background: transparent; border: none;
            color: var(--muted); font-size: 14px;
            cursor: pointer;
            transition: all .22s var(--ease-spring);
            font-family: inherit;
        }
        .view-toggle button:hover { color: var(--ink); }
        .view-toggle button.active {
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff;
        }

        /* ============ GRID ============ */
        .content-area {
            padding: 0 28px 60px;
            flex: 1;
        }

        .vendor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 14px;
        }
        .vendor-grid.list-view { grid-template-columns: 1fr; }

        .vendor-card {
            background: #ffffff;
            border: 1.5px solid var(--line);
            border-radius: 18px;
            overflow: hidden;
            cursor: pointer;
            transition: all .3s var(--ease-smooth);
            position: relative;
            display: flex;
            flex-direction: column;
            box-shadow: var(--sh-xs);
            animation: cardIn .35s var(--ease-expo) backwards;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .vendor-card::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(16,185,129,.07), transparent 55%);
            opacity: 0; transition: opacity .3s;
            pointer-events: none;
        }
        .vendor-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--sh-lg);
            border-color: var(--green-300);
        }
        .vendor-card:hover::before { opacity: 1; }

        .card-inner {
            padding: 16px 16px 14px;
            position: relative; z-index: 2;
            display: flex; flex-direction: column; gap: 12px;
            flex: 1;
        }

        .card-top { display: flex; gap: 12px; align-items: flex-start; }
        .vendor-avatar {
            width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
            display: grid; place-items: center;
            color: #ffffff; font-size: 16px; font-weight: 800;
            letter-spacing: .03em;
            transition: transform .35s var(--ease-spring);
        }
        .vendor-card:hover .vendor-avatar { transform: scale(1.08) rotate(-5deg); }
        .card-top-info { flex: 1; min-width: 0; padding-right: 32px; }
        .vendor-name {
            font-size: 15px; font-weight: 800; color: var(--ink);
            letter-spacing: -.2px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .vendor-biz {
            font-size: 12px; color: var(--muted);
            margin-top: 3px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .fav-btn {
            position: absolute; top: 14px; right: 14px;
            width: 32px; height: 32px; border-radius: 50%;
            background: #ffffff;
            border: 1.5px solid var(--line);
            cursor: pointer;
            display: grid; place-items: center;
            color: #cbd5e1; font-size: 13px;
            transition: all .25s var(--ease-spring);
            z-index: 3;
        }
        .fav-btn:hover {
            background: #fef2f2; border-color: #fca5a5;
            color: #dc2626; transform: scale(1.12);
        }
        .fav-btn.active {
            background: #fef2f2; border-color: #fca5a5; color: #dc2626;
        }

        .card-badges { display: flex; gap: 6px; flex-wrap: wrap; }
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 11px; font-weight: 700;
            letter-spacing: .02em;
            white-space: nowrap;
        }
        .badge-stall {
            background: var(--bg); color: var(--ink);
            border: 1px solid var(--line);
        }
        .badge-stall i { color: var(--brand); font-size: 9px; }
        .badge-section { color: #ffffff; }
        .badge-section i { font-size: 9px; }

        .card-products {
            font-size: 12.5px; color: var(--ink-soft);
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            padding-top: 10px;
            border-top: 1px dashed var(--line);
        }
        .card-products strong { color: var(--muted); font-weight: 600; }

        /* ---- quick actions (revealed on hover, always visible on touch) ---- */
        .card-quick {
            display: flex; gap: 6px;
            margin-top: auto;
        }
        .quick-btn {
            flex: 1;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px;
            padding: 9px 10px;
            border-radius: 10px;
            font-size: 12px; font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all .25s var(--ease-spring);
            border: 1.5px solid var(--line);
            background: #ffffff;
            color: var(--ink-soft);
            text-decoration: none;
        }
        .quick-btn i { font-size: 12px; transition: transform .25s var(--ease-spring); }
        .quick-btn:hover { transform: translateY(-1px); }
        .quick-btn:hover i { transform: scale(1.15); }
        .quick-btn.view {
            background: linear-gradient(135deg, #059669, #065f46);
            border-color: transparent;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(16,185,129,.28);
        }
        .quick-btn.view:hover { box-shadow: 0 7px 16px rgba(16,185,129,.42); }
        .quick-btn.map:hover {
            background: #f0f9ff;
            border-color: #7dd3fc;
            color: #0369a1;
        }

        /* list view: horizontal */
        .vendor-grid.list-view .vendor-card {
            flex-direction: row;
            align-items: stretch;
        }
        .vendor-grid.list-view .card-inner {
            flex-direction: row;
            align-items: center;
            gap: 18px;
            padding: 14px 18px;
            flex-wrap: nowrap;
        }
        .vendor-grid.list-view .card-top { flex: 0 0 300px; padding-right: 0; }
        .vendor-grid.list-view .card-top-info { padding-right: 0; }
        .vendor-grid.list-view .card-badges { flex: 0 0 auto; }
        .vendor-grid.list-view .card-products {
            flex: 1; border-top: none; padding-top: 0;
            -webkit-line-clamp: 1;
            border-left: 1px dashed var(--line);
            padding-left: 18px;
        }
        .vendor-grid.list-view .card-quick { margin-top: 0; flex: 0 0 280px; }
        .vendor-grid.list-view .fav-btn { position: static; flex-shrink: 0; }@media (max-width: 1100px){
            .vendor-grid.list-view .vendor-card { flex-direction: column; }
            .vendor-grid.list-view .card-inner {
                flex-direction: column; align-items: stretch; gap: 12px;
            }
            .vendor-grid.list-view .card-top { flex: 1; }
            .vendor-grid.list-view .card-products {
                border-left: none; padding-left: 0; padding-top: 10px;
                border-top: 1px dashed var(--line);
                -webkit-line-clamp: 2;
            }
            .vendor-grid.list-view .card-quick { flex: 1; }
            .vendor-grid.list-view .fav-btn { position: absolute; top: 14px; right: 14px; }}

        /* ============ EMPTY STATE ============ */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 72px 28px;
            background: #ffffff;
            border-radius: 22px;
            border: 1.5px dashed var(--green-200);
            animation: fadeIn .35s var(--ease-expo);
        }
        .empty-state i {
            font-size: 52px; color: var(--green-300);
            margin-bottom: 16px; display: block;
        }
        .empty-state h3 { font-size: 18px; font-weight: 800; color: var(--ink); margin-bottom: 6px; }
        .empty-state p { font-size: 13.5px; color: var(--muted); max-width: 400px; margin: 0 auto 20px; line-height: 1.6; }
        .empty-state button {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px;
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff; border: none; border-radius: 11px;
            font-family: inherit; font-size: 13px; font-weight: 800;
            cursor: pointer;
            box-shadow: var(--glow);
            transition: all .25s var(--ease-spring);
        }
        .empty-state button:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(16,185,129,.5); }

        /* ============ DRAWER (Vendor Detail) ============ */
        .drawer-backdrop {
            position: fixed; inset: 0;
            background: rgba(2,44,34,.5);
            backdrop-filter: blur(4px);
            z-index: 200;
            opacity: 0; pointer-events: none;
            transition: opacity .3s var(--ease-smooth);
        }
        .drawer-backdrop.open { opacity: 1; pointer-events: auto; }

        .drawer {
            position: fixed; top: 0; right: 0;
            width: 100%; max-width: 460px;
            height: 100vh;
            background: #ffffff;
            z-index: 201;
            display: flex; flex-direction: column;
            transform: translateX(100%);
            transition: transform .4s var(--ease-expo);
            box-shadow: -20px 0 60px rgba(2,44,34,.25);
            overflow: hidden;
        }
        .drawer.open { transform: translateX(0); }@media (max-width: 600px){
            .drawer {
                max-width: 100%;
                top: auto; bottom: 0;
                height: 92vh;
                border-top-left-radius: 24px;
                border-top-right-radius: 24px;
                transform: translateY(100%);
            }
            .drawer.open { transform: translateY(0); }
            .drawer::before {
                content: ''; position: absolute; top: 10px; left: 50%;
                transform: translateX(-50%);
                width: 40px; height: 4px; border-radius: 2px;
                background: rgba(255,255,255,.5);
                z-index: 10;
            }}

        .drawer-head {
            position: relative;
            padding: 32px 26px 24px;
            background:
                radial-gradient(circle at 85% 20%, rgba(52,211,153,.24) 0%, transparent 52%),
                linear-gradient(135deg, #022c22 0%, #04443a 55%, #064e3b 100%);
            color: #ffffff;
            display: flex; align-items: center; gap: 16px;
            flex-shrink: 0;
        }
        .drawer-avatar {
            width: 72px; height: 72px; border-radius: 20px; flex-shrink: 0;
            display: grid; place-items: center;
            color: #ffffff; font-size: 25px; font-weight: 800;
            letter-spacing: .04em;
            box-shadow: 0 12px 24px rgba(16,185,129,.35);
        }
        .drawer-head-info { flex: 1; min-width: 0; }
        .drawer-head-info h2 {
            font-size: 20px; font-weight: 800; color: #ffffff;
            letter-spacing: -.4px; margin-bottom: 3px;
            overflow: hidden; text-overflow: ellipsis;
        }
        .drawer-head-info p { font-size: 13px; color: #b6c5d3; }
        .drawer-close {
            position: absolute; top: 16px; right: 16px;
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.16);
            color: #ffffff; font-size: 13px;
            cursor: pointer;
            display: grid; place-items: center;
            transition: all .3s var(--ease-spring);
        }
        .drawer-close:hover { background: rgba(255,255,255,.2); transform: rotate(90deg); }

        .drawer-body { flex: 1; overflow-y: auto; padding: 22px 26px 16px; }
        .drawer-body::-webkit-scrollbar { width: 8px; }
        .drawer-body::-webkit-scrollbar-thumb { background: rgba(6,78,59,.16); border-radius: 4px; }

        .drawer-row {
            display: flex; gap: 13px;
            padding: 13px 0;
            border-bottom: 1px dashed var(--line);
            align-items: flex-start;
        }
        .drawer-row:last-child { border-bottom: none; }
        .drawer-row-icon {
            width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
            background: var(--green-50);
            border: 1px solid var(--green-100);
            color: var(--brand-dark);
            display: grid; place-items: center;
            font-size: 14px;
            transition: transform .3s var(--ease-spring);
        }
        .drawer-row:hover .drawer-row-icon { transform: scale(1.08); }
        .drawer-row-content { flex: 1; min-width: 0; }
        .drawer-row-label {
            font-size: 10.5px; font-weight: 800;
            color: var(--muted);
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .drawer-row-value {
            font-size: 14.5px; font-weight: 600;
            color: var(--ink);
            line-height: 1.5;
            word-break: break-word;
        }
        .drawer-row-value.mono { letter-spacing: .04em; }

        .drawer-footer {
            padding: 16px 26px 22px;
            border-top: 1px solid var(--line-soft);
            background: #fbfcfd;
            display: flex; gap: 9px; flex-wrap: wrap;
            flex-shrink: 0;
        }
        .drawer-action {
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
        .drawer-action.map {
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff;
            box-shadow: 0 5px 14px rgba(16,185,129,.35);
        }
        .drawer-action.map:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(16,185,129,.48); }
        .drawer-action.fav {
            background: #ffffff; color: var(--ink-soft);
            border: 1.5px solid var(--line);
            flex: 0 0 auto; min-width: 56px;
        }
        .drawer-action.fav:hover { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
        .drawer-action.fav.active { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
        .drawer-action.fav.active i::before { content: '\f004'; font-weight: 900; }

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
            z-index: 300;
            opacity: 0;
            transition: all .35s var(--ease-spring);
            pointer-events: none;
            border: 1px solid rgba(52,211,153,.28);
        }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast i { color: #34d399; }

        /* section colors */
        .bg-meat { background:#dc2626; } .bg-fish { background:#0284c7; }
        .bg-veg  { background:#059669; } .bg-fruit{ background:#f59e0b; }
        .bg-flower{background:#db2777; } .bg-rice { background:#a16207; }
        .bg-dry  { background:#7c3aed; } .bg-bread{ background:#ea580c; }
        .bg-proc { background:#0891b2; } .bg-eatery{background:#b91c1c; }
        .bg-others{background:#64748b; }@media (max-width: 900px){
            .top-bar, .chip-bar-wrap { padding-left: 16px; padding-right: 16px; }
            .content-area { padding: 0 16px 40px; }
            .toolbar { padding: 14px 16px 12px; }
            .chip-bar-wrap { top: 71px; }}@media (max-width: 600px){
            .vendor-grid { grid-template-columns: 1fr; }
            .toolbar .count .filter-tag { display: none; }}
        /* this page's own main-content tweaks; the shell owns the rest */
        .main-content{display: flex;flex-direction: column;}

    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/customer_sidebar.php'; ?>

    <main class="main-content">

        <!-- STICKY TOP BAR -->
        <header class="top-bar" id="topBar">
            <div class="search-box" id="heroSearchWrap">
                <span class="search-icon"><i class="fas fa-magnifying-glass"></i></span>
                <input type="text" id="heroSearch"
                       placeholder="Search vendors, products, stalls…"
                       autocomplete="off" />
                <kbd>/</kbd>
                <button type="button" class="search-clear" onclick="clearSearch()">
                    <i class="fas fa-xmark"></i>
                </button>
                <button type="button" class="search-go" onclick="focusResults()">
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <div class="top-stats">
                <span class="stat-pill">
                    <i class="fas fa-store-alt"></i>
                    <strong><?= $totalVendors ?></strong> Vendors
                </span>
                <span class="stat-pill">
                    <i class="fas fa-layer-group"></i>
                    <strong><?= $totalSections ?></strong> Sections
                </span>
            </div>

            <div class="top-actions">
                <a href="customer_stall_map.php" class="icon-btn" title="Open stall map">
                    <i class="fas fa-map-location-dot"></i>
                </a>
                <button type="button" class="icon-btn" id="favToggle" title="Show favorites only">
                    <i class="fas fa-heart"></i>
                    <span class="badge-dot" id="favCount">0</span>
                </button>
                <button type="button" class="icon-btn active" id="viewGrid" title="Grid view">
                    <i class="fas fa-th"></i>
                </button>
                <button type="button" class="icon-btn" id="viewList" title="List view">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </header>

        <!-- CHIP BAR -->
        <div class="chip-bar-wrap">
            <div class="chip-bar" id="categoryChips">
                <button type="button" class="chip active" data-filter="all">
                    <i class="fas fa-border-all chip-icon"></i>
                    <span>All Sections</span>
                    <span class="chip-count"><?= $totalVendors ?></span>
                </button>
                <?php foreach ($bySection as $section => $list):
                    $meta = $sectionMeta[$section] ?? ['icon'=>'fa-shop','color'=>'#64748b'];
                ?>
                    <button type="button" class="chip" data-filter="<?= htmlspecialchars($section) ?>">
                        <i class="fas <?= $meta['icon'] ?> chip-icon" style="color:<?= $meta['color'] ?>;"></i>
                        <span><?= htmlspecialchars($section) ?></span>
                        <span class="chip-count"><?= count($list) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TOOLBAR -->
        <div class="toolbar">
            <div class="count">
                Showing <strong id="visibleCount">0</strong> vendor(s)
                <span class="filter-tag" id="filterTag" style="display:none;">
                    <i class="fas fa-filter" style="font-size:9px;"></i>
                    <span id="filterTagText">All</span>
                </span>
            </div>

            <div class="toolbar-right">
                <div class="sort-wrap">
                    <i class="fas fa-arrow-down-wide-short"></i>
                    <select id="sortSelect" class="sort-select">
                        <option value="section">Section A→Z</option>
                        <option value="name">Vendor Name</option>
                        <option value="stall">Stall Number</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- RESULTS -->
        <div class="content-area">
            <div class="vendor-grid" id="vendorGrid">

                <?php if ($totalVendors === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-store-slash"></i>
                        <h3>No vendors listed yet</h3>
                        <p>Vendors will appear here as soon as they register their stalls at the market office.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($vendors as $v):
                        $meta = $sectionMeta[$v['market_section']] ?? ['icon' => 'fa-shop', 'color' => '#64748b', 'class' => 'others'];
                        $ini  = strtoupper(substr($v['first_name'] ?? 'U', 0, 1) . substr($v['last_name'] ?? '', 0, 1));
                        $name = trim(($v['first_name'] ?? '') . ' ' . ($v['middle_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                        $blob = strtolower(
                            $name . ' ' . ($v['business_name'] ?? '') . ' ' .
                            $v['stall_number'] . ' ' . $v['market_section'] . ' ' .
                            $v['product_type'] . ' ' . ($v['contact_number'] ?? '')
                        );
                    ?>
                        <article class="vendor-card"
                                 data-id="<?= (int)$v['id'] ?>"
                                 data-name="<?= htmlspecialchars($name) ?>"
                                 data-name-sort="<?= htmlspecialchars(strtolower($name)) ?>"
                                 data-biz="<?= htmlspecialchars($v['business_name'] ?? '') ?>"
                                 data-stall="<?= htmlspecialchars($v['stall_number']) ?>"
                                 data-stall-sort="<?= htmlspecialchars(strtolower($v['stall_number'])) ?>"
                                 data-section="<?= htmlspecialchars($v['market_section']) ?>"
                                 data-section-sort="<?= htmlspecialchars(strtolower($v['market_section'])) ?>"
                                 data-section-color="<?= $meta['color'] ?>"
                                 data-section-icon="<?= $meta['icon'] ?>"
                                 data-section-class="<?= $meta['class'] ?>"
                                 data-products="<?= htmlspecialchars($v['product_type']) ?>"
                                 data-contact="<?= htmlspecialchars($v['contact_number'] ?? '') ?>"
                                 data-initials="<?= htmlspecialchars($ini) ?>"
                                 data-search="<?= htmlspecialchars($blob) ?>">

                            <div class="card-inner">
                                <div class="card-top">
                                    <div class="vendor-avatar" style="background: linear-gradient(135deg, <?= $meta['color'] ?>, <?= $meta['color'] ?>dd);">
                                        <?= htmlspecialchars($ini) ?>
                                    </div>
                                    <div class="card-top-info">
                                        <div class="vendor-name"><?= htmlspecialchars($name) ?></div>
                                        <div class="vendor-biz">
                                            <?= $v['business_name'] ? htmlspecialchars($v['business_name']) : 'Independent vendor' ?>
                                        </div>
                                    </div>
                                    <button type="button" class="fav-btn" data-id="<?= (int)$v['id'] ?>" title="Save to favorites">
                                        <i class="far fa-heart"></i>
                                    </button>
                                </div>

                                <div class="card-badges">
                                    <span class="badge badge-stall">
                                        <i class="fas fa-location-dot"></i>
                                        <?= htmlspecialchars($v['stall_number']) ?>
                                    </span>
                                    <span class="badge badge-section bg-<?= $meta['class'] ?>">
                                        <i class="fas <?= $meta['icon'] ?>"></i>
                                        <?= htmlspecialchars($v['market_section']) ?>
                                    </span>
                                </div>

                                <div class="card-products">
                                    <strong>Products:</strong> <?= htmlspecialchars($v['product_type']) ?>
                                </div>

                                <div class="card-quick">
                                    <button type="button" class="quick-btn view"
                                            onclick="event.stopPropagation(); openVendorById(<?= (int)$v['id'] ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <a class="quick-btn map"
                                       href="customer_stall_map.php?stall=<?= rawurlencode((string)$v['stall_number']) ?>"
                                       onclick="event.stopPropagation();">
                                        <i class="fas fa-map-location-dot"></i> Map
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="empty-state" id="noResults" style="display:none;">
                    <i class="fas fa-magnifying-glass"></i>
                    <h3>No vendors found</h3>
                    <p>Try a different keyword, or reset the filters to browse the full market again.</p>
                    <button type="button" onclick="resetAll()">
                        <i class="fas fa-rotate-left"></i> Reset Filters
                    </button>
                </div>

            </div>
        </div>
    </main>

    <!-- ============ DRAWER ============ -->
    <div class="drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>
    <aside class="drawer" id="vendorDrawer" aria-hidden="true">
        <div class="drawer-head">
            <div class="drawer-avatar" id="dAvatar" style="background:#059669;">--</div>
            <div class="drawer-head-info">
                <h2 id="dName">—</h2>
                <p id="dBiz">—</p>
            </div>
            <button type="button" class="drawer-close" onclick="closeDrawer()">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="drawer-body">
            <div class="drawer-row">
                <div class="drawer-row-icon"><i class="fas fa-location-dot"></i></div>
                <div class="drawer-row-content">
                    <div class="drawer-row-label">Stall Location</div>
                    <div class="drawer-row-value mono" id="dStall">—</div>
                </div>
            </div>
            <div class="drawer-row">
                <div class="drawer-row-icon"><i class="fas fa-layer-group"></i></div>
                <div class="drawer-row-content">
                    <div class="drawer-row-label">Market Section</div>
                    <div class="drawer-row-value" id="dSection">—</div>
                </div>
            </div>
            <div class="drawer-row">
                <div class="drawer-row-icon"><i class="fas fa-box-open"></i></div>
                <div class="drawer-row-content">
                    <div class="drawer-row-label">Products Sold</div>
                    <div class="drawer-row-value" id="dProducts">—</div>
                </div>
            </div>
            <div class="drawer-row">
                <div class="drawer-row-icon"><i class="fas fa-phone"></i></div>
                <div class="drawer-row-content">
                    <div class="drawer-row-label">Contact Number</div>
                    <div class="drawer-row-value mono" id="dContact">—</div>
                </div>
            </div>
        </div>

        <div class="drawer-footer">
            <a class="drawer-action map" id="dMapBtn" href="customer_stall_map.php">
                <i class="fas fa-map-location-dot"></i> Map
            </a>
            <button type="button" class="drawer-action fav" id="dFavBtn" title="Save">
                <i class="far fa-heart"></i>
            </button>
        </div>
    </aside>

    <div class="toast" id="toast">
        <i class="fas fa-heart"></i> <span id="toastMsg">Saved to favorites</span>
    </div>

    <script>
        /* ============================================================
           Sidebar
           ============================================================ */
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarBackdrop').classList.toggle('show');
        }

        /* ============================================================
           State
           ============================================================ */
        const cards          = Array.from(document.querySelectorAll('.vendor-card'));
        const heroSearch     = document.getElementById('heroSearch');
        const heroSearchWrap = document.getElementById('heroSearchWrap');
        const categoryChips  = document.querySelectorAll('.chip');
        const sortSelect     = document.getElementById('sortSelect');
        const favToggle      = document.getElementById('favToggle');
        const viewGridBtn    = document.getElementById('viewGrid');
        const viewListBtn    = document.getElementById('viewList');
        const grid           = document.getElementById('vendorGrid');
        const noResults      = document.getElementById('noResults');
        const visibleCountEl = document.getElementById('visibleCount');
        const filterTag      = document.getElementById('filterTag');
        const filterTagText  = document.getElementById('filterTagText');
        const favCountEl     = document.getElementById('favCount');
        const topBar         = document.getElementById('topBar');

        let activeFilter = 'all';
        let activeSort   = 'section';
        let showFavOnly  = false;
        let favorites    = new Set();

        /* ============================================================
           Scroll shadow on top bar
           ============================================================ */
        window.addEventListener('scroll', () => {
            topBar.classList.toggle('scrolled', window.scrollY > 8);
        }, { passive: true });

        /* ============================================================
           Favorites (localStorage)
           ============================================================ */
        const FAV_KEY = 'mallengke_customer_favorites';

        function loadFavorites() {
            try {
                const raw = localStorage.getItem(FAV_KEY);
                if (raw) favorites = new Set(JSON.parse(raw).map(String));
            } catch (e) { favorites = new Set(); }
            updateFavUI();
        }

        function saveFavorites() {
            localStorage.setItem(FAV_KEY, JSON.stringify(Array.from(favorites)));
        }

        function updateFavUI() {
            favCountEl.textContent = favorites.size;
            const sb = document.getElementById('sidebarFavCount');
            if (sb) sb.textContent = favorites.size;

            favToggle.classList.toggle('fav-active', showFavOnly);

            document.querySelectorAll('.fav-btn').forEach(btn => {
                const id = String(btn.dataset.id);
                if (favorites.has(id)) {
                    btn.classList.add('active');
                    btn.querySelector('i').classList.remove('far');
                    btn.querySelector('i').classList.add('fas');
                } else {
                    btn.classList.remove('active');
                    btn.querySelector('i').classList.remove('fas');
                    btn.querySelector('i').classList.add('far');
                }
            });
        }

        function toggleFav(id) {
            id = String(id);
            if (favorites.has(id)) {
                favorites.delete(id);
                showToast('Removed from favorites', 'far');
            } else {
                favorites.add(id);
                showToast('Saved to favorites', 'fas');
            }
            saveFavorites();
            updateFavUI();
            applyFilters();
        }

        document.querySelectorAll('.fav-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleFav(btn.dataset.id);
            });
        });

        /* ============================================================
           Toast
           ============================================================ */
        let toastTimer;
        function showToast(msg, iconClass) {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            const i = toast.querySelector('i');
            i.className = (iconClass || 'fas') + ' fa-heart';
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
        }

        /* ============================================================
           Filtering + sorting
           ============================================================ */
        function applyFilters() {
            const q = (heroSearch.value || '').trim().toLowerCase();
            let visible = 0;

            cards.forEach(card => {
                const matchesSection = (activeFilter === 'all') || (card.dataset.section === activeFilter);
                const matchesSearch  = q === '' || (card.dataset.search || '').includes(q);
                const matchesFav     = !showFavOnly || favorites.has(String(card.dataset.id));

                const show = matchesSection && matchesSearch && matchesFav;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            sortCards();

            visibleCountEl.textContent = visible;
            visibleCountEl.classList.add('bump');
            setTimeout(() => visibleCountEl.classList.remove('bump'), 320);

            if (noResults) {
                noResults.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
            }

            heroSearchWrap.classList.toggle('has-value', q !== '');

            /* filter tag */
            const tags = [];
            if (activeFilter !== 'all') tags.push(activeFilter);
            if (showFavOnly) tags.push('Favorites');
            if (q) tags.push('"' + q + '"');
            if (tags.length) {
                filterTagText.textContent = tags.join(' · ');
                filterTag.style.display = 'inline-flex';
            } else {
                filterTag.style.display = 'none';
            }

            /* search box focused state */
            heroSearchWrap.classList.toggle('focused', document.activeElement === heroSearch);
        }

        function sortCards() {
            const visibleCards = cards.filter(c => c.style.display !== 'none');

            visibleCards.sort((a, b) => {
                if (activeSort === 'name') return a.dataset.nameSort.localeCompare(b.dataset.nameSort);
                if (activeSort === 'stall') return a.dataset.stallSort.localeCompare(b.dataset.stallSort);
                const sa = a.dataset.sectionSort + '|' + a.dataset.stallSort;
                const sb = b.dataset.sectionSort + '|' + b.dataset.stallSort;
                return sa.localeCompare(sb);
            });

            visibleCards.forEach((card, i) => {
                card.style.animationDelay = Math.min(i * 20, 300) + 'ms';
                grid.appendChild(card);
            });
        }

        /* ============================================================
           Bind UI
           ============================================================ */
        heroSearch.addEventListener('input', applyFilters);
        heroSearch.addEventListener('focus', () => heroSearchWrap.classList.add('focused'));
        heroSearch.addEventListener('blur', () => heroSearchWrap.classList.remove('focused'));

        categoryChips.forEach(chip => {
            chip.addEventListener('click', () => {
                categoryChips.forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                activeFilter = chip.dataset.filter;
                applyFilters();

                /* scroll chip into view */
                chip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            });
        });

        sortSelect.addEventListener('change', () => {
            activeSort = sortSelect.value;
            applyFilters();
        });

        favToggle.addEventListener('click', () => {
            showFavOnly = !showFavOnly;
            favToggle.classList.toggle('fav-active', showFavOnly);
            updateFavUI();
            applyFilters();
        });

        viewGridBtn.addEventListener('click', () => {
            grid.classList.remove('list-view');
            viewGridBtn.classList.add('active');
            viewListBtn.classList.remove('active');
            localStorage.setItem('mallengke_browse_view', 'grid');
        });
        viewListBtn.addEventListener('click', () => {
            grid.classList.add('list-view');
            viewListBtn.classList.add('active');
            viewGridBtn.classList.remove('active');
            localStorage.setItem('mallengke_browse_view', 'list');
        });

        function clearSearch() {
            heroSearch.value = '';
            applyFilters();
            heroSearch.focus();
        }
        function resetAll() {
            heroSearch.value = '';
            activeFilter = 'all';
            showFavOnly  = false;
            categoryChips.forEach(c => c.classList.remove('active'));
            document.querySelector('.chip[data-filter="all"]').classList.add('active');
            applyFilters();
        }
        function focusResults() {
            grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        /* ============================================================
           Drawer
           ============================================================ */
        let currentModalId = null;

        function openVendorById(id) {
            const card = document.querySelector('.vendor-card[data-id="' + id + '"]');
            if (card) openVendor(card);
        }

        function openVendor(card) {
            const id       = String(card.dataset.id);
            const name     = card.dataset.name;
            const biz      = card.dataset.biz;
            const stall    = card.dataset.stall;
            const section  = card.dataset.section;
            const products = card.dataset.products;
            const contact  = card.dataset.contact;
            const initials = card.dataset.initials;
            const color    = card.dataset.sectionColor;

            currentModalId = id;

            const avatar = document.getElementById('dAvatar');
            avatar.textContent = initials;
            avatar.style.background = 'linear-gradient(135deg, ' + color + ', ' + color + 'dd)';

            document.getElementById('dName').textContent     = name;
            document.getElementById('dBiz').textContent      = biz || 'Independent vendor';
            document.getElementById('dStall').textContent    = stall;
            document.getElementById('dSection').textContent  = section;
            document.getElementById('dProducts').textContent = products;
            document.getElementById('dContact').textContent  = contact || 'Not provided';

            /* Send the customer to the stall map with this vendor's stall already
               highlighted, so they land on the right place. */
            const mapBtn = document.getElementById('dMapBtn');
            mapBtn.href = stall
                ? 'customer_stall_map.php?stall=' + encodeURIComponent(stall)
                : 'customer_stall_map.php';

            updateDrawerFav();

            document.getElementById('vendorDrawer').classList.add('open');
            document.getElementById('vendorDrawer').setAttribute('aria-hidden', 'false');
            document.getElementById('drawerBackdrop').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function updateDrawerFav() {
            const btn = document.getElementById('dFavBtn');
            const i   = btn.querySelector('i');
            if (favorites.has(currentModalId)) {
                btn.classList.add('active');
                i.className = 'fas fa-heart';
            } else {
                btn.classList.remove('active');
                i.className = 'far fa-heart';
            }
        }

        document.getElementById('dFavBtn').addEventListener('click', () => {
            if (currentModalId) {
                toggleFav(currentModalId);
                updateDrawerFav();
            }
        });

        function closeDrawer() {
            document.getElementById('vendorDrawer').classList.remove('open');
            document.getElementById('vendorDrawer').setAttribute('aria-hidden', 'true');
            document.getElementById('drawerBackdrop').classList.remove('open');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.vendor-card').forEach(card => {
            card.addEventListener('click', () => openVendor(card));
        });

        /* ============================================================
           Keyboard shortcuts
           ============================================================ */
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (document.getElementById('vendorDrawer').classList.contains('open')) {
                    closeDrawer();
                } else if (heroSearch.value) {
                    clearSearch();
                }
            }
            if (e.key === '/' && document.activeElement !== heroSearch && !isTypingElsewhere()) {
                e.preventDefault();
                heroSearch.focus();
                heroSearch.select();
            }
        });
        function isTypingElsewhere() {
            const el = document.activeElement;
            return el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
        }

        /* ============================================================
           Init
           ============================================================ */
        loadFavorites();

        const lastView = localStorage.getItem('mallengke_browse_view');
        if (lastView === 'list') viewListBtn.click();

        applyFilters();

        /* ============================================================
           Deep link support — ?section=, ?favorites=1, ?vendor=ID
           ============================================================ */
        (function handleDeepLinks() {
            const params = new URLSearchParams(window.location.search);

            const section = params.get('section');
            if (section) {
                const chip = Array.from(categoryChips).find(c => c.dataset.filter === section);
                if (chip) { chip.click(); return; }
            }

            if (params.get('favorites') === '1') {
                if (!showFavOnly) favToggle.click();
            }

            const vendorId = params.get('vendor');
            if (vendorId) {
                const card = document.querySelector('.vendor-card[data-id="' + vendorId + '"]');
                if (card) setTimeout(() => openVendor(card), 200);
            }
        })();
    </script>

</body>
</html>