<?php
/**
 * my_mikrotiks.php
 * -------------------------------------------------------------
 * Reseller anaona routers zake zote, anaongeza mpya (self-service,
 * live-test kama save_mikrotik.php ya zamani), na anaswitch kati
 * yao (session['active_router_id']) - dashboard nzima inafuata
 * router aliyochagua.
 *
 * Kikomo cha idadi ya routers kinatokana na subscription yake ya
 * sasa (getCurrentMaxRouters() hapa chini).
 * -------------------------------------------------------------
 */
session_start();
include 'auth_check.php';
include 'login_signup.php';          // $conn, mt_encrypt(), mt_decrypt()
require_once 'routeros_api.class.php';
require_once 'error_logger.php';
require_once 'mikrotik_helper.php';  // toleo JIPYA (multi-router)
require_once 'subscription_helper.php';

$user_id = (int) $_SESSION['user_id'];
$toast   = null;

// ── SUBSCRIPTION GATE — HAIMHUSU ADMIN. Admin ana routers zake mwenyewe
// (anauza vocha kama reseller), lakini halazimiki kulipia subscription
// wala kufungwa na max_routers ya plans. ──
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

if ($is_admin) {
    $max_routers = PHP_INT_MAX; // hakuna kikomo cha idadi ya routers kwa Admin
} else {
    $subscription_info = getSubscriptionStatus($conn, $user_id);
    if ($subscription_info['status'] === 'expired') {
        header("Location: subscribe.php");
        exit();
    }
    $max_routers = $subscription_info['max_routers'];
}
$current_routers = getUserRouters($user_id, $conn);
$router_count    = count($current_routers);

