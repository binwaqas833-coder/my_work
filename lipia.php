<?php
/**
 * lipia.php
 * -------------------------------------------------------------
 * Inapokea fomu kutoka index_backup.php, inaanzisha malipo (kwa sasa MOCK
 * ya AzamPay), na kuhifadhi rekodi ya "pending" kwenye payment_transactions.
 *
 * MUHIMU (MULTI-ROUTER): sasa inapokea router_id pia (siyo user_id peke
 * yake) - tariff HALISI (chanzo cha ukweli) inatafutwa kwa router_id,
 * na router_id inahifadhiwa kwenye payment_transactions ili
 * payment_helper.php ijue vocha itengenezwe/kupandishwe router ipi.
 *
 * HAITENGENEZI VOUCHER MOJA KWA MOJA hapa - hilo linatokea ndani ya
 * payment_helper.php::completeVoucherPayment().
 * -------------------------------------------------------------
 */

session_start();
include 'login_signup.php';
require_once 'payment_helper.php';
require_once 'dalipay_client.php';   // ISP Gateway ya Dalipay (malipo halisi)

// ── 1. POKEA DATA KUTOKA FOMU YA index_backup.php ──
$package_type = strtolower(trim($_POST['package_type'] ?? ''));
$user_id      = intval($_POST['user_id'] ?? 0);
$router_id    = intval($_POST['router_id'] ?? 0);
$namba_simu   = trim($_POST['namba_simu'] ?? '');

$client_mac = $_SESSION['client_mac'] ?? '';
$client_ip  = $_SESSION['client_ip']  ?? '';

function onyeshaUkurasaWaHitilafu($ujumbe) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='sw'><head><meta charset='UTF-8'><title>Hitilafu</title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
    <body style='font-family:Arial,sans-serif;text-align:center;padding:60px 20px;background:#f4f4f4;'>
    <div style='background:#fff;max-width:420px;margin:0 auto;padding:30px;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.1);'>
    <h2 style='color:#d32f2f;'>⚠️ Hitilafu</h2>
    <p style='color:#444;line-height:1.6;'>" . htmlspecialchars($ujumbe) . "</p>
    <a href='index_backup.php' style='display:inline-block;margin-top:14px;padding:10px 20px;background:#1f8a3d;color:#fff;border-radius:8px;text-decoration:none;'>Rudi Nyuma</a>
    </div></body></html>";
    exit();
}

// ── 2. VALIDATION YA MSINGI ──
if (empty($package_type) || $user_id <= 0 || $router_id <= 0 || empty($namba_simu)) {
    onyeshaUkurasaWaHitilafu("Taarifa za malipo hazijakamilika. Tafadhali rudi nyuma na ujaze fomu kwa usahihi.");
}
if (!preg_match('/^0[67]\d{8}$/', $namba_simu)) {
    onyeshaUkurasaWaHitilafu("Namba ya simu '$namba_simu' si sahihi. Tumia muundo: 07XXXXXXXX.");
}

// Gateway inakubali Vodacom, Tigo/Yas, Airtel na Halotel pekee. Tunakagua
// HAPA (kabla ya kutengeneza rekodi yoyote) ili mteja wa TTCL apate ujumbe
// wazi mara moja, badala ya muamala kukwama kama 'failed' bila maelezo.
if (dalipayProviderFromPhone($namba_simu) === null) {
    onyeshaUkurasaWaHitilafu("Mtandao wa namba '$namba_simu' hauwezi kutumika kwa malipo kwa sasa. Tafadhali tumia namba ya Vodacom, Tigo/Yas, Airtel au Halotel.");
}

// ── 3. THIBITISHA router_id HII KWELI NI YA user_id HII (usalama) ──
$rc_stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
$rc_stmt->bind_param("ii", $router_id, $user_id);
$rc_stmt->execute();
if ($rc_stmt->get_result()->num_rows === 0) {
    $rc_stmt->close();
    onyeshaUkurasaWaHitilafu("Router hii haipatikani. Tafadhali rudi nyuma na ujaribu tena.");
}
$rc_stmt->close();

// ── 4. TAFUTA TARIFF HALISI - sasa kwa router_id (chanzo cha ukweli) ──
$t_stmt = $conn->prepare("SELECT * FROM tariffs WHERE router_id = ? AND package_type = ? LIMIT 1");
$t_stmt->bind_param("is", $router_id, $package_type);
$t_stmt->execute();
$tariff = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$tariff) {
    onyeshaUkurasaWaHitilafu("Kifurushi hicho hakipatikani kwa router hii. Tafadhali rudi nyuma na uchague tena.");
}
$price = (float)$tariff['price'];

// ── 5. TENGENEZA REJEA YA KIPEKEE YA TRANSACTION ──
$transaction_id = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));

