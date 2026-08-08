<?php
/**
 * cash_out.php
 * -------------------------------------------------------------
 * Reseller anaomba kutoa salio lake lililopo kwenye akaunti (users.balance).
 * Ombi linakwenda kwenye payout_requests na salio linakatwa.
 * -------------------------------------------------------------
 */
session_start();
include 'auth_check.php';
include 'login_signup.php';
require_once 'dalipay_client.php'; // dalipayProviderFromPhone() - kuthibitisha namba MAPEMA

// Kikomo cha chini cha ombi moja. Kilikuwa kwenye HTML (min="1000") pekee,
// hivyo ombi lililotengenezwa kwa mkono lingeweza kupita likiwa TSh 1.
define('MIN_PAYOUT', 1000);
define('MAX_PAYOUT', 5000000); // kikomo cha gateway (Dalipay)

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// CSRF Token Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 1. Leta taarifa za Reseller
$stmt_user = $conn->prepare("SELECT phone, email, username, balance FROM users WHERE id = ?");
if (!$stmt_user) {
    die("Database Error: " . $conn->error);
}
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$reseller = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

// MUHIMU: tenganisha thamani HALISI na maandishi ya kuonyesha. Awali zote mbili
// zilikuwa kitu kimoja, hivyo reseller asiye na namba alihifadhiwa maneno
// 'Haujaweka namba' kwenye payout_requests.phone_number - na maneno hayo ndiyo
// yaliyotumwa Dalipay kama namba ya mpokeaji.
$user_phone    = trim((string)($reseller['phone'] ?? ''));
$phone_display = $user_phone !== '' ? $user_phone : 'Haujaweka namba';
$total_balance = (float)($reseller['balance'] ?? 0);

// Namba lazima iwe ya mtandao unaokubaliwa na gateway. Tunakagua HAPA ili
// reseller ajue mara moja, badala ya ombi kushikilia salio lake kisha likwame
// wakati admin anaidhinisha.
$phone_provider = $user_phone !== '' ? dalipayProviderFromPhone($user_phone) : null;

