<?php
// ── SESSION + ERRORS ──
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

include 'login_signup.php';             // inaleta config.php + $conn
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper (mysqli version)

// JSON safi: hakikisha error za PHP hazichapishwi ndani ya response (hata dev)
ini_set('display_errors', 0);
error_reporting(E_ALL);

function jibu($status, $message = '', $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit();
}

/**
 * Tengeneza voucher code kulingana na "format" na "length" uliyochagua.
 *
 * $format inaweza kuwa:
 *   'numeric'     -> namba tu, mfano: 483920
 *   'alpha'       -> herufi kubwa tu, mfano: KDJQXM
 *   'alnum'       -> herufi + namba (bila 0,O,1,I ili kuepuka mkanganyiko), mfano: A3K9Q1
 *
 * $length ni idadi ya characters (mfano 4,5,6,8...)
 */
function generateVoucherCode($format = 'numeric', $length = 6) {
    switch ($format) {
        case 'alpha':
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // bila I na O (zinafanana na 1/0)
            break;
        case 'alnum':
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // bila I,O,0,1
            break;
        case 'numeric':
        default:
            $chars = '0123456789';
            break;
    }
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

/**
 * Tengeneza prefix (SK-/WK-/MW-) kulingana na jina la kifurushi au idadi ya siku.
 * Hii inasaidia kutofautisha vocha za Siku/Wiki/Mwezi kwa jicho tu.
 */
function getVoucherPrefix($package_label, $duration_days) {
    $p = strtolower($package_label ?? '');

    if (strpos($p, 'daily') !== false || strpos($p, 'siku') !== false) {
        return 'SK';
    }
    if (strpos($p, 'weekly') !== false || strpos($p, 'wiki') !== false) {
        return 'WK';
    }
    if (strpos($p, 'monthly') !== false || strpos($p, 'mwezi') !== false) {
        return 'MW';
    }

    // Fallback: jina la kifurushi halikuwa na neno linalotambulika,
    // basi tumia idadi ya siku kukisia aina yake.
    if ($duration_days <= 1)       return 'SK';
    if ($duration_days <= 13)      return 'WK';
    return 'MW';
}

// ── LINDA UKURASA ──
if (!isset($_SESSION['user_id'])) {
    jibu('error', 'Tafadhali ingia kwanza! 🔒');
}

$user_id = (int)$_SESSION['user_id'];

// ── POKEA DATA (sawa na vile vmGenerate() inavyotuma) ──
$mode          = $_POST['mode']          ?? 'batch';      // 'batch' | 'single' | 'free'
$package_type  = trim($_POST['package']  ?? '');          // mfano: 'daily', 'weekly', 'monthly'
$qty           = max(1, intval($_POST['qty'] ?? 1));
$phone         = trim($_POST['phone']    ?? '');
$payment       = trim($_POST['payment']  ?? '');
$sync_mikrotik = isset($_POST['sync_mikrotik']) && $_POST['sync_mikrotik'] == '1';

// ── FORMAT YA VOCHA (mpya) ──
// code_format: 'numeric' | 'alpha' | 'alnum'
// code_length: idadi ya characters (4-12)
$code_format = trim($_POST['code_format'] ?? 'numeric');
$code_length = intval($_POST['code_length'] ?? 6);

if (!in_array($code_format, ['numeric', 'alpha', 'alnum'])) {
    $code_format = 'numeric';
}
if ($code_length < 4 || $code_length > 12) {
    $code_length = 6;
}

if (empty($package_type)) {
    jibu('error', 'Tafadhali chagua kifurushi.');
}

if ($mode === 'batch' && $qty > 100) {
    jibu('warning', 'Idadi kubwa sana! Kiwango cha juu ni vocha 100 kwa wakati mmoja.');
}

if ($mode === 'single' && empty($phone)) {
    jibu('error', 'Ingiza namba ya simu ya mteja.');
}

// ── VUTA TARIFF HALISI KUTOKA DATABASE (chanzo cha ukweli - siyo data za JS) ──
$t_stmt = $conn->prepare("SELECT * FROM tariffs WHERE user_id = ? AND package_type = ? LIMIT 1");
$t_stmt->bind_param("is", $user_id, $package_type);
$t_stmt->execute();
$tariff = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$tariff) {
    jibu('error', 'Kifurushi hicho hakipatikani kwenye akaunti yako.');
}

$tariff_id     = (int)$tariff['id'];
$profile_name  = $tariff['profile_name'];
$duration_days = (int)$tariff['duration_days'];   // int(11) kwenye database
$price         = (float)$tariff['price'];          // bei halisi ya kifurushi (kwa vocha za malipo)
$package_label = $tariff['package_type'];

// Kama ni "Bure", bei iliyohifadhiwa kwenye voucher ni 0 (haijalishi bei ya tariff)
$is_free  = ($mode === 'free') || (strtolower($payment) === 'bure') || (strtolower($payment) === 'free');
$rec_price = $is_free ? 0 : $price;
$rec_type  = $is_free ? 'free' : 'paid';
$rec_payment = $is_free ? 'Bure' : ($payment ?: 'Haijabainishwa');

// ── GEUZA MUDA → MUUNDO WA MIKROTIK (limit-uptime) ──
if ($duration_days < 1) {
    $mikrotik_limit_uptime = "1h"; // default salama kama duration_days=0 haijawekwa vizuri
} else {
    $mikrotik_limit_uptime = $duration_days . "d";
}

