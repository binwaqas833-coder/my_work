<?php
/**
 * mikrotik_helper.php  (toleo la MULTI-ROUTER)
 * ----------------------------------------------------
 * BADILIKO KUBWA kutoka toleo la awali:
 * getMikrotikConnection() sasa inahitaji $router_id MAALUM, siyo
 * $user_id peke yake - kwa sababu reseller mmoja anaweza kuwa na
 * routers kadhaa sasa (tofauti na awali: router 1 kwa reseller 1).
 *
 * USALAMA: kila wakati tunathibitisha "WHERE router_id=? AND
 * user_id=?" - hii inazuia reseller A kuunganisha na router ya
 * reseller B hata akijaribu kubadilisha router_id kwenye
 * fomu/session kwa mkono.
 *
 * Matumizi:
 *   require_once 'login_signup.php';       // inapatia $conn
 *   require_once 'routeros_api.class.php'; // class ya MikroTik
 *   require_once 'error_logger.php';       // logSystemError()
 *   require_once 'mikrotik_helper.php';    // hii file
 *
 *   $API = getMikrotikConnection($router_id, $user_id, $conn);
 * ----------------------------------------------------
 */

/**
 * Pata connection ya MikroTik kwa router MAALUM ya reseller fulani.
 *
 * @param int    $router_id - router_id kutoka mikrotik_configs (SIYO user_id)
 * @param int    $user_id   - id ya reseller mwenye kudai umiliki wa router hii
 * @param mysqli $conn      - connection ya mysqli kutoka login_signup.php
 * @return RouterosAPI|null - null ikiwa router haipo, si mali ya user huyu, au muunganisho umeshindikana
 */
function getMikrotikConnection($router_id, $user_id, $conn)
{
    $router_id = (int) $router_id;
    $user_id   = (int) $user_id;

    // Prepared statement (siyo string concatenation) - thibitisha umiliki
    // (router_id NA user_id lazima zilingane) kwenye query moja.
    $stmt = $conn->prepare("SELECT * FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ii", $router_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        // Router haipo AU si mali ya user huyu - hatuambii ni ipi kati ya
        // hizo mbili (usalama: usimpe mtu njia ya "kubahatisha" router_id
        // za wengine kwa kuangalia tofauti ya ujumbe wa error).
        if (function_exists('logSystemError')) {
            logSystemError($conn, 'mikrotik_helper.php',
                "Jaribio la kufikia router_id={$router_id} lisilo mali ya user_id={$user_id} (au halipo).",
                ['user_id' => $user_id, 'router_id' => $router_id]
            );
        }
        return null;
    }

    $config = $result->fetch_assoc();
    $stmt->close();

    $API = new RouterosAPI();
    $API->debug = false;
    $API->port  = $config['api_port'] ?: 8728;

    $connected = $API->connect(
        $config['mikrotik_ip'],
        $config['api_user'],
        mt_decrypt($config['api_pass'])
    );

    if (!$connected && function_exists('logSystemError')) {
        logSystemError($conn, 'mikrotik_helper.php',
            "Muunganisho wa MikroTik umeshindikana (IP: {$config['mikrotik_ip']}).",
            ['user_id' => $user_id, 'router_id' => $router_id]
        );
    }

    return $connected ? $API : null;
}

/**
 * Thibitisha router fulani ni mali ya user fulani - bila kufungua
 * muunganisho wa MikroTik. Tumia hii KABLA ya kufanya query yoyote
 * ya tariffs/vouchers inayotumia router_id kutoka session/POST, ili
 * kuzuia mtu ku-tweak router_id na kuona/kubadilisha data ya router
 * ya mwingine.
 */
function routerBelongsToUser($router_id, $user_id, $conn): bool
{
    $router_id = (int) $router_id;
    $user_id   = (int) $user_id;
    if ($router_id <= 0 || $user_id <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $router_id, $user_id);
    $stmt->execute();
    $owns = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $owns;
}

/**
 * Hesabu routers ngapi reseller huyu anazo sasa (kwa ajili ya
 * kuzilinganisha na max_routers ya subscription plan yake kabla
 * ya kumruhusu "Ongeza Router Mpya").
 */
function countUserRouters($user_id, $conn): int
{
    $user_id = (int) $user_id;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM mikrotik_configs WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $c;
}

