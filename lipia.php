<?php
/**
 * lipia.php
 * Inapokea fomu kutoka index_backup.php, inafanya (kwa sasa) MOCK ya malipo
 * ya AzamPay, kisha inatengeneza voucher na kumuunganisha mteja moja kwa moja
 * kwenye MikroTik Hotspot (auto-login) - bila kumtaka abonyeze kitufe.
 *
 * ⚠️ MUHIMU: Sehemu ya AzamPay kwa sasa ni "mock" (haijaunganishwa na API
 * halisi) kwa sababu API keys hazijapatikana bado. Mara utapopata Client ID
 * na Client Secret, badilisha tu function tumaUSSDPush() chini - kazi
 * iliyobaki (voucher + auto-login) haitahitaji kubadilika.
 */

session_start();
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';

// ── 1. POKEA DATA KUTOKA FOMU YA index_backup.php ──
$package_type = strtolower(trim($_POST['package_type'] ?? ''));
$user_id  = intval($_POST['user_id'] ?? 0);
$namba_simu   = trim($_POST['namba_simu'] ?? '');
$bei_kutoka_form = floatval($_POST['kifurushi_kichaguliwa'] ?? 0);

// Taarifa za MikroTik zilizonaswa na index_backup.php kwenye session
$client_mac  = $_SESSION['client_mac']  ?? '';
$client_ip   = $_SESSION['client_ip']   ?? '';
$client_link = $_SESSION['client_link'] ?? ''; // $(link-login-only)

function onyeshaUkurasaWaHitilafu($ujumbe) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='sw'><head><meta charset='UTF-8'><title>Hitilafu</title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
    <body style='font-family:Arial,sans-serif;text-align:center;padding:60px 20px;background:#f4f4f4;'>
    <div style='background:#fff;max-width:420px;margin:0 auto;padding:30px;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.1);'>
    <h2 style='color:#d32f2f;'>⚠️ Hitilafu</h2>
    <p style='color:#444;line-height:1.6;'>" . htmlspecialchars($ujumbe) . "</p>
    <a href='index.php' style='display:inline-block;margin-top:14px;padding:10px 20px;background:#1f8a3d;color:#fff;border-radius:8px;text-decoration:none;'>Rudi Nyuma</a>
    </div></body></html>";
    exit();
}

/**
 * Tambua mtandao wa simu (Vodacom/Tigo/Airtel/Halotel/Zantel) kutoka prefix
 * ya namba ya simu. Hii ni kiashiria kizuri lakini siyo hakika 100% kwa
 * namba zilizofanyiwa "number portability" (kubadili mtandao bila kubadili namba).
 *
 * Chanzo cha prefixes: TCRA National Numbering Plan.
 */
function tambuaMtandaoWaSimu($namba) {
    $namba = preg_replace('/[^0-9]/', '', $namba);
    // Geuza kuwa muundo wa 0XXXXXXXXX kama imeandikwa na +255 au 255
    if (strpos($namba, '255') === 0) {
        $namba = '0' . substr($namba, 3);
    }
    $prefix3 = substr($namba, 0, 3); // mfano '075'

    $ramani = [
        '074' => 'Vodacom (M-Pesa)', '075' => 'Vodacom (M-Pesa)', '076' => 'Vodacom (M-Pesa)',
        '065' => 'Yas/Tigo (Mixx by Yas)', '067' => 'Yas/Tigo (Mixx by Yas)', '071' => 'Yas/Tigo (Mixx by Yas)',
        '068' => 'Airtel Money', '069' => 'Airtel Money', '078' => 'Airtel Money',
        '061' => 'Halotel (HaloPesa)', '062' => 'Halotel (HaloPesa)',
        '077' => 'Yas/Tigo (Mixx by Yas)',
        '073' => 'TTCL',
    ];

    return $ramani[$prefix3] ?? 'Haijatambulika';
}

// ── 2. VALIDATION YA MSINGI ──
if (empty($package_type) || $user_id <= 0 || empty($namba_simu)) {
    onyeshaUkurasaWaHitilafu("Taarifa za malipo hazijakamilika. Tafadhali rudi nyuma na ujaze fomu kwa usahihi.");
}

