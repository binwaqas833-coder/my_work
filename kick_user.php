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
// (disconnectActiveUser() inahitaji .id, sio username)
$active_users = getActiveHotspotUsers($API);
$active_id = null;

if (is_array($active_users)) {
    foreach ($active_users as $u) {
        if (isset($u['user']) && $u['user'] === $username) {
            $active_id = $u['.id'];
            break;
        }
    }
}

if (!$active_id) {
    $API->disconnect();
    echo json_encode(['status' => 'error', 'message' => 'Mtumiaji huyu si active kwa sasa (labda ameshaondoka).']);
    exit();
}

$result = disconnectActiveUser($API, $active_id);
$API->disconnect();

if ($result !== false) {
    echo json_encode(['status' => 'success', 'message' => $username . ' amekatwa mtandao.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kumkata kwenye MikroTik.']);
}