// ── 6. HIFADHI KAMA "PENDING" (bado si voucher - malipo hayajathibitika) ──
$ins = $conn->prepare("
    INSERT INTO payment_transactions (user_id, router_id, phone, package_type, amount, transaction_id, status, client_mac, client_ip)
    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
");
$ins->bind_param("iissdsss", $user_id, $router_id, $namba_simu, $package_type, $price, $transaction_id, $client_mac, $client_ip);
$ins->execute();
$ins->close();

// ── 7. ANZISHA MALIPO HALISI KWENYE GATEWAY (USSD prompt kwenye simu) ──
// Rekodi ya 'pending' tayari ipo hapo juu KABLA ya ombi hili kwa makusudi:
// kama gateway itapokea ombi lakini jibu lipotee njiani (timeout), tunataka
// bado tuwe na rekodi yenye external_id ile ile ambayo webhook itaitambua.
if (!PAYMENT_MOCK_MODE) {
    $malipo = dalipayCreateCollection($namba_simu, $price, $transaction_id);

    if (!$malipo['ok']) {
        markTransactionFailed($conn, $transaction_id, $malipo['error']);
        logSystemError($conn, 'lipia.php', 'Kuanzisha collection kumeshindikana: ' . $malipo['error'], [
            'user_id'   => $user_id,
            'router_id' => $router_id,
            'context'   => ['transaction_id' => $transaction_id, 'phone' => $namba_simu],
        ]);
        onyeshaUkurasaWaHitilafu("Imeshindikana kuanzisha malipo: " . $malipo['error']);
    }

    // Hifadhi vitambulisho vya gateway - uuid ndiyo tunayotumia kuuliza
    // hali ya malipo (check_payment_status.php), na reference ndiyo namba
    // ya kunukuu ukiwasiliana na Dalipay kuhusu muamala huu.
    $g = $conn->prepare(
        "UPDATE payment_transactions SET gateway_uuid=?, gateway_reference=? WHERE transaction_id=?"
    );
    $g->bind_param("sss", $malipo['uuid'], $malipo['reference'], $transaction_id);
    $g->execute();
    $g->close();
}

// ── 7. ONYESHA UKURASA WA "KUSUBIRI MALIPO" (JS itapoll hadi ikamilike) ──
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inasubiri Malipo...</title>
<style>
    body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px;}
    .card{background:#fff;max-width:400px;width:100%;padding:34px 28px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,0.12);text-align:center;}
    .icon{font-size:54px;margin-bottom:12px;}
    h2{margin:0 0 10px;color:#1f8a3d;}
    p{color:#444;line-height:1.6;font-size:14px;}
    .code-box{background:#f1f8e9;border:2px dashed #1f8a3d;border-radius:10px;padding:14px;margin:18px 0;font-size:22px;font-weight:700;letter-spacing:3px;color:#145a28;}
    .warn{color:#d32f2f;font-size:13px;margin-top:10px;}
    .spinner{width:44px;height:44px;border:4px solid #e0e0e0;border-top-color:#1f8a3d;border-radius:50%;margin:0 auto 16px;animation:spin 0.9s linear infinite;}
    @keyframes spin{to{transform:rotate(360deg);}}
    .phone-hint{font-size:12px;color:#888;margin-top:14px;}
</style>
</head>
<body>
<div class="card" id="card">
    <div class="spinner" id="spinner"></div>
    <h2 id="title">Tafadhali Kamilisha Malipo</h2>
    <p id="msg">Tumetuma ombi la malipo (STK Push) kwenye simu namba <b><?php echo htmlspecialchars($namba_simu); ?></b>. Ingiza PIN yako pale itakapokuomba.</p>
    <p class="phone-hint">Usifunge ukurasa huu - utaunganishwa kiotomatiki mara malipo yatakapokamilika.</p>
</div>

<script>
const REF = <?php echo json_encode($transaction_id); ?>;
let jaribio = 0;
const MAX_JARIBIO = 40;

function angaliaHaliYaMalipo() {
    jaribio++;
    fetch('check_payment_status.php?ref=' + encodeURIComponent(REF))
        .then(r => r.json())
        .then(data => {
            if (data.status === 'completed') {
                onyeshaMafanikio(data.voucher_code);
            } else if (data.status === 'failed') {
                onyeshaHitilafu(data.message || 'Malipo yameshindikana.');
            } else if (jaribio >= MAX_JARIBIO) {
                onyeshaHitilafu('Muda wa kusubiri umeisha. Kama pesa imetoka kwenye simu yako, wasiliana na msimamizi ukiwa na namba hii ya rejea: ' + REF);
            } else {
                setTimeout(angaliaHaliYaMalipo, 3000);
            }
        })
        .catch(() => setTimeout(angaliaHaliYaMalipo, 3000));
}

function onyeshaMafanikio(kodi) {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('card').innerHTML = `
        <div class="icon">✅</div>
        <h2>Umeunganishwa Kikamilifu!</h2>
        <p>Malipo yamekamilika. Furahia intaneti yako Asante!</p>
        <div class="code-box">${kodi}</div>
        <p style="font-size:12px;color:#888;">Tunza namba hii ya vocha kwa rejea.</p>
    `;
}

function onyeshaHitilafu(ujumbe) {
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('card').innerHTML = `
        <div class="icon">⚠️</div>
        <h2 style="color:#d32f2f;">Hitilafu</h2>
        <p>${ujumbe}</p>
        <a href="index_backup.php" style="display:inline-block;margin-top:14px;padding:10px 20px;background:#1f8a3d;color:#fff;border-radius:8px;text-decoration:none;">Rudi Nyuma</a>
    `;
}

setTimeout(angaliaHaliYaMalipo, 2000);
</script>
</body>
</html>pay