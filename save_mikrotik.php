<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'auth_check.php';
include 'login_signup.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Huna ruhusa kufikia ukurasa huu. 🚫'];
    header("Location: index.php");
    exit();
}

require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)

$user_id     = isset($_POST['user_id'])     ? $conn->real_escape_string($_POST['user_id'])     : '';
$mteja_name  = isset($_POST['mteja_name'])  ? $conn->real_escape_string($_POST['mteja_name'])  : 'Mteja';
$mikrotik_ip = isset($_POST['mikrotik_ip']) ? $conn->real_escape_string($_POST['mikrotik_ip']) : '';
$api_user    = isset($_POST['api_user'])    ? $conn->real_escape_string($_POST['api_user'])    : '';
$api_pass    = isset($_POST['api_pass'])    ? $conn->real_escape_string($_POST['api_pass'])    : '';

if (empty($user_id) || empty($mikrotik_ip) || empty($api_user) || empty($api_pass)) {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Tafadhali jaza nafasi zote kwenye fomu kabla ya kuhifadhi! ⚠️'];
    header("Location: admin.php");
    exit();
}

$API = new RouterosAPI();
$API->debug = false;

if ($API->connect($mikrotik_ip, $api_user, $api_pass)) {
    $API->disconnect();

    // id=LAST_INSERT_ID(id) inafanya $conn->insert_id irudishe id ya row hii
    // hata pale ambapo config ilishakuwepo (UPDATE), siyo kwenye INSERT mpya tu.
    $sql = "INSERT INTO mikrotik_configs (user_id, mikrotik_ip, api_user, api_pass)
            VALUES ('$user_id', '$mikrotik_ip', '$api_user', '$api_pass')
            ON DUPLICATE KEY UPDATE
            id=LAST_INSERT_ID(id), mikrotik_ip='$mikrotik_ip', api_user='$api_user', api_pass='$api_pass'";

    if ($conn->query($sql) === TRUE) {
        $row_id = (int)$conn->insert_id;

        // Mpe router hii router_id yake kama bado haina. router_id ndiyo namba
        // inayoandikwa kwenye login.html (var routerID) ya router husika, na
        // ndiyo inayotumiwa na index_backup.php kuitambua.
        $conn->query("UPDATE mikrotik_configs SET router_id = id WHERE id = $row_id AND (router_id IS NULL OR router_id = 0)");
        $rid_row   = $conn->query("SELECT router_id FROM mikrotik_configs WHERE id = $row_id")->fetch_assoc();
        $router_id = (int)($rid_row['router_id'] ?? 0);

        $_SESSION['toast'] = [
            'type' => 'success',
            'msg'  => 'MikroTik ya ' . htmlspecialchars($mteja_name, ENT_QUOTES) . ' imethibitishwa na kuhifadhiwa! 🎉 Router ID: ' . $router_id . ' — weka namba hii kwenye login.html (var routerID) ya router hii.'
        ];
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'msg'  => 'Hitilafu ya Database: ' . $conn->error
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
