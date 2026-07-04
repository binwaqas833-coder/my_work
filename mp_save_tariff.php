<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id        = (int)$_SESSION['user_id'];
$tariff_id    = intval($_POST['id'] ?? 0);
$package_type = trim($_POST['package_type'] ?? '');
$price        = floatval($_POST['price'] ?? 0);
$duration     = floatval($_POST['duration_days'] ?? 0);
$speed        = trim($_POST['speed'] ?? '');

// ── VALIDATION ──
if (empty($package_type) || $price <= 0 || $duration < 0 || empty($speed)) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali jaza taarifa zote kwa usahihi.']);
    exit();
}

// package_type kwenye database ni ENUM('daily','weekly','monthly') tu.
// Tunakubali pia maneno ya Kiswahili (siku/wiki/mwezi) na kuyageuza kuwa ENUM sahihi.
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

// Tengeneza profile_name otomatiki kutoka package_type (kwa MikroTik)
$profile_name = preg_replace('/[^a-z0-9_]/', '_', $package_type_safe) . '_profile';

if ($tariff_id > 0) {
    // ── HARIRI KILICHOPO (thibitisha ni cha huyu user) ──
    $stmt = $conn->prepare("UPDATE tariffs SET package_type=?, price=?, duration_days=?, speed=? WHERE id=? AND user_id=?");
    $stmt->bind_param("sddsii", $package_type_safe, $price, $duration, $speed, $tariff_id, $my_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows >= 0) {
            echo json_encode(['status' => 'success', 'message' => 'Kifurushi kimebadilishwa.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Huna ruhusa ya kuhariri kifurushi hiki.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuhifadhi mabadiliko.']);
    }
    $stmt->close();
} else {
    // ── ONGEZA KIPYA ──
    // Hakikisha hajaongeza package_type ile ile mara mbili
    $check = $conn->prepare("SELECT id FROM tariffs WHERE user_id=? AND package_type=?");
    $check->bind_param("is", $my_id, $package_type_safe);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Una tayari kifurushi cha aina hii.']);
        $check->close();
        exit();
    }
    $check->close();

    $ins = $conn->prepare("INSERT INTO tariffs (user_id, package_type, price, duration_days, speed, profile_name) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param("isddss", $my_id, $package_type_safe, $price, $duration, $speed, $profile_name);

    if ($ins->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Kifurushi kimeongezwa.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuongeza kifurushi.']);
    }
    $ins->close();
}
