<?php
session_start();
require_once 'routeros_api.class.php';
include 'login_signup.php'; // Hubeba unganisho la database yako ($conn)

// 1. Pokea na kusafisha kodi ya vocha kutoka kwenye fomu
$kodi_vocha = isset($_POST['kodi_vocha']) ? trim($_POST['kodi_vocha']) : '';
$link_login = $_SESSION['client_link'] ?? '';
$router_id  = $_SESSION['router_id'] ?? null;

// 2. Kagua kama mtumiaji ameingiza kodi ya vocha
if (empty($kodi_vocha)) {
    header("Location: index_backup.php?error=" . urlencode("Tafadhali ingiza namba ya vocha iliyo sahihi."));
    exit();
}

// 3. Kagua kama taarifa za Hotspot (kutoka MikroTik) zipo kwenye session
if (empty($link_login) || empty($router_id)) {
    header("Location: index_backup.php?error=" . urlencode("Hitilafu ya Mtandao: Tafadhali kagua kama umeunganishwa kwenye Wi-Fi yetu."));
    exit();
}

// 4. Vuta vigezo vya siri vya Router ya Reseller (Multi-User Core) kutoka database
$stmt = $conn->prepare("SELECT ip_address, api_username, api_password FROM mikrotik_configs WHERE router_id = ?");
$stmt->bind_param("i", $router_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Kama router haijapatikana kwenye database ya mfumo
if (!$result) {
    header("Location: index_backup.php?error=" . urlencode("Mipangilio ya kiufundi ya router ya eneo hili haijapatikana."));
    exit();
}

$router_ip   = $result['ip_address'];
$router_user = $result['api_username'];
$router_pass = $result['api_password'];

// 5. Fungua Mlango wa API wa MikroTik ya Reseller husika
$api = new RouterosAPI();

if ($api->connect($router_ip, $router_user, $router_pass)) {
    
    // Kagua uwepo wa vocha na kama ipo hai ndani ya Winbox (/ip/hotspot/user)
    $api->write('/ip/hotspot/user/print', false);
    $api->write('?name=' . $kodi_vocha);
    $soma_user = $api->read();

    if (!empty($soma_user)) {
        // Vocha imepatikana na ipo hai! 
        // Tunatengeneza URL ya kumpa mtumiaji internet kiotomatiki (Auto-Login)
        $login_url = $link_login . "?username=" . urlencode($kodi_vocha) . "&password=" . urlencode($kodi_vocha);
        
        // Funga muunganisho wa API
        $api->disconnect();
        
        // Mpeleke mteja akapate internet moja kwa moja
        header("Location: " . $login_url);
        exit();
    } else {
        $api->disconnect();
        // Vocha haipo kabisa kwenye router au imeshafutika/imeisha muda
        header("Location: index_backup.php?error=" . urlencode("Samahani, vocha uliyoingiza si halali au imeshatumika."));
        exit();
    }
} else {
    // API imefeli kuunganisha Router (Labda ipo offline au port 8728 imefungwa)
    header("Location: index_backup.php?error=" . urlencode("Mawasiliano na router yamefeli. Tafadhali wasiliana na mmiliki wa eneo hili."));
    exit();
}
?>