<?php
/**
 * cash_out.php
 * -------------------------------------------------------------
 * Reseller/Admin anaomba kutoa pesa anazomiliki KWA KILA ROUTER.
 *
 * SALIO LINATOKA WAPI (angalia balance_helper.php kwa maelezo kamili):
 *
 *     Mteja analipa (gross)
 *            -> ada ya 3.8% inakokotolewa MARA MOJA muamala unapokamilika
 *               (payment_helper.php) na kuhifadhiwa kwenye
 *               payment_transactions.fee_amount / net_amount
 *            -> net_amount ndiyo anayomiliki mwenye router
 *            -> salio la router = SUM(net_amount) - maombi ya cash-out
 *               yaliyopo (pending/awaiting_approval/success)
 *
 * MUHIMU: ukurasa huu HAUKATI 3.8% tena. net_amount tayari imekatwa.
 * Ukiona kukokotoa 3.8% hapa - ni hitilafu.
 *
 * MUHIMU (MULTI-ROUTER): kila ombi ni la router MOJA. Pesa za router A
 * haziwezi kuombwa kama salio la router B.
 * -------------------------------------------------------------
 */
session_start();
include 'auth_check.php';
include 'login_signup.php';
require_once 'dalipay_client.php';  // dalipayProviderFromPhone() - kuthibitisha namba MAPEMA
require_once 'balance_helper.php';  // CHANZO KIMOJA cha ada ya 3.8% na salio

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
$stmt_user = $conn->prepare("SELECT phone, email, username FROM users WHERE id = ?");
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

    $amount        = (float)($_POST['amount'] ?? 0);
    $post_router   = (int)($_POST['router_id'] ?? 0);

    if ($post_router <= 0) {
        $toast = ['type' => 'error', 'msg' => 'Tafadhali chagua router unayotaka kutoa pesa zake. ⚠️'];
    } elseif ($user_phone === '') {
        $toast = ['type' => 'error', 'msg' => 'Huna namba ya simu kwenye akaunti yako. Iweke kwenye Mipangilio kabla ya kuomba cash out. 🚫'];
    } elseif ($phone_provider === null) {
        $toast = ['type' => 'error', 'msg' => 'Namba yako (' . htmlspecialchars($user_phone) . ') si ya mtandao unaopokea malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel. 🚫'];
    } else {
        // Ukaguzi WOTE wa salio (pamoja na lock dhidi ya maombi mawili ya
        // haraka) uko ndani ya requestRouterPayout(). Hakuna kukokotoa
        // salio wala ada hapa - chanzo kimoja cha ukweli.
        $res = requestRouterPayout($conn, $user_id, $post_router, $user_phone,
                                   $amount, MIN_PAYOUT, MAX_PAYOUT);

        $toast = ['type' => $res['ok'] ? 'success' : 'error',
                  'msg'  => $res['msg'] . ($res['ok'] ? ' ✅' : ' 🚫')];

        // Baada ya ombi kufanikiwa, mbaki kwenye router ile ile ili aone
        // salio jipya mara moja.
        $_SESSION['active_router_id'] = $post_router;
    }
}

// 3. Salio la kila router (baada ya POST, ili nambari zionyeshwe zikiwa mpya)
$balances = getOwnerRouterBalances($conn, $user_id);
$routers  = $balances['routers'];
$totals   = $balances['totals'];

// 4. Router iliyochaguliwa: ?router_id= -> POST -> session -> ya kwanza
$selected_id = (int)($_GET['router_id'] ?? $_POST['router_id'] ?? $_SESSION['active_router_id'] ?? 0);
if (!isset($routers[$selected_id])) {
    $selected_id = $routers ? (int)array_key_first($routers) : 0;
}
$selected = $routers[$selected_id] ?? null;
if ($selected_id > 0) {
    $_SESSION['active_router_id'] = $selected_id;
}

