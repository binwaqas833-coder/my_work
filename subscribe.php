<?php
/**
 * subscribe.php
 * -------------------------------------------------------------
 * Reseller anaona hali yake ya sasa (trial/active/grace/expired) na
 * anachagua/analipia plan (Tech Solo/Pro/Max). Kubofya "Lipia Sasa"
 * kunapeleka kwenye start_subscription_payment.php (STK Push - MOCK).
 * -------------------------------------------------------------
 */
session_start();
include 'login_signup.php';
require_once 'subscription_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$my_id = (int)$_SESSION['user_id'];
$sub   = getSubscriptionStatus($conn, $my_id);

$plans = $conn->query("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY price ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lipia Plan · Tech 5G Wi-Fi</title>
<style>
    :root{--bg:#0d1b17;--surface:#132a22;--border:#1f4438;--accent:#07f793;--text:#e8f5ee;--text-muted:#8fa89c;--red:#ff5c5c;--amber:#ffb020;}
    *{box-sizing:border-box;}
    body{font-family:'DM Sans',Arial,sans-serif;background:#0d1b17;color:var(--text);min-height:100vh;padding:30px 16px;margin:0;}
    .wrap{max-width:900px;margin:0 auto;}
    .topnav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
    h1{font-size:22px;margin:0;}
    .back-btn{color:var(--text);text-decoration:none;font-size:13px;opacity:0.8;}
    .status-banner{padding:14px 18px;border-radius:10px;font-size:13.5px;margin-bottom:24px;}
    .status-banner.trial{background:rgba(63,199,253,0.1);border:1px solid rgba(63,199,253,0.3);}
    .status-banner.grace{background:rgba(255,176,32,0.1);border:1px solid rgba(255,176,32,0.3);color:var(--amber);}
    .status-banner.expired{background:rgba(255,92,92,0.1);border:1px solid rgba(255,92,92,0.3);color:var(--red);}
    .status-banner.active{background:rgba(7,247,147,0.1);border:1px solid rgba(7,247,147,0.3);color:var(--accent);}
    .plans-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
    .plan-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;display:flex;flex-direction:column;}
    .plan-name{font-weight:800;font-size:17px;margin-bottom:6px;}
    .plan-price{font-weight:800;font-size:24px;color:var(--accent);margin-bottom:2px;}
    .plan-price span{font-size:12px;color:var(--text-muted);font-weight:400;}
    .plan-routers{font-size:12px;color:var(--text-muted);margin-bottom:16px;}
    .plan-card input{width:100%;padding:10px 12px;margin-bottom:10px;border-radius:8px;border:1px solid var(--border);background:#0d1b17;color:var(--text);font-size:13px;}
    .btn{padding:11px;border-radius:8px;border:none;font-weight:700;font-size:13.5px;cursor:pointer;width:100%;background:var(--accent);color:#04231a;}
    @media (max-width:760px){.plans-grid{grid-template-columns:1fr;}}
</style>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="wrap">

<div class="topnav">
    <h1>💳 Lipia Plan Yako</h1>
    <a class="back-btn" href="user_dashboard.php">← Rudi Dashboard</a>
</div>

<?php if ($sub['status'] === 'trial'): ?>
    <div class="status-banner trial">
        🎁 Uko kwenye <b>Trial ya Bure</b> — inaisha <?php echo date('d M Y', strtotime($sub['expires_at'])); ?>.
        Chagua plan hapa chini kuendelea baada ya hapo.
    </div>
<?php elseif ($sub['status'] === 'active'): ?>
    <div class="status-banner active">
        ✅ Una plan <b><?php echo htmlspecialchars($sub['plan_name']); ?></b> inayotumika hadi <?php echo date('d M Y', strtotime($sub['expires_at'])); ?>.
    </div>
<?php elseif ($sub['status'] === 'grace'): ?>
    <div class="status-banner grace">
        ⚠️ Plan yako imeisha muda — uko kwenye siku za onyo hadi <?php echo date('d M Y', strtotime($sub['grace_until'])); ?>.
        Lipia sasa ili usipoteze access ya dashboard yako.
    </div>
<?php elseif ($sub['status'] === 'expired'): ?>
    <div class="status-banner expired">
        🚫 Plan yako imeisha. Lipia hapa chini ili kupata access ya dashboard yako tena.
        (Routers zako zinaendelea kufanya kazi kwa wateja wako wakati huu.)
    </div>
<?php endif; ?>

<div class="plans-grid">
<?php foreach ($plans as $p): ?>
    <div class="plan-card">
        <div class="plan-name"><?php echo htmlspecialchars($p['plan_name']); ?></div>
        <div class="plan-price">Tsh <?php echo number_format($p['price']); ?><span>/mwaka</span></div>
        <div class="plan-routers"><?php echo (int)$p['max_routers']; ?> router(s)</div>
        <form method="POST" action="start_subscription_payment.php">
            <input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>">
            <input type="tel" name="phone" placeholder="07XXXXXXXX" required>
            <button type="submit" class="btn">Lipia Sasa</button>
        </form>
    </div>
<?php endforeach; ?>
</div>

</div>
</body>
</html>