if (!preg_match('/^0[67]\d{8}$/', $namba_simu)) {
    onyeshaUkurasaWaHitilafu("Namba ya simu '$namba_simu' si sahihi. Tumia muundo: 07XXXXXXXX.");
}

// ── 3. TAFUTA TARIFF HALISI (chanzo cha ukweli - siyo bei kutoka form) ──
$t_stmt = $conn->prepare("SELECT * FROM tariffs WHERE user_id = ? AND package_type = ? LIMIT 1");
$t_stmt->bind_param("is", $user_id, $package_type);
$t_stmt->execute();
$tariff = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$tariff) {
    onyeshaUkurasaWaHitilafu("Kifurushi hicho hakipatikani kwa mtoa huduma huyu. Tafadhali rudi nyuma na uchague tena.");
}

$price         = (float)$tariff['price'];   // bei HALISI kutoka database, siyo kutoka form
$duration_days = (int)$tariff['duration_days'];
$profile_name  = $tariff['profile_name'];

// ── 4. MOCK YA AZAMPAY USSD PUSH ──
/**
 * Mara utapopata API keys za AzamPay, badilisha mwili wa function hii
 * kuzungumza na API halisi (POST kwenda checkout endpoint, kusubiri
 * callback/webhook). Kwa sasa, hii inadhania malipo yamefanikiwa moja
 * kwa moja, ili tuweze kutest mfumo mzima bila kusubiri AzamPay.
 *
 * @return array ['success' => bool, 'transaction_id' => string|null, 'message' => string]
 */
function tumaUSSDPush($namba_simu, $kiasi) {
    // ⚠️ MOCK - AzamPay halisi itawekwa hapa baadaye
    $transaction_id = 'MOCK-' . strtoupper(bin2hex(random_bytes(5)));
    return [
        'success'        => true,
        'transaction_id' => $transaction_id,
        'message'        => 'Malipo yamefanikiwa (MOCK - bado AzamPay halisi haijaunganishwa).'
    ];
}

$malipo = tumaUSSDPush($namba_simu, $price);

if (!$malipo['success']) {
    onyeshaUkurasaWaHitilafu("Malipo yameshindikana: " . $malipo['message']);
}

// ── 5. TAMBUA MTANDAO WA SIMU (kwa ajili ya payment_method) ──
// AzamPay ni gateway tu (njia ya kupitisha malipo), siyo mtandao wa simu.
// payment_method inahitaji kuonyesha mtandao halisi: Vodacom/Tigo/Airtel/Halotel.
$mtandao_wa_simu = tambuaMtandaoWaSimu($namba_simu);

// ── 6. TENGENEZA VOCHA YA KIPEKEE ──
do {
    $voucher_code = rand(100000, 999999);
    $check = $conn->query("SELECT id FROM vouchers WHERE voucher_code='$voucher_code' AND user_id='$user_id' LIMIT 1");
} while ($check && $check->num_rows > 0);

// ── 6. UNGANISHA NA MIKROTIK NA UNDA MTUMIAJI WA HOTSPOT ──
$mt_res = $conn->query("SELECT * FROM mikrotik_configs WHERE user_id='$user_id' LIMIT 1");
if (!$mt_res || $mt_res->num_rows == 0) {
    onyeshaUkurasaWaHitilafu("Mtoa huduma huyu hajasajili router yake. Tafadhali wasiliana na msimamizi.");
}
$router = $mt_res->fetch_assoc();

$mikrotik_limit_uptime = ($duration_days >= 1) ? ($duration_days . "d") : "1h";

$API = new RouterosAPI();
$API->debug = false;
$mikrotik_synced = 0;
$login_imefanikiwa = false;

