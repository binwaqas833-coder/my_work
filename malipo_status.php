<?php
/**
 * payment_status.php
 * -------------------------------------------------------------
 * Ukurasa wa kuonyesha hali za malipo ya miamala kwa reseller/admin.
 * Umeboreshwa kutumia user_id NA router_id ili kutenganisha miamala
 * ya router tofauti tofauti.
 * -------------------------------------------------------------
 */
session_start();
include 'auth_check.php';
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$my_id            = (int)$_SESSION['user_id'];
$active_router_id = (int)($_SESSION['active_router_id'] ?? 0);

$STUCK_MINUTES = 15;

// ── Tafuta / chuja kulingana na user_id na active_router_id ──
$search = trim($_GET['q'] ?? '');

if ($active_router_id > 0) {
    $where  = "WHERE user_id = ? AND router_id = ?";
    $params = [$my_id, $active_router_id];
    $types  = "ii";
} else {
    // Fallback kama hana active router iliyochaguliwa
    $where  = "WHERE user_id = ?";
    $params = [$my_id];
    $types  = "i";
}

if ($search !== '') {
    $where .= " AND (phone LIKE ? OR transaction_id LIKE ? OR voucher_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like; 
    $params[] = $like; 
    $params[] = $like;
    $types .= "sss";
}

// ── Pagination ──
$PER_PAGE = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Idadi jumla ya rekodi
$count_sql = "SELECT COUNT(*) AS total FROM payment_transactions $where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, (int)ceil($total_rows / $PER_PAGE));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $PER_PAGE;

