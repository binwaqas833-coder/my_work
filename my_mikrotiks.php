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
    $api_port     = (int) ($_POST['api_port'] ?? 8728);

    if ($router_label === '' || $mikrotik_ip === '' || $api_user === '' || $api_pass === '') {
        $toast = ['type' => 'error', 'msg' => 'Jaza taarifa zote: Jina la Router, IP, API User, na API Password. ⚠️'];
    } elseif ($router_count >= $max_routers) {
        $toast = ['type' => 'error', 'msg' => "Umefikia kikomo cha routers ({$max_routers}) kwa mpango wako wa sasa. Fanya upgrade ya plan ili kuongeza zaidi. 🚫"];
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

$active_router_id = $_SESSION['active_router_id'] ?? ($current_routers[0]['router_id'] ?? null);
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
    ?>
        <div class="card <?php echo $is_active ? 'active' : ''; ?>">
            <div>
                <div class="r-name">
                    <i class="fa-solid fa-router" style="margin-right:8px; color:var(--accent);"></i>
                    <?php echo htmlspecialchars($r['router_label']); ?>
                    <?php if ($is_active): ?><span class="tag">ACTIVE</span><?php endif; ?>
                </div>
                <div class="r-ip"><?php echo htmlspecialchars($r['mikrotik_ip']); ?>:<?php echo (int)$r['api_port']; ?></div>
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

</body>
</html>