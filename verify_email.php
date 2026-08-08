<?php
/**
 * verify_email.php
 * -------------------------------------------------------------
 * Mtumiaji anaweka OTP ya tarakimu 6 aliyotumiwa kwenye barua pepe.
 *
 * MUHIMU: ukurasa huu HAUHITAJI kuwa umeingia mfumoni. Unatumia
 * $_SESSION['pending_verify_user_id'] - session ya "kuthibitisha tu"
 * inayowekwa na process_engine.php. Haitoi ruhusa yoyote ya dashboard.
 * -------------------------------------------------------------
 */
session_start();
include 'login_signup.php';
require_once 'otp_helper.php';

$pending_id = (int)($_SESSION['pending_verify_user_id'] ?? 0);
if ($pending_id <= 0) {
    header("Location: index.php?msg=" . urlencode('Anza kwa kusajili au kuingia.'));
    exit();
}

// Taarifa za mtumiaji anayesubiri uthibitisho
$u = $conn->prepare("SELECT username, email, email_verified FROM users WHERE id = ? LIMIT 1");
$u->bind_param("i", $pending_id);
$u->execute();
$mtumiaji = $u->get_result()->fetch_assoc();
$u->close();

if (!$mtumiaji) {
    unset($_SESSION['pending_verify_user_id']);
    header("Location: index.php?msg=" . urlencode('Akaunti haijapatikana.'));
    exit();
}

if ((int)$mtumiaji['email_verified'] === 1) {
    unset($_SESSION['pending_verify_user_id']);
    header("Location: index.php?msg=" . urlencode('Barua pepe imethibitishwa. Sasa unaweza kuingia.'));
    exit();
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$toast = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $toast = ['type' => 'error', 'msg' => 'Ombi si salama. Jaribu tena.'];
    } elseif (($_POST['kitendo'] ?? '') === 'tuma_tena') {
        $res   = otpGenerateAndSend($conn, $pending_id, (string)$mtumiaji['email'], (string)$mtumiaji['username']);
        $toast = ['type' => $res['ok'] ? 'success' : 'error', 'msg' => $res['msg']];
    } else {
        $res = otpVerify($conn, $pending_id, (string)($_POST['otp'] ?? ''));
        if ($res['ok']) {
            unset($_SESSION['pending_verify_user_id']);
            header("Location: index.php?msg=" . urlencode('Barua pepe imethibitishwa! Subiri Admin akuidhinishe kisha uingie.'));
            exit();
        }
        $toast = ['type' => 'error', 'msg' => $res['msg']];
    }
}

