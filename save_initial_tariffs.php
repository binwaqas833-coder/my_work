<?php
/**
 * save_initial_tariffs.php
 * Hifadhi tariffs 3 za mwanzo (Siku/Wiki/Mwezi) ambazo USER MWENYEWE
 * amejipangia - pamoja na kuweka profile_name kwa ajili ya MikroTik.
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

$my_id = $_SESSION['user_id'];

// ── PATA NA SAFISHA DATA KUTOKA FOMU ──
$siku_price   = (float)($_POST['siku_price'] ?? 0);
$siku_speed   = trim($_POST['siku_speed'] ?? '');
$wiki_price   = (float)($_POST['wiki_price'] ?? 0);
$wiki_speed   = trim($_POST['wiki_speed'] ?? '');
$mwezi_price  = (float)($_POST['mwezi_price'] ?? 0);
$mwezi_speed  = trim($_POST['mwezi_speed'] ?? '');

// ── VALIDATION: vyote vitatu lazima viwe na bei sahihi na speed ──
if ($siku_price <= 0 || $wiki_price <= 0 || $mwezi_price <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Bei zote tatu zinahitajika na lazima ziwe zaidi ya 0.']);
    exit();
}
if (empty($siku_speed) || empty($wiki_speed) || empty($mwezi_speed)) {
    echo json_encode(['status' => 'error', 'message' => 'Speed ya vifurushi vyote tatu inahitajika.']);
    exit();
}

// ── HAKIKISHA HAJAWEKA TARIFFS TAYARI (kuzuia duplicate) ──
$check = $conn->prepare("SELECT COUNT(*) as c FROM tariffs WHERE user_id = ?");
$check->bind_param("i", $my_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc()['c'];

if ($existing > 0) {
    // Tayari ana tariffs - hakuna haja ya kuongeza tena
    echo json_encode(['status' => 'success', 'message' => 'Tariffs zilikuwepo tayari.']);
    exit();
}

// ── ANDAA DATA ZA VIFURUSHI PAMOJA NA PROFILE_NAME ZA MIKROTIK ──
// *MUHIMU:* Hakikisha majina haya ('daily_profile', 'weekly_profile', 'monthly_profile') 
// yanafanana herufi kwa herufi na yale uliyotengeneza kule kwenye MikroTik RouterOS!
$tariffs = [
    [
        'package_type'  => 'daily',
        'price'         => $siku_price,  
        'duration_days' => 1,  
        'speed'         => $siku_speed,
        'profile_name'  => 'daily_profile'
    ],
    [
        'package_type'  => 'weekly',
        'price'         => $wiki_price,  
        'duration_days' => 7,  
        'speed'         => $wiki_speed,
        'profile_name'  => 'weekly_profile'
    ],
    [
        'package_type'  => 'monthly',
        'price'         => $mwezi_price, 
        'duration_days' => 30, 
        'speed'         => $mwezi_speed,
        'profile_name'  => 'monthly_profile'
    ],
];

// Query imeboreshwa kuingiza profile_name na duration_days kwa usahihi
$sql = "INSERT INTO tariffs (user_id, package_type, price, speed, profile_name, duration_days) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

$all_success = true;
foreach ($tariffs as $t) {
    // "isdssi" inamaanisha: Integer, String, Double(float), String, String, Integer
    $stmt->bind_param(
        "isdssi",
        $my_id,
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
    // Log activity kama faili lipo
    if (file_exists('activity_log.php')) {
        require_once 'activity_log.php';
        logActivity($conn, $my_id, 'setup_tariffs', 'Amejipangia bei za vifurushi vya mwanzo vyenye profile za MikroTik');
    }
    echo json_encode(['status' => 'success', 'message' => 'Vifurushi vimehifadhiwa kwa mafanikio!']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Kosa la kiufundi wakati wa kuhifadhi. Jaribu tena.']);
}
exit();
?>