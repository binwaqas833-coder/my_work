<?php
/**
 * kick_user.php
 * ----------------------------------------------------
 * Inaitwa na kataInternet() kwenye user_dashboard.php (fetch POST).
 * Inatumia mikrotik_helper.php kutafuta mtumiaji active kwa 'username'
 * yake, kupata .id ya MikroTik, kisha kumkata (disconnect).
 * ----------------------------------------------------
 */
session_start();
require_once 'login_signup.php';       // inapatia $conn (mysqli)
require_once 'routeros_api.class.php'; // class ya MikroTik
require_once 'error_logger.php';       // logSystemError()
require_once 'mikrotik_helper.php';    // getMikrotikConnection(), getActiveHotspotUsers(), disconnectActiveUser()

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Umetoka kwenye akaunti, tafadhali ingia tena.']);
    exit();
}

$my_id    = $_SESSION['user_id'];
$username = trim($_POST['username'] ?? '');

if ($username === '') {
    echo json_encode(['status' => 'error', 'message' => 'Jina la mtumiaji halijatumwa.']);
    exit();
}

// Router "active" ya dashboard - kataInternet() inakata mtu aliye kwenye
// router unayoiangalia sasa hivi, siyo router ya kwanza tu.
$router_id = (int)($_SESSION['active_router_id'] ?? 0);
if ($router_id <= 0 || !routerBelongsToUser($router_id, $my_id, $conn)) {
    echo json_encode(['status' => 'error', 'message' => 'Chagua router kwanza.']);
    exit();
}

// Pata connection ya MikroTik ya router hii kupitia helper
$API = getMikrotikConnection($router_id, $my_id, $conn);

if (!$API) {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindwa kuunganisha na MikroTik. Angalia mipangilio yako.']);
    exit();
}

// Tafuta huyu mtumiaji kwenye orodha ya active ili tupate ".id" yake ya MikroTik
// (disconnectActiveUser() inahitaji .id, sio username).
//
// MUHIMU: tunakusanya session ZOTE, siyo ya kwanza tu. Vocha yenye
// shared-users > 1 inaweza kuwa na vifaa kadhaa vilivyo online kwa wakati
// mmoja - awali tulikata kimoja tu na vingine viliendelea na intaneti.
$active_users = getActiveHotspotUsers($API);
$active_ids   = [];

if (is_array($active_users)) {
    foreach ($active_users as $u) {
        if (isset($u['user'], $u['.id']) && $u['user'] === $username) {
            $active_ids[] = $u['.id'];
        }
    }
}

// Cookie LAZIMA ziondolewe hata kama hakuna session ya active kwa sasa:
// hotspot profile zetu zina login-by=cookie (http-cookie-lifetime=3d), hivyo
// cookie iliyobaki humrudisha mtu mtandaoni bila kuandika vocha tena.
$cookies_removed = removeHotspotCookies($API, $username);

if (empty($active_ids)) {
    $API->disconnect();
    if ($cookies_removed > 0) {
        // Hakuwa online sasa hivi, lakini tumemuondolea njia ya kujirudisha.
        echo json_encode(['status' => 'success', 'message' => $username . ' hakuwa online, lakini cookie zake zimefutwa - hataweza kujirudisha mwenyewe.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Mtumiaji huyu si active kwa sasa (labda ameshaondoka).']);
    }
    exit();
}

$zilizokatwa = 0;
foreach ($active_ids as $aid) {
    $res = disconnectActiveUser($API, $aid);
    // comm() hurudisha array; kosa halisi huja kama '!trap'. `!== false`
    // peke yake ilikuwa inahesabu hata majibu ya kosa kama mafanikio.
    if ($res !== false && !isset($res['!trap'])) {
        $zilizokatwa++;
    }
}
$API->disconnect();

if ($zilizokatwa > 0) {
    $ujumbe = $username . ' amekatwa mtandao';
    if (count($active_ids) > 1) {
        $ujumbe .= " (vifaa {$zilizokatwa} kati ya " . count($active_ids) . ")";
    }
    if ($cookies_removed > 0) {
        $ujumbe .= ", cookie zake zimefutwa";
    }
    echo json_encode(['status' => 'success', 'message' => $ujumbe . '.']);
} else {
    logSystemError($conn, 'kick_user.php',
        "Imeshindikana kumkata {$username} kwenye MikroTik.",
        ['user_id' => $my_id, 'router_id' => $router_id]
    );
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kumkata kwenye MikroTik.']);
}
