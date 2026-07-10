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

// Pata lugha iliyochaguliwa kutoka kwenye session (default ni Kiswahili 'sw')
$lang = $_SESSION['lang'] ?? 'sw';

// Kamusi ya ujumbe wa makosa na mafanikio kwa lugha zote mbili
$msg = [
    'sw' => [
        'hitilafu' => 'Hitilafu',
        'rudi_nyuma' => 'Rudi Nyuma',
        'brute_force' => 'Umejaribu mara nyingi bila mafanikio. Tafadhali subiri dakika %d kisha jaribu tena.',
        'ingiza_vocha' => 'Tafadhali ingiza namba ya vocha.',
        'haipo' => "Vocha '%s' haipo kabisa. Hakikisha umeandika sahihi.",
        'imetumika' => 'Vocha hii imetumika tayari na haiwezi kutumika tena.',
        'imeisha_muda' => 'Vocha hii imeisha muda wake (expired).',
        'unganisha_feli' => 'Imeshindikana kukuunganisha kwenye mtandao. Vocha bado ipo salama - jaribu tena au wasiliana na msimamizi.',
        'umeunganishwa' => 'Umeunganishwa!',
        'mafanikio_text' => 'Vocha yako imekubaliwa na umeunganishwa kwenye mtandao. Furahia %d siku za intaneti!'
    ],
    'en' => [
        'hitilafu' => 'Error',
        'rudi_nyuma' => 'Go Back',
        'brute_force' => 'Too many failed attempts. Please wait %d minutes and try again.',
        'ingiza_vocha' => 'Please enter the voucher number.',
        'haipo' => "Voucher '%s' does not exist. Please ensure it is correct.",
        'imetumika' => 'This voucher has already been used and cannot be reused.',
        'imeisha_muda' => 'This voucher has expired.',
        'unganisha_feli' => 'Failed to connect you to the network. Voucher is still safe - try again or contact the administrator.',
        'umeunganishwa' => 'Connected!',
        'mafanikio_text' => 'Your voucher has been accepted and you are connected to the network. Enjoy %d days of internet!'
    ]
];

$kodi_vocha = trim($_POST['kodi_vocha'] ?? '');

// Taarifa za MikroTik zilizonaswa na index_backup.php kwenye session
$client_mac  = $_SESSION['client_mac']  ?? '';
$client_ip   = $_SESSION['client_ip']   ?? '';
$client_link = $_SESSION['client_link'] ?? ''; 

// ── ULINZI DHIDI YA BRUTE-FORCE (rate limiting kwa IP) ──
const RATE_MAX_MAJARIBIO   = 5;   
const RATE_DIRISHA_DAKIKA  = 15;  
const RATE_BLOCK_DAKIKA    = 15;  

function pataKitambulishoMteja() {
    $router_id           = (int)($_SESSION['router_id'] ?? 0);
    $mac                 = $_SESSION['client_mac'] ?? '';
    $ip                  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $kitambulisho_kifaa  = !empty($mac) ? $mac : $ip; 
    return $router_id . ':' . $kitambulisho_kifaa;
}

function angaliaKamaIpImefungwa($conn, $key) {
    $stmt = $conn->prepare("SELECT blocked_until FROM voucher_attempts WHERE client_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
        $dakika_zilizobaki = (int)ceil((strtotime($row['blocked_until']) - time()) / 60);
        return ['blocked' => true, 'dakika' => $dakika_zilizobaki];
    }

    return ['blocked' => false];
}