if ($API->connect($router['mikrotik_ip'], $router['api_user'], $router['api_pass'])) {

    // Ongeza mtumiaji kwenye hotspot user list (jina la voucher = password)
    $API->write('/ip/hotspot/user/add', false);
    $API->write('=name='         . $voucher_code, false);
    $API->write('=password='     . $voucher_code, false);
    $API->write('=profile='      . $profile_name, false);
    $API->write('=limit-uptime=' . $mikrotik_limit_uptime);
    $add_response = $API->read();

    if (!isset($add_response['!trap'])) {
        $mikrotik_synced = 1;

        // ── AUTO-LOGIN: muunganishe mteja papo hapo kwenye hotspot active session ──
        // Tunatumia mac+ip zilizonaswa kutoka login.html (kupitia index_backup.php)
        if (!empty($client_mac) && !empty($client_ip)) {
            $API->write('/ip/hotspot/active/login', false);
            $API->write('=user='     . $voucher_code, false);
            $API->write('=password=' . $voucher_code, false);
            $API->write('=mac-address=' . $client_mac, false);
            $API->write('=ip='       . $client_ip);
            $login_response = $API->read();

            if (!isset($login_response['!trap'])) {
                $login_imefanikiwa = true;
            }
        }
    }
    $API->disconnect();
}

// ── 7. HIFADHI VOCHA KWENYE DATABASE (hata kama auto-login imeshindwa) ──
$status_voucher = $login_imefanikiwa ? 'used' : 'unused';
$expiry_sql_part = $login_imefanikiwa ? ", expiry_date = DATE_ADD(NOW(), INTERVAL $duration_days DAY)" : "";

$mac_kwa_db = !empty($client_mac) ? $client_mac : null;

$ins = $conn->prepare("
    INSERT INTO vouchers
        (user_id, phone, mac_address, voucher_code, package_type, price, duration_days,
         mikrotik_profile, status, payment_method, type, mikrotik_synced, transaction_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)
");
$ins->bind_param(
    "issssdisssis",
    $user_id, $namba_simu, $mac_kwa_db, $voucher_code, $package_type, $price, $duration_days,
    $profile_name, $status_voucher, $mtandao_wa_simu, $mikrotik_synced, $malipo['transaction_id']
);
$ins->execute();
$voucher_db_id = $conn->insert_id;
$ins->close();

if ($login_imefanikiwa) {
    $conn->query("UPDATE vouchers SET expiry_date = DATE_ADD(NOW(), INTERVAL $duration_days DAY), last_login_at = NOW() WHERE id = $voucher_db_id");
}

// ── 8. ONYESHA UKURASA WA MATOKEO ──
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Matokeo ya Malipo</title>
<style>
    body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px;}
    .card{background:#fff;max-width:400px;width:100%;padding:34px 28px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,0.12);text-align:center;}
    .icon{font-size:54px;margin-bottom:12px;}
    h2{margin:0 0 10px;color:#1f8a3d;}
    p{color:#444;line-height:1.6;font-size:14px;}
    .code-box{background:#f1f8e9;border:2px dashed #1f8a3d;border-radius:10px;padding:14px;margin:18px 0;font-size:22px;font-weight:700;letter-spacing:3px;color:#145a28;}
    .warn{color:#d32f2f;font-size:13px;margin-top:10px;}
</style>
</head>
<body>
<div class="card">
<?php if ($login_imefanikiwa): ?>
    <div class="icon">✅</div>
    <h2>Umeunganishwa!</h2>
    <p>Malipo yamekamilika na umeunganishwa kwenye mtandao moja kwa moja. Furahia <?php echo $duration_days; ?> siku za intaneti!</p>
    <div class="code-box"><?php echo $voucher_code; ?></div>
    <p style="font-size:12px;color:#888;">Tunza namba hii ya vocha kwa rejea.</p>
<?php else: ?>
    <div class="icon">🎫</div>
    <h2>Malipo Yamekamilika</h2>
    <p>Vocha yako imetengenezwa, lakini imeshindikana kukuunganisha moja kwa moja. Tafadhali tumia namba hii kwenye ukurasa wa kuingia wa Wi-Fi:</p>
    <div class="code-box"><?php echo $voucher_code; ?></div>
    <p class="warn">Kama tatizo litaendelea, wasiliana na msimamizi wa mtandao.</p>
<?php endif; ?>
</div>
</body>
</html>