<?php
/* ============================================================
   ACCOUNT SUSPENDED
   ------------------------------------------------------------
   Where a vendor lands when they try to sign in and the market
   office has blocked them. The guard puts the reason in the session;
   this page shows it, explains what happened, and offers a way back
   to the login form.

   Reaching this page without a reason in the session (someone typed
   the URL) just shows the plain message — it never leaks who is
   blocked or why.
   ============================================================ */
session_start();

$block = $_SESSION['access_block'] ?? null;
unset($_SESSION['access_block']);   /* one page load only */

if (!is_array($block) || empty($block['description'])) {
    $block = [
        'kind'        => 'suspended',
        'name'        => '',
        'stall'       => '',
        'description' => 'Your stall access has been suspended by the market office. '
                       . 'Please visit the market office to sort this out.',
    ];
}

$isRemoved = ($block['kind'] ?? 'suspended') === 'removed';
$heading   = $isRemoved ? 'Account removed' : 'Account suspended';
$icon      = $isRemoved ? 'fa-user-slash' : 'fa-pause-circle';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Account Suspended • MALLENGKE</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    padding:24px;line-height:1.6;color:#0f172a;
    background:radial-gradient(circle at 20% 0%, rgba(239,68,68,.14) 0%, transparent 45%),
               linear-gradient(180deg,#f8fafc,#f1f5f9);
}
.card{
    width:100%;max-width:520px;background:#fff;border:1.5px solid #e5e7eb;
    border-radius:24px;padding:34px 30px;text-align:center;
    box-shadow:0 24px 60px rgba(2,44,34,.12);animation:rise .5s cubic-bezier(.34,1.56,.64,1);
}
@keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}

.badge{
    width:76px;height:76px;border-radius:24px;margin:0 auto 20px;display:grid;place-items:center;
    font-size:30px;color:#fff;background:linear-gradient(135deg,#f59e0b,#d97706);
    box-shadow:0 14px 34px rgba(217,119,6,.35);
}
.badge.removed{background:linear-gradient(135deg,#ef4444,#b91c1c);box-shadow:0 14px 34px rgba(239,68,68,.35);}

.brand{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:18px;
    font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:#64748b;}
.brand i{color:#dc2626;}

h1{font-size:22px;font-weight:800;letter-spacing:-.4px;margin-bottom:10px;}
.lead{font-size:14px;color:#475569;margin-bottom:20px;}

.reason{
    text-align:left;background:#fff7ed;border:1.5px solid #fed7aa;border-left:4px solid #f59e0b;
    border-radius:14px;padding:16px 18px;margin-bottom:20px;
}
.reason.removed{background:#fef2f2;border-color:#fecaca;border-left-color:#ef4444;}
.reason .k{font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#9a3412;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.reason.removed .k{color:#991b1b;}
.reason p{font-size:13.5px;color:#475569;}

.facts{display:flex;flex-direction:column;gap:8px;text-align:left;margin-bottom:22px;}
.fact{display:flex;align-items:center;gap:10px;font-size:12.5px;color:#64748b;}
.fact i{width:26px;height:26px;border-radius:9px;background:#f1f5f9;display:grid;place-items:center;font-size:11px;color:#64748b;flex-shrink:0;}
.fact b{color:#0f172a;font-weight:700;}

.note{font-size:12px;color:#64748b;background:#f8fafc;border-radius:12px;padding:13px 16px;margin-bottom:22px;text-align:left;}
.note b{color:#0f172a;}

.actions{display:flex;gap:10px;flex-wrap:wrap;}
.btn{
    flex:1;min-width:180px;display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:13px 20px;border-radius:12px;font-size:13.5px;font-weight:800;text-decoration:none;
    border:none;cursor:pointer;font-family:inherit;transition:all .25s cubic-bezier(.34,1.56,.64,1);
}
.btn.primary{background:linear-gradient(135deg, #059669, #065f46);color:#fff;box-shadow:0 8px 22px rgba(16,185,129,.32);}
.btn.primary:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(16,185,129,.42);}
.btn.ghost{background:#fff;border:1.5px solid #e2e8f0;color:#475569;}
.btn.ghost:hover{background:#f7f9fb;border-color:#6ee7b7;}
</style>
</head>
<body>

<div class="card">
    <div class="brand"><i class="fas fa-store"></i> MALLENGKE</div>

    <div class="badge<?= $isRemoved ? ' removed' : '' ?>">
        <i class="fas <?= htmlspecialchars($icon) ?>"></i>
    </div>

    <h1><?= htmlspecialchars($heading) ?></h1>
    <p class="lead">
        <?= $isRemoved
            ? 'This account can no longer sign in to the vendor portal.'
            : 'This account cannot sign in to the vendor portal at the moment.' ?>
    </p>

    <div class="reason<?= $isRemoved ? ' removed' : '' ?>">
        <div class="k"><i class="fas fa-circle-info"></i> From the market office</div>
        <p><?= htmlspecialchars((string)$block['description']) ?></p>
    </div>

    <?php if (!empty($block['stall']) || !empty($block['name'])): ?>
    <div class="facts">
        <?php if (!empty($block['stall'])): ?>
        <div class="fact"><i class="fas fa-store"></i> Your stall: <b><?= htmlspecialchars((string)$block['stall']) ?></b></div>
        <?php endif; ?>
        <div class="fact"><i class="fas fa-user"></i> Signed in as: <b><?= htmlspecialchars((string)($block['name'] ?: 'your account')) ?></b></div>
    </div>
    <?php endif; ?>

    <div class="note">
        <b>What you can do:</b> visit the market office during business hours to ask about this,
        and bring a valid ID. Nothing in your stall record was deleted<?= $isRemoved ? ' except your login' : '' ?> — the
        office can explain the decision and restore access if it was a mistake.
    </div>

    <div class="actions">
        <a href="login.php" class="btn primary"><i class="fas fa-right-to-bracket"></i> Back to sign in</a>
        <a href="stall_map.php" class="btn ghost"><i class="fas fa-map-location-dot"></i> Stall map</a>
    </div>
</div>

</body>
</html>