// ── MUHIMU: kama hana active_router_id kwenye session bado, LAKINI ana
// router(s), ITUE moja kiotomatiki NA IHIFADHI KWENYE SESSION papo hapo
// (siyo UI display tu) - la sivyo "Rudi Dashboard" inamrudisha huku huku
// milele kwa sababu user_dashboard.php inaangalia session, siyo UI. ──
if (empty($_SESSION['active_router_id']) && $router_count > 0) {
    // Jaribu last_active_router_id yake ya awali kwanza (kama bado ni yake)
    $default_router_id = null;
    $u_stmt = $conn->prepare("SELECT last_active_router_id FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $last_rid = $u_stmt->get_result()->fetch_assoc()['last_active_router_id'] ?? null;
    $u_stmt->close();

    foreach ($current_routers as $r) {
        if ((int)$r['router_id'] === (int)$last_rid) {
            $default_router_id = (int)$r['router_id'];
            break;
        }
    }
    if (!$default_router_id) {
        $default_router_id = (int)$current_routers[0]['router_id']; // fallback: ya kwanza
    }

    $_SESSION['active_router_id'] = $default_router_id;
    $upd = $conn->prepare("UPDATE users SET last_active_router_id = ? WHERE id = ?");
    $upd->bind_param("ii", $default_router_id, $user_id);
    $upd->execute();
    $upd->close();
}

// ── (1) KUONGEZA ROUTER MPYA ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_router') {

    $router_label = trim($_POST['router_label'] ?? '');
    $mikrotik_ip  = trim($_POST['mikrotik_ip'] ?? '');
    $api_user     = trim($_POST['api_user'] ?? '');
    $api_pass     = $_POST['api_pass'] ?? '';
    // Uwanja wa port ni hiari. (int)'' ni 0, na awali sifuri hiyo ilihifadhiwa
    // DB-ni kama api_port=0; ilifanya kazi kwa bahati tu kwa sababu
    // getMikrotikConnection() ina `?: 8728`. Tunairekebisha hapa mara moja.
    $api_port     = (int) ($_POST['api_port'] ?? 0);
    if ($api_port <= 0 || $api_port > 65535) {
        $api_port = 8728;
    }

    // Je, tayari ana router yenye IP+port hii? Bila ukaguzi huu kifaa kimoja
    // kingeweza kuwa na rows mbili, na takwimu za "kila router" zingegawanyika
    // kati yao - merchant asijue router ipi ina wateja gani.
    $dup_stmt = $conn->prepare(
        "SELECT router_label FROM mikrotik_configs WHERE user_id=? AND mikrotik_ip=? AND api_port=? LIMIT 1"
    );
    $dup_stmt->bind_param("isi", $user_id, $mikrotik_ip, $api_port);
    $dup_stmt->execute();
    $dup_row = $dup_stmt->get_result()->fetch_assoc();
    $dup_stmt->close();

    if ($router_label === '' || $mikrotik_ip === '' || $api_user === '' || $api_pass === '') {
        $toast = ['type' => 'error', 'msg' => 'Jaza taarifa zote: Jina la Router, IP, API User, na API Password. ⚠️'];
    } elseif ($router_count >= $max_routers) {
        $toast = ['type' => 'error', 'msg' => "Umefikia kikomo cha routers ({$max_routers}) kwa mpango wako wa sasa. Fanya upgrade ya plan ili kuongeza zaidi. 🚫"];
    } elseif ($dup_row) {
        $toast = ['type' => 'error', 'msg' => 'Router yenye IP hii tayari ipo kwenye orodha yako ("' . htmlspecialchars($dup_row['router_label']) . '"). 🚫'];
    } else {
        $API = new RouterosAPI();
        $API->debug   = false;
        $API->port    = $api_port ?: 8728;
        $API->timeout = 6;

        if ($API->connect($mikrotik_ip, $api_user, $api_pass)) {
            $API->disconnect();

            $api_pass_enc = mt_encrypt($api_pass);
            $stmt = $conn->prepare(
                "INSERT INTO mikrotik_configs (user_id, router_label, mikrotik_ip, api_user, api_pass, api_port)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("issssi", $user_id, $router_label, $mikrotik_ip, $api_user, $api_pass_enc, $api_port);

            if ($stmt->execute()) {
                $new_router_id = $stmt->insert_id;
                $stmt->close();

                // Router mpya inakuwa "active" moja kwa moja
                $_SESSION['active_router_id'] = $new_router_id;
                $upd = $conn->prepare("UPDATE users SET last_active_router_id = ? WHERE id = ?");
                $upd->bind_param("ii", $new_router_id, $user_id);
                $upd->execute();
                $upd->close();

                // Router mpya haina tariffs bado - mpeleke moja kwa moja aweke bei zake
                header("Location: setup_tariffs.php");
                exit();
            } else {
                logSystemError($conn, 'my_mikrotiks.php', 'Imeshindikana ku-INSERT router mpya: ' . $conn->error, ['user_id' => $user_id]);
                $toast = ['type' => 'error', 'msg' => 'Hitilafu ya database. Jaribu tena.'];
            }
        } else {
            logSystemError($conn, 'my_mikrotiks.php', "Live-test imeshindikana kwa IP {$mikrotik_ip}", ['user_id' => $user_id]);
            $toast = ['type' => 'error', 'msg' => 'Mawasiliano na MikroTik yamefeli! Kagua IP, API User, na Password. ❌'];
        }
    }
}

// ── (2) KUSWITCH ROUTER (kubadilisha "active router" ya sasa) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'switch_router') {
    $target_id = (int) ($_POST['router_id'] ?? 0);

    $stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $target_id, $user_id);
    $stmt->execute();
    $owns = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($owns) {
        $_SESSION['active_router_id'] = $target_id;
        $upd = $conn->prepare("UPDATE users SET last_active_router_id = ? WHERE id = ?");
        $upd->bind_param("ii", $target_id, $user_id);
        $upd->execute();
        $upd->close();

        header("Location: user_dashboard.php");
        exit();
    } else {
        logSystemError($conn, 'my_mikrotiks.php', "Jaribio la kuswitch kwenda router_id={$target_id} isiyo mali yake.", ['user_id' => $user_id]);
        $toast = ['type' => 'error', 'msg' => 'Router hiyo si mali yako.'];
    }
}

// ── (3) WASHA/ZIMA "JARIBU DAKIKA 5 BURE" KWA ROUTER FULANI ──
// Tunabadilisha pande MBILI:
//   (a) database  - ndiyo inayoamua kama kitufe kinaonekana kwenye portal
//   (b) router     - tunaondoa/kurudisha "trial" kwenye login-by, kwa sababu
//       MikroTik hutoa trial kwa mtu yeyote anayefungua
//       <link-login>?username=T-<MAC>, hata kitufe kikiwa kimefichwa.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_trial') {
    $target_id = (int) ($_POST['router_id'] ?? 0);
    $washa     = (($_POST['trial_enabled'] ?? '') === '1');

    if (!routerBelongsToUser($target_id, $user_id, $conn)) {
        logSystemError($conn, 'my_mikrotiks.php', "Jaribio la kubadilisha trial ya router_id={$target_id} isiyo mali yake.", ['user_id' => $user_id]);
        $toast = ['type' => 'error', 'msg' => 'Router hiyo si mali yako.'];
    } else {
        $upd = $conn->prepare("UPDATE mikrotik_configs SET trial_enabled = ? WHERE router_id = ? AND user_id = ?");
        $t_val = $washa ? 1 : 0;
        $upd->bind_param("iii", $t_val, $target_id, $user_id);
        $upd->execute();
        $upd->close();

        // Jaribu kubadilisha router yenyewe (best-effort)
        $API = getMikrotikConnection($target_id, $user_id, $conn);
        $router_ok = false;
        if ($API) {
            $router_ok = setRouterTrial($API, $washa);
            $API->disconnect();
        }

        if ($router_ok) {
            $toast = ['type' => 'success', 'msg' => $washa
                ? 'Jaribio la dakika 5 limewashwa kwa router hii. ✅'
                : 'Jaribio la dakika 5 limezimwa kwa router hii. ✅'];
        } else {
            $toast = ['type' => 'error', 'msg' => ($washa ? 'Imewashwa' : 'Imezimwa')
                . ' kwenye mfumo, LAKINI router haikufikiwa - kitufe kimefichwa, '
                . 'ila router bado inaruhusu trial mpaka iunganishwe. Kagua muunganisho kisha jaribu tena. ⚠️'];
            logSystemError($conn, 'my_mikrotiks.php', 'setRouterTrial imeshindikana (router haikufikiwa).',
                ['user_id' => $user_id, 'router_id' => $target_id]);
        }
        // Onyesha upya orodha ikiwa na thamani mpya
        $current_routers = getUserRouters($user_id, $conn);
    }
}

