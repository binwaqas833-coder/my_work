<?php
session_start();
include 'auth_check.php';
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Huna ruhusa ya kufikia ukurasa huu. 🚫'];
    header("Location: index.php");
    exit();
}

// ── AJAX: FUTA ANTENA ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_station') {
    header('Content-Type: application/json');
    $station_id = (int)$_POST['station_id'];
    $del = $conn->prepare("DELETE FROM access_points WHERE id=?");
    $del->bind_param("i", $station_id);
    $del->execute();
    echo json_encode(['status' => $del->affected_rows > 0 ? 'success' : 'error', 'msg' => $del->affected_rows > 0 ? 'Antena imefutwa.' : 'Imeshindikana kufuta.']);
    exit();
}

// ── HIFADHI / SASISHA ANTENA (STATION) ──
if (isset($_POST['save_station'])) {
    $reseller_user_id = $conn->real_escape_string($_POST['user_id']);
    $jina_la_ap       = $conn->real_escape_string($_POST['jina_la_ap']);
    $ip_address       = $conn->real_escape_string($_POST['ip_address']);
    $eneo_ilipo       = $conn->real_escape_string($_POST['eneo_ilipo'] ?? '');
    $edit_station_id  = (int)($_POST['edit_station_id'] ?? 0);
    $tarehe           = date('Y-m-d H:i:s');

    if ($edit_station_id > 0) {
        // ── SASISHA ANTENA ILIYOPO (mfano: router/antena imebadilishwa) ──
        $sql_station = "UPDATE access_points
                         SET jina_la_ap='$jina_la_ap', ip_address='$ip_address', eneo_ilipo='$eneo_ilipo'
                         WHERE id=$edit_station_id AND user_id='$reseller_user_id'";
        $success_msg = "Antena ($jina_la_ap) imesasishwa kwa mafanikio! ✏️";
    } else {
        // ── ONGEZA ANTENA MPYA ──
        $sql_station = "INSERT INTO access_points (user_id, jina_la_ap, ip_address, eneo_ilipo, tarehe_ya_kufungwa, status)
                         VALUES ('$reseller_user_id', '$jina_la_ap', '$ip_address', '$eneo_ilipo', '$tarehe', 'offline')";
        $success_msg = "Antena ($jina_la_ap) imehifadhiwa kwa mafanikio! 🎉";
    }

    if ($conn->query($sql_station) === TRUE) {
        $_SESSION['toast'] = ['type' => 'success', 'msg' => $success_msg];
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Hitilafu ya Database: ' . $conn->error];
    }
    header("Location:dashboard_chaguo.php");
    exit();
}

// ── WATEJA + ANTENA ZAO ──
$current_admin = $conn->real_escape_string($_SESSION['username']);
$res = mysqli_query($conn, "SELECT id, username, role FROM users WHERE role='user' OR username='$current_admin' ORDER BY username ASC");
$wateja = [];
while ($row = mysqli_fetch_assoc($res)) {
    // Vuta antena za kila user
    $uid = (int)$row['id'];
    $st  = mysqli_query($conn, "SELECT id, jina_la_ap, ip_address, eneo_ilipo, status FROM access_points WHERE user_id=$uid ORDER BY id ASC");
    $row['stations'] = [];
    while ($s = mysqli_fetch_assoc($st)) $row['stations'][] = $s;

    // Vuta mipangilio ya MikroTik iliyopo (kama ipo) - kwa ajili ya kuonyesha/edit
    $mt_row = mysqli_query($conn, "SELECT mikrotik_ip, api_user, api_pass FROM mikrotik_configs WHERE user_id=$uid LIMIT 1");
    $row['mikrotik'] = ($mt_row && mysqli_num_rows($mt_row) > 0) ? mysqli_fetch_assoc($mt_row) : null;

    $wateja[] = $row;
}

