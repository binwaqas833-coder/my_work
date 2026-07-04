<?php
/**
 * mikrotik_helper.php  (toleo la MYSQLI)
 * ----------------------------------------------------
 * Hii file haibadilishi chochote kwenye mfumo wako wa sasa.
 * Inatumia $conn (mysqli) kutoka login_signup.php moja kwa moja.
 *
 * Matumizi kwenye file mpya pekee, mfano mikrotik_dashboard.php:
 *
 *   require_once 'login_signup.php';       // inapatia $conn
 *   require_once 'routeros_api.class.php'; // class ya MikroTik
 *   require_once 'mikrotik_helper.php';    // hii file
 *
 *   $API = getMikrotikConnection($user_id, $conn);
 * ----------------------------------------------------
 */

/**
 * Pata connection ya MikroTik kwa admin/user fulani
 * kutoka table: mikrotik_configs (kwa kutumia mysqli)
 *
 * @param int    $user_id - id ya admin/reseller
 * @param mysqli $conn    - connection ya mysqli kutoka login_signup.php
 * @return RouterosAPI|null
 */
function getMikrotikConnection($user_id, $conn)
{
    $user_id = (int) $user_id; // usalama: lazimisha iwe namba

    $sql = "SELECT * FROM mikrotik_configs WHERE user_id = $user_id LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if (!$result || mysqli_num_rows($result) === 0) {
        return null; // hakuna config ya mikrotik kwa user huyu
    }

    $config = mysqli_fetch_assoc($result);

    $API = new RouterosAPI();
    $API->debug = false; // weka true ukitaka kuona logs za connection
    $API->port  = $config['api_port'] ?: 8728;

    $connected = $API->connect(
        $config['mikrotik_ip'],
        $config['api_user'],
        $config['api_pass']
    );

    return $connected ? $API : null;
}

/**
 * Pata watumiaji wanaotumia Hotspot SASA HIVI (Active)
 */
function getActiveHotspotUsers($API)
{
    return $API->comm('/ip/hotspot/active/print');
}

/**
 * Pata Hosts zote zilizounganishwa na network (zina IP/MAC)
 */
function getActiveHosts($API)
{
    return $API->comm('/ip/hotspot/host/print');
}

/**
 * Pata taarifa za Router: CPU, RAM, Uptime
 */
function getRouterResources($API)
{
    $res = $API->comm('/system/resource/print');
    return $res[0] ?? [];
}

/**
 * Pata orodha ya Hotspot Users (vouchers walizoweka MikroTik-ni)
 */
function getHotspotUsers($API)
{
    return $API->comm('/ip/hotspot/user/print');
}

/**
 * Ondoa/zima mtumiaji aliye-active (disconnect)
 * Inahitaji ".id" ya MikroTik (sio id ya database yako)
 */
function disconnectActiveUser($API, $activeId)
{
    return $API->comm('/ip/hotspot/active/remove', [
        '.id' => $activeId
    ]);
}

/**
 * Ongeza voucher/user mpya moja kwa moja kwenye MikroTik
 * (Hii inatumika baada ya mtu kununua voucher kwenye dashboard yako)
 */
function addHotspotUserToMikrotik($API, $username, $password, $profile = 'default')
{
    return $API->comm('/ip/hotspot/user/add', [
        'name'     => $username,
        'password' => $password,
        'profile'  => $profile
    ]);
}

/**
 * Futa voucher/user kutoka MikroTik (mfano: imeisha muda)
 */
function removeHotspotUserFromMikrotik($API, $username)
{
    $users = $API->comm('/ip/hotspot/user/print', [
        '?name' => $username
    ]);

    if (!empty($users) && isset($users[0]['.id'])) {
        return $API->comm('/ip/hotspot/user/remove', [
            '.id' => $users[0]['.id']
        ]);
    }
    return false;
}