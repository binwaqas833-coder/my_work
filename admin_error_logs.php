<?php
/**
 * admin_error_logs.php
 * -------------------------------------------------------------
 * Admin anaona makosa yote yaliyoandikwa na logSystemError() kutoka
 * mfumo mzima (MikroTik, malipo, database, n.k.), na anaweza kuchuja
 * kwa 'source' au kufuta zilizopitwa na wakati.
 * -------------------------------------------------------------
 */
session_start();
include 'login_signup.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// ── AJAX: futa error logs zote (au zilizo zaidi ya siku X) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'clear_logs') {
    header('Content-Type: application/json');
    $days = (int)($_POST['older_than_days'] ?? 0);
    if ($days > 0) {
        $stmt = $conn->prepare("DELETE FROM error_logs WHERE created_at < (NOW() - INTERVAL ? DAY)");
        $stmt->bind_param("i", $days);
        $stmt->execute();
    } else {
        $conn->query("DELETE FROM error_logs");
    }
    echo json_encode(['status' => 'success', 'message' => 'Error logs zimefutwa.']);
    exit();
}

// ── CHUJA ──
$source_filter = trim($_GET['source'] ?? '');
$where = "1=1";
$params = [];
$types = "";
if ($source_filter !== '') {
    $where .= " AND source = ?";
    $params[] = $source_filter;
    $types .= "s";
}

$PER_PAGE = 40;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;

$count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM error_logs WHERE $where");
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = (int)$count_stmt->get_result()->fetch_assoc()['c'];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total / $PER_PAGE));

