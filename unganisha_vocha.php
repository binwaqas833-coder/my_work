<?php
/**
 * unganisha_vocha.php
 * Mteja anayetumia vocha aliyoshapata (mfano vocha ya bure aliyopewa na
 * reseller, au aliyonunua mahali pengine) anaingiza namba ya vocha hapa.
 * Tunaithibitisha kwenye database, kisha tunamuunganisha kwenye MikroTik.
 */

session_start();
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';

$kodi_vocha = trim($_POST['kodi_vocha'] ?? '');

// Taarifa za MikroTik zilizonaswa na index_backup.php kwenye session
$client_mac  = $_SESSION['client_mac'] ?? '';
$client_ip   = $_SESSION['client_ip']  ?? '';
$router_id_session = $_SESSION['router_id'] ?? null; // taarifa ya router (kwa rejea ya baadaye, haitumiki bado)

function onyeshaHitilafuVocha($ujumbe) {
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

// ── 1. VALIDATION YA MSINGI ──
if (empty($kodi_vocha)) {
    onyeshaHitilafuVocha("Tafadhali ingiza namba ya vocha.");
}

// ── 2. TAFUTA VOCHA KWENYE DATABASE ──
$v_stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_code = ? LIMIT 1");
$v_stmt->bind_param("s", $kodi_vocha);
$v_stmt->execute();
$voucher = $v_stmt->get_result()->fetch_assoc();
$v_stmt->close();

if (!$voucher) {
    onyeshaHitilafuVocha("Vocha '$kodi_vocha' haipo kabisa. Hakikisha umeandika sahihi.");
}

// ── 3. THIBITISHA HALI YA VOCHA ──
if ($voucher['status'] === 'used') {
    onyeshaHitilafuVocha("Vocha hii imetumika tayari na haiwezi kutumika tena.");
}
if ($voucher['status'] === 'expired') {
    onyeshaHitilafuVocha("Vocha hii imeisha muda wake (expired).");
}

$reseller_id_halisi = (int)$voucher['user_id'];
$profile_name       = $voucher['mikrotik_profile'];
$duration_days      = (int)$voucher['duration_days'];

// ── 4. UNGANISHA NA MIKROTIK YA RESELLER MWENYE VOCHA HII ──
$mt_res = $conn->query("SELECT * FROM mikrotik_configs WHERE user_id='$reseller_id_halisi' LIMIT 1");
if (!$mt_res || $mt_res->num_rows == 0) {
    onyeshaHitilafuVocha("Tatizo la kiufundi: router ya mtoa huduma halipatikani.");
}
$router = $mt_res->fetch_assoc();

$API = new RouterosAPI();
$API->debug = false;
$login_imefanikiwa = false;

if ($API->connect($router['mikrotik_ip'], $router['api_user'], $router['api_pass'])) {

    // Kama vocha haijapandwa kwenye MikroTik bado (mfano ilitengenezwa
    // tu kwenye database), tuiongeze sasa kabla ya kumlogin mteja.
    if (!$voucher['mikrotik_synced']) {
        $mikrotik_limit_uptime = ($duration_days >= 1) ? ($duration_days . "d") : "1h";
        $API->write('/ip/hotspot/user/add', false);
        $API->write('=name='         . $kodi_vocha, false);
        $API->write('=password='     . $kodi_vocha, false);
        $API->write('=profile='      . $profile_name, false);
        $API->write('=limit-uptime=' . $mikrotik_limit_uptime);
        $API->read();
    }

    // ── LOGIN MOJA KWA MOJA (kama tunazo taarifa za mac/ip) ──
    if (!empty($client_mac) && !empty($client_ip)) {
        $API->write('/ip/hotspot/active/login', false);
        $API->write('=user='        . $kodi_vocha, false);
        $API->write('=password='    . $kodi_vocha, false);
        $API->write('=mac-address=' . $client_mac, false);
        $API->write('=ip='          . $client_ip);
        $login_response = $API->read();

        if (!isset($login_response['!trap'])) {
            $login_imefanikiwa = true;
        }
    }
    $API->disconnect();
}

if (!$login_imefanikiwa) {
    onyeshaHitilafuVocha("Imeshindikana kukuunganisha kwenye mtandao. Vocha bado ipo salama - jaribu tena au wasiliana na msimamizi.");
}

// ── 5. SASISHA STATUS YA VOCHA KUWA 'used' ──
$upd = $conn->prepare("
    UPDATE vouchers
    SET status = 'used', expiry_date = DATE_ADD(NOW(), INTERVAL ? DAY), last_login_at = NOW()
    WHERE id = ?
");
$upd->bind_param("ii", $duration_days, $voucher['id']);
$upd->execute();
$upd->close();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Umeunganishwa</title>
<style>
    body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px;}
    .card{background:#fff;max-width:400px;width:100%;padding:34px 28px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,0.12);text-align:center;}
    .icon{font-size:54px;margin-bottom:12px;}
    h2{margin:0 0 10px;color:#1f8a3d;}
    p{color:#444;line-height:1.6;font-size:14px;}
</style>
</head>
<body>
<div class="card">
    <div class="icon">✅</div>
    <h2>Umeunganishwa!</h2>
    <p>Vocha yako imekubaliwa na umeunganishwa kwenye mtandao. Furahia <?php echo $duration_days; ?> siku za intaneti!</p>
</div>
</body>
</html>
