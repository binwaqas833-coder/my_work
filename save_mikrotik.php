<?php
session_start();
include 'auth_check.php';
include 'login_signup.php';   // inaleta config.php ($conn, mt_encrypt, mt_decrypt, mipangilio ya error)

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Huna ruhusa kufikia ukurasa huu. 🚫'];
    header("Location: index.php");
    exit();
}

require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';

// ── Pokea RAW (SIYO escaped): creds hutumwa kwa router kama zilivyo.
// Ku-escape kabla ya kuunganisha huharibu password/username zenye herufi maalum.
$user_id     = (int)($_POST['user_id'] ?? 0);
$mteja_name  = $_POST['mteja_name']  ?? 'Mteja';
$mikrotik_ip = trim($_POST['mikrotik_ip'] ?? '');
$api_user    = trim($_POST['api_user']    ?? '');
$api_pass    = $_POST['api_pass'] ?? '';   // usi-trim: password inaweza kuwa na nafasi

if ($user_id <= 0 || $mikrotik_ip === '' || $api_user === '') {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Tafadhali jaza IP na API User kabla ya kuhifadhi! ⚠️'];
    header("Location: admin.php");
    exit();
}

// Config iliyopo (kwa ku-detect update na ku-preserve password ikiachwa wazi)
$existing = null;
$er = $conn->query("SELECT api_pass FROM mikrotik_configs WHERE user_id = $user_id LIMIT 1");
if ($er && $er->num_rows > 0) {
    $existing = $er->fetch_assoc();
}

// Password: ikiachwa wazi na router tayari ipo -> baki ile ile (usiilazimishe kuandika upya)
if ($api_pass === '') {
    if ($existing) {
        $api_pass      = mt_decrypt($existing['api_pass']); // ya kutumia kwenye test ya connect
        $api_pass_store = $existing['api_pass'];            // hifadhi kama ilivyo (tayari salama)
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Weka API Password kwa router hii mpya. ⚠️'];
        header("Location: admin.php");
        exit();
    }
} else {
    $api_pass_store = mt_encrypt($api_pass); // encrypt kabla ya kuhifadhi
}

$API = new RouterosAPI();
$API->debug = false;

if ($API->connect($mikrotik_ip, $api_user, $api_pass)) {
    $API->disconnect();

    // Escape kwa SQL TU (baada ya test ya connect kutumia RAW)
    $ip_e   = $conn->real_escape_string($mikrotik_ip);
    $user_e = $conn->real_escape_string($api_user);
    $pass_e = $conn->real_escape_string($api_pass_store);

    // router_id ndiyo PRIMARY KEY (AUTO_INCREMENT) ya jedwali, na ndiyo namba
    // inayowekwa kwenye login.html (var routerID) ya router husika.
    // LAST_INSERT_ID(router_id) hufanya insert_id irudishe router_id hata kwenye
    // UPDATE (pale router ya user huyu tayari ipo — user_id ni UNIQUE KEY).
    $sql = "INSERT INTO mikrotik_configs (user_id, mikrotik_ip, api_user, api_pass)
            VALUES ('$user_id', '$ip_e', '$user_e', '$pass_e')
            ON DUPLICATE KEY UPDATE
            router_id=LAST_INSERT_ID(router_id), mikrotik_ip='$ip_e', api_user='$user_e', api_pass='$pass_e'";

    if ($conn->query($sql) === TRUE) {
        $router_id = (int)$conn->insert_id;

        $_SESSION['toast'] = [
            'type' => 'success',
            'msg'  => 'MikroTik ya ' . htmlspecialchars($mteja_name, ENT_QUOTES) . ' imethibitishwa na kuhifadhiwa! 🎉 Router ID: ' . $router_id . ' — weka namba hii kwenye login.html (var routerID) ya router hii.'
        ];
    } else {
        error_log('save_mikrotik DB error: ' . $conn->error);
        $_SESSION['toast'] = [
            'type' => 'error',
            'msg'  => 'Hitilafu ya kuhifadhi kwenye database. Jaribu tena.'
        ];
    }
} else {
    $_SESSION['toast'] = [
        'type' => 'error',
        'msg'  => 'Mawasiliano na MikroTik yamefeli! Kagua IP au API kwenye WinBox. ❌'
    ];
}

$conn->close();
header("Location: admin.php");
exit();
?>
