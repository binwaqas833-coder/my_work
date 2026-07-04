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
$phone       = trim($_POST['username'] ?? '');
$mpango_mpya = trim($_POST['package']  ?? '');

if (empty($phone) || empty($mpango_mpya)) {
    echo json_encode(['status' => 'error', 'message' => 'Data haijakamilika!']);
    exit();
}

// 3. Vuta MikroTik config ya owner huyu
$stmt = $conn->prepare("SELECT mikrotik_ip, api_user, api_pass FROM mikrotik_configs WHERE user_id=?");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$config = $stmt->get_result()->fetch_assoc();

if (!$config) {
    echo json_encode(['status' => 'error', 'message' => 'Huna router iliyosajiliwa!']);
    exit();
}

// 4. Amua siku kulingana na package (inakubali majina ya Kiswahili au Kiingereza)
$pkg_lower = strtolower($mpango_mpya);
if ($pkg_lower === 'daily' || $pkg_lower === 'siku') {
    $days = 1;
} elseif ($pkg_lower === 'weekly' || $pkg_lower === 'wiki') {
    $days = 7;
} elseif ($pkg_lower === 'monthly' || $pkg_lower === 'mwezi') {
    $days = 30;
} else {
    $days = 1;
}

// ── Column ya 'package_type' kwenye vouchers ni ENUM('daily','weekly','monthly') TU ──
// Kwa hiyo lazima tubadilishe jina la Kiswahili (Siku/Wiki/Mwezi) kuwa hizo hasa,
// la sivyo MySQL inakataa UPDATE (data truncated) na kuvunja JSON.
if ($pkg_lower === 'daily' || $pkg_lower === 'siku') {
    $enum_package = 'daily';
} elseif ($pkg_lower === 'weekly' || $pkg_lower === 'wiki') {
    $enum_package = 'weekly';
} elseif ($pkg_lower === 'monthly' || $pkg_lower === 'mwezi') {
    $enum_package = 'monthly';
} else {
    $enum_package = 'daily';
}

// Jina la profile kwenye MikroTik (tumia jina halisi unalotumia kwenye router)
$mikrotik_profile = ucfirst($enum_package) . "_Profile";

// 5. Unganisha na MikroTik
$API = new RouterosAPI();

if ($API->connect($config['mikrotik_ip'], $config['api_user'], $config['api_pass'])) {

    // Sasisha profile na uptime kwenye MikroTik
    $API->comm("/ip/hotspot/user/set", [
        "numbers"      => $phone,
        "profile"      => $mikrotik_profile,
        "limit-uptime" => ($days * 24) . "h"
    ]);
    $API->disconnect();

    // 6. Sasisha database
    $new_expiry = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    $upd = $conn->prepare("UPDATE vouchers SET expiry_date=?, package_type=?, status='used' WHERE phone=? AND user_id=?");
    $upd->bind_param("sssi", $new_expiry, $enum_package, $phone, $owner_id);
    $upd->execute();

    if ($upd->affected_rows > 0) {
        echo json_encode([
            'status'  => 'success',
            'message' => "✅ {$phone} amefanyiwa renew ya {$mpango_mpya} hadi " . date('d M Y', strtotime($new_expiry))
        ]);
    } else {
        echo json_encode([
            'status'  => 'error',
            'message' => "Mteja '{$phone}' hakupatikana kwenye database."
        ]);
    }

} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Imeshindikana kuunganisha na MikroTik. Angalia router yako.'
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
?>