function ongezaJaribioLisilofanikiwa($conn, $key) {
    $stmt = $conn->prepare("SELECT id, attempts, first_attempt_at FROM voucher_attempts WHERE client_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $dirisha_limepita = $row && (strtotime($row['first_attempt_at']) < strtotime('-' . RATE_DIRISHA_DAKIKA . ' minutes'));

    if (!$row || $dirisha_limepita) {
        $stmt = $conn->prepare("
            INSERT INTO voucher_attempts (client_key, attempts, first_attempt_at, blocked_until)
            VALUES (?, 1, NOW(), NULL)
            ON DUPLICATE KEY UPDATE attempts = 1, first_attempt_at = NOW(), blocked_until = NULL
        ");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $majaribio_mapya = (int)$row['attempts'] + 1;
    $block_dakika    = RATE_BLOCK_DAKIKA;

    if ($majaribio_mapya >= RATE_MAX_MAJARIBIO) {
        $stmt = $conn->prepare("
            UPDATE voucher_attempts
            SET attempts = ?, blocked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
            WHERE id = ?
        ");
        $stmt->bind_param("iii", $majaribio_mapya, $block_dakika, $row['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("UPDATE voucher_attempts SET attempts = ? WHERE id = ?");
        $stmt->bind_param("ii", $majaribio_mapya, $row['id']);
        $stmt->execute();
        $stmt->close();
    }
}

function futaJaribioBaadaYaMafanikio($conn, $key) {
    $stmt = $conn->prepare("DELETE FROM voucher_attempts WHERE client_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->close();
}

function onyeshaHitilafuVocha($ujumbe, $lang_code, $kamusi) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='" . $lang_code . "'><head><meta charset='UTF-8'><title>" . $kamusi[$lang_code]['hitilafu'] . "</title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
    <body style='font-family:Arial,sans-serif;text-align:center;padding:60px 20px;background:#f4f4f4;'>
    <div style='background:#fff;max-width:420px;margin:0 auto;padding:30px;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.1);'>
    <h2 style='color:#d32f2f;'>⚠️ " . $kamusi[$lang_code]['hitilafu'] . "</h2>
    <p style='color:#444;line-height:1.6;'>" . htmlspecialchars($ujumbe) . "</p>
    <a href='index_backup.php' style='display:inline-block;margin-top:14px;padding:10px 20px;background:#1f8a3d;color:#fff;border-radius:8px;text-decoration:none;'> " . $kamusi[$lang_code]['rudi_nyuma'] . "</a>
    </div></body></html>";
    exit();
}

$kitambulisho_mteja = pataKitambulishoMteja();

// ── 0. ANGALIA KAMA KITAMBULISHO HIKI KIMEFUNGWA KWA SABABU YA MAJARIBIO MENGI ──
$hali_ya_block = angaliaKamaIpImefungwa($conn, $kitambulisho_mteja);
if ($hali_ya_block['blocked']) {
    $err_msg = sprintf($msg[$lang]['brute_force'], $hali_ya_block['dakika']);
    onyeshaHitilafuVocha($err_msg, $lang, $msg);
}

// ── 1. VALIDATION YA MSINGI ──
if (empty($kodi_vocha)) {
    onyeshaHitilafuVocha($msg[$lang]['ingiza_vocha'], $lang, $msg);
}

// ── 2. TAFUTA VOCHA KWENYE DATABASE ──
$v_stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_code = ? LIMIT 1");
$v_stmt->bind_param("s", $kodi_vocha);
$v_stmt->execute();
$voucher = $v_stmt->get_result()->fetch_assoc();
$v_stmt->close();

if (!$voucher) {
    ongezaJaribioLisilofanikiwa($conn, $kitambulisho_mteja);
    $err_msg = sprintf($msg[$lang]['haipo'], $kodi_vocha);
    onyeshaHitilafuVocha($err_msg, $lang, $msg);
}

// ── 3. THIBITISHA HALI YA VOCHA ──
if ($voucher['status'] === 'used') {
    ongezaJaribioLisilofanikiwa($conn, $kitambulisho_mteja);
    onyeshaHitilafuVocha($msg[$lang]['imetumika'], $lang, $msg);
}
if ($voucher['status'] === 'expired') {
    ongezaJaribioLisilofanikiwa($conn, $kitambulisho_mteja);
    onyeshaHitilafuVocha($msg[$lang]['imeisha_muda'], $lang, $msg);
}

$reseller_id_halisi = (int)$voucher['user_id'];
$profile_name       = $voucher['mikrotik_profile'];
$duration_days      = (int)$voucher['duration_days'];

// ── 4. UNGANISHA NA MIKROTIK YA RESELLER MWENYE VOCHA HII ──
$API = getMikrotikConnection($reseller_id_halisi, $conn);
$login_imefanikiwa = false;
$login_njia         = null; 
$login_url           = null;

if ($API) {
    if (!$voucher['mikrotik_synced']) {
        $mikrotik_limit_uptime = ($duration_days >= 1) ? ($duration_days . "d") : "1h";
        addHotspotUserToMikrotik(
            $API,
            $kodi_vocha,
            $kodi_vocha,
            $profile_name,
            ['limit-uptime' => $mikrotik_limit_uptime]
        );
    }

    if (!empty($client_mac) && !empty($client_ip)) {
        $login_response = loginHotspotUser($API, $kodi_vocha, $kodi_vocha, $client_mac, $client_ip);
        if (!isset($login_response['!trap'])) {
            $login_imefanikiwa = true;
            $login_njia = 'api';
        }
    }
    elseif (!empty($client_link)) {
        $login_url = $client_link . "?username=" . urlencode($kodi_vocha) . "&password=" . urlencode($kodi_vocha);
        $login_imefanikiwa = true;
        $login_njia = 'redirect';
    }

    $API->disconnect();
}

if (!$login_imefanikiwa) {
    onyeshaHitilafuVocha($msg[$lang]['unganisha_feli'], $lang, $msg);
}

futaJaribioBaadaYaMafanikio($conn, $kitambulisho_mteja);

// ── 5. SASISHA STATUS YA VOCHA KUWA 'used' ──
$upd = $conn->prepare("
    UPDATE vouchers
    SET status = 'used', expiry_date = DATE_ADD(NOW(), INTERVAL ? DAY), last_login_at = NOW()
    WHERE id = ?
");
$upd->bind_param("ii", $duration_days, $voucher['id']);
$upd->execute();
$upd->close();

if ($login_njia === 'redirect') {
    header("Location: " . $login_url);
    exit();
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $msg[$lang]['umeunganishwa']; ?></title>
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
    <h2><?php echo $msg[$lang]['umeunganishwa']; ?></h2>
    <p><?php echo sprintf($msg[$lang]['mafanikio_text'], $duration_days); ?></p>
</div>
</body>
</html>
