<?php
session_start();
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)

// ── SESSION TIMEOUT (dakika 15) ──
$timeout = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset(); session_destroy();
    header("Location: index.php?msg=Session yako imeisha.");
    exit();
}
$_SESSION['last_activity'] = time();

// ── LINDA UKURASA ──
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// ── AJAX ACTIONS (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $id     = intval($_POST['id'] ?? 0);

    if ($action === 'approve') {
        $check = mysqli_query($conn, "SELECT status FROM users WHERE id=$id");
        $row   = mysqli_fetch_assoc($check);
        if ($row['status'] === 'pending_reset') {
            mysqli_query($conn, "UPDATE users SET password=pending_password, pending_password=NULL, status='approved' WHERE id=$id");
            echo json_encode(['status'=>'success', 'msg'=>'Password Reset imeidhinishwa! ✅']);
        } else {
            mysqli_query($conn, "UPDATE users SET status='approved' WHERE id=$id");
            echo json_encode(['status'=>'success', 'msg'=>'Mtumiaji amekubaliwa! ✅']);
        }
    } elseif ($action === 'make_admin') {
        mysqli_query($conn, "UPDATE users SET role='admin' WHERE id=$id");
        echo json_encode(['status'=>'success', 'msg'=>'Mtumiaji amekuwa Admin! 🎉']);
    } elseif ($action === 'delete') {
        mysqli_query($conn, "DELETE FROM users WHERE id=$id");
        echo json_encode(['status'=>'success', 'msg'=>'Mtumiaji amefutwa.']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Kitendo hakijulikani.']);
    }
    exit();
}

// ── HESABU ──
$total_pending = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE status='pending'"))['c'] ?? 0;
$total_resets  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE status='pending_reset'"))['c'] ?? 0;
$total_users   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users"))['c'] ?? 0;
$total_admins  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'] ?? 0;

// ── VUTA WATUMIAJI WOTE (pamoja na router_id kutoka mikrotik_configs) ──
// Columns: id, username, email, phone, password, role, status, created_at
// LEFT JOIN: tunaweka watumiaji wote, hata wale wasio na router lililosajiliwa bado
$result = mysqli_query($conn, "
    SELECT u.id, u.username, u.email, u.phone, u.role, u.status, u.created_at,
           MIN(mc.router_id) AS router_id
    FROM users u
    LEFT JOIN mikrotik_configs mc ON mc.user_id = u.id
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel · 5G Wi-Fi</title>
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
body::before{content:'';position:fixed;inset:0;background:rgba(0,0,0,0.38);pointer-events:none;z-index:0}
.wrapper{position:relative;z-index:1;max-width:1150px;margin:0 auto}

/* ── HEADER ── */
.page-header{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border);border-radius:var(--radius);padding:20px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;position:relative;overflow:hidden}
.page-header::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.4),transparent)}
.header-left h2{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px}
.header-left h2 i{color:var(--red)}
.header-left p{font-size:12px;color:var(--text-dim);margin-top:3px}
.header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* ── ALERT PENDING ── */
.alert-pending{background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.30);border-left:4px solid #f59e0b;border-radius:var(--radius);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;backdrop-filter:var(--blur);font-size:13px}
.alert-pending i{color:#f59e0b;font-size:18px;flex-shrink:0}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:18px;position:relative;overflow:hidden;transition:transform 0.2s,border-color 0.2s}
.stat-card:hover{transform:translateY(-2px);border-color:var(--border)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.c1::before{background:linear-gradient(90deg,var(--accent),transparent)}
.stat-card.c2::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.stat-card.c3::before{background:linear-gradient(90deg,#f59e0b,transparent)}
.stat-card.c4::before{background:linear-gradient(90deg,var(--red),transparent)}
.stat-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-dim);font-family:'Space Mono',monospace;margin-bottom:8px}
.stat-value{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;line-height:1}
.stat-sub{font-size:11px;color:var(--text-dim);margin-top:5px}
.stat-icon{position:absolute;right:16px;top:16px;font-size:22px;opacity:0.12}
.stat-card.c1 .stat-icon{color:var(--accent)}
.stat-card.c2 .stat-icon{color:var(--accent2)}
.stat-card.c3 .stat-icon{color:#f59e0b}
.stat-card.c4 .stat-icon{color:var(--red)}

/* ── PANEL ── */
.panel{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:24px;margin-bottom:24px;position:relative;overflow:hidden}
.panel::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.3),transparent)}
.panel-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.panel-title h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.panel-title h3 i{color:var(--accent)}