// Ficha sehemu ya email kwa faragha: john@site.com -> jo**@site.com
$email_iliyofichwa = (function (string $e): string {
    $at = strpos($e, '@');
    if ($at === false || $at < 2) return $e;
    return substr($e, 0, 2) . str_repeat('*', max(2, $at - 2)) . substr($e, $at);
})((string)$mtumiaji['email']);
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Thibitisha Barua Pepe · Tech 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    :root{
        --surface: rgba(255,255,255,0.18);
        --border2: rgba(255,255,255,0.20);
        --accent: #07f793;
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
        background-image:linear-gradient(rgba(0,0,0,0.55),rgba(0,0,0,0.55)),url(beach5.jpg);
        background-size:cover; background-position:center; background-attachment:fixed;
        color:var(--text); min-height:100vh;
        display:flex; align-items:center; justify-content:center; padding:24px 16px;
    }
    .card{
        background:var(--surface);
        backdrop-filter:var(--blur); -webkit-backdrop-filter:var(--blur);
        border:1px solid var(--border2); border-radius:var(--radius);
        padding:30px 26px; max-width:440px; width:100%; text-align:center;
    }
    .icon{
        width:60px; height:60px; margin:0 auto 16px;
        background:linear-gradient(135deg,var(--accent),#00a86b);
        border-radius:14px; display:grid; place-items:center;
        font-size:26px; color:#04231a;
        box-shadow:0 0 24px rgba(7,247,147,0.35);
    }
    h1{font-family:'Syne',sans-serif; font-size:21px; font-weight:800; margin-bottom:8px;}
    .sub{font-size:13.5px; color:var(--text-dim); line-height:1.6; margin-bottom:22px;}
    .sub b{color:var(--text); font-family:'Space Mono',monospace;}
    .otp-input{
        width:100%; padding:16px; text-align:center;
        font-size:30px; font-family:'Space Mono',monospace; font-weight:700;
        letter-spacing:12px; text-indent:12px;
        border-radius:10px; border:1px solid rgba(255,255,255,0.22);
        background:rgba(0,0,0,0.32); color:var(--text); outline:none;
        margin-bottom:16px; transition:border-color .2s;
    }
    .otp-input:focus{border-color:var(--accent);}
    .otp-input::placeholder{letter-spacing:8px; font-size:20px; color:rgba(255,255,255,0.25);}
    .btn{
        width:100%; padding:13px; border-radius:9px; border:none;
        background:var(--accent); color:#04231a; font-weight:800; font-size:14.5px;
        cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px;
        transition:filter .2s, transform .2s;
    }
    .btn:hover{filter:brightness(1.08); transform:translateY(-1px);}
    .btn-link{
        background:none; border:none; color:var(--accent);
        font-size:13px; font-weight:600; cursor:pointer; margin-top:16px;
        text-decoration:underline; font-family:'DM Sans',sans-serif;
    }
    .btn-link[disabled]{color:var(--text-dim); cursor:not-allowed; text-decoration:none;}
    .toast{
        padding:12px 15px; border-radius:10px; font-size:13px;
        margin-bottom:18px; line-height:1.5; text-align:left;
    }
    .toast.success{background:rgba(7,247,147,0.14); border:1px solid rgba(7,247,147,0.32); color:var(--accent);}
    .toast.error{background:rgba(255,92,92,0.14); border:1px solid rgba(255,92,92,0.32); color:var(--red);}
    .foot{margin-top:20px; font-size:11.5px; color:rgba(255,255,255,0.42); line-height:1.6;}
    .foot a{color:var(--text-dim);}
</style>
</head>
<body>
<div class="card">
    <div class="icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
    <h1>Thibitisha Barua Pepe Yako</h1>
    <p class="sub">
        Tumekutumia code ya tarakimu <?php echo OTP_LENGTH; ?> kwenye<br>
        <b><?php echo htmlspecialchars($email_iliyofichwa); ?></b><br>
        Code inaisha baada ya dakika <?php echo OTP_TTL_MINUTES; ?>.
    </p>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="toast success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php if ($toast): ?>
        <div class="toast <?php echo $toast['type']; ?>"><?php echo htmlspecialchars($toast['msg']); ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input class="otp-input" type="text" name="otp" inputmode="numeric" pattern="[0-9]*"
               maxlength="<?php echo OTP_LENGTH; ?>" placeholder="------" required autofocus>
        <button class="btn" type="submit"><i class="fa-solid fa-check"></i> Thibitisha</button>
    </form>

    <form method="POST" id="formTumaTena">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="kitendo" value="tuma_tena">
        <button class="btn-link" type="submit" id="btnTumaTena">Hujapokea? Tuma tena</button>
    </form>

    <p class="foot">
        Angalia pia folda ya <strong>Spam/Junk</strong>.<br>
        Tatizo linaendelea? <a href="mailto:support@tech5g.co.tz">support@tech5g.co.tz</a>
    </p>
</div>

<script>
// Ruhusu tarakimu pekee, na tuma fomu code ikikamilika
const inp = document.querySelector('.otp-input');
inp.addEventListener('input', () => {
    inp.value = inp.value.replace(/\D/g, '');
    if (inp.value.length === <?php echo OTP_LENGTH; ?>) inp.form.submit();
});

// Zuia kubonyeza "Tuma tena" mfululizo (seva pia inazuia - hii ni kwa UI tu)
(function () {
    const btn = document.getElementById('btnTumaTena');
    let sekunde = <?php echo (int)OTP_RESEND_SECONDS; ?>;
    const maandishi = btn.textContent;
    const tick = () => {
        if (sekunde <= 0) { btn.disabled = false; btn.textContent = maandishi; return; }
        btn.disabled = true;
        btn.textContent = 'Tuma tena baada ya ' + sekunde + 's';
        sekunde--;
        setTimeout(tick, 1000);
    };
    tick();
})();
</script>
</body>
</html>