// ── UNGANISHA NA MIKROTIK (LAZIMA kila wakati - vocha lazima ifike MikroTik) ──
// Vocha isiyofika MikroTik haina thamani - mtu hawezi ku-login hotspot nayo.
$mt_res = $conn->query("SELECT * FROM mikrotik_configs WHERE user_id='$user_id' LIMIT 1");
if (!$mt_res || $mt_res->num_rows == 0) {
    jibu('error', 'Hujasajili router yako ya MikroTik kwenye mfumo bado! 📡');
}
$router = $mt_res->fetch_assoc();

$API = new RouterosAPI();
$API->debug = false;

if (!$API->connect($router['mikrotik_ip'], $router['api_user'], mt_decrypt($router['api_pass']))) {
    jibu('error', 'Mawasiliano na MikroTik yamefeli! Kagua kama router ipo Online na API imewashwa. 📡❌');
}

// ── IDADI YA VOCHA ZA KUTENGENEZA ──
$quantity = ($mode === 'single' || $mode === 'free') ? 1 : $qty;

$success_count   = 0;
$fail_count      = 0;
$codes_generated = [];

/**
 * MUHIMU kuhusu 'status':
 * - 'batch'  -> 'unused' (hazina mmiliki bado, hazijatumika)
 * - 'single' -> 'unused' (vocha imetengenezwa na kupewa namba ya simu kwa
 *                rejea, LAKINI mteja hajaitumia kwenye MikroTik bado -
 *                inabadilika 'used' pale ataipachika mwenyewe)
 * - 'free'   -> 'unused' (sawa na single - reseller amemkabidhi mteja,
 *                lakini bado haijatumika kweli)
 * status itabadilika kuwa 'used' tu ndani ya unganisha_vocha.php / lipia.php,
 * mahali ambapo mteja anaunganishwa kwenye MikroTik kweli.
 *
 * expiry_date na last_login_at hazijawekwa hapa kwa makusudi - zitajazwa
 * pale vocha itakapotumika kweli (siyo pale inapotengenezwa).
 */
$status_to_save = 'unused';
$expiry_sql = "NULL";

for ($i = 0; $i < $quantity; $i++) {

    // Tengeneza code ya kipekee kulingana na format uliyochagua (numeric/alpha/alnum)
    // + prefix ya kiotomatiki (SK-/WK-/MW-) kutofautisha Siku/Wiki/Mwezi kwa muonekano
    $prefix = getVoucherPrefix($package_label, $duration_days);
    do {
        $voucher_code = $prefix . '-' . generateVoucherCode($code_format, $code_length);
        $check = $conn->query("SELECT id FROM vouchers WHERE voucher_code='$voucher_code' AND user_id='$user_id' LIMIT 1");
    } while ($check && $check->num_rows > 0);

    // Tuma vocha kwenye MikroTik KWANZA - kabla ya kuhifadhi database
    $API->write('/ip/hotspot/user/add', false);
    $API->write('=name='         . $voucher_code, false);
    $API->write('=password='     . $voucher_code, false);
    $API->write('=profile='      . $profile_name, false);
    $API->write('=limit-uptime=' . $mikrotik_limit_uptime);

    $response = $API->read();

    // Kama MikroTik imeshindwa kupokea vocha - SIMAMA, usihifadhi database
    if (isset($response['!trap'])) {
        $fail_count++;
        continue;
    }

    $mikrotik_synced = 1; // MikroTik imepokea vocha - tunaweza hifadhi DB

    // ── HIFADHI KWENYE DATABASE (columns zote muhimu zimejazwa) ──
    $ins = $conn->prepare("
        INSERT INTO vouchers
            (user_id, phone, voucher_code, package_type, price, duration_days,
             mikrotik_profile, status, payment_method, type, mikrotik_synced, expiry_date)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $expiry_sql)
    ");

    // phone inahifadhiwa kwa single/free kwa rejea, hata kama vocha bado
    // 'unused' (mteja bado hajaipachika kwenye MikroTik).
    $phone_to_save = ($mode === 'single' || $mode === 'free') ? $phone : '';

    $ins->bind_param(
        "isssdissssi",
        $user_id,
        $phone_to_save,
        $voucher_code,
        $package_label,
        $rec_price,
        $duration_days,
        $profile_name,
        $status_to_save,
        $rec_payment,
        $rec_type,
        $mikrotik_synced
    );

    if ($ins->execute()) {
        $success_count++;
        $codes_generated[] = $voucher_code;
    } else {
        $fail_count++;
    }
    $ins->close();
}

$API->disconnect();

// ── JIBU JSON (siyo redirect - JS inasoma hii moja kwa moja) ──
if ($success_count > 0 && $fail_count == 0) {
    $label = $is_free ? "Vocha ya bure ya \"{$package_label}\"" : "Vocha {$success_count} za \"{$package_label}\"";
    jibu('success', "✅ {$label} imetengenezwa kikamilifu!", ['codes' => $codes_generated]);
} elseif ($success_count > 0 && $fail_count > 0) {
    jibu('warning', "⚠️ Vocha {$success_count} zimefanikiwa, lakini {$fail_count} zilishindikana. Angalia MikroTik.", ['codes' => $codes_generated]);
} else {
    jibu('error', '❌ Vocha hazikuweza kutengenezwa! Kagua mipangilio ya MikroTik na jaribu tena.');
}