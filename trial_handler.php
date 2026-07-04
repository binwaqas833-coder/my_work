<?php
session_start();

// 1. Kagua kama taarifa muhimu za Hotspot zipo kwenye Session
$client_mac   = $_SESSION['client_mac'] ?? '';
$link_login   = $_SESSION['client_link'] ?? '';
$router_id    = $_SESSION['router_id'] ?? null;

// 2. Kama session imepotea, mrudishe kwenye index na uwasishe Toast ya Kosa
if (empty($client_mac) || empty($link_login) || empty($router_id)) {
    header("Location: index_backup.php?error=" . urlencode("Hitilafu: Mtandao haujatambuliwa vizuri. Tafadhali zima Wi-Fi na uwasishe tena kisha jaribu upya."));
    exit();
}

// 3. Tengeneza URL maalum ya MikroTik Hotspot Trial Login
// Hii inamuelekeza mteja kwenda kwenye router yake ya eneo alipo kwa kutumia MAC address kama jina la mtumiaji
// parameter ya 'dst' inamuelekeza mteja aende wapi akishafanikiwa kuunganishwa (hapa tumeweka tiktok)
$trial_url = $link_login . "?username=T-" . urlencode($client_mac) . "&dst=" . urlencode("https://www.tiktok.com/");

// 4. Mpeleke mteja kwenye router yake kiotomatiki ili apewe Intaneti ya Bure
header("Location: " . $trial_url);
exit();
?>