// 2. Kuchakata Ombi la Cash Out
$toast = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_cashout'])) {
    
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Ombi hili si salama (Invalid CSRF Token).");
    }

    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount <= 0) {
        $toast = ['type' => 'error', 'msg' => 'Tafadhali weka kiasi sahihi cha fedha. ⚠️'];
    } elseif ($amount < MIN_PAYOUT) {
        $toast = ['type' => 'error', 'msg' => 'Kiasi cha chini kwa ombi moja ni TSh ' . number_format(MIN_PAYOUT) . '. ⚠️'];
    } elseif ($amount > MAX_PAYOUT) {
        // Kikomo cha gateway yenyewe (Dalipay: 1 - 5,000,000). Tunakikagua HAPA
        // ili reseller ajue mara moja, badala ya ombi kushikilia salio lake
        // kisha likataliwe wakati admin anaidhinisha.
        $toast = ['type' => 'error', 'msg' => 'Kiasi cha juu kwa ombi moja ni TSh ' . number_format(MAX_PAYOUT) . '. Gawa ombi lako. ⚠️'];
    } elseif ($user_phone === '') {
        $toast = ['type' => 'error', 'msg' => 'Huna namba ya simu kwenye akaunti yako. Iweke kwenye Mipangilio kabla ya kuomba cash out. 🚫'];
    } elseif ($phone_provider === null) {
        $toast = ['type' => 'error', 'msg' => 'Namba yako (' . htmlspecialchars($user_phone) . ') si ya mtandao unaopokea malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel. 🚫'];
    } elseif ($amount > $total_balance) {
        $toast = ['type' => 'error', 'msg' => "Huwezi kutoa kiasi kinachozidi salio lako la sasa (TSh " . number_format($total_balance, 2) . "). 🚫"];
    } else {
        $conn->begin_transaction();

        try {
            // A. Punguza salio KWANZA, kwa masharti (atomic).
            // Sharti la 'balance >= ?' ndilo linalozuia maombi mawili yanayokuja
            // kwa pamoja (bofya-mara-mbili, au tabo mbili) kupita ukaguzi wa juu
            // yote mawili na kuacha salio HASI. Ukaguzi wa juu unasoma salio la
            // KABLA ya transaction, hivyo peke yake hautoshi.
            $stmt_deduct = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            if (!$stmt_deduct) {
                throw new Exception("Error kwenye kubadilisha balance: " . $conn->error);
            }
            $stmt_deduct->bind_param("did", $amount, $user_id, $amount);
            $stmt_deduct->execute();
            $deducted = ($stmt_deduct->affected_rows === 1);
            $stmt_deduct->close();

            if (!$deducted) {
                throw new Exception('Salio halikutosha wakati ombi linachakatwa.');
            }

            // B. Hifadhi ombi kwenye payout_requests
            $stmt_req = $conn->prepare("INSERT INTO payout_requests (user_id, phone_number, amount, status) VALUES (?, ?, ?, 'pending')");
            if (!$stmt_req) {
                throw new Exception("Error kwenye payout_requests: " . $conn->error);
            }
            $stmt_req->bind_param("isd", $user_id, $user_phone, $amount);
            $stmt_req->execute();
            $stmt_req->close();

            $conn->commit();

            $total_balance -= $amount;
            $toast = ['type' => 'success', 'msg' => "Ombi lako la Cash Out la TSh " . number_format($amount, 2) . " limepelekwa kwa Admin kikamilifu! ✅"];

        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('cash_out.php: ' . $e->getMessage());
            $toast = ['type' => 'error', 'msg' => 'Hitilafu imetokea, ombi halikupokelewa. Salio lako halijaguswa. Jaribu tena. ❌'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Out · Tech 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preload" as="image" href="beach5.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    :root{
        --surface: rgba(255,255,255,0.18);
        --surface2: rgba(255,255,255,0.10);
        --border: rgba(255,255,255,0.35);
        --border2: rgba(255,255,255,0.20);
        --accent: #07f793;
        --accent2: #3fc7fd;
        --text: #fff;
        --text-dim: rgba(255,255,255,0.65);
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
        max-width:650px;
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

    .bal-card{
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--accent);
        border-radius:var(--radius);
        padding:22px;
        margin-bottom:20px;
        text-align:center;
        box-shadow:0 0 20px rgba(7,247,147,0.15);
    }
    .bal-title{
        font-size:12px;
        color:var(--text-dim);
        text-transform:uppercase;
        letter-spacing:1px;
    }
    .bal-amount{
        font-family:'Syne',sans-serif;
        font-size:32px;
        font-weight:800;
        color:var(--accent);
        margin-top:6px;
    }

    .form-card{
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2);
        border-radius:var(--radius);
        padding:24px;
    }
    .form-card h3{
        margin:0 0 16px;
        font-size:17px;
        font-family:'Syne',sans-serif;
        color:#fff;
        display:flex;
        align-items:center;
        gap:8px;
    }
    .form-card label{
        display:block;
        font-size:11.5px;
        color:var(--text-dim);
        margin-bottom:6px;
        letter-spacing:0.5px;
        text-transform:uppercase;
    }
    .form-card input{
        width:100%;
        padding:12px 14px;
        margin-bottom:16px;
        border-radius:9px;
        border:1px solid rgba(255,255,255,0.20);
        background:rgba(0,0,0,0.30);
        color:var(--text);
        font-size:14px;
        outline:none;
        transition:border-color 0.2s;
    }
    .form-card input:focus{
        border-color:var(--accent);
    }
    .form-card input[readonly]{
        background:rgba(255,255,255,0.05);
        color:var(--text-dim);
    }

    .btn-submit{
        width:100%;
        padding:12px;
        border-radius:8px;
        border:none;
        background:var(--accent);
        color:#04231a;
        font-weight:800;
        font-size:14px;
        cursor:pointer;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        transition:all 0.2s;
    }
    .btn-submit:hover{
        filter:brightness(1.1);
        transform:translateY(-1px);
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

    <!-- Header Section -->
    <div class="header-card">
        <div class="brand-logo">
            <div class="brand-icon"><i class="fa-solid fa-wifi"></i></div>
            <div>
                <div class="brand-name">Tech 5G Wi-Fi</div>
                <div class="brand-sub"><i class="fa-solid fa-money-bill-transfer"></i> Ombi la Cash Out</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="back-btn" href="user_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Rudi Dashboard</a>
            <button class="logout-btn-header" onclick="thibitishaLogout()"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
        </div>
    </div>

    <?php if ($toast): ?>
        <div class="toast-box <?php echo $toast['type']; ?>"><?php echo htmlspecialchars($toast['msg']); ?></div>
    <?php endif; ?>

    <!-- Balance Box -->
    <div class="bal-card">
        <div class="bal-title"><i class="fa-solid fa-wallet"></i> Salio Lako la Sasa</div>
        <div class="bal-amount">TSh <?php echo number_format($total_balance, 2); ?></div>
    </div>

    <!-- Cash Out Form -->
    <div class="form-card">
        <h3><i class="fa-solid fa-hand-holding-dollar" style="color:var(--accent);"></i> Tuma Ombi la Payout</h3>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <label>Namba ya Simu ya Kupokelea (Registration Phone)</label>
            <input type="text" value="<?php echo htmlspecialchars($phone_display); ?>" readonly>
            <?php if ($user_phone === '' || $phone_provider === null): ?>
                <p style="font-size:12px;color:var(--red);margin:-10px 0 16px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <?php echo $user_phone === ''
                        ? 'Huna namba ya simu. Iweke kwenye Mipangilio kabla ya kuomba cash out.'
                        : 'Mtandao wa namba hii haupokei malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel.'; ?>
                </p>
            <?php endif; ?>

            <label>Kiwango Unachotaka Kutoa (TSh)</label>
            <input type="number" name="amount" min="<?php echo MIN_PAYOUT; ?>" max="<?php echo (float)$total_balance; ?>" step="any" placeholder="Mfano: 5000" required>

            <button type="submit" name="request_cashout" class="btn-submit">
                <i class="fa-solid fa-paper-plane"></i> Thibitisha na Tuma Ombi
            </button>
        </form>
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