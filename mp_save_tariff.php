<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id     = (int)$_SESSION['user_id'];
$router_id = (int)($_SESSION['active_router_id'] ?? 0);

if ($router_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Hujachagua router. Nenda "My Mikrotiks" kwanza.']);
    exit();
}

// ── THIBITISHA ROUTER HII NI YAKO (usalama - mtu asije akaandika
// bei kwa router isiyo yake kwa kubadilisha session kwa mkono) ──
$own_stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
$own_stmt->bind_param("ii", $router_id, $my_id);
$own_stmt->execute();
if ($own_stmt->get_result()->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Router hii si mali yako.']);
    $own_stmt->close();
    exit();
}
$own_stmt->close();

$tariff_id    = intval($_POST['id'] ?? 0);
$package_type = trim($_POST['package_type'] ?? '');
$price        = floatval($_POST['price'] ?? 0);
$duration     = floatval($_POST['duration_days'] ?? 0);
$speed        = trim($_POST['speed'] ?? '');

if (empty($package_type) || $price <= 0 || $duration < 0 || empty($speed)) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali jaza taarifa zote kwa usahihi.']);
    exit();
}

$ramani_majina = [
    'daily' => 'daily', 'siku' => 'daily',
    'weekly' => 'weekly', 'wiki' => 'weekly',
    'monthly' => 'monthly', 'mwezi' => 'monthly',
];
$package_type_safe = $ramani_majina[strtolower($package_type)] ?? null;

if ($package_type_safe === null) {
    echo json_encode(['status' => 'error', 'message' => 'Aina ya kifurushi inakubalika ni: Daily, Weekly, au Monthly tu.']);
    exit();
}

$profile_name = preg_replace('/[^a-z0-9_]/', '_', $package_type_safe) . '_profile';

if ($tariff_id > 0) {
    // ── HARIRI KILICHOPO (thibitisha ni cha huyu user NA router hii) ──
    $stmt = $conn->prepare("UPDATE tariffs SET package_type=?, price=?, duration_days=?, speed=? WHERE id=? AND user_id=? AND router_id=?");
    $stmt->bind_param("sddsiii", $package_type_safe, $price, $duration, $speed, $tariff_id, $my_id, $router_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Kifurushi kimebadilishwa.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuhifadhi mabadiliko.']);
    }
    $stmt->close();
} else {
    // ── ONGEZA KIPYA — hakikisha hajaongeza package_type ile ile mara mbili KWENYE ROUTER HII ──
    $check = $conn->prepare("SELECT id FROM tariffs WHERE router_id=? AND package_type=?");
    $check->bind_param("is", $router_id, $package_type_safe);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Router hii tayari ina kifurushi cha aina hii.']);
        $check->close();
        exit();
    }
    $check->close();

    $ins = $conn->prepare("INSERT INTO tariffs (user_id, router_id, package_type, price, duration_days, speed, profile_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("iisddss", $my_id, $router_id, $package_type_safe, $price, $duration, $speed, $profile_name);

    if ($ins->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Kifurushi kimeongezwa.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuongeza kifurushi.']);
    }
    $ins->close();
}