// ── STATS ──
$total_users    = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE role='user'"))['c'] ?? 0;
$total_stations = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM access_points"))['c'] ?? 0;
$total_mikrotik = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM mikrotik_configs"))['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard · 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --surface: rgba(255,255,255,0.15);
    --surface2: rgba(255,255,255,0.08);
    --border: rgba(255,255,255,0.30);
    --border2: rgba(255,255,255,0.15);
    --accent:  #07f793;
    --accent2: #3fc7fd;
    --accent3: #ff6b35;
    --text: #fff;
    --text-dim: rgba(255,255,255,0.65);
    --text-muted: rgba(255,255,255,0.35);
    --red: #ff3d57;
    --radius: 14px;
    --blur: blur(18px);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background-image:linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg);background-size:cover;background-position:center;background-attachment:fixed;color:var(--text);min-height:100vh;padding:24px}

body::before{content:'';position:fixed;inset:0;background:rgba(0,0,0,0.35);pointer-events:none;z-index:0}
.wrapper{position:relative;z-index:1;max-width:1200px;margin:0 auto}

/* ── HEADER ── */
.page-header{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border);border-radius:var(--radius);padding:20px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;position:relative;overflow:hidden}
.page-header::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.4),transparent)}
.header-left h2{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px}
.header-left h2 i{color:var(--accent)}
.header-left p{font-size:12px;color:var(--text-dim);margin-top:3px}
.header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-blue{background:rgba(63,199,253,0.15);color:var(--accent2);border:1px solid rgba(63,199,253,0.30)}
.btn-blue:hover{background:rgba(63,199,253,0.25)}
.btn-red{background:rgba(255,61,87,0.15);color:var(--red);border:1px solid rgba(255,61,87,0.30)}
.btn-red:hover{background:rgba(255,61,87,0.25)}
.btn-gray{background:rgba(255,255,255,0.10);color:var(--text-dim);border:1px solid var(--border2)}
.btn-gray:hover{background:rgba(255,255,255,0.18);color:#fff}
.btn-green{background:rgba(7,247,147,0.15);color:var(--accent);border:1px solid rgba(7,247,147,0.30)}
.btn-green:hover{background:rgba(7,247,147,0.25)}
.btn-sm{padding:6px 12px;font-size:11px;border-radius:7px}

/* ── PANEL ── */
.panel{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:24px;margin-bottom:24px;position:relative;overflow:hidden}
.panel::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.3),transparent)}
.panel-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.panel-title h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.panel-title h3 i{color:var(--accent)}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:18px;position:relative;overflow:hidden;transition:transform 0.2s}
.stat-card:hover{transform:translateY(-2px);border-color:var(--border)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.c1::before{background:linear-gradient(90deg,var(--accent),transparent)}
.stat-card.c2::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.stat-card.c3::before{background:linear-gradient(90deg,var(--accent3),transparent)}
.stat-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-dim);font-family:'Space Mono',monospace;margin-bottom:8px}
.stat-value{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;line-height:1}
.stat-sub{font-size:11px;color:var(--text-dim);margin-top:5px}
.stat-icon{position:absolute;right:16px;top:16px;font-size:22px;opacity:0.12}
.stat-card.c1 .stat-icon{color:var(--accent)}
.stat-card.c2 .stat-icon{color:var(--accent2)}
.stat-card.c3 .stat-icon{color:var(--accent3)}

