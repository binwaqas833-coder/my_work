<?php
session_start();
require('routeros_api.class.php');
include 'login_signup.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)

// ── Jibu daima kwa JSON ──
header('Content-Type: application/json');

// ── Kamata error/exception YOYOTE, isionekane kama HTML inayovunja JSON ──
try {

// 1. Session check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unatakiwa uwe ume-login!']);
    exit();
}
$owner_id = $_SESSION['user_id'];

// 2. Pokea data kwa POST (kutoka modal yetu)
$phone       = trim($_POST['username'] ?? ''); // ni namba ya simu, sio jina la MikroTik
$mpango_mpya = trim($_POST['package']  ?? ''); // hii ni package_type HALISI kutoka jedwali la tariffs

if (empty($phone) || empty($mpango_mpya)) {
    echo json_encode(['status' => 'error', 'message' => 'Data haijakamilika!']);
    exit();
}

// 3. Pata tariff HALISI ya kifurushi kipya (chanzo cha ukweli - siyo kubahatisha)
//    Hii inatupatia profile_name na duration_days sahihi, sawa kabisa na
//    jinsi generate_vouchers.php inavyopata tariff.
$t_stmt = $conn->prepare("SELECT * FROM tariffs WHERE user_id = ? AND package_type = ? LIMIT 1");
$t_stmt->bind_param("is", $owner_id, $mpango_mpya);
$t_stmt->execute();
$tariff = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$tariff) {
    echo json_encode(['status' => 'error', 'message' => "Kifurushi '{$mpango_mpya}' hakipatikani kwenye akaunti yako."]);
    exit();
}

$mikrotik_profile = $tariff['profile_name'];
$days              = (int)$tariff['duration_days'];
if ($days < 1) { $days = 1; }

// ── Column ya 'package_type' kwenye vouchers ni ENUM('daily','weekly','monthly') TU ──
// Kwa hiyo lazima tubadilishe jina la kifurushi (linaweza kuwa jina lolote alilochagua
// admin, mfano "Siku", "Kila Wiki") kuwa hizo hasa, la sivyo MySQL inakataa UPDATE.
$p_lower = strtolower($mpango_mpya);
if (strpos($p_lower, 'week') !== false || strpos($p_lower, 'wiki') !== false) {
    $enum_package = 'weekly';
} elseif (strpos($p_lower, 'month') !== false || strpos($p_lower, 'mwezi') !== false) {
    $enum_package = 'monthly';
} else {
    $enum_package = 'daily';
}

// 4. Tafuta VOUCHER HALISI ya mteja huyu ili kupata voucher_code yake
//    (jina la mtumiaji kwenye MikroTik ni voucher_code, SIYO namba ya simu!)
$vf_stmt = $conn->prepare("SELECT id, voucher_code FROM vouchers WHERE phone = ? AND user_id = ? ORDER BY created_at DESC LIMIT 1");
$vf_stmt->bind_param("si", $phone, $owner_id);
$vf_stmt->execute();
$voucher_row = $vf_stmt->get_result()->fetch_assoc();
$vf_stmt->close();

if (!$voucher_row) {
    echo json_encode(['status' => 'error', 'message' => "Mteja '{$phone}' hakupatikana kwenye database."]);
    exit();
}

$mikrotik_username = $voucher_row['voucher_code'];

// 5. Unganisha na MikroTik kupitia helper (inaheshimu api_port kiotomatiki)
$API = getMikrotikConnection($owner_id, $conn);

if (!$API) {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuunganisha na MikroTik. Angalia router yako.']);
    exit();
}

// Sasisha profile na uptime kwenye MikroTik - kwa kutumia voucher_code halisi
$result = renewHotspotUser($API, $mikrotik_username, $mikrotik_profile, ($days * 24) . "h");
$API->disconnect();

// Kama mtumiaji hakupatikana kwenye MikroTik, au command imeshindwa - SIMAMA
// (usisasishe database kana kwamba imefanikiwa, kama ilivyokuwa awali)
if ($result === false || (is_array($result) && isset($result['!trap']))) {
    echo json_encode([
        'status'  => 'error',
        'message' => "Imeshindikana kufanya renew kwenye MikroTik - '{$mikrotik_username}' haipo kwenye router au kuna hitilafu."
    ]);
    exit();
}

// 6. Sasisha database - rekodi HII HASA tu (id), siyo vocha zote za huyu phone
$new_expiry = date('Y-m-d H:i:s', strtotime("+{$days} days"));
$upd = $conn->prepare("UPDATE vouchers SET expiry_date=?, package_type=?, status='used' WHERE id=?");
$upd->bind_param("ssi", $new_expiry, $enum_package, $voucher_row['id']);
$upd->execute();

if ($upd->affected_rows > 0) {
    echo json_encode([
        'status'  => 'success',
        'message' => "✅ {$phone} amefanyiwa renew ya {$mpango_mpya} hadi " . date('d M Y', strtotime($new_expiry))
    ]);
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => "Imeshindwa kusasisha database kwa mteja '{$phone}'."
    ]);
}

} catch (\Throwable $e) {
    // Chochote kilichovunjika (mysqli error, MikroTik error, n.k) kinarudi
    // kama JSON safi badala ya Fatal Error ya HTML inayovunja fetch() ya JS.
    echo json_encode([
        'status'  => 'error',
        'message' => 'Hitilafu ya mfumo: ' . $e->getMessage()
    ]);
}