// 5. Historia ya maombi ya cash-out ya router hii (ili reseller aone
//    kilichotolewa, kinachosubiri, na kilichokataliwa - siyo tu jumla).
$history = [];
if ($selected_id > 0) {
    $h = $conn->prepare(
        "SELECT id, amount, status, fail_reason, created_at, approved_at
           FROM payout_requests
          WHERE user_id = ? AND router_id = ?
          ORDER BY id DESC
          LIMIT 15"
    );
    $h->bind_param("ii", $user_id, $selected_id);
    $h->execute();
    $hr = $h->get_result();
    while ($row = $hr->fetch_assoc()) {
        $history[] = $row;
    }
    $h->close();
}

/** Jina la Kiswahili + rangi kwa kila hali ya ombi. */
function payoutStatusLabel(string $status): array
{
    switch ($status) {
        case 'success':            return ['Imelipwa',           'var(--accent)'];
        case 'failed':             return ['Imeshindikana',      'var(--red)'];
        case 'rejected':           return ['Imekataliwa',        'var(--red)'];
        case 'awaiting_approval':  return ['Inasubiri Gateway',  '#ffb547'];
        case 'approved':           return ['Imeidhinishwa',      'var(--accent2)'];
        default:                   return ['Inasubiri Admin',    '#ffb547'];
    }
}