/* ── SEARCH ── */
.search-wrap{margin-bottom:18px}
.search-input{width:100%;padding:11px 16px;border-radius:10px;border:1px solid rgba(255,255,255,0.20);background:rgba(255,255,255,0.08);color:#fff;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color 0.2s}
.search-input:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(7,247,147,0.10)}
.search-input::placeholder{color:rgba(255,255,255,0.35)}

/* ── TABLE ── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:850px}
thead th{padding:11px 14px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.50);font-family:'Space Mono',monospace;font-weight:400;border-bottom:1px solid rgba(255,255,255,0.10);text-align:left;white-space:nowrap}
tbody td{padding:13px 14px;border-bottom:1px solid rgba(255,255,255,0.05);color:#fff;vertical-align:middle}
tbody tr{transition:background 0.15s,opacity 0.4s,transform 0.4s}
tbody tr:hover{background:rgba(255,255,255,0.04)}
tbody tr:last-child td{border-bottom:none}
tbody tr.removing{opacity:0;transform:translateX(30px);pointer-events:none}

/* ── BADGES ── */
.badge{font-family:'Space Mono',monospace;font-size:9px;font-weight:700;padding:4px 9px;border-radius:5px;letter-spacing:0.5px;display:inline-block}
.badge-admin   {background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30)}
.badge-user    {background:rgba(63,199,253,0.12);color:var(--accent2);border:1px solid rgba(63,199,253,0.25)}
.badge-approved{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.badge-pending {background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.30)}
.badge-reset   {background:rgba(63,199,253,0.12);color:var(--accent2);border:1px solid rgba(63,199,253,0.25)}
.badge-me      {background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30);font-size:9px;padding:2px 7px;border-radius:4px;margin-left:6px;font-family:'Space Mono',monospace;font-weight:700}