/* ── TABLE ── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:700px}
thead th{padding:11px 14px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.50);font-family:'Space Mono',monospace;font-weight:400;border-bottom:1px solid rgba(255,255,255,0.10);text-align:left;white-space:nowrap}
tbody td{padding:14px;border-bottom:1px solid rgba(255,255,255,0.05);color:#fff;vertical-align:top}
tbody tr:hover{background:rgba(255,255,255,0.03)}
tbody tr:last-child td{border-bottom:none}

/* ── BADGES ── */
.badge-admin{background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30);padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;font-family:'Space Mono',monospace}
.badge-user{background:rgba(63,199,253,0.12);color:var(--accent2);border:1px solid rgba(63,199,253,0.25);padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;font-family:'Space Mono',monospace}
.badge-online{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25);padding:2px 7px;border-radius:4px;font-size:9px;font-weight:700;font-family:'Space Mono',monospace}
.badge-offline{background:rgba(255,61,87,0.12);color:var(--red);border:1px solid rgba(255,61,87,0.25);padding:2px 7px;border-radius:4px;font-size:9px;font-weight:700;font-family:'Space Mono',monospace}

/* ── MINI FORMS ── */
.mini-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.mini-input{padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,0.20);background:rgba(255,255,255,0.08);color:#fff;font-size:12px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color 0.2s;width:90px}
.mini-input:focus{border-color:var(--accent)}
.mini-input::placeholder{color:rgba(255,255,255,0.30)}