// ── (4) JIOMBEE TUNNEL YA VPN (self-service) ──
// Awali hatua hii ilihitaji admin mwenye SSH ya root aendeshe
// /root/add-tech5g-router.sh. Sasa reseller anajiombea mwenyewe.
//
// USALAMA: PHP HAIANDIKI wg1.conf. Inaita script moja iliyoruhusiwa kupitia
// sudo (/usr/local/sbin/tech5g-provision-peer) inayopokea user_id TU. Script
// yenyewe ndiyo inayothibitisha kwenye database, kuchagua anwani, na kutunga
// config - hivyo hata PHP ikidukuliwa haiwezi kuandika chochote inachotaka.
$vpn_config = null;   // huonyeshwa MARA MOJA tu baada ya kuomba
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'omba_tunnel') {

    $out  = [];
    $code = 0;
    // escapeshellarg + (int) - hoja pekee inayopita ni namba
    // Mode pekee inayoruhusiwa kutoka kwa mtumiaji ni '--regenerate'.
    // Hatupitishi maandishi ya mtumiaji moja kwa moja - tunachagua kati ya
    // thamani MBILI zinazojulikana, hivyo hakuna njia ya kudunga chochote.
    $mode = (($_POST['mode'] ?? '') === 'regenerate') ? '--regenerate' : 'new';
    $cmd  = 'sudo -n /usr/local/sbin/tech5g-provision-peer ' . escapeshellarg((string)$user_id) . ' ' . escapeshellarg($mode);
    if ($mode === '--regenerate') {
        // peer_id ni namba tu; script inathibitisha kuwa ni YAKE
        $cmd .= ' ' . escapeshellarg((string)(int)($_POST['peer_id'] ?? 0));
    }
    exec($cmd . ' 2>&1', $out, $code);
    $raw  = trim(implode("\n", $out));
    $json = json_decode($raw, true);

    if (!is_array($json)) {
        logSystemError($conn, 'my_mikrotiks.php', 'provision-peer haikurudisha JSON: ' . mb_substr($raw, 0, 300), ['user_id' => $user_id]);
        $toast = ['type' => 'error', 'msg' => 'Imeshindikana kuandaa tunnel. Wasiliana na msimamizi.'];
    } elseif (!empty($json['ok'])) {
        $vpn_config = $json;
        $toast = ['type' => 'success', 'msg' => 'Tunnel yako iko tayari: ' . htmlspecialchars($json['tunnel_ip']) . ' ✅'];
    } else {
        $toast = ['type' => 'error', 'msg' => $json['error'] ?? 'Imeshindikana kuandaa tunnel.'];
    }
}

// Tunnel ZOTE alizonazo. Kila router ya kimwili inahitaji tunnel yake -
// haziwezi kushirikiana funguo wala IP. Kwa hiyo reseller mwenye plan ya
// routers 3 anahitaji tunnel 3.
$tunnels_zangu = [];
$tq = $conn->prepare("SELECT id, tunnel_ip, created_at FROM wg_peers WHERE user_id=? AND revoked_at IS NULL ORDER BY id");
if ($tq) {
    $tq->bind_param("i", $user_id);
    $tq->execute();
    $tunnels_zangu = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
    $tq->close();
}
$tunnel_count = count($tunnels_zangu);
// Kikomo ni kile kile cha routers (script inakithibitisha upya upande wa root)
$tunnel_ruhusa = $is_admin ? PHP_INT_MAX : (int)$max_routers;

$active_router_id = $_SESSION['active_router_id'] ?? ($current_routers[0]['router_id'] ?? null);