$sql = "SELECT * FROM error_logs WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$all_params = array_merge($params, [$PER_PAGE, $offset]);
$stmt->bind_param($types . "ii", ...$all_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Orodha ya "sources" tofauti (kwa dropdown ya kuchuja)
$sources = $conn->query("SELECT DISTINCT source FROM error_logs ORDER BY source ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Error Logs · Tech 5G Admin</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preload" as="image" href="beach5.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    :root{
        --surface: rgba(255,255,255,0.14);
        --surface-card: rgba(15, 25, 38, 0.75);
        --border: rgba(255,255,255,0.20);
        --border-light: rgba(255,255,255,0.12);
        --accent: #07f793;
        --text: #ffffff;
        --text-dim: rgba(255,255,255,0.65);
        --red: #ff3d57;
        --radius: 14px;
        --blur: blur(20px);
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{
        font-family:'DM Sans',sans-serif;
        background-color:#0b1319;
        background-image:linear-gradient(rgba(0,0,0,0.65), rgba(0,0,0,0.65)), url(beach5.jpg);
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
        background:rgba(0,0,0,0.25);
        pointer-events:none;
        z-index:0;
    }
    .wrap{
        max-width:1100px;
        margin:0 auto;
        width:100%;
        position:relative;
        z-index:1;
        flex:1;
    }

    /* Header & Branding */
    .header-card{
        background:var(--surface-card);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border);
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
        border:1px solid var(--border-light);
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

    /* Toolbar & Content */
    .toolbar{
        display:flex;
        gap:10px;
        margin-bottom:20px;
        flex-wrap:wrap;
        align-items:center;
        background:var(--surface-card);
        padding:14px 18px;
        border-radius:var(--radius);
        border:1px solid var(--border-light);
        backdrop-filter:var(--blur);
    }
    select, input{
        padding:9px 12px;
        border-radius:8px;
        border:1px solid var(--border);
        background:rgba(0,0,0,0.35);
        color:var(--text);
        font-size:13px;
        outline:none;
    }
    .btn{
        padding:9px 14px;
        border-radius:8px;
        border:none;
        font-weight:700;
        font-size:12.5px;
        cursor:pointer;
        display:inline-flex;
        align-items:center;
        gap:6px;
        transition:all 0.2s;
    }
    .btn-red{
        background:rgba(255,61,87,0.15);
        color:var(--red);
        border:1px solid rgba(255,61,87,0.35);
    }
    .btn-red:hover{
        background:rgba(255,61,87,0.28);
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
        border-radius: var(--radius);
        border: 1px solid var(--border-light);
        background: var(--surface-card);
        backdrop-filter: var(--blur);
    }
    table{
        width:100%;
        border-collapse:collapse;
        font-size:13px;
    }
    th,td{
        padding:12px 14px;
        text-align:left;
        border-bottom:1px solid var(--border-light);
    }
    th{
        color:var(--text-dim);
        font-size:11px;
        text-transform:uppercase;
        letter-spacing:1px;
        background:rgba(0,0,0,0.2);
    }
    .src-tag{
        background:rgba(255,176,32,0.15);
        color:#ffb020;
        border:1px solid rgba(255,176,32,0.3);
        padding:3px 10px;
        border-radius:14px;
        font-size:10.5px;
        font-weight:700;
        display:inline-block;
    }
    .msg{
        max-width:420px;
        word-break:break-word;
    }
    .ctx{
        color:var(--text-dim);
        font-size:11px;
        font-family:'Space Mono',monospace;
    }
    .pager{
        display:flex;
        gap:6px;
        margin-top:18px;
        flex-wrap:wrap;
    }
    .pager a{
        padding:7px 14px;
        border-radius:8px;
        border:1px solid var(--border-light);
        background:rgba(255,255,255,0.05);
        color:var(--text);
        text-decoration:none;
        font-size:12.5px;
    }
    .pager a.active{
        background:var(--accent);
        color:#04231a;
        border-color:var(--accent);
        font-weight:700;
    }
    .empty{
        text-align:center;
        padding:50px;
        color:var(--text-dim);
        background:var(--surface-card);
        border-radius:var(--radius);
        border:1px solid var(--border-light);
        backdrop-filter:var(--blur);
    }

    /* Pop-up Modal ya Logout */
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
        background:rgba(15,30,50,0.95);
        border:1px solid rgba(255,255,255,0.18);
        padding:26px;
        border-radius:16px;
        width:90%;
        max-width:400px;
        color:#fff;
    }

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

    <!-- Header Section -->
    <div class="header-card">
        <div class="brand-logo">
            <div class="brand-icon"><i class="fa-solid fa-bug"></i></div>
            <div>
                <div class="brand-name">Tech 5G Wi-Fi</div>
                <div class="brand-sub"><i class="fa-solid fa-shield-halved"></i> Admin Error Logs</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="back-btn" href="admin.php"><i class="fa-solid fa-arrow-left"></i> Rudi Admin Dashboard</a>
            <button class="logout-btn-header" onclick="thibitishaLogout()"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
        </div>
    </div>

    <!-- Toolbar Section -->
    <div class="toolbar">
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <select name="source" onchange="this.form.submit()">
                <option value="">Sources zote</option>
                <?php foreach ($sources as $s): ?>
                    <option value="<?php echo htmlspecialchars($s['source']); ?>" <?php echo $source_filter === $s['source'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['source']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button class="btn btn-red" onclick="futaLogs(7)"><i class="fa-solid fa-trash-can"></i> Futa > siku 7</button>
        <button class="btn btn-red" onclick="futaLogs(30)"><i class="fa-solid fa-trash-can"></i> Futa > siku 30</button>
        <button class="btn btn-red" onclick="futaLogs(0)"><i class="fa-solid fa-dumpster"></i> Futa Zote</button>
        <span style="color:var(--text-dim);font-size:12.5px;margin-left:auto;">Jumla: <b><?php echo number_format($total); ?></b></span>
    </div>

    <?php if (empty($rows)): ?>
        <div class="empty">
            <i class="fa-solid fa-circle-check" style="font-size:36px;color:var(--accent);margin-bottom:10px;display:block;"></i>
            Hakuna error logs zilizopatikana. Mfumo uko safi! 🎉
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Muda</th>
                    <th>Source</th>
                    <th>User / Router</th>
                    <th>Ujumbe</th>
                    <th>Context</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap;color:var(--text-dim);font-size:11.5px;font-family:'Space Mono',monospace;">
                        <?php echo date('d M, H:i:s', strtotime($r['created_at'])); ?>
                    </td>
                    <td><span class="src-tag"><?php echo htmlspecialchars($r['source']); ?></span></td>
                    <td style="font-size:11.5px;font-family:'Space Mono',monospace;">
                        <?php echo $r['user_id'] ? 'U:' . (int)$r['user_id'] : '—'; ?>
                        <?php echo $r['router_id'] ? ' / R:' . (int)$r['router_id'] : ''; ?>
                    </td>
                    <td class="msg"><?php echo htmlspecialchars($r['message']); ?></td>
                    <td class="ctx"><?php echo $r['context'] ? htmlspecialchars($r['context']) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pager">
        <?php
        $qs = $source_filter !== '' ? '&source=' . urlencode($source_filter) : '';
        for ($p = 1; $p <= $total_pages; $p++):
        ?>
            <a href="?page=<?php echo $p; ?><?php echo $qs; ?>" class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Modal ya Logout Confirmation -->
<div id="logoutModal" class="modal-overlay">
    <div class="modal-content">
        <h4 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800;margin-bottom:12px;color:#fff;">
            <i class="fa-solid fa-right-from-bracket" style="color:var(--red);"></i> Unahakika Unataka Kutoka?
        </h4>
        <p style="font-size:13px;color:var(--text-dim);margin-bottom:20px;">
            Je, una uhakika unataka kuondoka kwenye mfumo wa Admin?
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

function futaLogs(days) {
    if (!confirm('Una uhakika unataka kufuta error logs hizi? Hatua hii haiwezi kutenduliwa.')) return;
    fetch('admin_error_logs.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_action=clear_logs&older_than_days=' + days
    })
    .then(r => r.json())
    .then(() => location.reload())
    .catch(() => alert('Hitilafu ya mtandao.'));
}
</script>

</body>
</html>