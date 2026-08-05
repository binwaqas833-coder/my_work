<?php
/**
 * start_subscription_payment.php
 * -------------------------------------------------------------
 * Sawa na lipia.php lakini kwa MALIPO YA SUBSCRIPTION (siyo vocha ya
 * mteja). Inatengeneza rekodi ya 'pending_payment', inaiga USSD Push
 * (MOCK - AzamPay halisi itawekwa hapa baadaye, sawa na lipia.php),
 * kisha inaonyesha ukurasa wa "kusubiri malipo" unaopiga poll kwenye
 * check_subscription_status.php.
 * -------------------------------------------------------------
 */
session_start();
include 'login_signup.php';
require_once 'subscription_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$my_id   = (int)$_SESSION['user_id'];
$plan_id = (int)($_POST['plan_id'] ?? 0);
$phone   = trim($_POST['phone'] ?? '');

function onyeshaHitilafu($ujumbe) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='sw'><head><meta charset='UTF-8'><title>Hitilafu</title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
    <body style='font-family:Arial,sans-serif;text-align:center;padding:60px 20px;background:#0d1b17;color:#e8f5ee;'>
    <div style='background:#132a22;max-width:420px;margin:0 auto;padding:30px;border-radius:14px;'>
    <h2 style='color:#ff5c5c;'>⚠️ Hitilafu</h2>
    <p>" . htmlspecialchars($ujumbe) . "</p>
    <a href='subscribe.php' style='display:inline-block;margin-top:14px;padding:10px 20px;background:#07f793;color:#04231a;border-radius:8px;text-decoration:none;font-weight:700;'>Rudi Nyuma</a>
    </div></body></html>";
    exit();
}

if ($plan_id <= 0 || empty($phone)) {
    onyeshaHitilafu("Tafadhali chagua plan na uweke namba ya simu sahihi.");
}
if (!preg_match('/^0[67]\d{8}$/', $phone)) {
    onyeshaHitilafu("Namba ya simu '$phone' si sahihi. Tumia muundo: 07XXXXXXXX.");
}

$p_stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1");
$p_stmt->bind_param("i", $plan_id);
$p_stmt->execute();
$plan = $p_stmt->get_result()->fetch_assoc();
$p_stmt->close();

if (!$plan) {
    onyeshaHitilafu("Plan hiyo haipatikani.");
}

$transaction_id = createPendingSubscriptionPayment($conn, $my_id, $plan_id);

/**
 * ⚠️ MOCK - AzamPay halisi itawekwa hapa (sawa kabisa na lipia.php).
 */
function tumaUSSDPushSubscription($phone, $kiasi, $transaction_id) {
    return ['success' => true, 'message' => 'USSD Push imetumwa (MOCK).'];
}
$malipo = tumaUSSDPushSubscription($phone, $plan['price'], $transaction_id);

if (!$malipo['success']) {
    markSubscriptionPaymentFailed($conn, $transaction_id, $malipo['message']);
    onyeshaHitilafu("Imeshindikana kuanzisha malipo: " . $malipo['message']);
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inasubiri Malipo ya Plan...</title>
<style>
    body{font-family:Arial,sans-serif;background:#0d1b17;color:#e8f5ee;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px;}
    .card{background:#132a22;max-width:400px;width:100%;padding:34px 28px;border-radius:16px;text-align:center;border:1px solid #1f4438;}
    h2{margin:0 0 10px;color:#07f793;}
    p{color:#8fa89c;line-height:1.6;font-size:14px;}
    .spinner{width:44px;height:44px;border:4px solid rgba(255,255,255,0.1);border-top-color:#07f793;border-radius:50%;margin:0 auto 16px;animation:spin 0.9s linear infinite;}
    @keyframes spin{to{transform:rotate(360deg);}}
</style>
</head>
<body>
<div class="card" id="card">
    <div class="spinner" id="spinner"></div>
    <h2 id="title">Tafadhali Kamilisha Malipo</h2>
    <p id="msg">Tumetuma ombi la malipo (STK Push) kwa <b><?php echo htmlspecialchars($plan['plan_name']); ?></b> kwenye simu namba <b><?php echo htmlspecialchars($phone); ?></b>. Ingiza PIN yako pale itakapokuomba.</p>
</div>

<script>
const REF = <?php echo json_encode($transaction_id); ?>;
let jaribio = 0;
const MAX_JARIBIO = 40;

function angaliaHaliYaMalipo() {
    jaribio++;
    fetch('check_subscription_status.php?ref=' + encodeURIComponent(REF))
        .then(r => r.json())
        .then(data => {
            if (data.status === 'completed') {
                onyeshaMafanikio(data.plan_name);
            } else if (data.status === 'failed') {
                onyeshaHitilafu(data.message || 'Malipo yameshindikana.');
            } else if (jaribio >= MAX_JARIBIO) {
                onyeshaHitilafu('Muda wa kusubiri umeisha. Kama pesa imetoka, wasiliana na msimamizi ukiwa na rejea: ' + REF);
            } else {
                setTimeout(angaliaHaliYaMalipo, 3000);
            }
        })
        .catch(() => setTimeout(angaliaHaliYaMalipo, 3000));
}

function onyeshaMafanikio(planJina) {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('card').innerHTML = `
        <div style="font-size:40px;">✅</div>
        <h2>Umefanikiwa!</h2>
        <p>Plan yako ya <b>${planJina}</b> sasa inatumika kwa mwaka mzima.</p>
        <a href="user_dashboard.php" style="display:inline-block;margin-top:14px;padding:10px 20px;background:#07f793;color:#04231a;border-radius:8px;text-decoration:none;font-weight:700;">Rudi Dashboard</a>
    `;
}
function onyeshaHitilafu(ujumbe) {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('card').innerHTML = `
        <div style="font-size:40px;">⚠️</div>
        <h2 style="color:#ff5c5c;">Hitilafu</h2>
        <p>${ujumbe}</p>
        <a href="subscribe.php" style="display:inline-block;margin-top:14px;padding:10px 20px;background:#07f793;color:#04231a;border-radius:8px;text-decoration:none;font-weight:700;">Rudi Nyuma</a>
    `;
}

setTimeout(angaliaHaliYaMalipo, 2000);
</script>
</body>
</html>