/* ── BUTTONS ── */
.btn-nav{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-nav.gray{background:rgba(255,255,255,0.10);color:var(--text-dim);border:1px solid var(--border2)}
.btn-nav.gray:hover{background:rgba(255,255,255,0.18);color:#fff}
.btn-nav.blue{background:rgba(63,199,253,0.15);color:var(--accent2);border:1px solid rgba(63,199,253,0.30)}
.btn-nav.blue:hover{background:rgba(63,199,253,0.25)}
.btn-nav.red{background:rgba(255,61,87,0.15);color:var(--red);border:1px solid rgba(255,61,87,0.30)}
.btn-nav.red:hover{background:rgba(255,61,87,0.25)}

.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;font-family:'DM Sans',sans-serif;white-space:nowrap}
.btn-approve  {background:rgba(7,247,147,0.15);color:var(--accent);border:1px solid rgba(7,247,147,0.30)}
.btn-approve:hover{background:rgba(7,247,147,0.25)}
.btn-resetpass{background:rgba(63,199,253,0.15);color:var(--accent2);border:1px solid rgba(63,199,253,0.30)}
.btn-resetpass:hover{background:rgba(63,199,253,0.25)}
.btn-makeadmin{background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30)}
.btn-makeadmin:hover{background:rgba(255,107,53,0.25)}
.btn-delete   {background:rgba(255,61,87,0.15);color:var(--red);border:1px solid rgba(255,61,87,0.30)}
.btn-delete:hover{background:rgba(255,61,87,0.25)}
.actions-wrap{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;justify-content:center;align-items:center;z-index:1500}
.modal-overlay.active{display:flex}
.modal-content{background:rgba(15,30,50,0.92);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 40px rgba(0,0,0,0.5);padding:28px;border-radius:16px;width:90%;max-width:380px;color:#fff;animation:modalIn 0.3s ease;text-align:center}
@keyframes modalIn{from{transform:scale(0.9);opacity:0}to{transform:scale(1);opacity:1}}
.modal-icon{font-size:44px;margin-bottom:14px}
.modal-content h4{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:8px}
.modal-content p{color:var(--text-dim);font-size:13px;margin-bottom:22px;line-height:1.6}
.modal-btns{display:flex;gap:10px;justify-content:center}
.btn-cancel{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.70);border:1px solid rgba(255,255,255,0.15);padding:10px 22px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-cancel:hover{background:rgba(255,255,255,0.14);color:#fff}
.btn-confirm{padding:10px 22px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;border:none;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-confirm.green{background:var(--accent);color:#000}
.btn-confirm.green:hover{filter:brightness(1.1)}
.btn-confirm.orange{background:var(--accent3);color:#fff}
.btn-confirm.orange:hover{filter:brightness(1.1)}
.btn-confirm.red{background:var(--red);color:#fff}
.btn-confirm.red:hover{filter:brightness(1.1)}

/* ── TOAST ── */
#toastContainer{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.toast{min-width:300px;max-width:420px;padding:14px 18px;border-radius:12px;color:#fff;display:flex;align-items:center;gap:12px;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 32px rgba(0,0,0,0.3);font-size:13px;pointer-events:auto;animation:toastIn 0.4s cubic-bezier(.4,0,.2,1) forwards}
.toast.success{background:rgba(7,247,147,0.15);border-left:4px solid var(--accent)}
.toast.error  {background:rgba(255,61,87,0.15); border-left:4px solid var(--red)}
.toast.warning{background:rgba(245,158,11,0.15);border-left:4px solid #f59e0b}
.toast.info   {background:rgba(63,199,253,0.15);border-left:4px solid var(--accent2)}
.toast.success .ti{color:var(--accent);font-size:18px}
.toast.error   .ti{color:var(--red);font-size:18px}
.toast.warning .ti{color:#f59e0b;font-size:18px}
.toast.info    .ti{color:var(--accent2);font-size:18px}
.toast-msg{flex:1;line-height:1.4}
.toast-close{background:none;border:none;color:rgba(255,255,255,0.5);font-size:16px;cursor:pointer;padding:0 0 0 8px;transition:color 0.2s}
.toast-close:hover{color:#fff}
@keyframes toastIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateX(60px)}}

/* ── FOOTER ── */
.footer{text-align:center;padding:22px;font-size:11px;color:rgba(255,255,255,0.35);font-family:'Space Mono',monospace}

/* ── RESPONSIVE ── */
@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){
    body{padding:12px}
    .stats-row{grid-template-columns:1fr 1fr}
    .page-header{flex-direction:column;align-items:flex-start}
    .header-right{width:100%;justify-content:flex-start}
    table,tr,td,th{display:block;width:100%}
    tr{margin-bottom:12px;background:rgba(0,0,0,0.2);padding:12px;border-radius:10px}
    td{border:none;padding:6px 0}
    thead{display:none}
    .actions-wrap{flex-direction:row;flex-wrap:wrap}
}
@media(max-width:480px){
    .stats-row{grid-template-columns:1fr}
    .btn-nav{font-size:12px;padding:8px 12px}
}
</style>
</head>
<body>

<div id="toastContainer"></div>

<!-- Flash toast kutoka session (inakuja kutoka save_mikrotik.php n.k.) -->
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

    <!-- ── HEADER ── -->
    <div class="page-header">
        <div class="header-left">
            <h2><i class="fa-solid fa-user-shield"></i> Admin Panel</h2>
            <p>Usimamizi wa Watumiaji — Karibu, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></p>
        </div>
        <div class="header-right">
            <a href="admin.php" class="btn-nav blue">
                <i class="fa-solid fa-sliders"></i> Admin Dashboard
            </a>
            <a href="user_dashboard.php" class="btn-nav gray">
                <i class="fa-solid fa-chart-pie"></i> Billing
            </a>
            <a href="logout.php" class="btn-nav red">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <!-- ── ALERT: Maombi Yanayosubiri ── -->
    <?php if($total_pending > 0 || $total_resets > 0): ?>
    <div class="alert-pending">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Maombi yanayosubiri idhini yako:</strong>
            &nbsp;&nbsp;
            <?php if($total_pending > 0): ?>
                <span style="color:var(--accent);">
                    <i class="fa-solid fa-user-plus" style="font-size:11px;"></i>
                    Usajili mpya: <strong><?php echo $total_pending; ?></strong>
                </span>
            <?php endif; ?>
            <?php if($total_pending > 0 && $total_resets > 0): ?>
                &nbsp;·&nbsp;
            <?php endif; ?>
            <?php if($total_resets > 0): ?>
                <span style="color:var(--accent2);">
                    <i class="fa-solid fa-key" style="font-size:11px;"></i>
                    Password Reset: <strong><?php echo $total_resets; ?></strong>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── STAT CARDS ── -->
    <div class="stats-row">
        <div class="stat-card c1">
            <div class="stat-label">Watumiaji Wote</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-sub">Waliojisajili</div>
            <i class="fa-solid fa-users stat-icon"></i>
        </div>
        <div class="stat-card c2">
            <div class="stat-label">Admins</div>
            <div class="stat-value"><?php echo $total_admins; ?></div>
            <div class="stat-sub">Wasimamizi</div>
            <i class="fa-solid fa-user-shield stat-icon"></i>
        </div>
        <div class="stat-card c3">
            <div class="stat-label">Wanasubiri</div>
            <div class="stat-value"><?php echo $total_pending; ?></div>
            <div class="stat-sub">Usajili mpya</div>
            <i class="fa-solid fa-clock stat-icon"></i>
        </div>
        <div class="stat-card c4">
            <div class="stat-label">Pass Reset</div>
            <div class="stat-value"><?php echo $total_resets; ?></div>
            <div class="stat-sub">Wanasubiri reset</div>
            <i class="fa-solid fa-key stat-icon"></i>
        </div>
    </div>

    <!-- ── MAIN TABLE ── -->
    <div class="panel">
        <div class="panel-title">
            <h3><i class="fa-solid fa-users-gear"></i> Orodha ya Watumiaji</h3>
            <span style="font-family:'Space Mono',monospace;font-size:11px;color:var(--text-dim);">
                <?php echo $total_users; ?> watumiaji wote
            </span>
        </div>

        <!-- Search -->
        <div class="search-wrap">
            <input type="text" class="search-input" id="liveSearch"
                   placeholder="Tafuta kwa jina, email au namba ya simu..."
                   onkeyup="searchTable()">
        </div>

        <div class="table-wrap">
            <table id="userTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Simu</th>
                        <th>Tarehe ya Kujisajili</th>
                        <th>Status</th>
                        <th>Role</th>
                        <th>Router ID</th>
                        <th>Vitendo</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $no = 1;
                while($row = mysqli_fetch_assoc($result)):
                    $is_me  = ($row['username'] === $_SESSION['username']);
                    $role   = $row['role']   ?? 'user';
                    $status = $row['status'] ?? 'approved';

                    // Status badge
                    if ($status === 'pending')
                        $status_badge = '<span class="badge badge-pending">PENDING</span>';
                    elseif ($status === 'pending_reset')
                        $status_badge = '<span class="badge badge-reset">RESET</span>';
                    else
                        $status_badge = '<span class="badge badge-approved">APPROVED</span>';

                    // Role badge
                    $role_badge = ($role === 'admin')
                        ? '<span class="badge badge-admin">ADMIN</span>'
                        : '<span class="badge badge-user">USER</span>';

                    // Tarehe ya kujisajili
                    $created = $row['created_at']
                        ? date('d M Y', strtotime($row['created_at']))
                        : '—';
                ?>
                <tr id="user-row-<?php echo $row['id']; ?>">
                    <td style="color:var(--text-dim);font-family:'Space Mono',monospace;font-size:11px;">
                        <?php echo $no++; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                        <?php if($is_me): ?>
                            <span class="badge-me">Wewe</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--text-dim);font-size:12px;">
                        <?php echo htmlspecialchars($row['email'] ?? '—'); ?>
                    </td>
                    <td style="font-family:'Space Mono',monospace;font-size:11px;color:var(--accent2);">
                        <?php echo htmlspecialchars($row['phone'] ?? '—'); ?>
                    </td>
                    <td style="font-size:11px;color:var(--text-dim);white-space:nowrap;">
                        <?php echo $created; ?>
                    </td>
                    <td><?php echo $status_badge; ?></td>
                    <td><?php echo $role_badge; ?></td>
                    <td style="font-family:'Space Mono',monospace;font-size:12px;">
                        <?php if (!empty($row['router_id'])): ?>
                            <span style="color:var(--accent);font-weight:700;cursor:pointer;" 
                                  title="Bonyeza kunakili"
                                  onclick="navigator.clipboard.writeText('<?php echo (int)$row['router_id']; ?>');showToast('Router ID imenakiliwa: <?php echo (int)$row['router_id']; ?>','info');">
                                <?php echo (int)$row['router_id']; ?> <i class="fa-regular fa-copy" style="font-size:10px;opacity:0.6;"></i>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;">Hajasajili router</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions-wrap">
                        <?php if(!$is_me): ?>

                            <?php if($status === 'pending'): ?>
                                <button class="btn btn-approve"
                                    onclick="funguaConfirm('approve',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')">
                                    <i class="fa-solid fa-check"></i> Kubali
                                </button>
                            <?php elseif($status === 'pending_reset'): ?>
                                <button class="btn btn-resetpass"
                                    onclick="funguaConfirm('approve',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')">
                                    <i class="fa-solid fa-key"></i> Approve Pass
                                </button>
                            <?php endif; ?>

                            <?php if($role !== 'admin'): ?>
                                <button class="btn btn-makeadmin"
                                    onclick="funguaConfirm('make_admin',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')">
                                    <i class="fa-solid fa-star"></i> Make Admin
                                </button>
                            <?php endif; ?>

                            <button class="btn btn-delete"
                                onclick="funguaConfirm('delete',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')">
                                <i class="fa-solid fa-trash-can"></i> Futa
                            </button>

                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px;font-style:italic;">
                                Mstari wako
                            </span>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<footer class="footer">© <?php echo date('Y'); ?> Bin Waqas Wi-Fi System &nbsp;·&nbsp; Haki zote zimehifadhiwa</footer>

<!-- ═══ MODAL: CONFIRM ACTION ═══ -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-content">
        <div class="modal-icon" id="modalIcon">⚠️</div>
        <h4 id="modalTitle">Una uhakika?</h4>
        <p id="modalMsg">Je, unataka kuendelea na kitendo hiki?</p>
        <div class="modal-btns">
            <button class="btn-cancel" onclick="fungaModal()">Ghairi</button>
            <button class="btn-confirm green" id="confirmBtn" onclick="tekelezaAction()">
                <i class="fa-solid fa-check" id="confirmIcon"></i>
                <span id="confirmText">Ndiyo</span>
            </button>
        </div>
    </div>
</div>

<script>
// ══ TOAST ══
function showToast(msg, type) {
    type = type || 'info';
    const icons = {
        success: 'fa-circle-check',
        error:   'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info:    'fa-circle-info'
    };
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML =
        '<i class="fa-solid ' + (icons[type] || 'fa-circle-info') + ' ti"></i>' +
        '<span class="toast-msg">' + msg + '</span>' +
        '<button class="toast-close" onclick="this.parentElement.remove()">✕</button>';
    c.appendChild(t);
    setTimeout(function() {
        t.style.animation = 'toastOut 0.4s ease forwards';
        setTimeout(function() { t.remove(); }, 400);
    }, 4000);
}

// ══ SEARCH ══
function searchTable() {
    const input = document.getElementById('liveSearch').value.toLowerCase();
    document.querySelectorAll('#userTable tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(input) ? '' : 'none';
    });
}

// ══ MODAL ══
let pendingAction = null;
let pendingId     = null;

const modalConfigs = {
    approve: {
        icon: '✅', title: 'Kubali Mtumiaji',
        msg: function(u){ return 'Una uhakika unataka kumkubali <strong>' + u + '</strong>?'; },
        btnClass: 'green', btnIcon: 'fa-check', btnText: 'Ndiyo, Kubali'
    },
    make_admin: {
        icon: '⭐', title: 'Fanya Admin',
        msg: function(u){ return 'Una uhakika unataka kumfanya <strong>' + u + '</strong> kuwa Admin?'; },
        btnClass: 'orange', btnIcon: 'fa-star', btnText: 'Ndiyo, Fanya Admin'
    },
    delete: {
        icon: '🗑️', title: 'Futa Mtumiaji',
        msg: function(u){ return 'Una uhakika unataka kumfuta <strong>' + u + '</strong>?<br><small style="color:var(--red);">Kitendo hiki hakiwezi kurudishwa!</small>'; },
        btnClass: 'red', btnIcon: 'fa-trash-can', btnText: 'Ndiyo, Futa'
    }
};

function funguaConfirm(action, id, username) {
    pendingAction = action;
    pendingId     = id;
    const cfg = modalConfigs[action];
    document.getElementById('modalIcon').textContent   = cfg.icon;
    document.getElementById('modalTitle').textContent  = cfg.title;
    document.getElementById('modalMsg').innerHTML      = cfg.msg(username);
    document.getElementById('confirmBtn').className    = 'btn-confirm ' + cfg.btnClass;
    document.getElementById('confirmIcon').className   = 'fa-solid ' + cfg.btnIcon;
    document.getElementById('confirmText').textContent = cfg.btnText;
    document.getElementById('confirmModal').classList.add('active');
}

function fungaModal() {
    document.getElementById('confirmModal').classList.remove('active');
    pendingAction = null;
    pendingId     = null;
}

document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) fungaModal();
});

// ══ TEKELEZA ACTION (AJAX) ══
function tekelezaAction() {
    if (!pendingAction || !pendingId) return;

    const action = pendingAction;
    const id     = pendingId;
    fungaModal();

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=' + action + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            showToast(data.msg, 'success');

            const row = document.getElementById('user-row-' + id);
            if (!row) return;

            if (action === 'delete') {
                // Futa row kwa animation
                row.classList.add('removing');
                setTimeout(function() { row.remove(); }, 420);

            } else if (action === 'approve') {
                // Badilisha status badge kuwa APPROVED
                const statusCell = row.querySelector('td:nth-child(6)');
                if (statusCell) statusCell.innerHTML = '<span class="badge badge-approved">APPROVED</span>';
                // Ondoa kitufe cha approve/reset
                const approveBtn = row.querySelector('.btn-approve, .btn-resetpass');
                if (approveBtn) approveBtn.remove();

            } else if (action === 'make_admin') {
                // Badilisha role badge kuwa ADMIN
                const roleCell = row.querySelector('td:nth-child(7)');
                if (roleCell) roleCell.innerHTML = '<span class="badge badge-admin">ADMIN</span>';
                // Ondoa kitufe cha make_admin
                const adminBtn = row.querySelector('.btn-makeadmin');
                if (adminBtn) adminBtn.remove();
            }
        } else {
            showToast(data.msg || 'Imeshindikana.', 'error');
        }
    })
    .catch(function() {
        showToast('Tatizo la mtandao. Jaribu tena.', 'error');
    });
}
</script>

</body>
</html>