/* ── STATIONS LIST ── */
.stations-list{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.station-item{display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.10);border-radius:8px;padding:8px 12px;gap:8px;transition:opacity 0.4s,transform 0.4s}
.station-item.removing{opacity:0;transform:translateX(20px);pointer-events:none}
.station-info{flex:1;min-width:0}
.station-name{font-size:12px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.station-ip{font-size:10px;color:var(--accent2);font-family:'Space Mono',monospace;margin-top:2px}
.station-eneo{font-size:10px;color:var(--text-muted);margin-top:1px}
.station-empty{font-size:12px;color:var(--text-muted);font-style:italic;padding:6px 0}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;justify-content:center;align-items:center;z-index:1500}
.modal-overlay.active{display:flex}
.modal-content{background:rgba(15,30,50,0.92);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 40px rgba(0,0,0,0.5);padding:28px;border-radius:16px;width:90%;max-width:420px;color:#fff;animation:modalIn 0.3s ease}
.modal-content.sm{max-width:360px;text-align:center}
@keyframes modalIn{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.modal-header h4{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;display:flex;align-items:center;gap:8px}
.modal-header h4 i{color:var(--accent)}
.modal-close-x{background:none;border:none;color:rgba(255,255,255,0.6);font-size:22px;cursor:pointer;transition:color 0.2s}
.modal-close-x:hover{color:#fff}
.modal-label{font-size:11px;color:var(--text-dim);letter-spacing:1px;text-transform:uppercase;margin-bottom:6px}
.modal-input{width:100%;padding:11px 14px;border-radius:9px;border:1px solid rgba(255,255,255,0.20);background:rgba(255,255,255,0.08);color:#fff;font-size:13px;font-family:'DM Sans',sans-serif;margin-bottom:14px;outline:none;transition:border-color 0.2s}
.modal-input:focus{border-color:var(--accent)}
.modal-input::placeholder{color:rgba(255,255,255,0.30)}
.modal-icon-big{font-size:44px;margin-bottom:14px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:4px}
.modal-footer.center{justify-content:center}
.btn-cancel{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.70);border:1px solid rgba(255,255,255,0.15);padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-cancel:hover{background:rgba(255,255,255,0.14);color:#fff}
.btn-save{background:var(--accent);color:#000;border:none;padding:9px 20px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-save:hover{filter:brightness(1.1)}
.btn-confirm-red{background:var(--red);color:#fff;border:none;padding:10px 22px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-confirm-red:hover{filter:brightness(1.1)}

/* ── TOAST ── */
#toastContainer{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.toast{min-width:300px;max-width:420px;padding:14px 18px;border-radius:12px;color:#fff;display:flex;align-items:center;gap:12px;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 32px rgba(0,0,0,0.3);font-size:13px;pointer-events:auto;animation:toastIn 0.4s cubic-bezier(.4,0,.2,1) forwards}
.toast.success{background:rgba(7,247,147,0.15);border-left:4px solid var(--accent)}
.toast.error{background:rgba(255,61,87,0.15);border-left:4px solid var(--red)}
.toast.warning{background:rgba(245,158,11,0.15);border-left:4px solid #f59e0b}
.toast.info{background:rgba(63,199,253,0.15);border-left:4px solid var(--accent2)}
.toast.success .ti{color:var(--accent);font-size:18px}
.toast.error .ti{color:var(--red);font-size:18px}
.toast.warning .ti{color:#f59e0b;font-size:18px}
.toast.info .ti{color:var(--accent2);font-size:18px}
.toast-msg{flex:1;line-height:1.4}
.toast-close{background:none;border:none;color:rgba(255,255,255,0.5);font-size:16px;cursor:pointer;padding:0 0 0 8px;transition:color 0.2s}
.toast-close:hover{color:#fff}
@keyframes toastIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateX(60px)}}

/* ── FOOTER ── */
.footer{text-align:center;padding:22px;font-size:11px;color:rgba(255,255,255,0.35);font-family:'Space Mono',monospace}

/* ── RESPONSIVE ── */
@media(max-width:768px){
    body{padding:12px}
    .stats-row{grid-template-columns:1fr 1fr}
    .mini-form{flex-direction:column;align-items:stretch}
    .mini-input{width:100%}
    table,tr,td,th{display:block;width:100%}
    tr{margin-bottom:12px;background:rgba(0,0,0,0.2);padding:12px;border-radius:10px}
    td{border:none;padding:6px 0}
    thead{display:none}
}
@media(max-width:480px){.stats-row{grid-template-columns:1fr}}
</style>
</head>
<body>

<div id="toastContainer"></div>

<?php if(isset($_SESSION['toast'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showToast(
        '<?php echo addslashes(htmlspecialchars($_SESSION['toast']['msg'], ENT_QUOTES)); ?>',
        '<?php echo $_SESSION['toast']['type']; ?>'
    );
});
</script>
<?php unset($_SESSION['toast']); ?>
<?php endif; ?>

<div class="wrapper">

    <!-- HEADER -->
    <div class="page-header">
        <div class="header-left">
            <h2><i class="fa-solid fa-shield-halved"></i> Admin Dashboard</h2>
            <p>Karibu, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> — Usimamizi wa Mfumo</p>
        </div>
        <div class="header-right">
            <a href="user_dashboard.php" class="btn btn-blue">
                <i class="fa-solid fa-chart-pie"></i> Billing Dashboard
            </a>
            <a href="admin.php" class="btn btn-red">
                <i class="fa-solid fa-user-shield"></i> Admin Panel
            </a>
            <a href="logout.php" class="btn btn-gray">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-row">
        <div class="stat-card c1">
            <div class="stat-label">Jumla ya Wateja</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-sub">Resellers wote</div>
            <i class="fa-solid fa-users stat-icon"></i>
        </div>
        <div class="stat-card c2">
            <div class="stat-label">Vituo (Stations)</div>
            <div class="stat-value" id="totalStationsVal"><?php echo $total_stations; ?></div>
            <div class="stat-sub">Access Points zote</div>
            <i class="fa-solid fa-tower-broadcast stat-icon"></i>
        </div>
        <div class="stat-card c3">
            <div class="stat-label">MikroTik Configs</div>
            <div class="stat-value"><?php echo $total_mikrotik; ?></div>
            <div class="stat-sub">Routers zilizosajiliwa</div>
            <i class="fa-solid fa-router stat-icon"></i>
        </div>
    </div>

    <!-- MAIN TABLE -->
    <div class="panel">
        <div class="panel-title">
            <h3><i class="fa-solid fa-users-gear"></i> Wateja (Owners & Resellers)</h3>
            <span style="font-family:'Space Mono',monospace;font-size:11px;color:var(--text-dim);">
                <?php echo count($wateja); ?> watumiaji
            </span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:14%;">Jina la Mteja</th>
                        <th style="width:8%;">Role</th>
                        <th style="width:36%;">Mipangilio ya MikroTik</th>
                        <th style="width:20%;">Ongeza Station</th>
                        <th style="width:22%;">Antena Zilizopo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($wateja as $row):
                    $is_me = ($row['username'] === $_SESSION['username']);
                ?>
                <tr>
                    <!-- Jina -->
                    <td>
                        <strong style="color:var(--accent2);"><?php echo htmlspecialchars($row['username']); ?></strong>
                        <?php if($is_me): ?>
                        <span class="badge-admin" style="margin-left:6px;display:block;margin-top:4px;">Wewe</span>
                        <?php endif; ?>
                    </td>

                    <!-- Role -->
                    <td>
                        <?php if($row['role']==='admin'): ?>
                            <span class="badge-admin">ADMIN</span>
                        <?php else: ?>
                            <span class="badge-user">USER</span>
                        <?php endif; ?>
                    </td>

                    <!-- MikroTik Form -->
                    <td>
                        <form action="save_mikrotik.php" method="POST" class="mini-form">
                            <input type="hidden" name="user_id"    value="<?php echo $row['id']; ?>">
                            <input type="hidden" name="mteja_name" value="<?php echo htmlspecialchars($row['username']); ?>">
                            <input type="text"     name="mikrotik_ip" class="mini-input" placeholder="IP ya Router"
                                   value="<?php echo htmlspecialchars($row['mikrotik']['mikrotik_ip'] ?? ''); ?>" required>
                            <input type="text"     name="api_user"    class="mini-input" placeholder="API User"
                                   value="<?php echo htmlspecialchars($row['mikrotik']['api_user'] ?? ''); ?>" required>
                            <input type="password" name="api_pass"    class="mini-input" placeholder="API Pass"
                                   value="<?php echo htmlspecialchars($row['mikrotik']['api_pass'] ?? ''); ?>" required>
                            <button type="submit" class="btn btn-green btn-sm">
                                <i class="fa-solid fa-floppy-disk"></i> <?php echo $row['mikrotik'] ? 'Sasisha' : 'Hifadhi'; ?>
                            </button>
                        </form>
                    </td>

                    <!-- Ongeza Station -->
                    <td>
                        <button class="btn btn-blue btn-sm"
                            onclick="funguaStationModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')">
                            <i class="fa-solid fa-plus"></i> Ongeza Station
                        </button>
                    </td>

                    <!-- ✅ Antena Zilizopo + Futa -->
                    <td>
                        <div class="stations-list" id="stations-list-<?php echo $row['id']; ?>">
                        <?php if(!empty($row['stations'])): ?>
                            <?php foreach($row['stations'] as $st): ?>
                            <div class="station-item" id="station-item-<?php echo $st['id']; ?>">
                                <div class="station-info">
                                    <div class="station-name">
                                        <i class="fa-solid fa-tower-broadcast" style="font-size:10px;color:var(--accent2);margin-right:4px;"></i>
                                        <?php echo htmlspecialchars($st['jina_la_ap']); ?>
                                    </div>
                                    <div class="station-ip"><?php echo htmlspecialchars($st['ip_address']); ?></div>
                                    <?php if($st['eneo_ilipo']): ?>
                                    <div class="station-eneo">
                                        <i class="fa-solid fa-location-dot" style="font-size:9px;color:#ff4d4d;"></i>
                                        <?php echo htmlspecialchars($st['eneo_ilipo']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                                    <span class="badge-<?php echo $st['status']==='online'?'online':'offline'; ?>">
                                        <?php echo strtoupper($st['status']); ?>
                                    </span>
                                    <div style="display:flex;gap:4px;">
                                        <button class="btn btn-blue btn-sm"
                                            onclick="funguaEditStation(<?php echo $st['id']; ?>, <?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>', '<?php echo htmlspecialchars($st['jina_la_ap'],ENT_QUOTES); ?>', '<?php echo htmlspecialchars($st['ip_address'],ENT_QUOTES); ?>', '<?php echo htmlspecialchars($st['eneo_ilipo'],ENT_QUOTES); ?>')"
                                            style="padding:4px 10px;font-size:10px;">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button class="btn btn-red btn-sm"
                                            onclick="funguaFutaStation(<?php echo $st['id']; ?>, '<?php echo htmlspecialchars($st['jina_la_ap'],ENT_QUOTES); ?>', <?php echo $row['id']; ?>)"
                                            style="padding:4px 10px;font-size:10px;">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="station-empty" id="no-stations-<?php echo $row['id']; ?>">
                                <i class="fa-solid fa-circle-info" style="font-size:10px;"></i>
                                Hakuna antena bado
                            </div>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<footer class="footer">© <?php echo date('Y'); ?> Bin Waqas Wi-Fi System &nbsp;·&nbsp; Haki zote zimehifadhiwa</footer>

<!-- ═══ MODAL: ONGEZA STATION ═══ -->
<div class="modal-overlay" id="stationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 id="stationModalTitle"><i class="fa-solid fa-tower-broadcast"></i> Ongeza Antena (Station)</h4>
            <button class="modal-close-x" onclick="fungaModal('stationModal')">&times;</button>
        </div>
        <form action="" method="POST" id="stationForm">
            <input type="hidden" name="save_station" value="1">
            <input type="hidden" name="user_id" id="station_user_id">
            <input type="hidden" name="edit_station_id" id="edit_station_id" value="">

            <div class="modal-label">Mteja</div>
            <div id="station_mteja_display"
                 style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:9px;padding:10px 14px;margin-bottom:14px;font-weight:700;color:var(--accent2);">
            </div>

            <div class="modal-label">Jina la Antena</div>
            <input type="text" name="jina_la_ap" class="modal-input" placeholder="Mfano: Station A" required>

            <div class="modal-label">IP ya Access Point</div>
            <input type="text" name="ip_address" class="modal-input" placeholder="Mfano: 192.168.10.1" required>

            <div class="modal-label">Eneo Ilipo (si lazima)</div>
            <input type="text" name="eneo_ilipo" class="modal-input" placeholder="Mfano: Mtaa wa Mji Mpya">

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="fungaModal('stationModal')">Ghairi</button>
                <button type="submit" class="btn-save" id="stationSaveBtn">
                    <i class="fa-solid fa-tower-broadcast"></i> Hifadhi Station
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL: CONFIRM FUTA STATION ═══ -->
<div class="modal-overlay" id="deleteStationModal">
    <div class="modal-content sm">
        <div class="modal-icon-big">🗑️</div>
        <h4 style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:8px;">Futa Antena?</h4>
        <p id="deleteStationMsg" style="color:var(--text-dim);font-size:13px;margin-bottom:22px;line-height:1.6;"></p>
        <div class="modal-footer center">
            <button class="btn-cancel" onclick="fungaModal('deleteStationModal')">Ghairi</button>
            <button class="btn-confirm-red" id="confirmDeleteBtn" onclick="tekelezaFutaStation()">
                <i class="fa-solid fa-trash-can"></i> Ndiyo, Futa
            </button>
        </div>
    </div>
</div>

<script>
// ══ TOAST ══
function showToast(msg, type) {
    type = type || 'info';
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = '<i class="fa-solid '+(icons[type]||'fa-circle-info')+' ti"></i><span class="toast-msg">'+msg+'</span><button class="toast-close" onclick="this.parentElement.remove()">✕</button>';
    c.appendChild(t);
    setTimeout(function() { t.style.animation='toastOut 0.4s ease forwards'; setTimeout(function(){t.remove();},400); }, 4000);
}

// ══ MODAL: FUNGUA / FUNGA ══
function funguaModal(id) { document.getElementById(id).classList.add('active'); }
function fungaModal(id)  { document.getElementById(id).classList.remove('active'); }

// Funga ukibonyeza nje
['stationModal','deleteStationModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) fungaModal(id);
    });
});

// ══ ONGEZA STATION MODAL (mpya) ══
function funguaStationModal(userId, username) {
    document.getElementById('stationForm').reset();
    document.getElementById('station_user_id').value = userId;
    document.getElementById('station_mteja_display').textContent = username;
    document.getElementById('edit_station_id').value = '';

    // Hakikisha modal iko kwenye hali ya "Ongeza" (siyo "Badilisha")
    document.getElementById('stationModalTitle').innerHTML = '<i class="fa-solid fa-tower-broadcast"></i> Ongeza Antena (Station)';
    document.getElementById('stationSaveBtn').innerHTML = '<i class="fa-solid fa-tower-broadcast"></i> Hifadhi Station';

    funguaModal('stationModal');
}

// ══ BADILISHA STATION ILIYOPO ══
function funguaEditStation(stationId, userId, username, jina, ip, eneo) {
    document.getElementById('stationForm').reset();
    document.getElementById('station_user_id').value = userId;
    document.getElementById('station_mteja_display').textContent = username;
    document.getElementById('edit_station_id').value = stationId;

    // Jaza taarifa zilizopo za antena hii
    document.querySelector('#stationForm input[name="jina_la_ap"]').value = jina;
    document.querySelector('#stationForm input[name="ip_address"]').value = ip;
    document.querySelector('#stationForm input[name="eneo_ilipo"]').value = eneo;

    // Badilisha modal iwe kwenye hali ya "Badilisha"
    document.getElementById('stationModalTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Badilisha Antena';
    document.getElementById('stationSaveBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Sasisha Station';

    funguaModal('stationModal');
}

// ══ FUTA STATION ══
let pendingStationId  = null;
let pendingUserId     = null;

function funguaFutaStation(stationId, jinaStation, userId) {
    pendingStationId = stationId;
    pendingUserId    = userId;
    document.getElementById('deleteStationMsg').innerHTML =
        'Una uhakika unataka kufuta antena <strong>' + jinaStation + '</strong>?<br>' +
        '<small style="color:var(--red);">Kitendo hiki hakiwezi kurudishwa!</small>';
    funguaModal('deleteStationModal');
}

function tekelezaFutaStation() {
    if (!pendingStationId) return;
    fungaModal('deleteStationModal');

    const btn  = document.getElementById('confirmDeleteBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inafuta...';
    btn.disabled  = true;

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=delete_station&station_id=' + pendingStationId
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.innerHTML = orig;
        btn.disabled  = false;

        if (data.status === 'success') {
            // Ondoa station item kwa animation
            const item = document.getElementById('station-item-' + pendingStationId);
            if (item) {
                item.classList.add('removing');
                setTimeout(function() {
                    item.remove();
                    // Kama hakuna stations zilizobaki, onyesha "Hakuna antena"
                    const list = document.getElementById('stations-list-' + pendingUserId);
                    if (list && !list.querySelector('.station-item')) {
                        list.innerHTML = '<div class="station-empty"><i class="fa-solid fa-circle-info" style="font-size:10px;"></i> Hakuna antena bado</div>';
                    }
                }, 420);
            }
            // Punguza hesabu ya stations
            const sv = document.getElementById('totalStationsVal');
            if (sv) sv.textContent = Math.max(0, parseInt(sv.textContent) - 1);

            showToast('Antena imefutwa. 🗑️', 'info');
        } else {
            showToast(data.msg || 'Imeshindikana kufuta.', 'error');
        }
    })
    .catch(function() {
        btn.innerHTML = orig;
        btn.disabled  = false;
        showToast('Tatizo la mtandao. Jaribu tena.', 'error');
    });
}
</script>

</body>
</html>