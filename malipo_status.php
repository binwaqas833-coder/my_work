<?php
session_start();
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$my_id = (int)$_SESSION['user_id'];

// ── Usafi wa kiotomatiki: 'pending' zilizokwama zaidi ya dakika 15 ──
// tunaziacha kama zilivyo (bado zinaweza kukamilishwa na "Jaribu Tena"),
// lakini tunaziweka alama ya "imekwama" kwenye UI (badala ya kubadilisha
// status yenyewe) ili admin aone haraka zipi zinahitaji hatua.
$STUCK_MINUTES = 15;

// ── Tafuta / chuja ──
$search = trim($_GET['q'] ?? '');
$where  = "WHERE user_id = ?";
$params = [$my_id];
$types  = "i";

if ($search !== '') {
    $where .= " AND (phone LIKE ? OR transaction_id LIKE ? OR voucher_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}

$sql = "SELECT * FROM payment_transactions $where ORDER BY created_at DESC LIMIT 100";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hali za Malipo</title>
<style>
    :root{--bg:#0d1b17;--surface:#132a22;--border:#1f4438;--accent:#07f793;--text:#e8f5ee;--text-muted:#8fa89c;--red:#ff5c5c;--amber:#ffb020;}
    *{box-sizing:border-box}
body{
    font-family:'DM Sans',sans-serif;
    background-color:#0d1b17;
    background-image:linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('beach5.jpg');
    background-size:cover;
    background-position:center;
    background-repeat:no-repeat;
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
    padding:24px;
}
    h1{font-size:20px;margin:0 0 4px;}
    p.sub{color:var(--text-muted);font-size:13px;margin:0 0 20px;}
    .search-bar{display:flex;gap:8px;margin-bottom:18px;max-width:420px;}
    .search-bar input{flex:1;padding:10px 14px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:14px;}
    .search-bar button{padding:10px 16px;border-radius:8px;border:none;background:var(--accent);color:#04231a;font-weight:600;cursor:pointer;}
    table{width:100%;border-collapse:collapse;background:var(--surface);border-radius:12px;overflow:hidden;font-size:13px;}
    th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);}
    th{color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;}
    tr:last-child td{border-bottom:none;}
    .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
    .badge-completed{background:rgba(7,247,147,0.15);color:var(--accent);}
    .badge-pending{background:rgba(255,176,32,0.15);color:var(--amber);}
    .badge-failed{background:rgba(255,92,92,0.15);color:var(--red);}
    .stuck{color:var(--amber);font-size:11px;display:block;margin-top:2px;}
    .btn-retry{padding:6px 12px;border-radius:6px;border:1px solid var(--accent);background:transparent;color:var(--accent);font-size:12px;cursor:pointer;}
    .btn-retry:hover{background:var(--accent);color:#04231a;}
    .btn-retry:disabled{opacity:0.5;cursor:not-allowed;}
    .empty{text-align:center;padding:40px;color:var(--text-muted);}
    .voucher-code{font-family:'Space Mono',monospace;color:var(--accent);font-weight:700;}
    .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface);border:1px solid var(--border);padding:10px 18px;border-radius:8px;font-size:13px;display:none;z-index:50;}
</style>
</head>
<body>

<h1>📊 Hali za Malipo</h1>
<p class="sub">Tafuta mteja aliyesema "nimelipia sikupata vocha" kwa namba yake ya simu, angalia hali halisi, na jaribu kukamilisha kama imekwama.
    &nbsp;·&nbsp; <a href="user_dashboard.php" style="color:var(--accent);text-decoration:none;">← Rudi Dashboard</a>
</p>

<form class="search-bar" method="GET">
    <input type="text" name="q" placeholder="Tafuta kwa namba ya simu, rejea, au voucher code..." value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit">Tafuta</button>
</form>

<table>
    <thead>
        <tr>
            <th>Muda</th>
            <th>Simu</th>
            <th>Kifurushi</th>
            <th>Kiasi</th>
            <th>Rejea</th>
            <th>Hali</th>
            <th>Voucher</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="empty">Hakuna rekodi zilizopatikana.</td></tr>
    <?php else: foreach ($rows as $r):
        $ni_pending_kwama = ($r['status'] === 'pending') && ((time() - strtotime($r['created_at'])) > $STUCK_MINUTES * 60);
        $badge_class = 'badge-' . $r['status'];
    ?>
        <tr id="row-<?php echo htmlspecialchars($r['transaction_id']); ?>">
            <td><?php echo date('d M, H:i', strtotime($r['created_at'])); ?></td>
            <td><?php echo htmlspecialchars($r['phone']); ?></td>
            <td><?php echo htmlspecialchars($r['package_type']); ?></td>
            <td><?php echo number_format($r['amount']); ?>/=</td>
            <td style="font-family:'Space Mono',monospace;font-size:11px;"><?php echo htmlspecialchars($r['transaction_id']); ?></td>
            <td>
                <span class="badge <?php echo $badge_class; ?>" data-status-label><?php echo strtoupper($r['status']); ?></span>
                <?php if ($ni_pending_kwama): ?><span class="stuck">⚠️ Imekwama zaidi ya <?php echo $STUCK_MINUTES; ?> dk</span><?php endif; ?>
            </td>
            <td data-voucher><?php echo $r['voucher_code'] ? "<span class='voucher-code'>{$r['voucher_code']}</span>" : '-'; ?></td>
            <td>
                <?php if (in_array($r['status'], ['pending', 'failed'])): ?>
                <button class="btn-retry" onclick="jaribuTena('<?php echo htmlspecialchars($r['transaction_id']); ?>', this)">Jaribu Kukamilisha</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

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
    btn.textContent = 'Inajaribu...';

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
            btn.textContent = 'Jaribu Kukamilisha';
            onyeshaToast('⚠️ ' + (data.message || 'Bado imeshindikana.'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Jaribu Kukamilisha';
        onyeshaToast('Hitilafu ya mtandao. Jaribu tena.');
    });
}
</script>
</body>
</html>