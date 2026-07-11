<?php
session_start();
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$my_id = $_SESSION['user_id'];

// ── AJAX: FUTA VOCHA ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['vu_action']) && $_POST['vu_action']==='delete') {
    header('Content-Type: application/json');
    $vid = (int)$_POST['id'];
    $d = $conn->prepare("DELETE FROM vouchers WHERE id=? AND user_id=?");
    $d->bind_param("ii", $vid, $my_id);
    $d->execute();
    echo json_encode(['status' => $d->affected_rows > 0 ? 'success' : 'error']);
    exit();
}

// ── MAPATO ──
$today       = date('Y-m-d');
$start_week  = date('Y-m-d', strtotime('monday this week'));
$start_month = date('Y-m-01');

$q1 = mysqli_query($conn,"SELECT SUM(price) as total FROM vouchers WHERE user_id='$my_id' AND type='paid' AND DATE(created_at)='$today'");
$d1 = mysqli_fetch_assoc($q1);
$q2 = mysqli_query($conn,"SELECT SUM(price) as total FROM vouchers WHERE user_id='$my_id' AND type='paid' AND created_at>='$start_week'");
$d2 = mysqli_fetch_assoc($q2);
$q3 = mysqli_query($conn,"SELECT SUM(price) as total FROM vouchers WHERE user_id='$my_id' AND type='paid' AND created_at>='$start_month'");
$d3 = mysqli_fetch_assoc($q3);
$q4 = mysqli_query($conn,"SELECT COUNT(*) as total FROM vouchers WHERE user_id='$my_id' AND status='used'");
$d4 = mysqli_fetch_assoc($q4);

// ── MIKROTIK + WATEJA ACTIVE ──
require_once('routeros_api.class.php');
require_once('mikrotik_helper.php'); // functions: getMikrotikConnection(), getActiveHotspotUsers(), n.k.

$count = 0;
$last_seen = null;
$active_users_list = [];
$mikrotik_ip = ''; // inatumika chini kwenye kadi ya "Hali ya MikroTik"

$MK_API = getMikrotikConnection($my_id, $conn);
if ($MK_API) {
    $active_users_list = getActiveHotspotUsers($MK_API);
    $count = is_array($active_users_list) ? count($active_users_list) : 0;
    $MK_API->disconnect();
}

// IP ya MikroTik (kwa ajili ya status badge tu, hatuhitaji password/user hapa)
$cfg_ip_q = $conn->query("SELECT mikrotik_ip FROM mikrotik_configs WHERE user_id='$my_id' LIMIT 1");
if ($cfg_ip_q && $cfg_ip_q->num_rows > 0) {
    $mikrotik_ip = $cfg_ip_q->fetch_assoc()['mikrotik_ip'] ?? '';
}

// ── LAST SEEN ──
$q_last = mysqli_query($conn,"SELECT created_at FROM vouchers WHERE user_id='$my_id' AND status='used' ORDER BY created_at DESC LIMIT 1");
if ($q_last && mysqli_num_rows($q_last)>0) {
    $last_seen = mysqli_fetch_assoc($q_last)['created_at'];
}

// ── GRAFU DATA (siku 7) ──
$grafu_labels = []; $grafu_data = [];
for ($i=6; $i>=0; $i--) {
    $siku = date('Y-m-d', strtotime("-$i days"));
    $grafu_labels[] = ['Jpi','Jtt','Jnn','Jtno','Alh','Ij','Jmo'][date('w', strtotime($siku))];
    $qg = mysqli_query($conn,"SELECT SUM(price) as total FROM vouchers WHERE user_id='$my_id' AND type='paid' AND DATE(created_at)='$siku'");
    $grafu_data[] = (int)(mysqli_fetch_assoc($qg)['total'] ?? 0);
}

// ── TARIFFS ──
$t_stmt = $conn->prepare("SELECT * FROM tariffs WHERE user_id=? ORDER BY price ASC");
$t_stmt->bind_param("i", $my_id);
$t_stmt->execute();
$tariffs_result = $t_stmt->get_result();

// ── VOCHA MFUMO: DATA ──
$vm_stats = [];
$vm_stats['total']  = $conn->query("SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id'")->fetch_assoc()['c'] ?? 0;
$vm_stats['unused'] = $conn->query("SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id' AND status='unused'")->fetch_assoc()['c'] ?? 0;
$vm_stats['used']   = $conn->query("SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id' AND status='used'")->fetch_assoc()['c'] ?? 0;
$vm_stats['synced'] = $conn->query("SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id' AND mikrotik_synced=1")->fetch_assoc()['c'] ?? 0;
$vm_list = $conn->query("SELECT * FROM vouchers WHERE user_id='$my_id' ORDER BY created_at DESC LIMIT 200");

// ── MIPANGILIO: TAARIFA ZA AKAUNTI ──
$acc_stmt = $conn->prepare("SELECT username, email, phone, alert_email, notify_station_offline, created_at FROM users WHERE id = ?");
$acc_stmt->bind_param("i", $my_id);
$acc_stmt->execute();
$account = $acc_stmt->get_result()->fetch_assoc();

// ── MIPANGILIO: MIKROTIK STATUS (READ-ONLY) ──
$mk_stmt = $conn->prepare("SELECT mikrotik_ip, allowed_ips FROM mikrotik_configs WHERE user_id = ?");
$mk_stmt->bind_param("i", $my_id);
$mk_stmt->execute();
$mikrotik_info = $mk_stmt->get_result()->fetch_assoc();

// ── MIPANGILIO: TARIFFS ZOTE (kwa CRUD) ──
$mp_tariffs_stmt = $conn->prepare("SELECT * FROM tariffs WHERE user_id = ? ORDER BY price ASC");
$mp_tariffs_stmt->bind_param("i", $my_id);
$mp_tariffs_stmt->execute();
$mp_tariffs = $mp_tariffs_stmt->get_result();

// ── STATIONS ──
$total_stations = 0; $online_stations = 0; $stations_html = "";
$st_result = $conn->query("SELECT ap.*, u.username FROM access_points ap JOIN users u ON ap.user_id=u.id WHERE ap.user_id='$my_id'");
if ($st_result && $st_result->num_rows > 0) {
    $total_stations = $st_result->num_rows;
    while ($row = $st_result->fetch_assoc()) {
        $ip    = $row['ip_address'];
        $eneo  = $row['eneo_ilipo'] ?? 'Eneo halijasajiliwa';
        $jina  = htmlspecialchars($row['jina_la_ap'].' ('.$row['username'].')');
        $out = []; $rc = 1;
        if (stripos(PHP_OS, 'WIN') === 0) {
            // Windows: -n = idadi ya packets, -w = muda wa kusubiri kwa MILISEKUNDI
            exec("ping -n 1 -w 1000 ".escapeshellarg($ip), $out, $rc);
        } else {
            // Linux/Unix/Mac: -c = idadi ya packets, -W = muda wa kusubiri kwa SEKUNDE
            exec("ping -c 1 -W 1 ".escapeshellarg($ip), $out, $rc);
        }
        if ($rc===0) { $online_stations++; $sc="on"; $st="ONLINE"; $border=""; }
        else         { $sc="off"; $st="OFFLINE"; $border="style='border-color:rgba(255,61,87,0.3);'"; }
        $idadi = 0;
        $parts = explode('.',$ip); $subnet = $parts[0].'.'.$parts[1].'.'.$parts[2].'.';
        if (is_array($active_users_list)) foreach ($active_users_list as $u) if (isset($u['address']) && strpos($u['address'],$subnet)===0) $idadi++;
        $stations_html .= '<div class="station-box" '.$border.'><h4>'.$jina.'</h4><p class="station-eneo"><i class="fa-solid fa-location-dot"></i> '.htmlspecialchars($eneo).'</p><p class="station-wateja"><i class="fa-solid fa-users"></i> Wateja: '.$idadi.' Active</p><div class="station-status '.$sc.'"><div class="dot '.$sc.'"></div>'.$st.'</div></div>';
    }
} else {
    $stations_html = "<p class='empty-msg'>Hauna vituo vilivyosajiliwa kwa sasa.</p>";
}

// ── QUERIES ZA TABLES ──
$vocha_query   = mysqli_query($conn,"SELECT * FROM vouchers WHERE user_id='$my_id' ORDER BY created_at DESC LIMIT 30");
$warning_query = mysqli_query($conn,"SELECT * FROM vouchers WHERE user_id='$my_id' AND status='used' AND expiry_date BETWEEN NOW() AND (NOW() + INTERVAL 24 HOUR) ORDER BY expiry_date ASC");
$expired_query = mysqli_query($conn,"SELECT * FROM vouchers WHERE user_id='$my_id' AND (status='expired' OR expiry_date < NOW()) ORDER BY expiry_date DESC LIMIT 30");

// ── VOCHA USED STATS ──
$vu_total  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id' AND status='used'"))['c'] ?? 0;
$vu_leo    = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id' AND status='used' AND DATE(created_at)=CURDATE()"))['c'] ?? 0;
$vu_mapato = mysqli_fetch_assoc(mysqli_query($conn,"SELECT SUM(price) s FROM vouchers WHERE user_id='$my_id' AND status='used'"))['s'] ?? 0;
$vu_active = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id' AND status='used' AND expiry_date > NOW()"))['c'] ?? 0;
$vu_query  = mysqli_query($conn,"SELECT * FROM vouchers WHERE user_id='$my_id' AND status='used' ORDER BY created_at DESC");

