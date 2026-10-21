<?php
/* ✅ FIX: 'require' (not require_once) so $conn is always captured */
$conn = require __DIR__ . '/../config/db.php';

$token = trim($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(404);
    die('Invalid passport link.');
}

$stmt = $conn->prepare(
    "SELECT id, first_name, middle_name, last_name,
            business_name, stall_number, market_section, product_type,
            years_in_business, status, remarks,
            permit_status, permit_expiry,
            payment_status, payment_due, last_payment_date,
            created_at
       FROM vendors WHERE qr_token = ? LIMIT 1"
);
$stmt->bind_param("s", $token);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$v) {
    http_response_code(404);
    die('Vendor passport not found.');
}

$fullName = trim($v['first_name'] . ' ' . $v['middle_name'] . ' ' . $v['last_name']);
$refNo    = 'VND-' . str_pad((string)$v['id'], 5, '0', STR_PAD_LEFT);

function daysUntil(?string $date): ?int {
    if (!$date) return null;
    $d = (new DateTime($date))->diff(new DateTime('today'));
    return (int)$d->format('%r%a');
}

$permitDays  = daysUntil($v['permit_expiry']);
$paymentDays = daysUntil($v['payment_due']);
$initials    = strtoupper(substr($v['first_name'], 0, 1) . substr($v['last_name'], 0, 1));

$permitClass  = 'badge-' . strtolower($v['permit_status']);
$paymentClass = 'badge-' . strtolower($v['payment_status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($refNo) ?> • Vendor Status Passport</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="../assets/mallengke-theme.css" />
</head>
<body class="auth-body flow">
<div class="passport-page">

    <header class="passport-header">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-store"></i></div>
            <div class="brand-text">
                <h1>MALLENGKE</h1>
                <p>Vendor Status Passport · Tupi, South Cotabato</p>
            </div>
        </div>
        <span class="badge-verified">
            <i class="fas fa-shield-halved"></i> Verified Record
        </span>
    </header>

    <main class="passport">

        <!-- ============ PASSPORT HEADER ============ -->
        <div class="passport-top">
            <div class="avatar"><?= htmlspecialchars($initials) ?></div>

            <div class="info">
                <h2><?= htmlspecialchars($fullName) ?></h2>
                <div class="ref"><?= htmlspecialchars($refNo) ?></div>
                <?php if (!empty($v['business_name'])): ?>
                    <div class="business">
                        <i class="fas fa-store-alt"></i> <?= htmlspecialchars($v['business_name']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stamp">
                MALLENGKE<br>VERIFIED
            </div>
        </div>

        <!-- ============ PASSPORT BODY ============ -->
        <div class="passport-body">

            <!-- Stall Location -->
            <section class="p-section">
                <h3><i class="fas fa-map-location-dot"></i> Stall Location</h3>
                <div class="p-row">
                    <span class="label">Stall Number</span>
                    <span class="value"><?= htmlspecialchars($v['stall_number'] ?: 'Not assigned yet') ?></span>
                </div>
                <div class="p-row">
                    <span class="label">Market Section</span>
                    <span class="value"><?= htmlspecialchars($v['market_section']) ?></span>
                </div>
                <div class="p-row">
                    <span class="label">Market</span>
                    <span class="value">Mallengke, Poblacion</span>
                </div>
                <div class="p-row">
                    <span class="label">Municipality</span>
                    <span class="value">Tupi, South Cotabato</span>
                </div>
            </section>

            <!-- Products -->
            <section class="p-section">
                <h3><i class="fas fa-box-open"></i> Products</h3>
                <div class="p-row">
                    <span class="label">Main Products</span>
                    <span class="value"><?= htmlspecialchars($v['product_type']) ?></span>
                </div>
                <div class="p-row">
                    <span class="label">Years in Business</span>
                    <span class="value"><?= (int)$v['years_in_business'] ?> year(s)</span>
                </div>
                <div class="p-row">
                    <span class="label">Overall Status</span>
                    <span class="value">
                        <span class="badge badge-<?= strtolower($v['status']) ?>"><?= $v['status'] ?></span>
                    </span>
                </div>
            </section>

            <!-- Permit Status -->
            <section class="p-section">
                <h3><i class="fas fa-file-shield"></i> Permit Status</h3>
                <div class="p-row">
                    <span class="label">Permit</span>
                    <span class="value">
                        <span class="badge <?= $permitClass ?>"><?= htmlspecialchars($v['permit_status']) ?></span>
                    </span>
                </div>
                <div class="p-row">
                    <span class="label">Valid Until</span>
                    <span class="value">
                        <?= $v['permit_expiry'] ? date('M j, Y', strtotime($v['permit_expiry'])) : '—' ?>
                    </span>
                </div>

                <?php if ($permitDays !== null): ?>
                    <?php if ($permitDays < 0): ?>
                        <div class="deadline-note danger">
                            <i class="fas fa-triangle-exclamation"></i>
                            Permit expired <?= abs($permitDays) ?> day(s) ago
                        </div>
                    <?php elseif ($permitDays <= 30): ?>
                        <div class="deadline-note warn">
                            <i class="fas fa-clock"></i>
                            Permit expires in <?= $permitDays ?> day(s)
                        </div>
                    <?php else: ?>
                        <div class="deadline-note ok">
                            <i class="fas fa-circle-check"></i>
                            Permit valid for <?= $permitDays ?> more day(s)
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- Payment Status -->
            <section class="p-section">
                <h3><i class="fas fa-money-bill-wave"></i> Payment Status</h3>
                <div class="p-row">
                    <span class="label">Payment</span>
                    <span class="value">
                        <span class="badge <?= $paymentClass ?>"><?= htmlspecialchars($v['payment_status']) ?></span>
                    </span>
                </div>
                <div class="p-row">
                    <span class="label">Next Due</span>
                    <span class="value">
                        <?= $v['payment_due'] ? date('M j, Y', strtotime($v['payment_due'])) : '—' ?>
                    </span>
                </div>
                <div class="p-row">
                    <span class="label">Last Paid</span>
                    <span class="value">
                        <?= $v['last_payment_date'] ? date('M j, Y', strtotime($v['last_payment_date'])) : '—' ?>
                    </span>
                </div>

                <?php if ($paymentDays !== null): ?>
                    <?php if ($paymentDays < 0): ?>
                        <div class="deadline-note danger">
                            <i class="fas fa-triangle-exclamation"></i>
                            Payment overdue by <?= abs($paymentDays) ?> day(s)
                        </div>
                    <?php elseif ($paymentDays <= 7): ?>
                        <div class="deadline-note warn">
                            <i class="fas fa-clock"></i>
                            Payment due in <?= $paymentDays ?> day(s)
                        </div>
                    <?php else: ?>
                        <div class="deadline-note ok">
                            <i class="fas fa-circle-check"></i>
                            Payment due in <?= $paymentDays ?> day(s)
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if (!empty($v['remarks'])): ?>
            <section class="p-section full">
                <h3><i class="fas fa-note-sticky"></i> Official Remarks</h3>
                <p class="passport-remarks"><?= htmlspecialchars($v['remarks']) ?></p>
            </section>
            <?php endif; ?>

        </div>

        <!-- ============ FOOTER ============ -->
        <div class="passport-footer">
            <span>
                <i class="fas fa-calendar-check"></i>
                Registered: <strong><?= date('M j, Y', strtotime($v['created_at'])) ?></strong>
            </span>
            <span>
                <i class="fas fa-qrcode"></i> Scanned via Smart QR Passport
            </span>
        </div>

    </main>

</div>
</body>
</html>