// Kuleta data
$sql = "SELECT * FROM payment_transactions $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$page_params = array_merge($params, [$PER_PAGE, $offset]);
$stmt->bind_param($types . "ii", ...$page_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hali za Malipo · Tech 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
<link rel="preload" as="image" href="beach5.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    :root{
        --surface: rgba(255,255,255,0.14);
        --surface2: rgba(255,255,255,0.08);
        --border: rgba(255,255,255,0.25);
        --border2: rgba(255,255,255,0.15);
        --accent: #07f793;
        --accent2: #3fc7fd;
        --text: #fff;
        --text-dim: rgba(255,255,255,0.70);
        --red: #ff3d57;
        --amber: #ffb020;
        --radius: 14px;
        --blur: blur(18px);
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{
        font-family:'DM Sans',sans-serif;
        background-color:#0d1b17;
        background-image:linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.55)), url('beach5.jpg');
        background-size:cover;
        background-position:center;
        background-attachment:fixed;
        color:var(--text);
        min-height:100vh;
        display:flex;
        flex-direction:column;
    }
    body::before{
        content:'';
        position:fixed;
        inset:0;
        background:rgba(0,0,0,0.25);
        pointer-events:none;
        z-index:0;
    }
    .wrap{
        max-width:1100px;
        margin:0 auto;
        width:100%;
        padding:26px 16px;
        position:relative;
        z-index:1;
        flex:1;
    }

    /* Header Styling */
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
        margin:0 0 18px 4px;
    }

    /* Search Bar */
    .search-bar{
        display:flex;
        gap:8px;
        margin-bottom:20px;
        max-width:460px;
    }
    .search-bar input{
        flex:1;
        padding:11px 16px;
        border-radius:10px;
        border:1px solid var(--border2);
        background:rgba(0,0,0,0.30);
        color:var(--text);
        font-size:13.5px;
        outline:none;
    }
    .search-bar input:focus{
        border-color:var(--accent);
    }
    .search-bar button{
        padding:11px 20px;
        border-radius:10px;
        border:none;
        background:var(--accent);
        color:#04231a;
        font-weight:700;
        font-size:13px;
        cursor:pointer;
        transition:all 0.2s;
    }
    .search-bar button:hover{
        filter:brightness(1.1);
    }

    /* Table Design */
    .table-wrap{
        width:100%;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
        border-radius:var(--radius);
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2);
    }
    table{
        width:100%;
        border-collapse:collapse;
        font-size:13px;
        min-width:740px;
    }
    th, td{
        padding:12px 16px;
        text-align:left;
        border-bottom:1px solid var(--border2);
        white-space:nowrap;
    }
    th{
        color:var(--text-dim);
        font-weight:700;
        font-size:11px;
        text-transform:uppercase;
        letter-spacing:0.8px;
        background:rgba(0,0,0,0.20);
    }
    tr:last-child td{ border-bottom:none; }
    tr:hover td{ background:rgba(255,255,255,0.03); }

    /* Badges & Buttons */
    .badge{
        display:inline-block;
        padding:3px 10px;
        border-radius:20px;
        font-size:10.5px;
        font-weight:800;
        letter-spacing:0.5px;
    }
    .badge-completed{ background:rgba(7,247,147,0.18); color:var(--accent); }
    .badge-pending{ background:rgba(255,176,32,0.18); color:var(--amber); }
    .badge-failed{ background:rgba(255,61,87,0.18); color:var(--red); }
    
    .stuck{ color:var(--amber); font-size:10.5px; display:block; margin-top:3px; }
    .btn-retry{
        padding:6px 14px;
        border-radius:8px;
        border:1px solid var(--accent);
        background:rgba(7,247,147,0.10);
        color:var(--accent);
        font-size:12px;
        font-weight:600;
        cursor:pointer;
        transition:all 0.2s;
    }
    .btn-retry:hover{ background:var(--accent); color:#04231a; }
    .btn-retry:disabled{ opacity:0.5; cursor:not-allowed; }
    
    .empty{ text-align:center; padding:40px; color:var(--text-dim); }
    .voucher-code{ font-family:'Space Mono',monospace; color:var(--accent); font-weight:700; }
    
    /* Toast */
    .toast{
        position:fixed;
        bottom:24px;
        left:50%;
        transform:translateX(-50%);
        background:rgba(15,30,25,0.95);
        border:1px solid var(--accent);
        color:#fff;
        padding:12px 22px;
        border-radius:10px;
        font-size:13px;
        display:none;
        z-index:2000;
        box-shadow:0 8px 30px rgba(0,0,0,0.5);
    }

    /* Pagination */
    .page-footer{
        display:flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        flex-wrap:wrap;
        padding:16px;
        margin-top:20px;
        background:var(--surface);
        backdrop-filter:var(--blur);
        border:1px solid var(--border2);
        border-radius:var(--radius);
    }
    .page-footer a, .page-footer span.num{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-width:34px;
        height:34px;
        padding:0 10px;
        border-radius:8px;
        font-size:12.5px;
        text-decoration:none;
        color:var(--text);
        border:1px solid var(--border2);
        background:rgba(0,0,0,0.2);
    }
    .page-footer a:hover{ border-color:var(--accent); color:var(--accent); }
    .page-footer .active{ background:var(--accent); color:#04231a; border-color:var(--accent); font-weight:800; }
    .page-footer .disabled{ opacity:0.3; pointer-events:none; }
    .page-footer .info{ color:var(--text-dim); font-size:11.5px; width:100%; text-align:center; margin-bottom:6px; font-family:'Space Mono',monospace; }

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
        background:rgba(15,30,25,0.95);
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

    @media (max-width:768px){
        .search-bar{ max-width:100%; }
        th,td{ padding:10px 12px; }
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
                <div class="brand-sub"><i class="fa-solid fa-receipt"></i> Hali za Malipo ya Wateja</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="back-btn" href="user_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Rudi Dashboard</a>
            <button class="logout-btn-header" onclick="thibitishaLogout()"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
        </div>
    </div>

    <p class="sub">Tafuta mteja kwa namba yake ya simu, angalia hali halisi, na jaribu kukamilisha muamala uliokwama.</p>

    <form class="search-bar" method="GET">
        <input type="text" name="q" placeholder="Tafuta kwa namba, rejea, au voucher..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Tafuta</button>
    </form>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Muda</th>
                <th>Simu</th>
                <th>Kifurushi</th>
                <th>Kiasi</th>
                <th>Rejea (Transaction)</th>
                <th>Hali</th>
                <th>Voucher Code</th>
                <th>Kitendo</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="empty"><i class="fa-solid fa-inbox" style="font-size:24px; margin-bottom:8px; display:block;"></i>Hakuna rekodi zilizopatikana.</td></tr>
        <?php else: foreach ($rows as $r):
            $ni_pending_kwama = ($r['status'] === 'pending') && ((time() - strtotime($r['created_at'])) > $STUCK_MINUTES * 60);
            $badge_class = 'badge-' . $r['status'];
        ?>
            <tr id="row-<?php echo htmlspecialchars($r['transaction_id']); ?>">
                <td style="color:var(--text-dim); font-size:12px;"><?php echo date('d M, H:i', strtotime($r['created_at'])); ?></td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($r['phone']); ?></td>
                <td><?php echo htmlspecialchars($r['package_type']); ?></td>
                <td style="font-weight:700; color:var(--accent);"><?php echo number_format($r['amount']); ?>/=</td>
                <td style="font-family:'Space Mono',monospace; font-size:11px; color:var(--text-dim);"><?php echo htmlspecialchars($r['transaction_id']); ?></td>
                <td>
                    <span class="badge <?php echo $badge_class; ?>" data-status-label><?php echo strtoupper($r['status']); ?></span>
                    <?php if ($ni_pending_kwama): ?><span class="stuck"><i class="fa-solid fa-triangle-exclamation"></i> Imekwama > <?php echo $STUCK_MINUTES; ?>m</span><?php endif; ?>
                </td>
                <td data-voucher><?php echo $r['voucher_code'] ? "<span class='voucher-code'>{$r['voucher_code']}</span>" : '-'; ?></td>
                <td>
                    <?php if (in_array($r['status'], ['pending', 'failed'])): ?>
                    <button class="btn-retry" onclick="jaribuTena('<?php echo htmlspecialchars($r['transaction_id']); ?>', this)"><i class="fa-solid fa-rotate-right"></i> Kukamilisha</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php $qs = $search !== '' ? '&q=' . urlencode($search) : ''; ?>
    <div class="page-footer">
        <span class="info">
            Jumla ya Miamala: <?php echo number_format($total_rows); ?> &nbsp;·&nbsp; Ukurasa <?php echo $page; ?> / <?php echo $total_pages; ?>
        </span>

        <a href="?page=1<?php echo $qs; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>"><i class="fa-solid fa-angles-left"></i></a>
        <a href="?page=<?php echo max(1, $page - 1); ?><?php echo $qs; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>"><i class="fa-solid fa-angle-left"></i></a>

        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end   = min($total_pages, $page + $range);
        for ($p = $start; $p <= $end; $p++):
        ?>
            <a href="?page=<?php echo $p; ?><?php echo $qs; ?>" class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>

        <a href="?page=<?php echo min($total_pages, $page + 1); ?><?php echo $qs; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><i class="fa-solid fa-angle-right"></i></a>
        <a href="?page=<?php echo $total_pages; ?><?php echo $qs; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><i class="fa-solid fa-angles-right"></i></a>
    </div>

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

<div class="toast" id="toast"></div>

<script>
function onyeshaToast(ujumbe) {
    const t = document.getElementById('toast');
    t.textContent = ujumbe;
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 4000);
}

function jaribuTena(ref, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inajaribu...';

    fetch('retry_payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'transaction_id=' + encodeURIComponent(ref)
    })
    .then(r => r.json())
    .then(data => {
        const row = document.getElementById('row-' + ref);
        if (data.status === 'completed') {
            row.querySelector('[data-status-label]').textContent = 'COMPLETED';
            row.querySelector('[data-status-label]').className = 'badge badge-completed';
            row.querySelector('[data-voucher]').innerHTML = "<span class='voucher-code'>" + data.voucher_code + "</span>";
            btn.remove();
            onyeshaToast('✅ Imekamilika! Voucher: ' + data.voucher_code);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Kukamilisha';
            onyeshaToast('⚠️ ' + (data.message || 'Bado imeshindikana.'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Kukamilisha';
        onyeshaToast('Hitilafu ya mtandao. Jaribu tena.');
    });
}

function thibitishaLogout() {
    document.getElementById('logoutModal').classList.add('active');
}
function fungaModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>
</body>
</html>