// ── HELPERS ──
function packageBadge($pkg) {
    $p = strtolower($pkg ?? '');
    if (strpos($p,'daily')!==false||strpos($p,'siku')!==false)  return ['class'=>'siku', 'label'=>'SIKU'];
    if (strpos($p,'weekly')!==false||strpos($p,'wiki')!==false) return ['class'=>'wiki', 'label'=>'WIKI'];
    return ['class'=>'mwezi','label'=>'MWEZI'];
}
function formatWA($phone) {
    $phone = preg_replace('/[^0-9]/','', $phone);
    return (substr($phone,0,1)=='0') ? '255'.substr($phone,1) : $phone;
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bin Waqas · 5G Wi-Fi Premium</title>
<link rel="preload" as="image" href="beach5.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --surface: rgba(255,255,255,0.18);
    --surface2: rgba(255,255,255,0.10);
    --border: rgba(255,255,255,0.35);
    --border2: rgba(255,255,255,0.20);
    --accent:  #07f793;
    --accent2: #3fc7fd;
    --accent3: #ff6b35;
    --text: #fff;
    --text-dim: rgba(255,255,255,0.65);
    --text-muted: rgba(255,255,255,0.35);
    --red: #ff3d57;
    --sidebar: 270px;
    --radius: 14px;
    --blur: blur(18px);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background-color:#0d1b17;background-image:linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg);background-size:cover;background-position:center;background-attachment:fixed;color:var(--text);display:flex;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:rgba(0,0,0,0.30);pointer-events:none;z-index:0}
.sidebar{width:var(--sidebar);background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;inset:0 auto 0 0;z-index:100;transition:transform 0.35s cubic-bezier(.4,0,.2,1)}
.sidebar-brand{padding:24px 24px 18px;border-bottom:1px solid var(--border)}
.brand-logo{display:flex;align-items:center;gap:12px;margin-bottom:4px}
.brand-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--accent),#00a86b);border-radius:10px;display:grid;place-items:center;font-size:16px;color:#000;box-shadow:0 0 20px rgba(7,247,147,0.35)}
.brand-name{font-family:'Syne',sans-serif;font-weight:800;font-size:17px;color:#fff}
.brand-sub{font-size:10px;color:var(--text-dim);letter-spacing:2px;text-transform:uppercase;padding-left:50px;margin-top:2px}
.close-btn{display:none;background:none;border:none;color: #07f793;font-size:20px;cursor:pointer;margin-bottom:8px}
.sidebar-section-label{font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:var(--text-muted);padding:18px 24px 8px;font-family:'Space Mono',monospace}
.sidebar-menu{list-style:none;padding:0 12px;display:flex;flex-direction:column;gap:2px}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:11px 14px;color: #07f793;text-decoration:none;font-size:13.5px;font-weight:500;border-radius:10px;transition:all 0.2s;position:relative;cursor:pointer}
.sidebar-menu a .nav-icon{width:32px;height:32px;display:grid;place-items:center;border-radius:8px;font-size:13px;background:transparent;transition:all 0.2s;flex-shrink:0}
.sidebar-menu a:hover{color:#fff;background:var(--surface2)}
.sidebar-menu a:hover .nav-icon{background:rgba(7,247,147,0.08);color:var(--accent)}
.sidebar-menu li.active a{color:#fff;background:linear-gradient(135deg,rgba(7,247,147,0.12),rgba(7,247,147,0.04));border:1px solid rgba(7,247,147,0.15)}
.sidebar-menu li.active a .nav-icon{background:rgba(7,247,147,0.15);color:var(--accent)}
.sidebar-menu li.active a::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--accent);border-radius:0 2px 2px 0}
.sidebar-menu a.logout-link{color:#ff6b6b}
.sidebar-menu a.logout-link:hover{background:rgba(255,61,87,0.08);color:var(--red)}
.sidebar-footer{margin-top:auto;padding:18px 24px;border-top:1px solid var(--border)}
.signal-meter{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text-dim)}
.signal-bars{display:flex;gap:3px;align-items:flex-end}
.signal-bars span{width:4px;background:var(--accent);border-radius:2px;animation:pulse-bar 2s ease-in-out infinite}
.signal-bars span:nth-child(1){height:8px;animation-delay:0s}
.signal-bars span:nth-child(2){height:13px;animation-delay:.2s}
.signal-bars span:nth-child(3){height:18px;animation-delay:.4s}
.signal-bars span:nth-child(4){height:24px;animation-delay:.6s;opacity:.3}
@keyframes pulse-bar{0%,100%{opacity:1}50%{opacity:.4}}
.dashboard-section{display:none}
.dashboard-section.active{display:block}
.main-content{flex:1;margin-left:var(--sidebar);padding:26px;position:relative;z-index:1;max-width:calc(100% - var(--sidebar))}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;padding:0 4px}
.menu-toggle{display:none;background:none;border:none;color: #07f793;font-size:20px;cursor:pointer}
.topbar-left h2{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px}
.topbar-left p{font-size:12px;color:var(--text-dim);margin-top:2px}
.topbar-right{display:flex;align-items:center;gap:12px}
.topbar-pill{background:var(--surface);border:1px solid var(--border);border-radius:50px;padding:8px 16px;font-size:12.5px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;backdrop-filter:var(--blur)}
.dot-live{width:7px;height:7px;background:var(--accent);border-radius:50%;animation:live-pulse 1.5s ease-in-out infinite;box-shadow:0 0 6px var(--accent)}
@keyframes live-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.5);opacity:.6}}
.admin-badge{background:linear-gradient(135deg,rgba(7,247,147,0.15),rgba(7,247,147,0.05));border:1px solid rgba(7,247,147,0.25);border-radius:50px;padding:8px 16px;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--accent)}
.panel{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:22px;margin-bottom:24px;position:relative;overflow:hidden}
.panel::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.3),transparent)}
.panel-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.panel-title h3{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.panel-title h3 i{color:var(--accent);font-size:13px}
.cards-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:20px;position:relative;overflow:hidden;transition:transform 0.2s,border-color 0.2s;cursor:default}
.stat-card:hover{transform:translateY(-2px);border-color:var(--border)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.c1::before{background:linear-gradient(90deg,var(--accent),transparent)}
.stat-card.c2::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.stat-card.c3::before{background:linear-gradient(90deg,var(--accent3),transparent)}
.stat-card.c4::before{background:linear-gradient(90deg,#a78bfa,transparent)}
.stat-card.c5::before{background:linear-gradient(90deg,#f59e0b,transparent)}
.stat-card.c6::before{background:linear-gradient(90deg,#ec4899,transparent)}
.stat-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-dim);font-family:'Poppins',sans-serif;margin-bottom:10px}
.stat-value{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:#fff;line-height:1;letter-spacing:-1px}
.stat-sub{font-size:11px;color:var(--text-dim);margin-top:6px}
.stat-icon{position:absolute;right:18px;top:18px;font-size:20px;opacity:0.12}
.stat-card.c1 .stat-icon{color:var(--accent)}
.stat-card.c2 .stat-icon{color:var(--accent2)}
.stat-card.c3 .stat-icon{color:var(--accent3)}
.stat-card.c4 .stat-icon{color:#a78bfa}
.stat-card.c5 .stat-icon{color:#f59e0b}
.stat-card.c6 .stat-icon{color:#ec4899}
.stat-change{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;margin-top:8px;padding:2px 7px;border-radius:4px}
.stat-change.online-badge{background:rgba(167,139,250,0.12);color:#a78bfa}
.tariff-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.tariff-card{background:rgba(255,255,255,0.10);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid rgba(255,255,255,0.18);border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:12px;transition:all 0.2s}
.tariff-card:hover{border-color:rgba(255,255,255,0.40);background:rgba(255,255,255,0.16)}
.tariff-top{display:flex;align-items:center;gap:10px}
.tariff-ico{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;font-size:14px;flex-shrink:0}
.tariff-ico.g{background:rgba(7,247,147,0.12);color:var(--accent)}
.tariff-ico.b{background:rgba(63,199,253,0.12);color:var(--accent2)}
.tariff-ico.o{background:rgba(255,107,53,0.12);color:var(--accent3)}
.tariff-name{font-size:13px;font-weight:700;color:#fff;font-family:'Syne',sans-serif}
.tariff-meta{font-size:11px;color:var(--text-dim);margin-top:2px}
.tariff-bottom{display:flex;justify-content:space-between;align-items:center}
.speed-tag{font-family:'Space Mono',monospace;font-size:10px;padding:3px 8px;border-radius:5px;font-weight:700}
.speed-tag.g{background:rgba(7,247,147,0.1);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.speed-tag.b{background:rgba(63,199,253,0.1);color:var(--accent2);border:1px solid rgba(63,199,253,0.25)}
.speed-tag.o{background:rgba(255,107,53,0.1);color:var(--accent3);border:1px solid rgba(255,107,53,0.25)}
.stations-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.station-box{background:rgba(255,255,255,0.10);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid rgba(255,255,255,0.18);border-radius:10px;padding:14px 10px;text-align:center;transition:all 0.2s}
.station-box:hover{border-color:rgba(255,255,255,0.40);background:rgba(255,255,255,0.16)}
.station-box h4{font-size:12px;font-weight:700;color:#fff;margin-bottom:6px;font-family:'Space Mono',monospace}
.station-eneo{font-size:10px;color:var(--text-dim);font-style:italic;margin:-2px 0 6px}
.station-eneo i{color:#ff4d4d;font-size:9px;margin-right:2px}
.station-wateja{font-size:11px;color:var(--accent2);font-weight:600;margin-bottom:8px}
.station-status{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;font-family:'Space Mono',monospace}
.dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.dot.on{background:var(--accent);box-shadow:0 0 8px var(--accent);animation:pulse-dot 2s infinite}
.dot.off{background:var(--red)}
.station-status.on{color:var(--accent)}
.station-status.off{color:var(--red)}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.5}}
.main-grid{display:flex;flex-direction:column;gap:20px}
.bottom-section{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.search-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.search-input,.filter-select{background:rgba(255,255,255,0.10);backdrop-filter:var(--blur);border:1px solid rgba(255,255,255,0.22);border-radius:9px;padding:10px 14px;color:#fff;font-family:'DM Sans',sans-serif;font-size:13px;outline:none;transition:border-color 0.2s}
.search-input{flex:1;min-width:180px}
.search-input:focus,.filter-select:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(7,247,147,0.10)}
.search-input::placeholder{color:rgba(255,255,255,0.35)}
.filter-select option{background:#0f2030;color:#fff}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:480px}
thead th{padding:11px 12px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.50);font-family:'Poppins',sans-serif;font-weight:500;border-bottom:1px solid rgba(255,255,255,0.10);text-align:left}
tbody td{padding:13px 12px;border-bottom:1px solid rgba(255,255,255,0.05);color:#fff;vertical-align:middle}
tbody tr{transition:background 0.15s,opacity 0.4s,transform 0.4s}
tbody tr:hover{background:rgba(255,255,255,0.05)}
tbody tr:last-child td{border-bottom:none}
tbody tr.removing{opacity:0;transform:translateX(30px);pointer-events:none}
code{font-family:'Space Mono',monospace;font-size:11px;color:var(--accent2);background:rgba(63,199,253,0.08);padding:2px 6px;border-radius:4px}
.badge{font-family:'Poppins',sans-serif;font-size:9px;font-weight:700;letter-spacing:0.5px;padding:4px 9px;border-radius:5px}
.badge.siku{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.badge.wiki{background:rgba(63,199,253,0.12);color:var(--accent2);border:1px solid rgba(63,199,253,0.25)}
.badge.mwezi{background:rgba(255,107,53,0.12);color:var(--accent3);border:1px solid rgba(255,107,53,0.25)}
.badge.live{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25);animation:blink 2s linear infinite}
.badge.status-used{background:rgba(255,61,87,0.12);color:var(--red);border:1px solid rgba(255,61,87,0.25)}
.badge.status-active{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.badge.unused{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.badge.used{background:rgba(255,61,87,0.12);color:var(--red);border:1px solid rgba(255,61,87,0.25)}
.badge.expired{background:rgba(255,107,53,0.12);color:var(--accent3);border:1px solid rgba(255,107,53,0.25)}
@keyframes blink{50%{opacity:.6}}
.divider{height:1px;background:rgba(255,255,255,0.08);margin:20px 0}
.empty-msg{text-align:center;padding:20px;color:var(--text-dim);font-size:13px}
.btn-primary{background:var(--accent);color:#000;border:none;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-ghost-red{background:none;border:1px solid rgba(255,61,87,0.30);color:var(--red);padding:6px 10px;border-radius:7px;cursor:pointer;font-size:11px;transition:all 0.2s}
.btn-ghost-red:hover{background:rgba(255,61,87,0.10)}
.btn-ghost-orange{background:none;border:1px solid rgba(255,107,53,0.30);color:var(--accent3);padding:6px 12px;border-radius:7px;cursor:pointer;font-size:11px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s}
.btn-ghost-orange:hover{background:rgba(255,107,53,0.10)}
.btn-ghost-blue{background:none;border:1px solid rgba(63,199,253,0.30);color:var(--accent2);padding:6px 12px;border-radius:7px;cursor:pointer;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s}
.btn-ghost-blue:hover{background:rgba(63,199,253,0.10)}
.btn-edit{background:none;border:1px solid var(--border2);color:var(--text-dim);padding:5px 12px;border-radius:7px;cursor:pointer;font-size:11px;font-weight:600;transition:all 0.2s}
.btn-edit:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger{background:rgba(255,61,87,0.15);color:var(--red);border:1px solid rgba(255,61,87,0.30);padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-danger:hover{background:rgba(255,61,87,0.25)}
.btn-save{background:var(--accent);color:#000;border:none;padding:9px 20px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s}
.btn-save:hover{filter:brightness(1.1)}
.btn-cancel{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.70);border:1px solid rgba(255,255,255,0.15);padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s}
.btn-cancel:hover{background:rgba(255,255,255,0.14);color:#fff}
.status-list{list-style:none}
.status-item{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:12.5px}
.status-item:last-child{border-bottom:none}
.status-label{color:rgba(255,255,255,0.60);display:flex;align-items:center;gap:8px}
.val-ok{color:var(--accent);font-weight:700;font-family:'Space Mono',monospace;font-size:11px}
.val-num{color:#fff;font-family:'Space Mono',monospace;font-size:11px}
.exp-ok{color:var(--accent);font-size:12px;font-weight:600}
.exp-soon{color:#f59e0b;font-size:12px;font-weight:600}
.exp-expired{color:var(--red);font-size:12px;font-weight:600}
#voucher-used .vu-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.vu-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.vu-selected-info{display:none;align-items:center;gap:10px;font-size:12px;color:var(--text-dim)}
.vu-selected-info.visible{display:flex}
.vu-records{font-family:'Space Mono',monospace;font-size:12px;color:var(--text-dim)}
.vm-tab-btn{background:transparent;color:var(--text-dim);border:1px solid transparent}
.vm-tab-btn.active{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.field-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:600px){.field-row-2{grid-template-columns:1fr}}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;justify-content:center;align-items:center;z-index:1500}
.modal-overlay.active{display:flex}
.modal-content{background:rgba(15,30,50,0.90);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 40px rgba(0,0,0,0.5);padding:28px;border-radius:16px;width:90%;max-width:420px;color:#fff;animation:modalIn 0.3s ease}
@keyframes modalIn{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.modal-header h4{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;display:flex;align-items:center;gap:8px}
.modal-header h4 i{color:var(--accent)}
.modal-close-x{background:none;border:none;color:rgba(255,255,255,0.6);font-size:22px;cursor:pointer;transition:color 0.2s}
.modal-close-x:hover{color:#fff}
.modal-label{font-size:11px;color:var(--text-dim);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px}
.modal-input,.modal-select-field{width:100%;padding:12px 14px;border-radius:9px;border:1px solid rgba(255,255,255,0.20);background:rgba(255,255,255,0.08);color:#fff;font-family:'DM Sans',sans-serif;font-size:14px;margin-bottom:18px;outline:none;transition:border-color 0.2s}
.modal-input:focus,.modal-select-field:focus{border-color:var(--accent)}
.modal-input::placeholder{color:rgba(255,255,255,0.35)}
.modal-select-field option{background:#0f2030}
.modal-footer{display:flex;justify-content:flex-end;gap:10px}
.renew-info-box{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:10px}
#toastContainer{position:fixed;top:20px;right:20px;z-index:2500;display:flex;flex-direction:column;gap:10px}
.toast{min-width:280px;padding:14px 18px;border-radius:12px;color:#fff;display:flex;align-items:center;gap:12px;backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 32px rgba(0,0,0,0.3);animation:toastIn 0.4s ease;font-size:13px}
.toast.success{background:rgba(7,247,147,0.15);border-left:4px solid var(--accent)}
.toast.success .ti{color:var(--accent);font-size:16px}
.toast.error{background:rgba(255,61,87,0.15);border-left:4px solid var(--red)}
.toast.error .ti{color:var(--red);font-size:16px}
.toast.warning{background:rgba(245,158,11,0.15);border-left:4px solid #f59e0b}
.toast.warning .ti{color:#f59e0b;font-size:16px}
.toast.info{background:rgba(63,199,253,0.15);border-left:4px solid var(--accent2)}
.toast.info .ti{color:var(--accent2);font-size:16px}
@keyframes toastIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes toastOut{to{opacity:0;transform:translateX(60px)}}
.footer{text-align:center;padding:22px;font-size:11px;color:rgba(255,255,255,0.35);font-family:'Space Mono',monospace}
@media print{
    body>*:not(#vmPrintArea){display:none!important}
    #vmPrintArea{display:block!important}
    .vm-print-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:15px}
    .vm-print-card{border:2px dashed #333;border-radius:8px;padding:14px;text-align:center;page-break-inside:avoid}
    .vm-print-card .pc-brand{font-size:11px;font-weight:700;color:#000;margin-bottom:8px;letter-spacing:1px}
    .vm-print-card .pc-code{font-family:'Courier New',monospace;font-size:22px;font-weight:900;color:#000;letter-spacing:3px;margin:10px 0;border:1px solid #ddd;padding:8px;border-radius:4px;background:#f9f9f9}
    .vm-print-card .pc-pkg{font-size:12px;color:#555;margin-bottom:4px}
    .vm-print-card .pc-price{font-size:16px;font-weight:700;color:#000}
    .vm-print-card .pc-days{font-size:11px;color:#777;margin-top:4px}
    .vm-print-card .pc-line{border:none;border-top:1px dashed #ccc;margin:8px 0}
    .vm-print-card .pc-wifi{font-size:10px;color:#999}
}
@media(max-width:1200px){.cards-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.bottom-section{grid-template-columns:1fr}.tariff-grid{grid-template-columns:1fr 1fr}.cards-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
    .sidebar{transform:translateX(-100%)}
    .sidebar.active{transform:translateX(0)}
    .close-btn{display:block!important}
    .main-content{margin-left:0;max-width:100%;padding:16px}
    .menu-toggle{margin-top:-50px;padding:8px;display:block!important}
    .cards-grid{grid-template-columns:repeat(2,1fr)}
    .tariff-grid{grid-template-columns:1fr}
    #voucher-used .vu-cards{grid-template-columns:repeat(2,1fr)}
    #vocha .vm-cards{grid-template-columns:repeat(2,1fr)!important}
}
@media(max-width:480px){.cards-grid{grid-template-columns:1fr}#voucher-used .vu-cards{grid-template-columns:1fr}}
@media(min-width:769px){.close-btn{display:none!important}.menu-toggle{margin-top:-50px;padding:8px;display:none!important}}

/* Fix ya "kupepesuka" (flicker) ya backdrop-filter: kila kipengele chenye blur
   kinalazimishwa kutengeneza GPU layer yake, badala ya kuhesabiwa upya pamoja
   na vingine kila background/animation inapobadilika. */
.panel,.stat-card,.tariff-card,.station-box,.topbar-pill,.admin-badge{
    transform:translateZ(0);
    -webkit-transform:translateZ(0);
    backface-visibility:hidden;
    -webkit-backface-visibility:hidden;
}
/* .sidebar haipati transform:translateZ(0) moja kwa moja kwa sababu
   inagongana na .sidebar.active (translateX) inayotumika kuifungua/kuifunga
   kwenye simu - will-change peke yake inatosha kuzuia flicker bila kuzima
   toggle. */
.sidebar{
    will-change:backdrop-filter;
}
</style>
</head>
<body>

<div id="toastContainer"></div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <button class="close-btn" onclick="toggleSidebar()"><i class="fa-solid fa-xmark"></i></button>
        <div class="brand-logo">
            <div class="brand-icon"><i class="fa-solid fa-wifi"></i></div>
            <div><div class="brand-name">System</div></div>
        </div>
        <div class="brand-sub">5G Wi-Fi Premium</div>
    </div>
    <div class="sidebar-section-label">Navigation</div>
    <ul class="sidebar-menu">
        <li id="nav-dashboard" class="active">
            <a onclick="onyeshaSection('dashboard')"><div class="nav-icon"><i class="fa-solid fa-chart-pie"></i></div>Dashboard</a>
        </li>
        <li id="nav-vocha">
            <a onclick="onyeshaSection('vocha')"><div class="nav-icon"><i class="fa-solid fa-ticket"></i></div>Vocha Mfumo</a>
        </li>
        <li id="nav-mipangilio">
            <a onclick="onyeshaSection('mipangilio')"><div class="nav-icon"><i class="fa-solid fa-sliders"></i></div>Mipangilio</a>
        </li>
        <li id="nav-voucher-used">
            <a onclick="onyeshaSection('voucher-used')"><div class="nav-icon"><i class="fa-solid fa-circle-check"></i></div>Vocha Zilizotumika</a>
        </li>
        <li>
            <a href="malipo_status.php"><div class="nav-icon"><i class="fa-solid fa-money-bill-transfer"></i></div>Hali za Malipo</a>
        </li>
        <li>
            <a href="logout.php" class="logout-link"><div class="nav-icon"><i class="fa-solid fa-right-from-bracket"></i></div>Logout</a>
        </li>
    </ul>
    <div class="sidebar-footer">
        <div class="signal-meter">
            <div class="signal-bars"><span></span><span></span><span></span><span></span></div>
            <span>Signal ya Mfumo OK</span>
        </div>
    </div>
</nav>

<main class="main-content">

    <header class="topbar">
        <button class="menu-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
        <div class="topbar-left">
            <h2 id="page-title">Usimamizi wa Wi-Fi</h2>
            <p><?php
                $h = date('H');
                echo $h<12 ? "Habari za Asubuhi, " : ($h<18 ? "Habari za Mchana, " : "Habari za Jioni, ");
            ?>tazama hali ya Mfumo wako</p>
        </div>
        <div class="topbar-right">
            <div class="topbar-pill"><div class="dot-live"></div>LIVE</div>
            <div class="admin-badge">
                <i class="fa-solid fa-user-shield"></i>
                <?php echo htmlspecialchars($_SESSION['username'] ?? 'Mgeni'); ?>
            </div>
        </div>
    </header>

    <div id="dashboard" class="dashboard-section active">
        <section class="cards-grid">
            <div class="stat-card c1">
                <div class="stat-label">Mapato ya Leo</div>
                <div class="stat-value"><?php echo number_format($d1['total']??0); ?></div>
                <div class="stat-sub">Tsh</div>
                <i class="fa-solid fa-money-bill-wave stat-icon"></i>
            </div>
            <div class="stat-card c2">
                <div class="stat-label">Wiki Hii</div>
                <div class="stat-value"><?php echo number_format($d2['total']??0); ?></div>
                <div class="stat-sub">Tsh</div>
                <i class="fa-solid fa-chart-line stat-icon"></i>
            </div>
            <div class="stat-card c3">
                <div class="stat-label">Mwezi Huu</div>
                <div class="stat-value"><?php echo number_format($d3['total']??0); ?></div>
                <div class="stat-sub">Tsh</div>
                <i class="fa-solid fa-wallet stat-icon"></i>
            </div>
            <div class="stat-card c4">
                <div class="stat-label">Vocha Zilizotumika</div>
                <div class="stat-value"><?php echo $d4['total']??0; ?></div>
                <div class="stat-sub">Jumla zilizowashwa</div>
                <i class="fa-solid fa-ticket stat-icon"></i>
            </div>
            <div class="stat-card c5">
                <div class="stat-label">Wateja Active</div>
                <div class="stat-value"><?php echo $count; ?></div>
                <div class="stat-sub">Wanaotumia sasa hivi</div>
                <div class="stat-change online-badge"><i class="fa-solid fa-circle" style="font-size:6px;"></i> Online</div>
                <i class="fa-solid fa-users stat-icon"></i>
            </div>
            <div class="stat-card c6">
                <div class="stat-label">Last Seen</div>
                <div class="stat-value" style="font-size:16px;"><?php echo $last_seen ? date('d M, H:i',strtotime($last_seen)) : '—'; ?></div>
                <div class="stat-sub">Shughuli ya mwisho</div>
                <i class="fa-solid fa-clock stat-icon"></i>
            </div>
        </section>

        <section class="panel">
            <div class="panel-title"><h3><i class="fa-solid fa-sliders"></i> Bei za Vifurushi Kwa Sasa</h3></div>
            <div class="tariff-grid">
            <?php
            $tariffs_result->data_seek(0);
            if ($tariffs_result->num_rows > 0):
                while ($row = $tariffs_result->fetch_assoc()):
                    $rangi='o'; $icon='fa-calendar-plus';
                    if(stripos($row['package_type'],'siku')!==false||stripos($row['package_type'],'daily')!==false){$rangi='g';$icon='fa-clock';}
                    elseif(stripos($row['package_type'],'wiki')!==false||stripos($row['package_type'],'weekly')!==false){$rangi='b';$icon='fa-calendar-days';}
            ?>
                <div class="tariff-card">
                    <div class="tariff-top">
                        <div class="tariff-ico <?php echo $rangi; ?>"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                        <div>
                            <div class="tariff-name"><?php echo htmlspecialchars($row['package_type']); ?></div>
                            <div class="tariff-meta">Tsh <?php echo number_format($row['price']); ?> · Siku <?php echo $row['duration_days']; ?></div>
                        </div>
                    </div>
                    <div class="tariff-bottom">
                        <span class="speed-tag <?php echo $rangi; ?>"><?php echo htmlspecialchars($row['speed']); ?></span>
                        <button class="btn-edit" onclick="haririKifurushi(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['package_type'],ENT_QUOTES); ?>',<?php echo $row['price']; ?>)">Hariri</button>
                    </div>
                </div>
            <?php endwhile; else: ?>
                <p class="empty-msg">Hujatengeneza bando lolote bado.</p>
            <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-title">
                <h3><i class="fa-solid fa-tower-broadcast"></i> Hali ya Vituo (Stations)</h3>
                <span style="font-family:'Space Mono',monospace;font-size:11px;color:var(--text-dim);">
                    <?php echo $online_stations.'/'.$total_stations; ?> <span style="color:var(--accent);">ONLINE</span>
                </span>
            </div>
            <div class="stations-grid"><?php echo $stations_html; ?></div>
        </section>

        <div class="main-grid">
            <div>
                <section class="panel">
                    <div class="panel-title">
                        <h3><i class="fa-solid fa-ticket"></i> Vocha Zilizolipiwa Karibuni</h3>
                        <button class="btn-primary" onclick="funguaModal('vochaModal')"><i class="fa-solid fa-plus"></i> Vocha ya Bure</button>
                    </div>
                    <div class="search-bar">
                        <input type="text" class="search-input" id="searchBox" onkeyup="tafutaKwenyeJedwali()" placeholder="Saka namba au vocha...">
                        <select class="filter-select" id="filterBox" onchange="tafutaKwenyeJedwali()">
                            <option value="">Vifurushi Vyote</option>
                            <option value="siku">Siku</option>
                            <option value="wiki">Wiki</option>
                            <option value="mwezi">Mwezi</option>
                        </select>
                    </div>
                    <div class="table-wrap">
                        <table id="vochaTable">
                            <thead><tr><th>Namba ya Simu</th><th>Kifurushi</th><th>Kodi</th><th>Muamala</th><th>Status</th><th>Muda</th><th></th></tr></thead>
                            <tbody>
                            <?php if($vocha_query && mysqli_num_rows($vocha_query)>0):
                                while($row=mysqli_fetch_assoc($vocha_query)):
                                    $pb=packageBadge($row['package_type']??'');
                                    $sc=($row['status']=='used')?'status-used':'status-active';
                                    $sl=($row['status']=='used')?'Imetumika':'Haikutumika';
                                    $ds=time()-strtotime($row['created_at']);
                                    $ms=$ds<3600?floor($ds/60).' dk':($ds<86400?floor($ds/3600).' saa':date('d M Y',strtotime($row['created_at'])));
                            ?>
                                <tr id="v-<?php echo $row['id']; ?>">
                                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td><span class="badge <?php echo $pb['class']; ?>"><?php echo $pb['label']; ?></span></td>
                                    <td><code><?php echo htmlspecialchars($row['voucher_code']??'—'); ?></code></td>
                                    <td style="font-size:12px;color:var(--text-dim);"><?php echo htmlspecialchars($row['payment_method']??'—'); ?></td>
                                    <td><span class="badge <?php echo $sc; ?>"><?php echo $sl; ?></span></td>
                                    <td style="color:var(--text-dim);font-size:12px;"><?php echo $ms; ?></td>
                                    <td><button class="btn-ghost-red" onclick="futaVocha(<?php echo $row['id']; ?>)"><i class="fa-solid fa-trash-can"></i></button></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="7" class="empty-msg">Hakuna vocha kwa sasa.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="divider"></div>
                    <div class="panel-title" style="margin-bottom:16px;">
                        <h3><i class="fa-solid fa-users-line"></i> Watumiaji Active Sasa</h3>
                        <span class="badge live"><i class="fa-solid fa-circle" style="font-size:7px;"></i> &nbsp;<?php echo $count; ?> ACTIVE</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Jina/Simu</th><th>IP Address</th><th>Uptime</th><th>Data</th><th></th></tr></thead>
                            <tbody>
                            <?php if(!empty($active_users_list)&&is_array($active_users_list)):
                                foreach($active_users_list as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['user']??'—'); ?></td>
                                    <td><code><?php echo htmlspecialchars($u['address']??'—'); ?></code></td>
                                    <td style="font-size:12px;color:var(--text-dim);"><?php echo htmlspecialchars($u['uptime']??'—'); ?></td>
                                    <td><?php $b=(int)($u['bytes-out']??0); echo $b>1048576?round($b/1048576,1).' MB':round($b/1024,1).' KB'; ?></td>
                                    <td><button class="btn-ghost-orange" onclick="kataInternet('<?php echo htmlspecialchars($u['user'],ENT_QUOTES); ?>')">Kata</button></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="empty-msg">Hakuna wateja active kwa sasa.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="divider"></div>
                    <div class="panel-title">
                        <h3><i class="fa-solid fa-bell" style="color:#f59e0b;"></i> Wanaomaliza (Masaa 24)</h3>
                        <span style="font-family:'Space Mono',monospace;font-size:11px;color:#f59e0b;">⚠️ <?php echo mysqli_num_rows($warning_query); ?> wanamaliza</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Namba ya Simu</th><th>Kifurushi</th><th>Inaisha</th><th>WhatsApp</th><th>Renew</th></tr></thead>
                            <tbody>
                            <?php if($warning_query&&mysqli_num_rows($warning_query)>0):
                                  while($row=mysqli_fetch_assoc($warning_query)):
                                    $pb=packageBadge($row['package_type']??'');
                                    $wa=formatWA($row['phone']);
                                    $msgs=['daily'=>"Habari! Ndugu Mteja, kifurushi chako cha SIKU cha 5G Wi-Fi kinaisha ndani ya masaa 3. Tafadhali Usisahau kufanya malipo ili kuendelea na Huduma yetu. Asante.",'weekly'=>"Habari! Ndugu Mteja, kifurushi chako cha WIKI cha 5G Wi-Fi kinaisha ndani ya masaa 24. Tafadhali Usisahau kufanya malipo ili kuendelea na huduma yetu. Asante.",'monthly'=>"Habari! Ndugu Mteja, kifurushi chako cha MWEZI cha 5G Wi-Fi kinaisha ndani ya masaa 24. Tafadhali Usisahau kufanya malipo ili kuendelea na huduma yetu. Asante."];
                                    $pkg_key=strtolower($row['package_type']??'monthly');
                                    $msg=$msgs[$pkg_key]??$msgs['monthly'];
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['phone']); ?></strong></td>
                                    <td><span class="badge <?php echo $pb['class']; ?>"><?php echo $pb['label']; ?></span></td>
                                    <td style="font-size:12px;color:#f59e0b;font-weight:600;"><i class="fa-solid fa-clock" style="font-size:10px;"></i> <?php echo date('d M, H:i',strtotime($row['expiry_date'])); ?></td>
                                    <td><a href="https://wa.me/<?php echo $wa; ?>?text=<?php echo urlencode($msg); ?>" target="_blank" class="btn-ghost-orange"><i class="fa-brands fa-whatsapp"></i> Tuma</a></td>
                                    <td><button class="btn-primary" style="padding:6px 14px;font-size:11px;" onclick="funguaRenewModal('<?php echo htmlspecialchars($row['phone'],ENT_QUOTES); ?>','<?php echo $pb['label']; ?>')"><i class="fa-solid fa-rotate"></i> Renew</button></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="5" class="empty-msg">Hakuna wanaomaliza muda.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="divider"></div>
                    <div class="panel-title">
                        <h3><i class="fa-solid fa-clock" style="color:var(--red);"></i> Wateja Walio-Expire</h3>
                        <span style="font-family:'Space Mono',monospace;font-size:11px;color:var(--red);">❌ <?php echo mysqli_num_rows($expired_query); ?> waliomaliza</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Namba ya Simu</th><th>Iliisha</th><th>Kifurushi</th><th>WhatsApp</th><th>Renew</th></tr></thead>
                            <tbody>
                            <?php
                            $user_info_q = $conn->prepare("SELECT username, phone FROM users WHERE id = ?");
                            $user_info_q->bind_param("i", $my_id);
                            $user_info_q->execute();
                            $user_info = $user_info_q->get_result()->fetch_assoc();
                            $phone_admin = $user_info['phone'] ?? '0700000000';
                            $username_admin = $user_info['username'] ?? 'Msimamizi';
                            if($expired_query&&mysqli_num_rows($expired_query)>0):
                                while($row=mysqli_fetch_assoc($expired_query)):
                                    $pb=packageBadge($row['package_type']??'');
                                    $wa=formatWA($row['phone']);
                                    $diff=floor((time()-strtotime($row['expiry_date']))/86400);
                                    if($diff==0)     $dl="<span style='color:#07f793;font-weight:700;font-size:12px;'>LEO</span>";
                                    elseif($diff==1) $dl="<span style='color:#f59e0b;font-weight:700;font-size:12px;'>JANA</span>";
                                    else             $dl="<span style='color:var(--red);font-weight:700;font-size:12px;'>{$diff} siku</span>";
                                    $msg_exp="Habari! Ndugu Mteja wa 5G Wi-Fi, huduma yako imekwisha muda. Tafadhali fanya malipo kupitia namba: {$phone_admin} (Jina: {$username_admin}). Baada ya malipo kukamilika, tutumie picha ya muamala hapa.Ili tuweze kukuunganisha tena na huduma yetu  Asante!";
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['phone']); ?></strong></td>
                                    <td><?php echo $dl; ?></td>
                                    <td><span class="badge <?php echo $pb['class']; ?>"><?php echo $pb['label']; ?></span></td>
                                    <td><a href="https://wa.me/<?php echo $wa; ?>?text=<?php echo urlencode($msg_exp); ?>" target="_blank" class="btn-ghost-orange"><i class="fa-brands fa-whatsapp"></i> Tuma</a></td>
                                    <td><button class="btn-primary" style="padding:6px 14px;font-size:11px;" onclick="funguaRenewModal('<?php echo htmlspecialchars($row['phone'],ENT_QUOTES); ?>','<?php echo $pb['label']; ?>')"><i class="fa-solid fa-rotate"></i> Renew</button></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="5" class="empty-msg">Hakuna wateja walio-expire.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <div class="bottom-section">
                <section class="panel">
                    <div class="panel-title"><h3><i class="fa-solid fa-chart-line" style="color:var(--accent2);"></i> Mwenendo wa Wiki</h3></div>
                    <canvas id="grafuYaMapato" height="140"></canvas>
                </section>
                <section class="panel">
                    <div class="panel-title"><h3><i class="fa-solid fa-server"></i> Hali ya Mfumo</h3></div>
                    <ul class="status-list">
                        <li class="status-item">
                            <span class="status-label"><i class="fa-solid fa-circle" style="color:var(--accent);font-size:8px;"></i> MikroTik</span>
                            <span class="val-ok"><?php echo !empty($mikrotik_ip)?'ONLINE':'OFFLINE'; ?></span>
                        </li>
                        <li class="status-item">
                            <span class="status-label"><i class="fa-solid fa-database"></i> Database</span>
                            <span class="val-ok">CONNECTED</span>
                        </li>
                        <li class="status-item">
                            <span class="status-label"><i class="fa-solid fa-tower-broadcast"></i> Vituo Online</span>
                            <span class="val-num"><?php echo $online_stations.'/'.$total_stations; ?></span>
                        </li>
                        <li class="status-item">
                            <span class="status-label"><i class="fa-solid fa-shield-halved"></i> Session</span>
                            <span class="val-ok">ACTIVE</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div><!-- END #dashboard -->


    <!-- ══ SECTION 2: VOCHA MFUMO ══ -->
    <div id="vocha" class="dashboard-section">
        <div class="cards-grid vm-cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
            <div class="stat-card c1">
                <div class="stat-label">Vocha Zote</div>
                <div class="stat-value"><?php echo number_format($vm_stats['total']); ?></div>
                <div class="stat-sub">Zilizotengenezwa</div>
                <i class="fa-solid fa-ticket stat-icon"></i>
            </div>
            <div class="stat-card c2">
                <div class="stat-label">Haijatumika</div>
                <div class="stat-value"><?php echo number_format($vm_stats['unused']); ?></div>
                <div class="stat-sub">Zinasubiri</div>
                <i class="fa-solid fa-hourglass-half stat-icon"></i>
            </div>
            <div class="stat-card c4">
                <div class="stat-label">Zilizotumika</div>
                <div class="stat-value"><?php echo number_format($vm_stats['used']); ?></div>
                <div class="stat-sub">Na wateja</div>
                <i class="fa-solid fa-circle-check stat-icon"></i>
            </div>
            <div class="stat-card c5">
                <div class="stat-label">MikroTik Sync</div>
                <div class="stat-value"><?php echo number_format($vm_stats['synced']); ?></div>
                <div class="stat-sub">Zilizopanda router</div>
                <i class="fa-brands fa-connectdevelop stat-icon"></i>
            </div>
        </div>

        <section class="panel">
            <div class="panel-title">
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Vocha Mfumo</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn-primary" onclick="vmFunguaGenModal()"><i class="fa-solid fa-plus"></i> Generate Vocha</button>
                    <button class="btn-ghost-blue" onclick="vmPrintSelected()"><i class="fa-solid fa-print"></i> Print Zilizochaguliwa</button>
                    <button class="btn-danger" onclick="vmFutaZilizochaguliwa()" id="vm-futa-btn" style="display:none;"><i class="fa-solid fa-trash-can"></i> Futa Zilizochaguliwa</button>
                </div>
            </div>
            <div class="search-bar" style="margin-bottom:16px;">
                <input type="text" class="search-input" id="vmSearch" placeholder="Tafuta code au namba ya simu..." oninput="vmFilter()">
                <select class="filter-select" id="vmFilterPkg" onchange="vmFilter()">
                    <option value="">Vifurushi Vyote</option>
                    <option value="siku">Siku</option>
                    <option value="wiki">Wiki</option>
                    <option value="mwezi">Mwezi</option>
                </select>
                <select class="filter-select" id="vmFilterStatus" onchange="vmFilter()">
                    <option value="">Hali Zote</option>
                    <option value="unused">Haijatumika</option>
                    <option value="used">Imetumika</option>
                    <option value="expired">Imeisha</option>
                </select>
                <select class="filter-select" id="vmFilterSync" onchange="vmFilter()">
                    <option value="">Sync Zote</option>
                    <option value="1">MikroTik ✓</option>
                    <option value="0">Haijapanda</option>
                </select>
            </div>
            <div class="table-wrap">
                <table id="vmTable">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="vmSelectAll" onchange="vmSelectAllFn(this)" style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px;"></th>
                            <th>#</th><th>Kodi ya Vocha</th><th>Kifurushi</th><th>Bei (Tsh)</th><th>Muda</th>
                            <th>Namba ya Simu</th><th>Hali</th><th>MikroTik</th><th>Tarehe</th><th>Vitendo</th>
                        </tr>
                    </thead>
                    <tbody id="vm-tbody">
                    <?php
                    $no = 1;
                    if ($vm_list && $vm_list->num_rows > 0):
                        while ($row = $vm_list->fetch_assoc()):
                            $pb = packageBadge($row['package_type'] ?? '');
                            $status_map = [
                                'unused'  => ['class'=>'unused', 'label'=>'Haijatumika'],
                                'used'    => ['class'=>'used',   'label'=>'Imetumika'],
                                'expired' => ['class'=>'expired','label'=>'Imeisha'],
                            ];
                            $st = $status_map[$row['status']] ?? ['class'=>'unused','label'=>$row['status']];
                            $synced_html = $row['mikrotik_synced']
                                ? '<span style="color:var(--accent);font-size:11px;font-weight:700;font-family:\'Space Mono\',monospace;">✓ SYNCED</span>'
                                : '<span style="color:var(--text-muted);font-size:11px;">— local</span>';
                    ?>
                    <tr id="vmr-<?php echo $row['id']; ?>"
                        data-code="<?php echo strtolower(htmlspecialchars($row['voucher_code']??'')); ?>"
                        data-phone="<?php echo strtolower(htmlspecialchars($row['phone']??'')); ?>"
                        data-pkg="<?php echo $pb['class']; ?>"
                        data-status="<?php echo htmlspecialchars($row['status']); ?>"
                        data-sync="<?php echo $row['mikrotik_synced']; ?>">
                        <td><input type="checkbox" class="vm-check" value="<?php echo $row['id']; ?>"
                               data-code="<?php echo htmlspecialchars($row['voucher_code']??''); ?>"
                               data-pkg="<?php echo htmlspecialchars($row['package_type']??''); ?>"
                               data-price="<?php echo $row['price']??0; ?>"
                               data-days="<?php echo $row['duration_days']??1; ?>"
                               onchange="vmUpdateSelected()" style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px;"></td>
                        <td style="color:var(--text-dim);font-family:'Space Mono',monospace;font-size:11px;"><?php echo $no++; ?></td>
                        <td>
                            <code style="font-size:13px;letter-spacing:1px;"><?php echo htmlspecialchars($row['voucher_code']??'—'); ?></code>
                            <button onclick="vmCopyCode('<?php echo htmlspecialchars($row['voucher_code']??'',ENT_QUOTES); ?>')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:11px;margin-left:4px;"><i class="fa-regular fa-copy"></i></button>
                        </td>
                        <td><span class="badge <?php echo $pb['class']; ?>"><?php echo $pb['label']; ?></span></td>
                        <td style="font-family:'Space Mono',monospace;font-size:12px;color:var(--accent);"><?php echo number_format($row['price']??0); ?></td>
                        <td style="font-size:12px;color:var(--text-dim);">Siku <?php echo $row['duration_days']??'—'; ?></td>
                        <td style="font-size:12px;"><?php echo $row['phone'] ? htmlspecialchars($row['phone']) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                        <td><span class="badge <?php echo $st['class']; ?>"><?php echo $st['label']; ?></span></td>
                        <td><?php echo $synced_html; ?></td>
                        <td style="font-size:11px;color:var(--text-dim);white-space:nowrap;"><?php echo date('d M Y, H:i',strtotime($row['created_at'])); ?></td>
                        <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <?php if (!$row['phone']): ?>
                            <button class="btn-edit" style="font-size:10px;padding:4px 8px;" onclick="vmAssignPhone(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['voucher_code']??'',ENT_QUOTES); ?>')"><i class="fa-solid fa-user-plus"></i> Assign</button>
                            <?php endif; ?>
                            <button class="btn-ghost-red" onclick="vmFutaMoja(<?php echo $row['id']; ?>)"><i class="fa-solid fa-trash-can"></i></button>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr id="vm-empty">
                        <td colspan="11">
                            <div style="text-align:center;padding:50px 20px;color:var(--text-dim);">
                                <i class="fa-solid fa-ticket-simple" style="font-size:48px;opacity:0.15;display:block;margin-bottom:16px;"></i>
                                <div style="font-size:14px;font-weight:600;margin-bottom:6px;">Hakuna vocha bado</div>
                                <div style="font-size:12px;opacity:0.6;">Bonyeza "Generate Vocha" kuanza</div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap;gap:8px;">
                <span style="font-family:'Space Mono',monospace;font-size:12px;color:var(--text-dim);">Inaonyesha: <strong id="vm-visible"><?php echo $vm_stats['total']; ?></strong> vocha</span>
                <span id="vm-sel-count" style="font-size:12px;color:var(--accent2);display:none;"><i class="fa-solid fa-check-double"></i> <strong id="vm-sel-num">0</strong> zimechaguliwa</span>
            </div>
        </section>
    </div><!-- END #vocha -->


    <!-- ══ SECTION 3: VOCHA ZILIZOTUMIKA ══ -->
    <div id="voucher-used" class="dashboard-section">
        <div class="vu-cards">
            <div class="stat-card c1">
                <div class="stat-label">Jumla Zilizotumika</div>
                <div class="stat-value"><?php echo number_format($vu_total); ?></div>
                <div class="stat-sub">Vocha zote za used</div>
                <i class="fa-solid fa-circle-check stat-icon"></i>
            </div>
            <div class="stat-card c2">
                <div class="stat-label">Zilizotumika Leo</div>
                <div class="stat-value"><?php echo number_format($vu_leo); ?></div>
                <div class="stat-sub">Tarehe ya leo</div>
                <i class="fa-solid fa-calendar-day stat-icon"></i>
            </div>
            <div class="stat-card c3">
                <div class="stat-label">Mapato ya Used</div>
                <div class="stat-value"><?php echo number_format($vu_mapato); ?></div>
                <div class="stat-sub">Tsh · Jumla</div>
                <i class="fa-solid fa-coins stat-icon"></i>
            </div>
            <div class="stat-card c4">
                <div class="stat-label">Bado Active</div>
                <div class="stat-value"><?php echo number_format($vu_active); ?></div>
                <div class="stat-sub">Hazijaisha muda</div>
                <i class="fa-solid fa-wifi stat-icon"></i>
            </div>
        </div>
        <section class="panel">
            <div class="panel-title"><h3><i class="fa-solid fa-list-check"></i> Orodha ya Vocha Zilizotumika</h3></div>
            <div class="vu-toolbar">
                <div class="search-bar" style="margin-bottom:0;flex:1;">
                    <input type="text" class="search-input" id="vuSearch" placeholder="Saka namba ya simu au kodi..." oninput="vuFilter()">
                    <select class="filter-select" id="vuFilterPkg" onchange="vuFilter()">
                        <option value="">Vifurushi Vyote</option><option value="siku">Siku</option><option value="wiki">Wiki</option><option value="mwezi">Mwezi</option>
                    </select>
                    <select class="filter-select" id="vuFilterExp" onchange="vuFilter()">
                        <option value="">Hali Zote</option><option value="active">Bado Active</option><option value="expired">Zimeisha</option>
                    </select>
                </div>
                <div class="vu-selected-info" id="vuSelectedInfo">
                    <span id="vuSelNum">0</span> zimechaguliwa
                    <button class="btn-danger" onclick="vuFutaNyingi()"><i class="fa-solid fa-trash-can"></i> Futa</button>
                </div>
            </div>
            <div class="table-wrap">
                <table id="vuTable">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="vuSelectAll" onchange="vuSelectAll(this)" style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px;"></th>
                            <th>#</th><th>Namba ya Simu</th><th>Kifurushi</th><th>Kodi ya Vocha</th>
                            <th>Bei (Tsh)</th><th>Muamala</th><th>Ilianzishwa</th><th>Inaisha</th><th>Hali</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="vu-tbody">
                    <?php
                    $no=1;
                    if($vu_query&&mysqli_num_rows($vu_query)>0):
                        while($row=mysqli_fetch_assoc($vu_query)):
                            $b=packageBadge($row['package_type']??'');
                            $et=strtotime($row['expiry_date']??'');
                            $df=$et-time();
                            if($et&&$df>86400){$ecls='exp-ok';$eico='✅';}
                            elseif($et&&$df>0){$ecls='exp-soon';$eico='⚠️';}
                            else{$ecls='exp-expired';$eico='❌';}
                            $elabel=$et?date('d M Y, H:i',$et):'—';
                            $clabel=date('d M Y, H:i',strtotime($row['created_at']));
                            $exp_data=($df>0)?'active':'expired';
                    ?>
                    <tr id="vur-<?php echo $row['id']; ?>"
                        data-phone="<?php echo strtolower(htmlspecialchars($row['phone']??'')); ?>"
                        data-code="<?php echo strtolower(htmlspecialchars($row['voucher_code']??'')); ?>"
                        data-pkg="<?php echo $b['class']; ?>"
                        data-exp="<?php echo $exp_data; ?>">
                        <td><input type="checkbox" class="vu-check" value="<?php echo $row['id']; ?>" onchange="vuUpdateSelected()" style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px;"></td>
                        <td style="color:var(--text-dim);font-family:'Space Mono',monospace;font-size:11px;"><?php echo $no++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['phone']??'—'); ?></strong></td>
                        <td><span class="badge <?php echo $b['class']; ?>"><?php echo $b['label']; ?></span></td>
                        <td><code><?php echo htmlspecialchars($row['voucher_code']??'—'); ?></code></td>
                        <td style="font-family:'Space Mono',monospace;font-size:12px;color:var(--accent);"><?php echo number_format($row['price']??0); ?></td>
                        <td style="font-size:12px;color:var(--text-dim);"><?php echo htmlspecialchars($row['payment_method']??'—'); ?></td>
                        <td style="font-size:11px;color:var(--text-dim);white-space:nowrap;"><?php echo $clabel; ?></td>
                        <td style="white-space:nowrap;"><span class="<?php echo $ecls; ?>"><?php echo $eico.' '.$elabel; ?></span></td>
                        <td><span class="badge used">USED</span></td>
                        <td><button class="btn-ghost-red" onclick="vuFutaMoja(<?php echo $row['id']; ?>)"><i class="fa-solid fa-trash-can"></i></button></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr id="vu-empty-row"><td colspan="11"><div style="text-align:center;padding:40px;color:var(--text-dim);"><i class="fa-solid fa-ticket-simple" style="font-size:36px;opacity:0.2;display:block;margin-bottom:12px;"></i>Hakuna vocha zilizotumika kwa sasa.</div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;flex-wrap:wrap;gap:8px;">
                <span class="vu-records">Zinaonyeshwa: <strong id="vuVisible"><?php echo $vu_total; ?></strong></span>
                <span style="color:var(--text-muted);font-size:11px;font-family:'Space Mono',monospace;"><?php echo htmlspecialchars($_SESSION['username']??''); ?> · Data yako binafsi</span>
            </div>
        </section>
    </div><!-- END #voucher-used -->


    <!-- ══ SECTION 4: MIPANGILIO ══ -->
    <div id="mipangilio" class="dashboard-section">

        <!-- 1) BEI ZA VIFURUSHI (CRUD) -->
        <section class="panel">
            <div class="panel-title">
                <h3><i class="fa-solid fa-tags"></i> Bei za Vifurushi Vyangu</h3>
                <button class="btn-primary" onclick="mpFunguaAddTariff()"><i class="fa-solid fa-plus"></i> Tengeza Kifurushi</button>
            </div>
            <div class="tariff-grid" id="mp-tariff-grid">
            <?php if ($mp_tariffs->num_rows > 0): ?>
                <?php while ($row = $mp_tariffs->fetch_assoc()):
                    $rangi='o'; $icon='fa-calendar-plus';
                    if(stripos($row['package_type'],'siku')!==false||stripos($row['package_type'],'daily')!==false){$rangi='g';$icon='fa-clock';}
                    elseif(stripos($row['package_type'],'wiki')!==false||stripos($row['package_type'],'weekly')!==false){$rangi='b';$icon='fa-calendar-days';}
                ?>
                    <div class="tariff-card" id="mp-tariff-<?php echo $row['id']; ?>">
                        <div class="tariff-top">
                            <div class="tariff-ico <?php echo $rangi; ?>"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                            <div>
                                <div class="tariff-name"><?php echo htmlspecialchars($row['package_type']); ?></div>
                                <div class="tariff-meta">Tsh <?php echo number_format($row['price']); ?> · Siku <?php echo $row['duration_days']; ?></div>
                            </div>
                        </div>
                        <div class="tariff-bottom">
                            <span class="speed-tag <?php echo $rangi; ?>"><?php echo htmlspecialchars($row['speed']); ?></span>
                            <div style="display:flex;gap:6px;">
                                <button class="btn-edit" onclick='mpFunguaEditTariff(<?php echo json_encode($row); ?>)'><i class="fa-solid fa-pen"></i></button>
                                <button class="btn-ghost-red" onclick="mpFutaTariff(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['package_type'],ENT_QUOTES); ?>')"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="empty-msg" id="mp-empty-tariffs">Hujaongeza kifurushi chochote bado. Bonyeza "Tengeza Kifurushi" kuanza.</p>
            <?php endif; ?>
            </div>
        </section>

        <!-- 2) TAARIFA ZA AKAUNTI -->
        <section class="panel">
            <div class="panel-title"><h3><i class="fa-solid fa-user-gear"></i> Taarifa za Akaunti</h3></div>
            <form onsubmit="mpSaveAccount(event)">
                <div class="field-row-2">
                    <div>
                        <div class="modal-label">Username</div>
                        <input type="text" class="modal-input" value="<?php echo htmlspecialchars($account['username']); ?>" disabled style="opacity:0.5;cursor:not-allowed;">
                    </div>
                    <div>
                        <div class="modal-label">Tarehe ya Kujiunga</div>
                        <input type="text" class="modal-input" value="<?php echo date('d M Y', strtotime($account['created_at'])); ?>" disabled style="opacity:0.5;cursor:not-allowed;">
                    </div>
                </div>
                <div class="field-row-2">
                    <div>
                        <div class="modal-label">Email</div>
                        <input type="email" id="acc_email" class="modal-input" value="<?php echo htmlspecialchars($account['email']??''); ?>" placeholder="email@example.com">
                    </div>
                    <div>
                        <div class="modal-label">Namba ya Simu</div>
                        <input type="tel" id="acc_phone" class="modal-input" value="<?php echo htmlspecialchars($account['phone']??''); ?>" placeholder="0712 345 678">
                    </div>
                </div>
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Hifadhi Taarifa</button>
            </form>
            <div class="divider"></div>
            <h4 style="font-size:13px;font-weight:700;margin-bottom:14px;color:#fff;"><i class="fa-solid fa-lock" style="color:var(--accent3);"></i> Badilisha Password</h4>
            <form onsubmit="mpChangePassword(event)">
                <div class="field-row-2">
                    <div>
                        <div class="modal-label">Password Mpya</div>
                        <input type="password" id="new_password" class="modal-input" placeholder="••••••••" minlength="6" required>
                    </div>
                    <div>
                        <div class="modal-label">Thibitisha Password</div>
                        <input type="password" id="confirm_password" class="modal-input" placeholder="••••••••" minlength="6" required>
                    </div>
                </div>
                <button type="submit" class="btn-save"><i class="fa-solid fa-key"></i> Badilisha Password</button>
            </form>
        </section>

        <!-- 3) MIKROTIK STATUS (READ-ONLY) -->
        <section class="panel">
            <div class="panel-title"><h3><i class="fa-solid fa-tower-broadcast"></i> Hali ya MikroTik</h3></div>
            <div style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="width:44px;height:44px;border-radius:10px;background:rgba(7,247,147,0.12);display:grid;place-items:center;font-size:18px;color:var(--accent);flex-shrink:0;"><i class="fa-solid fa-wifi"></i></div>
                <div style="flex:1;min-width:200px;">
                    <?php if (!empty($mikrotik_info['mikrotik_ip'])): ?>
                        <div style="font-weight:700;font-size:14px;margin-bottom:4px;"><i class="fa-solid fa-circle" style="color:var(--accent);font-size:8px;"></i> Imeunganishwa</div>
                        <div style="font-size:12px;color:var(--text-dim);font-family:'Space Mono',monospace;">IP: <?php echo htmlspecialchars($mikrotik_info['mikrotik_ip']); ?></div>
                    <?php else: ?>
                        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:var(--accent3);"><i class="fa-solid fa-circle" style="color:var(--accent3);font-size:8px;"></i> Bado Hujaunganishwa</div>
                        <div style="font-size:12px;color:var(--text-dim);">Wasiliana na Msimamizi (Admin) kuunganisha MikroTik yako.</div>
                    <?php endif; ?>
                </div>
                <div style="background:rgba(255,255,255,0.05);border-radius:8px;padding:8px 14px;font-size:11px;color:var(--text-dim);"><i class="fa-solid fa-circle-info"></i> Mipangilio hii inasimamiwa na Msimamizi</div>
            </div>
        </section>

        <!-- 4) ALERT EMAIL -->
        <section class="panel">
            <div class="panel-title"><h3><i class="fa-solid fa-envelope"></i> Mipangilio ya Alert (Station Offline)</h3></div>
            <form onsubmit="mpSaveAlertSettings(event)">
                <div class="modal-label">Email ya Kupokea Alert</div>
                <input type="email" id="alert_email" class="modal-input" placeholder="wewe@gmail.com" value="<?php echo htmlspecialchars($account['alert_email'] ?? ''); ?>">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-dim);margin-bottom:18px;">
                    <input type="checkbox" id="notify_enabled" <?php echo ($account['notify_station_offline'] ?? 1) ? 'checked' : ''; ?> style="accent-color:var(--accent);width:16px;height:16px;">
                    Tuma email pale station inapokuwa OFFLINE au inaporudi ONLINE
                </label>
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Hifadhi Mipangilio</button>
            </form>
        </section>

        <!-- 5) IP WHITELIST -->
        <section class="panel">
            <div class="panel-title"><h3><i class="fa-solid fa-shield-halved"></i> IP Whitelist kwa MikroTik API</h3></div>
            <p style="font-size:12px;color:var(--text-dim);margin-bottom:14px;line-height:1.6;">Weka IP zinazoruhusiwa kufikia huduma yako (tenganisha kwa comma). Acha wazi kuruhusu IP yoyote.</p>
            <form onsubmit="mpSaveWhitelist(event)">
                <input type="text" id="allowed_ips" class="modal-input" placeholder="41.78.12.5, 102.213.44.10" value="<?php echo htmlspecialchars($mikrotik_info['allowed_ips'] ?? ''); ?>">
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Hifadhi Whitelist</button>
            </form>
        </section>

    </div><!-- END #mipangilio -->


    <footer class="footer">© 2026 Bin Waqas Wi-Fi System &nbsp;·&nbsp; Haki zote zimehifadhiwa</footer>
</main>
<!-- ══════════════════════════════════════
     MODALS
══════════════════════════════════════ -->

<!-- Modal: Hariri Kifurushi (Dashboard quick edit) -->
<div class="modal-overlay" id="tariffModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fa-solid fa-pen-to-square"></i> <span id="modalTitle">Hariri Kifurushi</span></h4>
            <button class="modal-close-x" onclick="fungaModal('tariffModal')">&times;</button>
        </div>
        <form onsubmit="saveTariffChanges(event)">
            <input type="hidden" id="edit_bando_id">
            <div class="modal-label">Bei Mpya (Tsh)</div>
            <input type="number" id="edit_price" class="modal-input" required min="0" placeholder="Ingiza bei mpya">
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="fungaModal('tariffModal')">Ghairi</button>
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Hifadhi</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Vocha ya Bure -->
<div class="modal-overlay" id="vochaModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fa-solid fa-gift"></i> Gawa Vocha ya Bure</h4>
            <button class="modal-close-x" onclick="fungaModal('vochaModal')">&times;</button>
        </div>
        <div class="modal-label">Namba ya Simu ya Mteja (hiari)</div>
        <input type="tel" id="vochaBurePhone" class="modal-input" placeholder="0712 345 678">
        <div class="modal-label">Chagua Aina ya Kifurushi</div>
        <select id="mudaWaBure" class="modal-select-field">
            <?php
            $t_stmt_bure = $conn->prepare("SELECT * FROM tariffs WHERE user_id=? ORDER BY duration_days ASC");
            $t_stmt_bure->bind_param("i", $my_id);
            $t_stmt_bure->execute();
            $t_res_bure = $t_stmt_bure->get_result();
            if ($t_res_bure->num_rows > 0): while ($t = $t_res_bure->fetch_assoc()): ?>
            <option value="<?php echo htmlspecialchars($t['package_type']); ?>">
                <?php echo htmlspecialchars(ucfirst($t['package_type'])); ?> — Siku <?php echo (int)$t['duration_days']; ?> (bei ya kawaida Tsh <?php echo number_format($t['price']); ?>)
            </option>
            <?php endwhile; else: ?>
            <option value="">-- Hujaweka vifurushi bado --</option>
            <?php endif; $t_stmt_bure->close(); ?>
        </select>
        <div style="display:flex;gap:10px;margin-bottom:6px;">
            <div style="flex:2;">
                <div class="modal-label">Muonekano wa Vocha</div>
                <select id="vochaBure-code-format" class="modal-select-field">
                    <option value="numeric">Namba tu</option>
                    <option value="alpha">Herufi tu</option>
                    <option value="alnum">Herufi + Namba</option>
                </select>
            </div>
            <div style="flex:1;">
                <div class="modal-label">Urefu</div>
                <select id="vochaBure-code-length" class="modal-select-field">
                    <option value="4">4</option>
                    <option value="5">5</option>
                    <option value="6" selected>6</option>
                    <option value="8">8</option>
                    <option value="10">10</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="fungaModal('vochaModal')">Ghairi</button>
            <button class="btn-save" id="vochaBureBtn" onclick="tengenezaVochaBure()"><i class="fa-solid fa-sparkles"></i> Tengeneza</button>
        </div>
    </div>
</div>

<!-- Modal: Generate Vocha -->
<div class="modal-overlay" id="vmGenModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h4><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Vocha</h4>
            <button class="modal-close-x" onclick="fungaModal('vmGenModal')">&times;</button>
        </div>
        <div style="display:flex;gap:10px;margin-bottom:16px;">
            <div style="flex:2;">
                <div class="modal-label">Muonekano wa Vocha</div>
                <select id="vm-code-format" class="modal-select-field" onchange="vmUpdateCodePreview()">
                    <option value="numeric">Namba tu (mfano 483920)</option>
                    <option value="alpha">Herufi tu (mfano KDJQXM)</option>
                    <option value="alnum">Herufi + Namba (mfano A3K9Q1)</option>
                </select>
            </div>
            <div style="flex:1;">
                <div class="modal-label">Urefu</div>
                <select id="vm-code-length" class="modal-select-field" onchange="vmUpdateCodePreview()">
                    <option value="4">4</option>
                    <option value="5">5</option>
                    <option value="6" selected>6</option>
                    <option value="8">8</option>
                    <option value="10">10</option>
                </select>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;font-size:12px;color:var(--text-dim);">
            <i class="fa-solid fa-eye"></i> Mfano wa vocha: <span id="vm-code-preview" style="font-family:'Space Mono',monospace;font-weight:700;color:var(--accent);letter-spacing:2px;">483920</span>
        </div>
        <div style="display:flex;gap:0;margin-bottom:20px;background:rgba(255,255,255,0.06);border-radius:10px;padding:4px;">
            <button class="vm-tab-btn active" id="tab-batch" onclick="vmSwitchTab('batch')" style="flex:1;padding:9px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;transition:all 0.2s;"><i class="fa-solid fa-layer-group"></i> Batch (Nyingi)</button>
            <button class="vm-tab-btn" id="tab-single" onclick="vmSwitchTab('single')" style="flex:1;padding:9px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;transition:all 0.2s;"><i class="fa-solid fa-user"></i> Moja (na Simu)</button>
        </div>
        <div id="vm-tab-batch">
            <div class="modal-label">Kifurushi</div>
            <select id="vm-pkg-batch" class="modal-select-field" onchange="vmUpdateBatchPreview()">
                <?php
                $t_stmt4 = $conn->prepare("SELECT * FROM tariffs WHERE user_id=? ORDER BY price ASC");
                $t_stmt4->bind_param("i", $my_id);
                $t_stmt4->execute();
                $t_res4 = $t_stmt4->get_result();
                if ($t_res4->num_rows > 0): while ($t = $t_res4->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($t['package_type']); ?>" data-price="<?php echo $t['price']; ?>" data-days="<?php echo $t['duration_days']; ?>"><?php echo htmlspecialchars($t['package_type']); ?> — Tsh <?php echo number_format($t['price']); ?> (Siku <?php echo $t['duration_days']; ?>)</option>
                <?php endwhile; else: ?>
                <option value="daily" data-price="1000" data-days="1">Siku — Tsh 1,000</option>
                <option value="weekly" data-price="5000" data-days="7">Wiki — Tsh 5,000</option>
                <option value="monthly" data-price="15000" data-days="30">Mwezi — Tsh 15,000</option>
                <?php endif; ?>
            </select>
            <div class="modal-label">Idadi ya Vocha</div>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
                <input type="range" id="vm-qty" min="1" max="100" value="10" oninput="vmUpdateBatchPreview()" style="flex:1;accent-color:var(--accent);height:4px;">
                <div style="background:rgba(7,247,147,0.1);border:1px solid rgba(7,247,147,0.25);border-radius:8px;padding:6px 14px;font-family:'Space Mono',monospace;font-weight:700;color:var(--accent);min-width:48px;text-align:center;" id="vm-qty-display">10</div>
            </div>
            <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:14px;margin-bottom:18px;">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
                    <div><div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Idadi</div><div style="font-family:'Space Mono',monospace;font-weight:700;color:#fff;" id="prev-qty">10</div></div>
                    <div><div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Bei/Moja</div><div style="font-family:'Space Mono',monospace;font-weight:700;color:var(--accent);" id="prev-price">—</div></div>
                    <div><div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Jumla</div><div style="font-family:'Space Mono',monospace;font-weight:700;color:var(--accent2);" id="prev-total">—</div></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-dim);"><input type="checkbox" id="vm-sync-mikrotik" checked style="accent-color:var(--accent);width:16px;height:16px;"> Panda kwenye MikroTik Hotspot moja kwa moja</label>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="fungaModal('vmGenModal')">Ghairi</button>
                <button class="btn-save" id="vmGenBtn" onclick="vmGenerate('batch')"><i class="fa-solid fa-wand-magic-sparkles"></i> Tengeneza</button>
            </div>
        </div>
        <div id="vm-tab-single" style="display:none;">
            <div class="modal-label">Namba ya Simu ya Mteja</div>
            <input type="tel" id="vm-phone-single" class="modal-input" placeholder="0712 345 678">
            <div class="modal-label">Kifurushi</div>
            <select id="vm-pkg-single" class="modal-select-field">
                <?php
                $t_res4->data_seek(0);
                if ($t_res4->num_rows > 0): while ($t = $t_res4->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($t['package_type']); ?>" data-price="<?php echo $t['price']; ?>" data-days="<?php echo $t['duration_days']; ?>"><?php echo htmlspecialchars($t['package_type']); ?> — Tsh <?php echo number_format($t['price']); ?></option>
                <?php endwhile; else: ?>
                <option value="daily" data-price="1000" data-days="1">Siku</option>
                <option value="weekly" data-price="5000" data-days="7">Wiki</option>
                <option value="monthly" data-price="15000" data-days="30">Mwezi</option>
                <?php endif; ?>
            </select>
            <div class="modal-label">Njia ya Malipo</div>
            <select id="vm-payment" class="modal-select-field">
                <option value="M-Pesa">M-Pesa</option><option value="Tigo Pesa">Tigo Pesa</option><option value="Airtel Money">Airtel Money</option><option value="Halopesa">Halopesa</option><option value="Mkoba">Mkoba</option><option value="Cash">Cash</option><option value="Bure">Bure (Free)</option>
            </select>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-dim);"><input type="checkbox" id="vm-sync-single" checked style="accent-color:var(--accent);width:16px;height:16px;"> Panda kwenye MikroTik Hotspot</label>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="fungaModal('vmGenModal')">Ghairi</button>
                <button class="btn-save" id="vmSingleBtn" onclick="vmGenerate('single')"><i class="fa-solid fa-plus"></i> Tengeneza & Assign</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Assign Phone -->
<div class="modal-overlay" id="vmAssignModal">
    <div class="modal-content" style="max-width:380px;">
        <div class="modal-header">
            <h4><i class="fa-solid fa-user-plus"></i> Assign Namba ya Simu</h4>
            <button class="modal-close-x" onclick="fungaModal('vmAssignModal')">&times;</button>
        </div>
        <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-ticket" style="color:var(--accent);font-size:18px;"></i>
            <div><div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;">Kodi ya Vocha</div><div style="font-family:'Space Mono',monospace;font-weight:700;font-size:15px;color:var(--accent2);" id="vmAssignCode">—</div></div>
        </div>
        <input type="hidden" id="vmAssignId">
        <div class="modal-label">Namba ya Simu ya Mteja</div>
        <input type="tel" id="vmAssignPhone" class="modal-input" placeholder="0712 345 678">
        <div class="modal-label">Njia ya Malipo</div>
        <select id="vmAssignPayment" class="modal-select-field">
            <option value="M-Pesa">M-Pesa</option><option value="Tigo Pesa">Tigo Pesa</option><option value="Airtel Money">Airtel Money</option><option value="Cash">Cash</option><option value="Bure">Bure (Free)</option>
        </select>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="fungaModal('vmAssignModal')">Ghairi</button>
            <button class="btn-save" onclick="vmSaveAssign()"><i class="fa-solid fa-floppy-disk"></i> Hifadhi</button>
        </div>
    </div>
</div>

<!-- Modal: Renew -->
<div class="modal-overlay" id="renewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fa-solid fa-rotate"></i> Renew Kifurushi</h4>
            <button class="modal-close-x" onclick="fungaModal('renewModal')">&times;</button>
        </div>
        <div class="renew-info-box">
            <i class="fa-solid fa-user" style="color:var(--accent2);font-size:16px;"></i>
            <div><div style="font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;">Mteja</div><div style="font-weight:700;font-size:15px;" id="renew_phone_display">—</div></div>
            <div style="margin-left:auto;text-align:right;"><div style="font-size:11px;color:var(--text-dim);">Kifurushi cha awali</div><div id="renew_old_pkg" style="font-size:12px;font-weight:600;color:var(--accent3);">—</div></div>
        </div>
        <input type="hidden" id="renew_username">
        <div class="modal-label">Chagua Kifurushi Kipya</div>
        <select id="renew_package" class="modal-select-field">
            <?php
            $t_stmt5 = $conn->prepare("SELECT * FROM tariffs WHERE user_id=? ORDER BY price ASC");
            $t_stmt5->bind_param("i", $my_id);
            $t_stmt5->execute();
            $t_res5 = $t_stmt5->get_result();
            if($t_res5->num_rows>0): while($t=$t_res5->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($t['package_type']); ?>"><?php echo htmlspecialchars($t['package_type']); ?> — Tsh <?php echo number_format($t['price']); ?> (Siku <?php echo $t['duration_days']; ?>)</option>
            <?php endwhile; else: ?>
                <option value="daily">Siku</option><option value="weekly">Wiki</option><option value="monthly">Mwezi</option>
            <?php endif; ?>
        </select>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="fungaModal('renewModal')">Ghairi</button>
            <button class="btn-save" id="renewBtn" onclick="tekelezaRenew()"><i class="fa-solid fa-rotate"></i> Fanya Renew</button>
        </div>
    </div>
</div>

<!-- Modal: Confirm Delete -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-content" style="text-align:center;max-width:360px;">
        <div style="font-size:44px;margin-bottom:14px;">🗑️</div>
        <h4 style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:8px;">Una uhakika?</h4>
        <p id="confirmMsg" style="color:var(--text-dim);font-size:13px;margin-bottom:22px;line-height:1.6;">Unataka kufuta?</p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button class="btn-cancel" onclick="fungaModal('confirmModal')">Ghairi</button>
            <button class="btn-danger" id="confirmBtn" onclick="tekelezaFuta()"><i class="fa-solid fa-trash-can"></i> Ndiyo, Futa</button>
        </div>
    </div>
</div>

<!-- Modal: Ongeza/Hariri Kifurushi (Mipangilio) -->
<div class="modal-overlay" id="mpTariffModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fa-solid fa-tags"></i> <span id="mpTariffModalTitle">tengeza Kifurushi</span></h4>
            <button class="modal-close-x" onclick="fungaModal('mpTariffModal')">&times;</button>
        </div>
        <form onsubmit="mpSaveTariff(event)">
            <input type="hidden" id="mp_tariff_id">
            <div class="modal-label">Jina la Kifurushi</div>
            <input type="text" id="mp_tariff_name" class="modal-input" placeholder="Mfano: daily, weekly, monthly" required>
            <div class="modal-label">Bei (Tsh)</div>
            <input type="number" id="mp_tariff_price" class="modal-input" min="1" required>
            <div class="modal-label">Muda (Siku)</div>
            <input type="number" id="mp_tariff_days" class="modal-input" min="0" step="0.1" placeholder="Mfano: 1, 7, 30 " required>
            <div class="modal-label">Speed</div>
            <input type="text" id="mp_tariff_speed" class="modal-input" placeholder="Mfano: 6 Mbps" required>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="fungaModal('mpTariffModal')">Ghairi</button>
                <button type="submit" class="btn-save" id="mpTariffSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Hifadhi</button>
            </div>
        </form>
    </div>
</div>

<!-- Print Area -->
<div id="vmPrintArea" style="display:none;"></div>

<script>
// ══ TOAST ══
function showToast(msg, type='success') {
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const c=document.getElementById('toastContainer');
    const t=document.createElement('div');
    t.className='toast '+type;
    t.innerHTML=`<i class="fa-solid ${icons[type]||'fa-circle-info'} ti"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(()=>{t.style.animation='toastOut 0.4s ease forwards';setTimeout(()=>t.remove(),400);},6000);
}

// ══ SECTIONS ══
function onyeshaSection(id) {
    document.querySelectorAll('.dashboard-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('.sidebar-menu li').forEach(l=>l.classList.remove('active'));
    const s=document.getElementById(id);
    if(s) s.classList.add('active');
    const n=document.getElementById('nav-'+id);
    if(n) n.classList.add('active');
    const titles={'dashboard':'Usimamizi wa Wi-Fi','vocha':'Vocha Mfumo','voucher-used':'Vocha Zilizotumika','mipangilio':'Mipangilio'};
    document.getElementById('page-title').textContent=titles[id]||'Dashboard';
    if(window.innerWidth<769) document.getElementById('sidebar').classList.remove('active');
}

// ══ SIDEBAR ══
const sidebar=document.getElementById('sidebar');
function toggleSidebar(){sidebar.classList.toggle('active');}
document.addEventListener('click',function(e){
    if(!sidebar.contains(e.target)&&!e.target.closest('.menu-toggle')&&sidebar.classList.contains('active'))
        sidebar.classList.remove('active');
});

// ══ MODALS ══
function funguaModal(id){document.getElementById(id).classList.add('active');}
function fungaModal(id){document.getElementById(id).classList.remove('active');}

// ══ GRAFU ══
const ctx=document.getElementById('grafuYaMapato').getContext('2d');
new Chart(ctx,{
    type:'line',
    data:{
        labels:<?php echo json_encode($grafu_labels); ?>,
        datasets:[{
            label:'Mapato (Tsh)',
            data:<?php echo json_encode($grafu_data); ?>,
            backgroundColor:'rgba(7,247,147,0.06)',
            borderColor:'#07f793',
            borderWidth:2,tension:0.4,fill:true,
            pointBackgroundColor:'#07f793',
            pointBorderColor:'#0a1628',
            pointBorderWidth:2,pointRadius:4
        }]
    },
    options:{
        responsive:true,
        plugins:{legend:{display:false}},
        scales:{
            x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'rgba(255,255,255,0.45)',font:{size:10}}},
            y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'rgba(255,255,255,0.45)',font:{size:10}},beginAtZero:true}
        }
    }
});

// ══ HARIRI KIFURUSHI (dashboard quick edit) ══
function haririKifurushi(id,jina,bei){
    document.getElementById('modalTitle').innerText='Hariri: '+jina;
    document.getElementById('edit_bando_id').value=id;
    document.getElementById('edit_price').value=Math.round(bei);
    funguaModal('tariffModal');
}
function saveTariffChanges(e){
    e.preventDefault();
    const id=document.getElementById('edit_bando_id').value;
    const price=document.getElementById('edit_price').value;
    fetch('update_tariff.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}&price=${price}`})
    .then(r=>r.json())
    .then(d=>{
        if(d.status==='success'){fungaModal('tariffModal');showToast('Bei imebadilishwa! 🎉','success');setTimeout(()=>location.reload(),1500);}
        else showToast('Kuna shida: '+(d.message||'Imeshindikana'),'error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}

// ══ VOCHA YA BURE ══
function tengenezaVochaBure(){
    const pkg = document.getElementById('mudaWaBure').value;
    const phone = document.getElementById('vochaBurePhone').value.trim();

    if (!pkg) {
        showToast('Hujaweka vifurushi bado. Enda kwenye Mipangilio kuweka bei kwanza.', 'error');
        return;
    }

    const btn = document.getElementById('vochaBureBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inatengeneza...';

    const params = new URLSearchParams({
        mode: 'free',
        package: pkg,
        phone: phone,
        payment: 'Bure',
        sync_mikrotik: '1',
        code_format: document.getElementById('vochaBure-code-format').value,
        code_length: document.getElementById('vochaBure-code-length').value
    });

    fetch('generate_vouchers.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = orig;
        showToast(d.message || (d.status === 'success' ? 'Vocha imetengenezwa!' : 'Imeshindikana.'), d.status);
        if (d.status === 'success') {
            fungaModal('vochaModal');
            document.getElementById('vochaBurePhone').value = '';
            if (d.codes && d.codes.length > 0) {
                setTimeout(() => showToast('Namba ya vocha: ' + d.codes[0], 'info'), 600);
            }
            setTimeout(() => window.location.reload(), 2200);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = orig;
        showToast('Tatizo la mtandao.', 'error');
    });
}

// ══ FUTA VOCHA (shared delete logic) ══
let pendingDeleteId=null;
let _vmMultiDelete=null;
let _mpPendingDeleteTariff=null;
function futaVocha(id){
    pendingDeleteId=id;
    _vmMultiDelete=null;
    document.getElementById('confirmMsg').textContent='Unataka kufuta vocha hii? Kitendo hiki hakiwezi kurudishwa.';
    funguaModal('confirmModal');
}
function tekelezaFuta(){
    fungaModal('confirmModal');

    // ── Futa Tariff (Mipangilio) ──
    if (_mpPendingDeleteTariff !== null) {
        const tid = _mpPendingDeleteTariff;
        _mpPendingDeleteTariff = null;
        fetch('mp_delete_tariff.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${tid}`})
        .then(r=>r.json())
        .then(d=>{
            if (d.status === 'success') {
                const el = document.getElementById('mp-tariff-'+tid);
                if (el) { el.style.transition='opacity 0.3s, transform 0.3s'; el.style.opacity='0'; el.style.transform='scale(0.9)'; setTimeout(()=>el.remove(), 300); }
                showToast('Kifurushi kimefutwa.', 'info');
            } else {
                showToast(d.message || 'Imeshindikana kufuta.', 'error');
            }
        })
        .catch(()=>showToast('Tatizo la mtandao.','error'));
        return;
    }

    if(_vmMultiDelete&&_vmMultiDelete.length){
        const ids=[..._vmMultiDelete];
        _vmMultiDelete=null;
        let done=0;
        ids.forEach(id=>{
            fetch('delete_voucher.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}`})
            .then(r=>r.json()).then(d=>{
                if(d.status==='success'){
                    const row=document.getElementById('vmr-'+id)||document.getElementById('vur-'+id)||document.getElementById('v-'+id);
                    if(row){row.classList.add('removing');setTimeout(()=>row.remove(),420);}
                }
                done++;
                if(done===ids.length){showToast(`Vocha ${done} zimefutwa.`,'info');vmUpdateSelected();vuUpdateSelected();}
            });
        });
    } else {
        fetch('delete_voucher.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${pendingDeleteId}`})
        .then(r=>r.json())
        .then(d=>{
            if(d.status==='success'){
                const row=document.getElementById('vmr-'+pendingDeleteId)||document.getElementById('vur-'+pendingDeleteId)||document.getElementById('v-'+pendingDeleteId);
                if(row){row.classList.add('removing');setTimeout(()=>row.remove(),420);}
                showToast('Vocha imefutwa.','info');
            } else showToast('Imeshindikana kufuta.','error');
        })
        .catch(()=>showToast('Tatizo la mtandao.','error'));
    }
}

// ══ KATA INTERNET ══
function kataInternet(username){
    if(!confirm('Umuhakikisha unataka kumkata: '+username+'?')) return;
    fetch('kick_user.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`username=${encodeURIComponent(username)}`})
    .then(r=>r.json())
    .then(d=>{
        if(d.status==='success'){showToast(username+' amekatwa mtandao.','warning');setTimeout(()=>location.reload(),1500);}
        else showToast('Imeshindikana kumkata.','error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}

// ══ RENEW ══
function funguaRenewModal(phone,oldPkg){
    document.getElementById('renew_username').value=phone;
    document.getElementById('renew_phone_display').textContent=phone;
    document.getElementById('renew_old_pkg').textContent=oldPkg;
    funguaModal('renewModal');
}
function tekelezaRenew(){
    const username=document.getElementById('renew_username').value;
    const pkg=document.getElementById('renew_package').value;
    if(!username||!pkg){showToast('Tafadhali jaza taarifa zote.','error');return;}
    const btn=document.getElementById('renewBtn');
    const orig=btn.innerHTML;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Inafanya...';
    btn.disabled=true;
    fetch('renew.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`username=${encodeURIComponent(username)}&package=${encodeURIComponent(pkg)}`})
    .then(r=>r.json())
    .then(d=>{
        btn.innerHTML=orig;btn.disabled=false;
        if(d.status==='success'){fungaModal('renewModal');showToast(d.message,'success');setTimeout(()=>location.reload(),2000);}
        else showToast(d.message||'Imeshindikana kufanya renew.','error');
    })
    .catch(()=>{btn.innerHTML=orig;btn.disabled=false;showToast('Tatizo la mtandao.','error');});
}

// ══ SEARCH (main table) ══
function tafutaKwenyeJedwali(){
    const input=document.getElementById('searchBox').value.toLowerCase();
    const filter=document.getElementById('filterBox').value.toLowerCase();
    document.querySelectorAll('#vochaTable tbody tr').forEach(tr=>{
        const simu=(tr.cells[0]?.textContent||'').toLowerCase();
        const bando=(tr.cells[1]?.textContent||'').toLowerCase();
        const vocha=(tr.cells[2]?.textContent||'').toLowerCase();
        tr.style.display=(simu.includes(input)||vocha.includes(input))&&(!filter||bando.includes(filter))?'':'none';
    });
}

// ══ VOUCHER USED FILTER ══
function vuFilter(){
    const search=document.getElementById('vuSearch').value.toLowerCase();
    const pkg=document.getElementById('vuFilterPkg').value;
    const exp=document.getElementById('vuFilterExp').value;
    let visible=0;
    document.querySelectorAll('#vu-tbody tr[id^="vur-"]').forEach(tr=>{
        const mS=!search||tr.dataset.phone.includes(search)||tr.dataset.code.includes(search);
        const mP=!pkg||tr.dataset.pkg===pkg;
        const mE=!exp||tr.dataset.exp===exp;
        tr.style.display=(mS&&mP&&mE)?'':'none';
        if(mS&&mP&&mE) visible++;
    });
    document.getElementById('vuVisible').textContent=visible;
    const nr=document.getElementById('vu-no-results');
    const ee=document.getElementById('vu-empty-row');
    if(visible===0&&!ee){
        if(!nr){const tr=document.createElement('tr');tr.id='vu-no-results';tr.innerHTML='<td colspan="11"><div style="text-align:center;padding:30px;color:var(--text-dim);">Hakuna matokeo.</div></td>';document.getElementById('vu-tbody').appendChild(tr);}
    } else if(nr) nr.remove();
}
function vuSelectAll(cb){
    document.querySelectorAll('#vu-tbody tr[id^="vur-"]').forEach(tr=>{
        if(tr.style.display!=='none'){const c=tr.querySelector('.vu-check');if(c)c.checked=cb.checked;}
    });
    vuUpdateSelected();
}
function vuUpdateSelected(){
    const n=document.querySelectorAll('.vu-check:checked').length;
    const info=document.getElementById('vuSelectedInfo');
    info.classList.toggle('visible',n>0);
    document.getElementById('vuSelNum').textContent=n;
}
function vuFutaMoja(id){
    pendingDeleteId=id;
    _vmMultiDelete=null;
    document.getElementById('confirmMsg').textContent='Unataka kufuta vocha hii?';
    funguaModal('confirmModal');
}
function vuFutaNyingi(){
    const checks=Array.from(document.querySelectorAll('.vu-check:checked'));
    if(!checks.length)return;
    _vmMultiDelete=checks.map(c=>c.value);
    document.getElementById('confirmMsg').textContent=`Unataka kufuta vocha ${checks.length} zilizochaguliwa?`;
    funguaModal('confirmModal');
}

// ══ VM: TAB SWITCH ══
function vmSwitchTab(tab){
    document.getElementById('vm-tab-batch').style.display=tab==='batch'?'':'none';
    document.getElementById('vm-tab-single').style.display=tab==='single'?'':'none';
    document.getElementById('tab-batch').classList.toggle('active',tab==='batch');
    document.getElementById('tab-single').classList.toggle('active',tab==='single');
}
function vmFunguaGenModal(){ vmUpdateBatchPreview(); funguaModal('vmGenModal'); }
function vmUpdateBatchPreview(){
    const sel=document.getElementById('vm-pkg-batch');
    const opt=sel.options[sel.selectedIndex];
    const qty=parseInt(document.getElementById('vm-qty').value);
    const price=parseInt(opt?.dataset.price||0);
    document.getElementById('vm-qty-display').textContent=qty;
    document.getElementById('prev-qty').textContent=qty;
    document.getElementById('prev-price').textContent='Tsh '+price.toLocaleString();
    document.getElementById('prev-total').textContent='Tsh '+(qty*price).toLocaleString();
}
function vmUpdateCodePreview(){
    const format=document.getElementById('vm-code-format').value;
    const length=parseInt(document.getElementById('vm-code-length').value,10);
    const setsAndSamples={
        numeric:'0123456789',
        alpha:'ABCDEFGHJKLMNPQRSTUVWXYZ',
        alnum:'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    };
    const chars=setsAndSamples[format]||setsAndSamples.numeric;
    let sample='';
    for(let i=0;i<length;i++) sample+=chars[Math.floor(Math.random()*chars.length)];
    document.getElementById('vm-code-preview').textContent=sample;
}
document.addEventListener('DOMContentLoaded',vmUpdateCodePreview);
function vmGenerate(mode){
    const btn=document.getElementById(mode==='batch'?'vmGenBtn':'vmSingleBtn');
    const origHTML=btn.innerHTML;
    const codeFormat=document.getElementById('vm-code-format').value;
    const codeLength=document.getElementById('vm-code-length').value;
    let body=`mode=${mode}&code_format=${encodeURIComponent(codeFormat)}&code_length=${encodeURIComponent(codeLength)}`;
    if(mode==='batch'){
        const sel=document.getElementById('vm-pkg-batch');
        const opt=sel.options[sel.selectedIndex];
        body+=`&package=${encodeURIComponent(sel.value)}&price=${opt?.dataset.price||0}&days=${opt?.dataset.days||1}&qty=${document.getElementById('vm-qty').value}&sync_mikrotik=${document.getElementById('vm-sync-mikrotik').checked?1:0}`;
    } else {
        const phone=document.getElementById('vm-phone-single').value.trim();
        if(!phone){showToast('Ingiza namba ya simu.','error');return;}
        const sel=document.getElementById('vm-pkg-single');
        const opt=sel.options[sel.selectedIndex];
        body+=`&package=${encodeURIComponent(sel.value)}&price=${opt?.dataset.price||0}&days=${opt?.dataset.days||1}&phone=${encodeURIComponent(phone)}&payment=${encodeURIComponent(document.getElementById('vm-payment').value)}&sync_mikrotik=${document.getElementById('vm-sync-single').checked?1:0}`;
    }
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Inatengeneza...';
    btn.disabled=true;
    fetch('generate_vouchers.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body})
    .then(r=>r.json())
    .then(d=>{
        btn.innerHTML=origHTML;btn.disabled=false;
        if(d.status==='success'){fungaModal('vmGenModal');showToast(d.message||'Vocha zimetengenezwa! ✨','success');setTimeout(()=>location.reload(),1800);}
        else showToast(d.message||'Imeshindikana.','error');
    })
    .catch(()=>{btn.innerHTML=origHTML;btn.disabled=false;showToast('Tatizo la mtandao.','error');});
}
function vmAssignPhone(id,code){
    document.getElementById('vmAssignId').value=id;
    document.getElementById('vmAssignCode').textContent=code;
    document.getElementById('vmAssignPhone').value='';
    funguaModal('vmAssignModal');
}
function vmSaveAssign(){
    const id=document.getElementById('vmAssignId').value;
    const phone=document.getElementById('vmAssignPhone').value.trim();
    const payment=document.getElementById('vmAssignPayment').value;
    if(!phone){showToast('Ingiza namba ya simu.','error');return;}
    fetch('assign_voucher.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}&phone=${encodeURIComponent(phone)}&payment=${encodeURIComponent(payment)}`})
    .then(r=>r.json())
    .then(d=>{
        if(d.status==='success'){fungaModal('vmAssignModal');showToast('Vocha imeassignwa kwa '+phone+'! ✅','success');setTimeout(()=>location.reload(),1500);}
        else showToast(d.message||'Imeshindikana.','error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}
function vmFilter(){
    const search=document.getElementById('vmSearch').value.toLowerCase();
    const pkg=document.getElementById('vmFilterPkg').value;
    const status=document.getElementById('vmFilterStatus').value;
    const sync=document.getElementById('vmFilterSync').value;
    let visible=0;
    document.querySelectorAll('#vm-tbody tr[id^="vmr-"]').forEach(tr=>{
        const mS=!search||tr.dataset.code.includes(search)||tr.dataset.phone.includes(search);
        const mP=!pkg||tr.dataset.pkg===pkg;
        const mSt=!status||tr.dataset.status===status;
        const mSy=sync===''||tr.dataset.sync===sync;
        tr.style.display=(mS&&mP&&mSt&&mSy)?'':'none';
        if(mS&&mP&&mSt&&mSy)visible++;
    });
    document.getElementById('vm-visible').textContent=visible;
    const ex=document.getElementById('vm-no-results');
    const ee=document.getElementById('vm-empty');
    if(visible===0&&!ee){
        if(!ex){const tr=document.createElement('tr');tr.id='vm-no-results';tr.innerHTML='<td colspan="11"><div style="text-align:center;padding:30px;color:var(--text-dim);">Hakuna matokeo yanayolingana.</div></td>';document.getElementById('vm-tbody').appendChild(tr);}
    } else if(ex) ex.remove();
}
function vmSelectAllFn(cb){
    document.querySelectorAll('#vm-tbody tr[id^="vmr-"]').forEach(tr=>{
        if(tr.style.display!=='none'){const c=tr.querySelector('.vm-check');if(c)c.checked=cb.checked;}
    });
    vmUpdateSelected();
}
function vmUpdateSelected(){
    const n=document.querySelectorAll('.vm-check:checked').length;
    document.getElementById('vm-sel-count').style.display=n>0?'':'none';
    document.getElementById('vm-futa-btn').style.display=n>0?'':'none';
    document.getElementById('vm-sel-num').textContent=n;
}
function vmCopyCode(code){
    navigator.clipboard.writeText(code).then(()=>showToast('Kodi imenakiliwa! 📋','info'));
}
function vmFutaMoja(id){
    pendingDeleteId=id;
    _vmMultiDelete=null;
    document.getElementById('confirmMsg').textContent='Unataka kufuta vocha hii? Kumbuka kuifuta pia kwenye MikroTik kama inahitajika.';
    funguaModal('confirmModal');
}
function vmFutaZilizochaguliwa(){
    const checks=Array.from(document.querySelectorAll('.vm-check:checked'));
    if(!checks.length)return;
    _vmMultiDelete=checks.map(c=>c.value);
    document.getElementById('confirmMsg').textContent=`Unataka kufuta vocha ${checks.length} zilizochaguliwa?`;
    funguaModal('confirmModal');
}
function vmPrintSelected(){
    const checks=Array.from(document.querySelectorAll('.vm-check:checked'));
    if(!checks.length){showToast('Chagua vocha angalau moja kwanza.','warning');return;}
    const cards=checks.map(c=>{
        const code=c.dataset.code||'—';
        const pkg=c.dataset.pkg||'—';
        const price=parseInt(c.dataset.price)||0;
        const days=c.dataset.days||'—';
        return `<div class="vm-print-card">
            <div class="pc-brand">🛜 Tech 5G Wi-Fi </div>
            <hr class="pc-line"> 
            <div class="pc-pkg">${pkg.toUpperCase()} · Siku ${days}</div>
            <div class="pc-code">${code}</div>
            <div class="pc-price">Tsh ${price.toLocaleString()}</div>
            <hr class="pc-line">
            <div class="pc-wifi">Tumia kwenye Wi-Fi hotspot · Asante!</div>
        </div>`;
    }).join('');

    // ── Fungua dirisha jipya kuprinti (badala ya @media print CSS isiyoaminika ── 
    const printWin = window.open('', '_blank', 'width=900,height=700');
    if (!printWin) {
        showToast('Browser imezuia pop-up. Ruhusu pop-ups kwenye site hii kisha jaribu tena.', 'error');
        return;
    }
    printWin.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Vocha - Print</title>
<style>
    body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:15px;}
    .vm-print-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
    .vm-print-card{border:2px dashed #333;border-radius:8px;padding:14px;text-align:center;page-break-inside:avoid;}
    .vm-print-card .pc-brand{font-size:11px;font-weight:700;color:#000;margin-bottom:8px;letter-spacing:1px;}
    .vm-print-card .pc-code{font-family:'Courier New',monospace;font-size:22px;font-weight:900;color:#000;letter-spacing:3px;margin:10px 0;border:1px solid #ddd;padding:8px;border-radius:4px;background:#f9f9f9;}
    .vm-print-card .pc-pkg{font-size:12px;color:#555;margin-bottom:4px;}
    .vm-print-card .pc-price{font-size:16px;font-weight:700;color:#000;}
    .vm-print-card .pc-days{font-size:11px;color:#777;margin-top:4px;}
    .vm-print-card .pc-line{border:none;border-top:1px dashed #ccc;margin:8px 0;}
    .vm-print-card .pc-wifi{font-size:10px;color:#999;}
    @media print{ @page{ margin:10mm; } }
</style>
</head>
<body>
    <div class="vm-print-grid">${cards}</div>
    <script>
        window.onload = function(){
            window.print();
            window.onafterprint = function(){ window.close(); };
        };
    <\/script>
</body>
</html>`);
    printWin.document.close();
}

// ══════════════════════════════════════
// MIPANGILIO — TARIFFS CRUD
// ══════════════════════════════════════
function mpFunguaAddTariff(){
    document.getElementById('mpTariffModalTitle').textContent = 'Ongeza Kifurushi';
    document.getElementById('mp_tariff_id').value = '';
    document.getElementById('mp_tariff_name').value = '';
    document.getElementById('mp_tariff_price').value = '';
    document.getElementById('mp_tariff_days').value = '';
    document.getElementById('mp_tariff_speed').value = '';
    funguaModal('mpTariffModal');
}
function mpFunguaEditTariff(data){
    document.getElementById('mpTariffModalTitle').textContent = 'Hariri: ' + data.package_type;
    document.getElementById('mp_tariff_id').value = data.id;
    document.getElementById('mp_tariff_name').value = data.package_type;
    document.getElementById('mp_tariff_price').value = Math.round(data.price);
    document.getElementById('mp_tariff_days').value = data.duration_days;
    document.getElementById('mp_tariff_speed').value = data.speed;
    funguaModal('mpTariffModal');
}
function mpSaveTariff(e){
    e.preventDefault();
    const btn = document.getElementById('mpTariffSaveBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inahifadhi...';
    btn.disabled = true;
    const body = new URLSearchParams({
        id: document.getElementById('mp_tariff_id').value,
        package_type: document.getElementById('mp_tariff_name').value,
        price: document.getElementById('mp_tariff_price').value,
        duration_days: document.getElementById('mp_tariff_days').value,
        speed: document.getElementById('mp_tariff_speed').value
    });
    fetch('mp_save_tariff.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body})
    .then(r=>r.json())
    .then(d=>{
        btn.innerHTML = orig; btn.disabled = false;
        if (d.status === 'success') {
            fungaModal('mpTariffModal');
            showToast('Kifurushi kimehifadhiwa! 🎉', 'success');
            setTimeout(()=>location.reload(), 1200);
        } else {
            showToast(d.message || 'Imeshindikana.', 'error');
        }
    })
    .catch(()=>{btn.innerHTML=orig;btn.disabled=false;showToast('Tatizo la mtandao.','error');});
}
function mpFutaTariff(id, name){
    _mpPendingDeleteTariff = id;
    document.getElementById('confirmMsg').textContent = `Una uhakika unataka kufuta kifurushi "${name}"? Vocha zilizopo hazitaguswa, lakini hutaweza kutengeneza vocha mpya na kifurushi hiki.`;
    funguaModal('confirmModal');
}


// ══════════════════════════════════════
// MIPANGILIO — TAARIFA ZA AKAUNTI
// ══════════════════════════════════════
function mpSaveAccount(e){
    e.preventDefault();
    const email = document.getElementById('acc_email').value;
    const phone = document.getElementById('acc_phone').value;
    fetch('mp_save_account.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`email=${encodeURIComponent(email)}&phone=${encodeURIComponent(phone)}`})
    .then(r=>r.json())
    .then(d=>{
        if (d.status === 'success') showToast('Taarifa zimehifadhiwa! ✅', 'success');
        else showToast(d.message || 'Imeshindikana.', 'error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}
function mpChangePassword(e){
    e.preventDefault();
    const p1 = document.getElementById('new_password').value;
    const p2 = document.getElementById('confirm_password').value;
    if (p1 !== p2) { showToast('Password hazifanani.', 'error'); return; }
    if (p1.length < 6) { showToast('Password lazima iwe na herufi 6 au zaidi.', 'error'); return; }
    fetch('mp_change_password.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`password=${encodeURIComponent(p1)}`})
    .then(r=>r.json())
    .then(d=>{
        if (d.status === 'success') {
            showToast('Password imebadilishwa! ✅', 'success');
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
        } else showToast(d.message || 'Imeshindikana.', 'error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}

// ══════════════════════════════════════
// MIPANGILIO — ALERT EMAIL & WHITELIST
// ══════════════════════════════════════
function mpSaveAlertSettings(e){
    e.preventDefault();
    const email = document.getElementById('alert_email').value;
    const enabled = document.getElementById('notify_enabled').checked ? 1 : 0;
    fetch('save_alert_settings.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`email=${encodeURIComponent(email)}&enabled=${enabled}`})
    .then(r=>r.json())
    .then(d=>{
        if (d.status === 'success') showToast('Mipangilio imehifadhiwa! ✅', 'success');
        else showToast(d.message || 'Imeshindikana.', 'error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}
function mpSaveWhitelist(e){
    e.preventDefault();
    const ips = document.getElementById('allowed_ips').value;
    fetch('save_whitelist.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`ips=${encodeURIComponent(ips)}`})
    .then(r=>r.json())
    .then(d=>{
        if (d.status === 'success') showToast('Whitelist imehifadhiwa! ✅', 'success');
        else showToast(d.message || 'Imeshindikana.', 'error');
    })
    .catch(()=>showToast('Tatizo la mtandao.','error'));
}
</script>

</body>
</html>