$available = (float)($selected['available'] ?? 0);
$can_submit = $selected !== null
              && $available >= MIN_PAYOUT
              && $user_phone !== ''
              && $phone_provider !== null;
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

    /* ── Mchanganuo wa salio: gross -> ada 3.8% -> available ── */
    .bal-breakdown{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:10px;
        margin-top:18px;
        padding-top:16px;
        border-top:1px solid var(--border2);
        text-align:left;
    }
    .bal-cell .k{
        font-size:10.5px;
        color:var(--text-dim);
        text-transform:uppercase;
        letter-spacing:0.8px;
        display:block;
        margin-bottom:3px;
    }
    .bal-cell .v{
        font-family:'Space Mono',monospace;
        font-size:14px;
        font-weight:700;
        color:#fff;
    }
    .bal-cell .v.fee{ color:#ffb547; }
    .bal-cell .v.held{ color:var(--accent2); }

    .router-picker{
        background:var(--surface);
        backdrop-filter:var(--blur);
        -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2);
        border-radius:var(--radius);
        padding:16px 20px;
        margin-bottom:20px;
    }
    .router-picker label{
        display:block;
        font-size:11px;
        color:var(--text-dim);
        text-transform:uppercase;
        letter-spacing:0.8px;
        margin-bottom:8px;
    }
    .router-picker select{
        width:100%;
        padding:11px 14px;
        border-radius:9px;
        border:1px solid rgba(255,255,255,0.20);
        background:rgba(0,0,0,0.45);
        color:#fff;
        font-size:14px;
        font-family:'DM Sans',sans-serif;
        outline:none;
        cursor:pointer;
    }
    .router-picker select:focus{ border-color:var(--accent); }
    .router-picker option{ background:#0d1b17; color:#fff; }

    .router-table{
        width:100%;
        border-collapse:collapse;
        font-size:12.5px;
        margin-top:12px;
    }
    .router-table th{
        text-align:right;
        font-size:10px;
        color:var(--text-dim);
        text-transform:uppercase;
        letter-spacing:0.7px;
        font-weight:600;
        padding:7px 8px;
        border-bottom:1px solid var(--border2);
    }
    .router-table th:first-child{ text-align:left; }
    .router-table td{
        padding:9px 8px;
        border-bottom:1px solid rgba(255,255,255,0.08);
        text-align:right;
        font-family:'Space Mono',monospace;
        font-size:12px;
    }
    .router-table td:first-child{
        text-align:left;
        font-family:'DM Sans',sans-serif;
        font-size:13px;
    }
    .router-table tr.is-selected td{ background:rgba(7,247,147,0.08); }
    .router-table tr.total-row td{
        border-bottom:none;
        border-top:1px solid var(--border2);
        font-weight:700;
        color:var(--accent);
    }
    .table-scroll{ overflow-x:auto; }

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
<link rel="stylesheet" href="responsive.css">
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

    <?php if (!$routers): ?>

    <div class="form-card" style="text-align:center;">
        <h3 style="justify-content:center;"><i class="fa-solid fa-router" style="color:var(--accent2);"></i> Huna Router Yoyote</h3>
        <p style="font-size:13px;color:var(--text-dim);margin-bottom:16px;">
            Cash out inafanyika kwa kila router. Sajili router yako kwanza.
        </p>
        <a class="back-btn" href="my_mikrotiks.php"><i class="fa-solid fa-plus"></i> Ongeza Router</a>
    </div>

    <?php else: ?>

    <!-- Chagua Router: kila router ina salio lake -->
    <div class="router-picker">
        <label><i class="fa-solid fa-router"></i> Chagua Router</label>
        <form method="GET" id="routerForm">
            <select name="router_id" onchange="document.getElementById('routerForm').submit();">
                <?php foreach ($routers as $rid => $r): ?>
                    <option value="<?php echo $rid; ?>" <?php echo $rid === $selected_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($r['router_label']); ?>
                        — TSh <?php echo number_format($r['available'], 2); ?> inapatikana
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Salio la router iliyochaguliwa -->
    <div class="bal-card">
        <div class="bal-title">
            <i class="fa-solid fa-wallet"></i>
            Salio Linalopatikana · <?php echo htmlspecialchars($selected['router_label']); ?>
        </div>
        <div class="bal-amount">TSh <?php echo number_format($available, 2); ?></div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">
            Kiasi hiki tayari kimeshakatwa ada ya <?php echo PLATFORM_FEE_PERCENT; ?>%
        </div>

        <div class="bal-breakdown">
            <div class="bal-cell">
                <span class="k">Malipo Ghafi (Gross)</span>
                <span class="v">TSh <?php echo number_format($selected['gross'], 2); ?></span>
            </div>
            <div class="bal-cell">
                <span class="k">Ada ya Muamala (<?php echo PLATFORM_FEE_PERCENT; ?>%)</span>
                <span class="v fee">− TSh <?php echo number_format($selected['fees'], 2); ?></span>
            </div>
            <div class="bal-cell">
                <span class="k">Umeshatolewa</span>
                <span class="v">− TSh <?php echo number_format($selected['paid_out'], 2); ?></span>
            </div>
            <div class="bal-cell">
                <span class="k">Maombi Yanayosubiri</span>
                <span class="v held">− TSh <?php echo number_format($selected['held'], 2); ?></span>
            </div>
        </div>

        <div style="font-size:10.5px;color:var(--text-dim);margin-top:12px;font-family:'Space Mono',monospace;">
            <?php echo (int)$selected['txn_count']; ?> malipo yaliyokamilika kwenye router hii
        </div>
    </div>

    <!-- Cash Out Form -->
    <div class="form-card">
        <h3><i class="fa-solid fa-hand-holding-dollar" style="color:var(--accent);"></i> Tuma Ombi la Payout</h3>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <!-- Router ni sehemu ya ombi: seva inathibitisha tena kuwa ni yako -->
            <input type="hidden" name="router_id" value="<?php echo $selected_id; ?>">

            <label>Router</label>
            <input type="text" value="<?php echo htmlspecialchars($selected['router_label']); ?>" readonly>

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
            <input type="number" name="amount" min="<?php echo MIN_PAYOUT; ?>"
                   max="<?php echo $available; ?>" step="any"
                   placeholder="Mfano: <?php echo number_format(min($available, 5000), 0, '.', ''); ?>"
                   <?php echo $can_submit ? '' : 'disabled'; ?> required>

            <?php if ($selected !== null && $available < MIN_PAYOUT): ?>
                <p style="font-size:12px;color:var(--red);margin:-10px 0 16px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Salio la router hii (TSh <?php echo number_format($available, 2); ?>)
                    halijafikia kiwango cha chini cha TSh <?php echo number_format(MIN_PAYOUT); ?>.
                </p>
            <?php endif; ?>

            <button type="submit" name="request_cashout" class="btn-submit"
                    <?php echo $can_submit ? '' : 'disabled style="opacity:0.45;cursor:not-allowed;"'; ?>>
                <i class="fa-solid fa-paper-plane"></i> Thibitisha na Tuma Ombi
            </button>
        </form>
    </div>

    <!-- Historia ya cash-out ya router hii -->
    <div class="form-card" style="margin-top:20px;">
        <h3><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent2);"></i> Historia ya Cash Out · <?php echo htmlspecialchars($selected['router_label']); ?></h3>
        <?php if (!$history): ?>
            <p style="font-size:13px;color:var(--text-dim);">Bado hujaomba cash out kwenye router hii.</p>
        <?php else: ?>
        <div class="table-scroll">
            <table class="router-table">
                <thead>
                    <tr>
                        <th>Tarehe</th>
                        <th>Kiasi (TSh)</th>
                        <th>Hali</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $hrow):
                    list($hlabel, $hcolor) = payoutStatusLabel($hrow['status']); ?>
                    <tr>
                        <td style="font-family:'Space Mono',monospace;font-size:11.5px;color:var(--text-dim);">
                            <?php echo date('d M Y, H:i', strtotime($hrow['created_at'])); ?>
                        </td>
                        <td><?php echo number_format($hrow['amount'], 2); ?></td>
                        <td style="color:<?php echo $hcolor; ?>;font-family:'DM Sans',sans-serif;font-size:12px;">
                            <?php echo $hlabel; ?>
                            <?php if (!empty($hrow['fail_reason']) && in_array($hrow['status'], ['failed','rejected'], true)): ?>
                                <div style="font-size:10.5px;color:var(--text-dim);margin-top:2px;">
                                    <?php echo htmlspecialchars($hrow['fail_reason']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size:11px;color:var(--text-dim);margin-top:12px;">
            Maombi yaliyo <strong>Inasubiri</strong> au <strong>Imelipwa</strong> tayari yamepunguzwa
            kwenye salio hapo juu. Ombi likikataliwa, pesa inarudi yenyewe.
        </p>
        <?php endif; ?>
    </div>

    <!-- Muhtasari wa routers ZOTE: pesa za router moja haziingiliani na nyingine -->
    <?php if (count($routers) > 1): ?>
    <div class="form-card" style="margin-top:20px;">
        <h3><i class="fa-solid fa-list-ul" style="color:var(--accent2);"></i> Salio la Kila Router</h3>
        <div class="table-scroll">
            <table class="router-table">
                <thead>
                    <tr>
                        <th>Router</th>
                        <th>Gross</th>
                        <th>Ada <?php echo PLATFORM_FEE_PERCENT; ?>%</th>
                        <th>Inapatikana</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($routers as $rid => $r): ?>
                    <tr class="<?php echo $rid === $selected_id ? 'is-selected' : ''; ?>">
                        <td>
                            <a href="?router_id=<?php echo $rid; ?>" style="color:#fff;text-decoration:none;">
                                <?php echo htmlspecialchars($r['router_label']); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($r['gross'], 2); ?></td>
                        <td style="color:#ffb547;"><?php echo number_format($r['fees'], 2); ?></td>
                        <td style="color:var(--accent);font-weight:700;"><?php echo number_format($r['available'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="total-row">
                        <td>JUMLA</td>
                        <td><?php echo number_format($totals['gross'], 2); ?></td>
                        <td><?php echo number_format($totals['fees'], 2); ?></td>
                        <td><?php echo number_format($totals['available'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* $routers */ ?>

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