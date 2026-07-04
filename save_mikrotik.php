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

    $sql = "INSERT INTO mikrotik_configs (user_id, mikrotik_ip, api_user, api_pass)
            VALUES ('$user_id', '$mikrotik_ip', '$api_user', '$api_pass')
            ON DUPLICATE KEY UPDATE
            mikrotik_ip='$mikrotik_ip', api_user='$api_user', api_pass='$api_pass'";

    if ($conn->query($sql) === TRUE) {
        $_SESSION['toast'] = [
            'type' => 'success',
            'msg'  => 'MikroTik ya ' . htmlspecialchars($mteja_name, ENT_QUOTES) . ' imethibitishwa na kuhifadhiwa! 🎉'
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