// ── TAKWIMU ZA KILA ROUTER ──
// Kila router ina wateja/vocha zake, lakini awali orodha hii ilionyesha jina
// na IP pekee - ili kujua router ipi ina wateja gani ilibidi uingie ndani ya
// kila moja, mmoja baada ya mwingine. Query MOJA yenye GROUP BY inatosha
// (siyo query kwa kila router).
$router_stats = [];
$rs_stmt = $conn->prepare(
    "SELECT router_id,
            COUNT(*)                                          AS jumla,
            SUM(status = 'used')                              AS zilizotumika,
            SUM(status = 'unused')                            AS hazijatumika,
            SUM(CASE WHEN type = 'paid' AND DATE(created_at) = CURDATE()
                     THEN price ELSE 0 END)                   AS mapato_leo
       FROM vouchers
      WHERE user_id = ?
   GROUP BY router_id"
);
$rs_stmt->bind_param("i", $user_id);
$rs_stmt->execute();
foreach ($rs_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $srow) {
    $router_stats[(int)$srow['router_id']] = $srow;
}
$rs_stmt->close();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Mikrotiks · Tech 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
<link rel="preload" as="image" href="beach5.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<style>
    :root{
        --surface: rgba(255,255,255,0.18);
        --surface2: rgba(255,255,255,0.10);
        --border: rgba(255,255,255,0.35);
        --border2: rgba(255,255,255,0.20);
        --accent: #07f793;
        --accent2: #3fc7fd;
        --accent3: #ff6b35;
        --text: #fff;
        --text-dim: rgba(255,255,255,0.65);
        --text-muted: rgba(255,255,255,0.40);
        --red: #ff3d57;
        --radius: 14px;
        --blur: blur(18px);
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{
        font-family:'DM Sans',sans-serif;
        background-color:#0d1b17;
        background-image:linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg);
        background-size:cover;
        background-position:center;
        background-attachment:fixed;
        color:var(--text);
        min-height:100vh;
        padding:26px 16px;
        display:flex;
        flex-direction:column;
    }
    body::before{
        content:'';
        position:fixed;
        inset:0;
        background:rgba(0,0,0,0.30);
        pointer-events:none;
        z-index:0;
    }
    .wrap{
        max-width:800px;
        margin:0 auto;
        width:100%;
        position:relative;
        z-index:1;
        flex:1;
    }
    .header-card{
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2);
        border-radius:var(--radius);
        padding:20px 24px;
        margin-bottom:20px;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:16px;
        flex-wrap:wrap;
    }
    .brand-logo{
        display:flex;
        align-items:center;
        gap:14px;
    }
    .brand-icon{
        width:42px;
        height:42px;
        background:linear-gradient(135deg,var(--accent),#00a86b);
        border-radius:10px;
        display:grid;
        place-items:center;
        font-size:18px;
        color:#000;
        box-shadow:0 0 20px rgba(7,247,147,0.35);
    }
    .brand-name{
        font-family:'Syne',sans-serif;
        font-weight:800;
        font-size:19px;
        color:#fff;
        line-height:1.2;
    }
    .brand-sub{
        font-size:11px;
        color:var(--text-dim);
        letter-spacing:1px;
    }
    .top-actions{
        display:flex;
        align-items:center;
        gap:10px;
    }
    .back-btn{
        background:rgba(255,255,255,0.10);
        border:1px solid var(--border2);
        color:#fff;
        text-decoration:none;
        font-size:12.5px;
        font-weight:600;
        padding:9px 16px;
        border-radius:8px;
        display:inline-flex;
        align-items:center;
        gap:6px;
        transition:all 0.2s;
    }
    .back-btn:hover{
        background:rgba(255,255,255,0.18);
        border-color:var(--border);
    }
    .logout-btn-header{
        background:rgba(255,61,87,0.15);
        border:1px solid rgba(255,61,87,0.35);
        color:var(--red);
        text-decoration:none;
        font-size:12.5px;
        font-weight:700;
        padding:9px 16px;
        border-radius:8px;
        display:inline-flex;
        align-items:center;
        gap:6px;
        cursor:pointer;
        transition:all 0.2s;
    }
    .logout-btn-header:hover{
        background:rgba(255,61,87,0.25);
    }
    
    p.sub{
        color:var(--text-dim);
        font-size:13.5px;
        margin:0 0 20px 4px;
    }
    .limit-note{
        padding:14px 18px;
        border-radius:12px;
        font-size:13px;
        margin-bottom:20px;
        background:rgba(7,247,147,0.10);
        border:1px solid rgba(7,247,147,0.25);
        color:var(--text);
        backdrop-filter:var(--blur);
    }
    .limit-note.full{
        background:rgba(255,92,92,0.12);
        border-color:rgba(255,92,92,0.35);
        color:#ff8a8a;
    }
    .card{
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2);
        border-radius:var(--radius);
        padding:18px 22px;
        margin-bottom:14px;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:14px;
        flex-wrap:wrap;
        transition:all 0.2s;
    }
    .card.active{
        border-color:var(--accent);
        box-shadow:0 0 20px rgba(7,247,147,0.15);
    }
    .r-name{
        font-weight:700;
        font-size:16px;
        font-family:'Syne',sans-serif;
        display:flex;
        align-items:center;
    }
    .r-name .tag{
        font-size:9.5px;
        background:var(--accent);
        color:#04231a;
        padding:3px 9px;
        border-radius:20px;
        margin-left:10px;
        font-weight:800;
        letter-spacing:0.5px;
    }
    .r-ip{
        font-size:12px;
        color:var(--text-dim);
        font-family:'Space Mono',monospace;
        margin-top:4px;
    }
    .r-stats{
        display:flex;
        flex-wrap:wrap;
        gap:14px;
        margin-top:10px;
        font-size:12px;
        color:var(--text-dim);
    }
    .r-stats i{ margin-right:5px; color:var(--accent2); }
    .r-stats b{ color:var(--text); font-weight:600; }
    .trial-toggle{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-top:12px;
        padding-top:10px;
        border-top:1px dashed rgba(255,255,255,0.14);
        font-size:12px;
        color:var(--text-dim);
    }
    .trial-label i{ color:var(--accent3); margin-right:4px; }
    .trial-label b.on{ color:var(--accent); }
    .trial-label b.off{ color:var(--text-muted); }
    .trial-btn{
        padding:6px 14px;
        border-radius:7px;
        font-size:12px;
        font-weight:700;
        cursor:pointer;
        border:1px solid transparent;
        background:rgba(255,255,255,0.10);
        color:var(--text);
        transition:filter .2s;
    }
    .vpn-box{
        background:var(--surface);
        backdrop-filter:var(--blur); -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2); border-radius:var(--radius);
        padding:18px 20px; margin-bottom:18px; font-size:13.5px; color:var(--text-dim);
    }
    .vpn-box.new{ border-color:var(--accent); box-shadow:0 0 22px rgba(7,247,147,0.14); }
    .vpn-box h3{ font-family:'Syne',sans-serif; font-size:16px; color:#fff; margin-bottom:8px; }
    .vpn-box p{ margin:8px 0; line-height:1.55; }
    .vpn-step{ color:var(--text); font-weight:600; }
    .vpn-warn{
        background:rgba(255,61,87,0.12); border:1px solid rgba(255,61,87,0.32);
        color:#ffd7dd; border-radius:9px; padding:10px 12px;
    }
    .vpn-box pre{
        background:rgba(0,0,0,0.42); border:1px solid var(--border2); border-radius:10px;
        padding:14px; overflow-x:auto; font-family:'Space Mono',monospace;
        font-size:11.5px; line-height:1.6; color:#d7ffe9; white-space:pre; margin:10px 0;
    }
    .tunnel-row{
        display:flex; align-items:center; justify-content:space-between;
        gap:12px; flex-wrap:wrap;
        padding:9px 0; border-bottom:1px dashed rgba(255,255,255,0.10);
    }
    .tunnel-row:last-of-type{ border-bottom:none; }
    .trial-btn.on{ background:var(--accent); color:#04231a; }
    .trial-btn.off{ background:rgba(255,61,87,0.16); border-color:rgba(255,61,87,0.35); color:var(--red); }
    .trial-btn:hover{ filter:brightness(1.12); }
    .btn{
        padding:10px 18px;
        border-radius:8px;
        border:none;
        font-weight:700;
        font-size:13px;
        cursor:pointer;
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        gap:6px;
        transition:all 0.2s;
    }
    .btn-primary{
        background:var(--accent);
        color:#04231a;
    }
    .btn-primary:hover{
        filter:brightness(1.1);
        transform:translateY(-1px);
    }
    .btn-outline{
        background:rgba(255,255,255,0.06);
        border:1px solid var(--border2);
        color:var(--text-dim);
    }
    .add-card{
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px dashed var(--border);
        border-radius:var(--radius);
        padding:22px;
        margin-top:24px;
    }
    .add-card h3{
        margin:0 0 16px;
        font-size:16px;
        font-family:'Syne',sans-serif;
        color:#fff;
        display:flex;
        align-items:center;
        gap:8px;
    }
    .add-card label{
        display:block;
        font-size:11.5px;
        color:var(--text-dim);
        margin-bottom:6px;
        letter-spacing:0.5px;
        text-transform:uppercase;
    }
    .add-card input{
        width:100%;
        padding:11px 14px;
        margin-bottom:14px;
        border-radius:9px;
        border:1px solid rgba(255,255,255,0.20);
        background:rgba(0,0,0,0.25);
        color:var(--text);
        font-size:13.5px;
        outline:none;
        transition:border-color 0.2s;
    }
    .add-card input:focus{
        border-color:var(--accent);
    }
    .toast-box{
        padding:14px 18px;
        border-radius:12px;
        font-size:13px;
        margin-bottom:20px;
        backdrop-filter:var(--blur);
    }
    .toast-box.success{
        background:rgba(7,247,147,0.15);
        border:1px solid rgba(7,247,147,0.3);
        color:var(--accent);
    }
    .toast-box.error{
        background:rgba(255,92,92,0.15);
        border:1px solid rgba(255,92,92,0.3);
        color:var(--red);
    }

    /* Modal Styling for Logout */
    .modal-overlay{
        position:fixed;
        inset:0;
        background:rgba(0,0,0,0.7);
        backdrop-filter:blur(8px);
        -webkit-backdrop-filter:blur(8px);
        display:none;
        justify-content:center;
        align-items:center;
        z-index:1500;
    }
    .modal-overlay.active{ display:flex; }
    .modal-content{
        background:rgba(15,30,50,0.92);
        backdrop-filter:blur(20px);
        border:1px solid rgba(255,255,255,0.18);
        box-shadow:0 8px 40px rgba(0,0,0,0.5);
        padding:26px;
        border-radius:16px;
        width:90%;
        max-width:400px;
        color:#fff;
        animation:modalIn 0.3s ease;
    }
    @keyframes modalIn{ from{transform:translateY(20px);opacity:0;} to{transform:translateY(0);opacity:1;} }

    .footer{
        text-align:center;
        padding:22px 10px 10px;
        font-size:11px;
        color:rgba(255,255,255,0.40);
        font-family:'Space Mono',monospace;
        position:relative;
        z-index:1;
        margin-top:auto;
    }
</style>
<link rel="stylesheet" href="responsive.css">
</head>
<body>

<div class="wrap">

    <div class="header-card">
        <div class="brand-logo">
            <div class="brand-icon"><i class="fa-solid fa-wifi"></i></div>
            <div>
                <div class="brand-name">Tech 5G Wi-Fi</div>
                <div class="brand-sub"><i class="fa-solid fa-server"></i> Usimamizi wa Routers</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="back-btn" href="user_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Rudi Dashboard</a>
            <button class="logout-btn-header" onclick="thibitishaLogout()"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
        </div>
    </div>

    <p class="sub">Routers zako zote - bonyeza "Ingia" kwenye router unayotaka kudhibiti sasa.</p>

    <?php if ($toast): ?>
        <div class="toast-box <?php echo $toast['type']; ?>"><?php echo htmlspecialchars($toast['msg']); ?></div>
    <?php endif; ?>

    <!-- ── HATUA YA 1: TUNNEL YA VPN ── -->
    <?php if ($vpn_config): ?>
        <div class="vpn-box new">
            <h3><i class="fa-solid fa-shield-halved"></i> Tunnel yako iko tayari — <b><?php echo htmlspecialchars($vpn_config['tunnel_ip']); ?></b></h3>
            <p class="vpn-warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <b>Nakili SASA.</b> Funguo ya siri (private key) inaonyeshwa <u>mara moja tu</u> —
                haihifadhiwi popote. Ukiondoka kwenye ukurasa huu itapotea.
            </p>
            <p class="vpn-step">1. Bandika hizi kwenye <b>MikroTik Terminal</b>:</p>
<pre id="vpnCmd">/interface wireguard
add name=wg-tech5g listen-port=<?php echo htmlspecialchars($vpn_config['port']); ?> private-key="<?php echo htmlspecialchars($vpn_config['private_key']); ?>"

/ip address
add address=<?php echo htmlspecialchars($vpn_config['tunnel_ip']); ?>/24 interface=wg-tech5g

/interface wireguard peers
add interface=wg-tech5g public-key="<?php echo htmlspecialchars($vpn_config['server_pubkey']); ?>" \
    endpoint-address=<?php echo htmlspecialchars($vpn_config['endpoint']); ?> endpoint-port=<?php echo htmlspecialchars($vpn_config['port']); ?> \
    allowed-address=10.60.0.0/24 persistent-keepalive=25s

/ip firewall filter
add chain=input in-interface=wg-tech5g action=accept comment="Tech5G VPN trusted" place-before=0

/ip service enable api
/user group add name=api-only policy=api,read,write,test,ftp,!local,!telnet,!ssh,!reboot,!password,!policy,!winbox,!web,!sniff,!sensitive,!romon
/user add name=tech5g_api password="WEKA-PASSWORD-YAKO-IMARA" group=api-only</pre>
            <button type="button" class="btn btn-primary" onclick="nakiliVpn()"><i class="fa-solid fa-copy"></i> Nakili</button>
            <p class="vpn-step">2. Kisha ongeza router hapa chini ukitumia
               IP <b><?php echo htmlspecialchars($vpn_config['tunnel_ip']); ?></b>, user <b>tech5g_api</b>,
               na password uliyoiweka.</p>
        </div>
    <?php elseif ($tunnel_count > 0): ?>
        <div class="vpn-box">
            <h3><i class="fa-solid fa-shield-halved"></i> Tunnel zako
                <span style="font-weight:400;font-size:13px;color:var(--text-dim);">
                    (<?php echo $tunnel_count; ?><?php echo $is_admin ? '' : ' kati ya ' . (int)$tunnel_ruhusa; ?>)
                </span>
            </h3>
            <?php foreach ($tunnels_zangu as $i => $t): ?>
                <div class="tunnel-row">
                    <span>
                        <b style="font-family:'Space Mono',monospace;color:var(--accent);"><?php echo htmlspecialchars($t['tunnel_ip']); ?></b>
                        <span style="color:var(--text-muted);font-size:12px;">
                            · tangu <?php echo date('d M Y', strtotime($t['created_at'])); ?>
                        </span>
                    </span>
                    <form method="POST" style="margin:0;"
                          onsubmit="return confirm('Router iliyo kwenye <?php echo htmlspecialchars($t['tunnel_ip']); ?> itakatika mpaka ubandike funguo mpya. Endelea?');">
                        <input type="hidden" name="action" value="omba_tunnel">
                        <input type="hidden" name="mode" value="regenerate">
                        <input type="hidden" name="peer_id" value="<?php echo (int)$t['id']; ?>">
                        <button type="submit" class="trial-btn" title="Umepoteza funguo ya siri? Pata mpya (IP haibadiliki)">
                            <i class="fa-solid fa-rotate"></i> Funguo mpya
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if ($tunnel_count < $tunnel_ruhusa): ?>
                <p style="margin-top:12px;">Unaongeza router nyingine? Kila router inahitaji tunnel yake.</p>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="omba_tunnel">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Niandalie tunnel nyingine</button>
                </form>
            <?php else: ?>
                <p style="margin-top:12px;color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info"></i>
                    Umefikia kikomo cha mpango wako (<?php echo (int)$tunnel_ruhusa; ?>).
                    <a href="subscribe.php" style="color:var(--accent2);">Fanya upgrade</a> ili kuongeza zaidi.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="vpn-box">
            <h3><i class="fa-solid fa-shield-halved"></i> Hatua ya 1 — Omba Tunnel ya VPN</h3>
            <p>Router yako haina IP ya umma, hivyo inaunganishwa na mfumo kupitia tunnel salama.
               Bonyeza hapa chini upate anwani yako na maagizo ya MikroTik.</p>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="omba_tunnel">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-bolt"></i> Niandalie Tunnel</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="limit-note <?php echo $router_count >= $max_routers ? 'full' : ''; ?>">
        <i class="fa-solid fa-circle-info"></i> Una <b><?php echo $router_count; ?></b> kati ya <b><?php echo $max_routers; ?></b> routers zinazoruhusiwa na mpango wako wa sasa.
        <?php if ($router_count >= $max_routers): ?>
            Fanya upgrade ya plan ili kuongeza zaidi.
        <?php endif; ?>
    </div>

    <?php if (empty($current_routers)): ?>
        <p style="color:var(--text-dim); text-align:center; padding:30px;">Bado hujaongeza router yoyote.</p>
    <?php else: foreach ($current_routers as $r):
        $is_active = ((int)$r['router_id'] === (int)$active_router_id);
        $st        = $router_stats[(int)$r['router_id']] ?? null;
    ?>
        <div class="card <?php echo $is_active ? 'active' : ''; ?>">
            <div>
                <div class="r-name">
                    <i class="fa-solid fa-router" style="margin-right:8px; color:var(--accent);"></i>
                    <?php echo htmlspecialchars($r['router_label']); ?>
                    <?php if ($is_active): ?><span class="tag">ACTIVE</span><?php endif; ?>
                </div>
                <div class="r-ip"><?php echo htmlspecialchars($r['mikrotik_ip']); ?>:<?php echo (int)$r['api_port']; ?></div>
                <div class="r-stats">
                    <span title="Wateja waliotumia vocha za router hii">
                        <i class="fa-solid fa-users"></i>
                        <b><?php echo (int)($st['zilizotumika'] ?? 0); ?></b> wateja
                    </span>
                    <span title="Vocha zilizobaki (hazijatumika)">
                        <i class="fa-solid fa-ticket"></i>
                        <b><?php echo (int)($st['hazijatumika'] ?? 0); ?></b> zimebaki
                    </span>
                    <span title="Mapato ya leo kwenye router hii">
                        <i class="fa-solid fa-coins"></i>
                        <b>TSh <?php echo number_format((float)($st['mapato_leo'] ?? 0)); ?></b> leo
                    </span>
                </div>

                <?php $trial_on = ((int)($r['trial_enabled'] ?? 1) === 1); ?>
                <form method="POST" class="trial-toggle">
                    <input type="hidden" name="action" value="toggle_trial">
                    <input type="hidden" name="router_id" value="<?php echo (int)$r['router_id']; ?>">
                    <input type="hidden" name="trial_enabled" value="<?php echo $trial_on ? '0' : '1'; ?>">
                    <span class="trial-label">
                        <i class="fa-solid fa-gift"></i> Jaribio la dakika 5 bure:
                        <b class="<?php echo $trial_on ? 'on' : 'off'; ?>">
                            <?php echo $trial_on ? 'IMEWASHWA' : 'IMEZIMWA'; ?>
                        </b>
                    </span>
                    <button type="submit" class="trial-btn <?php echo $trial_on ? 'off' : 'on'; ?>">
                        <?php echo $trial_on ? 'Zima' : 'Washa'; ?>
                    </button>
                </form>
            </div>
            <?php if (!$is_active): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="switch_router">
                <input type="hidden" name="router_id" value="<?php echo (int)$r['router_id']; ?>">
                <button type="submit" class="btn btn-primary">Ingia <i class="fa-solid fa-arrow-right"></i></button>
            </form>
            <?php else: ?>
                <span class="btn btn-outline" style="cursor:default;"><i class="fa-solid fa-check"></i> Unaitumia sasa</span>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

    <?php if ($router_count < $max_routers): ?>
    <div class="add-card">
        <h3><i class="fa-solid fa-circle-plus" style="color:var(--accent);"></i> Ongeza Router Mpya</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_router">
            <label>Jina la Router (mfano: Duka Kariakoo)</label>
            <input type="text" name="router_label" required placeholder="Duka Kariakoo">
            <label>Tunnel IP ya MikroTik</label>
            <input type="text" name="mikrotik_ip" required placeholder="10.60.0.3">
            <label>API User</label>
            <input type="text" name="api_user" required placeholder="tech5g_api">
            <label>API Password</label>
            <input type="password" name="api_pass" required placeholder="••••••••">
            <label>API Port (hiari, default 8728)</label>
            <input type="text" name="api_port" placeholder="8728">
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;justify-content:center;">Thibitisha na Ongeza</button>
        </form>
    </div>
    <?php endif; ?>

</div>

<!-- Pop-up Modal ya Logout Confirmation -->
<div id="logoutModal" class="modal-overlay">
    <div class="modal-content">
        <h4 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800;margin-bottom:12px;color:#fff;">
            <i class="fa-solid fa-right-from-bracket" style="color:var(--red);"></i> Unahakika Unataka Kutoka?
        </h4>
        <p style="font-size:13px;color:var(--text-dim);margin-bottom:20px;">
            Je, una uhakika unataka kuondoka kwenye mfumo?
        </p>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button onclick="fungaModal('logoutModal')" style="background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.7);border:1px solid rgba(255,255,255,0.15);padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;">Ghairi</button>
            <a href="logout.php" style="background:var(--red);color:#fff;text-decoration:none;padding:9px 20px;border-radius:8px;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px;">Ndio, Logout</a>
        </div>
    </div>
</div>

<footer class="footer">
    &copy; <?php echo date('Y'); ?> Tech 5G Wi-Fi System. Haki zote zimehifadhiwa.
</footer>

<script>
function thibitishaLogout() {
    document.getElementById('logoutModal').classList.add('active');
}
function fungaModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>

<script>
function nakiliVpn(){
  var t = document.getElementById('vpnCmd');
  if(!t) return;
  navigator.clipboard.writeText(t.innerText).then(function(){
    alert('Imenakiliwa. Bandika kwenye MikroTik Terminal.');
  }, function(){
    // Baadhi ya vivinjari vya simu huzuia clipboard - chagua maandishi badala yake
    var r = document.createRange(); r.selectNodeContents(t);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    alert('Nakili maandishi yaliyochaguliwa (Ctrl/Cmd + C).');
  });
}
</script>

</body>
</html>