/**
 * Washa/zima "Jaribu Dakika 5 Bure" KWENYE ROUTER yenyewe.
 *
 * MUHIMU: kuficha kitufe kwenye ukurasa HAKUTOSHI. MikroTik hutoa trial
 * yenyewe kwa mtu yeyote anayefungua <link-login>?username=T-<MAC>, hivyo
 * mteja anayejua mbinu angeweza kuipata hata kitufe kikiwa kimefichwa.
 * Ulinzi wa kweli ni kuondoa "trial" kwenye `login-by` ya hotspot profile.
 *
 * Inarudisha true ikiwa router imebadilishwa; false ikiwa haikufikiwa
 * (mabadiliko ya database HAYATENGUKI - tunahifadhi nia ya merchant, na
 * ukurasa bado utaficha kitufe).
 */
function setRouterTrial($API, bool $enabled): bool
{
    if (!$API) {
        return false;
    }
    $profiles = $API->comm('/ip/hotspot/profile/print');
    if (empty($profiles) || !is_array($profiles)) {
        return false;
    }

    $ok = false;
    foreach ($profiles as $p) {
        if (!isset($p['.id'], $p['login-by'])) {
            continue;
        }
        $njia = array_filter(array_map('trim', explode(',', (string)$p['login-by'])));
        $ina  = in_array('trial', $njia, true);

        if ($enabled && !$ina) {
            $njia[] = 'trial';
        } elseif (!$enabled && $ina) {
            $njia = array_diff($njia, ['trial']);
        } else {
            $ok = true;   // tayari iko kama inavyotakiwa
            continue;
        }

        // MikroTik inakataa login-by tupu - hakikisha angalau http-chap ipo
        if (empty($njia)) {
            $njia = ['http-chap'];
        }

        $res = $API->comm('/ip/hotspot/profile/set', [
            '.id'      => $p['.id'],
            'login-by' => implode(',', $njia),
        ]);
        $ok = ($res !== false);
    }
    return $ok;
}

/**
 * Je, router hii inaruhusu trial ya dakika 5? (kutoka database)
 * Chaguo-msingi ni TRUE ikiwa column haipo bado (migration haijaendeshwa).
 */
function routerTrialEnabled($router_id, $conn): bool
{
    $router_id = (int) $router_id;
    $stmt = $conn->prepare("SELECT trial_enabled FROM mikrotik_configs WHERE router_id = ? LIMIT 1");
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param("i", $router_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? true : ((int)$row['trial_enabled'] === 1);
}

/**
 * Pata orodha ya routers zote za reseller huyu (kwa ukurasa wa
 * "My Mikrotiks") - bila API password (haihitajiki kwenye orodha).
 */
function getUserRouters($user_id, $conn): array
{
    $user_id = (int) $user_id;
    $stmt = $conn->prepare(
        "SELECT router_id, router_label, mikrotik_ip, api_user, api_port, trial_enabled, created_at
         FROM mikrotik_configs WHERE user_id = ? ORDER BY router_id ASC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
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
 *
 * @param array $extra - params za ziada, mfano: ['limit-uptime' => '7d']
 */
function addHotspotUserToMikrotik($API, $username, $password, $profile = 'default', $extra = [])
{
    $params = array_merge([
        'name'     => $username,
        'password' => $password,
        'profile'  => $profile
    ], $extra);

    return $API->comm('/ip/hotspot/user/add', $params);
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

/**
 * Login mteja moja kwa moja kwenye MikroTik Hotspot
 */
function loginHotspotUser($API, $username, $password, $mac, $ip)
{
    return $API->comm('/ip/hotspot/active/login', [
        'user'        => $username,
        'password'    => $password,
        'mac-address' => $mac,
        'ip'          => $ip
    ]);
}

/**
 * Sasisha profile na muda (limit-uptime) wa mtumiaji ALIYESHAPO kwenye
 * MikroTik (kwa ajili ya "Renew").
 *
 * @return array|false - matokeo ya MikroTik, au false kama mtumiaji hayupo kabisa
 */
function renewHotspotUser($API, $username, $profile, $limitUptime)
{
    $users = $API->comm('/ip/hotspot/user/print', [
        '?name' => $username
    ]);

    if (empty($users) || !isset($users[0]['.id'])) {
        return false;
    }

    return $API->comm('/ip/hotspot/user/set', [
        '.id'          => $users[0]['.id'],
        'profile'      => $profile,
        'limit-uptime' => $limitUptime
    ]);
}