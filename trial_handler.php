<?php
session_start();

// 1. Kagua kama taarifa muhimu za Hotspot zipo kwenye Session
$client_mac   = $_SESSION['client_mac'] ?? '';
$link_login   = $_SESSION['client_link'] ?? '';
$router_id    = $_SESSION['router_id'] ?? null;

// Destination halisi aliyokuwa akijaribu kuifungua mtumiaji kabla
// hajanaswa na hotspot (mfano: alikuwa anafungua TikTok, Instagram,
// WhatsApp, Google, chochote). Hii inatoka $(link-orig-esc) kupitia
// login.html -> index_backup.php -> SESSION.
$client_link_orig = $_SESSION['client_link_orig'] ?? '';

// 2. Kama session imepotea, mrudishe kwenye index na uwasishe Toast ya Kosa
if (empty($client_mac) || empty($link_login) || empty($router_id)) {
    header("Location: index_backup.php?error=" . urlencode("Hitilafu: Mtandao haujatambuliwa vizuri. Tafadhali zima Wi-Fi na uwasishe tena kisha jaribu upya."));
    exit();
}

// 3. Amua destination sahihi ya kumpeleka mtumiaji baada ya trial kuanza.
// - Kama tunajua alikuwa akijaribu kufungua tovuti fulani (link_orig),
//   tumtumie huko moja kwa moja (TikTok, Instagram, chochote alichokuwa
//   akitafuta).
// - Kama hatujui (link_orig haipo/tupu, au ni ombi la "connectivity
//   check" tu la simu), tumtumie ukurasa wa jumla salama badala ya
//   kulazimisha tovuti moja maalum.
$dst_default = "http://www.google.com/";
$dst_target  = (!empty($client_link_orig) && $client_link_orig !== "http://") 
                ? $client_link_orig 
                : $dst_default;

// 4. Tengeneza URL maalum ya MikroTik Hotspot Trial Login
// Hii inamuelekeza mteja kwenda kwenye router yake ya eneo alipo kwa kutumia MAC address kama jina la mtumiaji
// Muda wa trial (dakika 5) unadhibitiwa na 'session-timeout' ya
// profile "trial" KWENYE ROUTER - siyo hapa. Ikifika dakika 5,
// MikroTik yenyewe itakata intaneti moja kwa moja bila kujali
// mtumiaji yuko tovuti gani.
$trial_url = $link_login . "?username=T-" . urlencode($client_mac) . "&dst=" . urlencode($dst_target);

// 5. Mpeleke mteja kwenye router yake kiotomatiki ili apewe Intaneti ya Bure
header("Location: " . $trial_url);
exit();
?>