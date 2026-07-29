<?php
/**
 * save_initial_tariffs.php
 * Hifadhi tariffs 3 za mwanzo (Siku/Wiki/Mwezi) kwa ROUTER MAALUM
 * (router "active" ya session - multi-router), pamoja na kuweka
 * profile_name kwa ajili ya MikroTik.
 */
session_start();
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Login tena.']);
    exit();
}

$my_id     = (int)$_SESSION['user_id'];
$router_id = (int)($_SESSION['active_router_id'] ?? 0);

if ($router_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Hujachagua router. Nenda "My Mikrotiks" kwanza.']);
    exit();
}

// ── THIBITISHA ROUTER HII NI YAKO ──
$own_stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
$own_stmt->bind_param("ii", $router_id, $my_id);
$own_stmt->execute();
if ($own_stmt->get_result()->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Router hii si mali yako.']);
    $own_stmt->close();
    exit();
}
$own_stmt->close();

// ── PATA NA SAFISHA DATA KUTOKA FOMU ──
$siku_price   = (float)($_POST['siku_price'] ?? 0);
$siku_speed   = trim($_POST['siku_speed'] ?? '');
$wiki_price   = (float)($_POST['wiki_price'] ?? 0);
$wiki_speed   = trim($_POST['wiki_speed'] ?? '');
$mwezi_price  = (float)($_POST['mwezi_price'] ?? 0);
$mwezi_speed  = trim($_POST['mwezi_speed'] ?? '');

if ($siku_price <= 0 || $wiki_price <= 0 || $mwezi_price <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Bei zote tatu zinahitajika na lazima ziwe zaidi ya 0.']);
    exit();
}
if (empty($siku_speed) || empty($wiki_speed) || empty($mwezi_speed)) {
    echo json_encode(['status' => 'error', 'message' => 'Speed ya vifurushi vyote tatu inahitajika.']);
    exit();
}

// ── HAKIKISHA ROUTER HII HAJAWEKA TARIFFS TAYARI (kuzuia duplicate) ──
$check = $conn->prepare("SELECT COUNT(*) as c FROM tariffs WHERE router_id = ?");
$check->bind_param("i", $router_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc()['c'];

if ($existing > 0) {
    echo json_encode(['status' => 'success', 'message' => 'Tariffs zilikuwepo tayari kwa router hii.']);
    exit();
}

$tariffs = [
    ['package_type' => 'daily',   'price' => $siku_price,  'duration_days' => 1,  'speed' => $siku_speed,  'profile_name' => 'daily_profile'],
    ['package_type' => 'weekly',  'price' => $wiki_price,  'duration_days' => 7,  'speed' => $wiki_speed,  'profile_name' => 'weekly_profile'],
    ['package_type' => 'monthly', 'price' => $mwezi_price, 'duration_days' => 30, 'speed' => $mwezi_speed, 'profile_name' => 'monthly_profile'],
];

$sql = "INSERT INTO tariffs (user_id, router_id, package_type, price, speed, profile_name, duration_days) VALUES (?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

$all_success = true;
foreach ($tariffs as $t) {
    $stmt->bind_param(
        "iisdssi",
        $my_id,
        $router_id,
        $t['package_type'],
        $t['price'],
        $t['speed'],
        $t['profile_name'],
        $t['duration_days']
    );
    if (!$stmt->execute()) {
        $all_success = false;
    }
}

if ($all_success) {
    if (file_exists('activity_log.php')) {
        require_once 'activity_log.php';
        logActivity($conn, $my_id, 'setup_tariffs', "Amejipangia bei za vifurushi vya mwanzo (router_id={$router_id})");
    }
    echo json_encode(['status' => 'success', 'message' => 'Vifurushi vimehifadhiwa kwa mafanikio!']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Kosa la kiufundi wakati wa kuhifadhi. Jaribu tena.']);
}
exit();