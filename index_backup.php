<?php
session_start();
include 'login_signup.php'; 
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';

// ── NASA OMBI LA LUGHA KUTOKA KWA JAVASCRIPT NA LIHIFADHI KWENYE SESSION ──
if (isset($_POST['set_lang'])) {
    $_SESSION['lang'] = $_POST['set_lang'] === 'en' ? 'en' : 'sw';
    echo json_encode(['status' => 'success']);
    exit();
}

// ── 1. NASA TAARIFA ZA MIKROTIK NA ZIHIFADHI KWENYE SESSION ──
if (isset($_GET['mac'])) {
    $_SESSION['client_mac']    = $_GET['mac'];
    $_SESSION['client_ip']     = $_GET['ip'] ?? '';
    $_SESSION['client_link']   = $_GET['link_login'] ?? '';
    $_SESSION['client_router'] = $_GET['router'] ?? '';
}
if (isset($_GET['router_id'])) {
    $_SESSION['router_id'] = intval($_GET['router_id']);
}

$namba_ya_msaada = '0777360300';
$p_siku = 1000; $p_wiki = 5000; $p_mwezi = 10000;

// ── 2. TAFUTA RESELLER (user_id) SAHIHI KUTOKA DATABASE ──
$user_id = null;

if (isset($_SESSION['router_id']) && isset($conn)) {
    $router_id = $_SESSION['router_id'];
    $r_stmt = $conn->prepare("SELECT user_id FROM mikrotik_configs WHERE router_id = ?");
    $r_stmt->bind_param("i", $router_id);
    $r_stmt->execute();
    $r_row = $r_stmt->get_result()->fetch_assoc();
    $r_stmt->close();

    if ($r_row && !empty($r_row['user_id'])) {
        $user_id = (int)$r_row['user_id'];
    }
}

if ($user_id === null && isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}

if ($user_id === null) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='sw'><head><meta charset='UTF-8'>
    <title>Hitilafu</title></head><body style='font-family:sans-serif;text-align:center;padding:60px 20px;'>
    <h2>Router halijasajiliwa</h2>
    <p>Samahani, hotspot hii haijasanidiwa vizuri. Tafadhali wasiliana na msimamizi wa mtandao huu.</p>
    </body></html>";
    exit();
}

if (isset($conn)) {
    $stmt = $conn->prepare("SELECT phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $namba_ya_msaada = $row['phone'];
    }
    $stmt->close();

    $query_bei = "SELECT package_type, price FROM tariffs WHERE user_id = ?";
    $stmt2 = $conn->prepare($query_bei);
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $result_bei = $stmt2->get_result();

    $bei_zangu = [];
    while ($row = $result_bei->fetch_assoc()) {
        $bei_zangu[strtolower(trim($row['package_type']))] = $row['price'];
    }
    $stmt2->close();

    $p_siku  = $bei_zangu['daily']   ?? 1000;
    $p_wiki  = $bei_zangu['weekly']  ?? 5000;
    $p_mwezi = $bei_zangu['monthly'] ?? 10000;
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>5G Unlimited Wi-Fi</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root{
            --brand: #1f8a3d;
            --brand-dark: #145a28;
            --brand-soft: #e8f6ec;
            --ink: #10241a;
            --ink-soft: #4a5a51;
            --error: #e74c3c;
        }

        * { box-sizing: border-box; }

        body {
            background-image: url(zanzibar.jpg);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Poppins', sans-serif, Arial, sans-serif;
        }

        .main-wrapper {
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(10, 40, 20, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.5);
            padding: 28px;
            width: 100%;
            max-width: 420px;
            position: relative;
        }

        .language-switcher {
            position: absolute;
            top: 8px;
            right: 18px;
            display: flex;
            gap: 4px;
            background: rgba(255,255,255,0.6);
            padding: 4px;
            border-radius: 24px;
        }

       .lang-btn {
            background-color: rgba(0, 0, 0, 0.05);
            border: 1px solid #ccc;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: bold;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .lang-btn.active {
            background-color: var(--brand);
            color: white;
        }

        .header {
            text-align: center;
            margin-bottom: 22px;
            margin-top: 12px;
        }

        .wifi-logo i {
            font-size: 55px;
            color: var(--brand);
            margin-bottom: 10px;
        }
        .heading-1 {
            font-size: 23px;
            margin: 0;
            color: var(--ink);
        }

        .heading-1 .accent { color: var(--brand); }

        .maelezo {
            font-size: 13.5px;
            color: var(--ink-soft);
            line-height: 1.5;
            margin-top: 6px;
        }

        .heading-2 {
            font-size: 16px;
            color: var(--ink);
            border-bottom: 2px solid rgba(0,0,0,0.08);
            padding-bottom: 8px;
            margin-bottom: 14px;
        }

        .kichwa-sehemu {
            font-size: 13px;
            margin-bottom: 10px;
            color: var(--ink-soft);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 20px;
        }

        /* ---- PACKAGE BOX ---- */
        .kiboksi {
            display: flex;
            align-items: center;
            background-color: rgba(255, 255, 255, 0.92);
            border: 2px solid rgba(0,0,0,0.08);
            border-radius: 12px;
            padding: 13px 14px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .kiboksi:hover { border-color: rgba(31, 138, 61, 0.4); }

        .alama-check {
            width: 22px;
            height: 22px;
            border: 2px solid #b9c2bd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: transparent;
            font-size: 12px;
            margin-right: 14px;
            transition: all 0.18s ease;
        }

        .icon-kifurushi {
            font-size: 17px;
            color: var(--brand);
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .maandishi-kifurushi {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            width: 100%;
        }

        .muda-wrap { display: flex; flex-direction: column; }

        .muda { font-size: 14.5px; font-weight: 600; color: var(--ink); }
        .muda-ndogo { font-size: 11.5px; color: var(--ink-soft); }
        .bei { font-size: 15px; color: var(--ink); font-weight: 700; }

        .kiboksi.active {
            border-color: var(--brand);
            background-color: var(--brand-soft);
        }

        .kiboksi.active .alama-check {
            border-color: var(--brand);
            background-color: var(--brand);
            color: white;
        }

        .malipo-container { margin-top: 15px; }
        .lebo-namba { font-size: 13px; font-weight: 600; color: var(--ink); display: block; margin-bottom: 6px; }

        .inline-input-button input, .input-vocha-container input {
            width: 100%;
            border: 2px solid rgba(0,0,0,0.12);
            border-radius: 10px;
            padding: 13px 14px;
            font-family: inherit;
            font-size: 14px;
            margin-bottom: 10px;
            background: rgba(255,255,255,0.95);
        }

        .inline-input-button input:focus, .input-vocha-container input:focus {
            outline: none;
            border-color: var(--brand);
        }

        .btn-malipo {
            width: 100%;
            background-color: var(--brand);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 14px rgba(31, 138, 61, 0.35);
        }

        .btn-malipo:hover { background-color: var(--brand-dark); }

        /* ---- SEHEMU YA VOCHA ---- */
        .sehemu-vocha { margin-top: 15px; border-top: 1px dashed rgba(0,0,0,0.12); padding-top: 10px; }
        .input-vocha-container { display: flex; gap: 8px; margin-top: 6px; }
        .input-vocha-container input { flex: 1; margin-bottom: 0; }

        .btn-unganisha {
            background-color: #0288d1;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0 20px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(2, 136, 209, 0.3);
        }

        /* ---- SEHEMU YA TRIAL ---- */
        .sehemu-trial { margin-top: 15px; border-top: 1px dashed rgba(0,0,0,0.12); padding-top: 10px; text-align: center;}
        .btn-trial {
            width: 100%;
            background-color: #199331;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(37, 249, 108, 0.35);
        }

        .footer-sehemu { margin-top: 24px; padding-top: 14px; border-top: 1px solid rgba(0,0,0,0.1); text-align: center; }
        .msaada-text { font-size: 13px; color: var(--brand-dark); margin-bottom: 6px; font-weight: 600; }
        .copyright-text { font-size: 11px; color: #6b6b6b; margin: 0; }

        /* ---- DYNAMIC TOAST STYLE ---- */
        .toast-container {
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%) translateY(120px);
            background-color: var(--error);
            color: white;
            padding: 14px 24px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
            font-size: 14px;
            font-weight: 600;
            z-index: 9999;
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            gap: 10px;
            width: max-content;
            max-width: 90%;
        }

        .toast-container.show { transform: translateX(-50%) translateY(0); }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .main-wrapper { padding: 18px; width: 95%; }
            .input-vocha-container { flex-direction: column; }
            .btn-unganisha { width: 100%; padding: 12px; margin-top: 4px; }
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <div class="language-switcher">
        <button class="lang-btn active" onclick="badilishaLugha('sw')">SW</button>
        <button class="lang-btn" onclick="badilishaLugha('en')">EN</button>
    </div>

    <div class="header">
        <div class="wifi-logo">
            <i class="fa-solid fa-wifi"></i>
            <h3 class="heading-1">Tech <span class="accent">5G </span> Wi-Fi</h3>
        </div>
        <div class="maelezo" data-translate="maelezo">
            Chagua kifurushi ukipendacho na ulipie kwa akaunti yako ya simu kielektroniki.
        </div>
    </div>

    <div class="vifurushi-orodha">
        <h3 class="heading-2" data-translate="heading2">Jinsi ya kujiunga na Wi-Fi</h3>
        
        <!-- MPANGO WA 1: NUNUA KIFURUSHI -->
        <p class="kichwa-sehemu" data-translate="hatua1">1. Chagua Kifurushi & Lipia:</p>
        <form action="lipia.php" method="POST" id="form-malipo" class="inline-input-button">
            <input type="hidden" name="kifurushi_kichaguliwa" id="kifurushi_id" value="<?php echo $p_siku; ?>">
            <input type="hidden" name="package_type" id="package_type_input" value="daily">
            <input type="hidden" name="user_id" value="<?php echo (int)$user_id; ?>">

            <div class="kiboksi active" onclick="chaguaKifurushi(this, '<?php echo $p_siku; ?>', '<?php echo number_format($p_siku); ?>', 'daily')">
                <div class="alama-check">&#10003;</div>
                <i class="fa-solid fa-sun icon-kifurushi"></i>
                <div class="maandishi-kifurushi">
                    <div class="muda-wrap">
                        <span class="muda" data-translate="kif_siku">Kifurushi cha Siku</span>
                        <span class="muda-ndogo">Saa 24</span>
                    </div>
                    <span class="bei">Tsh <?php echo number_format($p_siku); ?></span>
                </div>
            </div>

            <div class="kiboksi" onclick="chaguaKifurushi(this, '<?php echo $p_wiki; ?>', '<?php echo number_format($p_wiki); ?>', 'weekly')">
                <div class="alama-check">&#10003;</div>
                <i class="fa-solid fa-calendar-week icon-kifurushi"></i>
                <div class="maandishi-kifurushi">
                    <div class="muda-wrap">
                        <span class="muda" data-translate="kif_wiki">Kifurushi cha Wiki</span>
                        <span class="muda-ndogo">Siku 7</span>
                    </div>
                    <span class="bei">Tsh <?php echo number_format($p_wiki); ?></span>
                </div>
            </div>

            <div class="kiboksi" onclick="chaguaKifurushi(this, '<?php echo $p_mwezi; ?>', '<?php echo number_format($p_mwezi); ?>', 'monthly')">
                <div class="alama-check">&#10003;</div>
                <i class="fa-solid fa-calendar-days icon-kifurushi"></i>
                <div class="maandishi-kifurushi">
                    <div class="muda-wrap">
                        <span class="muda" data-translate="kif_mwezi">Kifurushi cha Mwezi</span>
                        <span class="muda-ndogo">Siku 30</span>
                    </div>
                    <span class="bei">Tsh <?php echo number_format($p_mwezi); ?></span>
                </div>
            </div>

            <div class="malipo-container">
                <label class="lebo-namba" data-translate="lebo_namba">Namba ya Simu:</label>
                <input type="tel" name="namba_simu" id="namba_simu" placeholder="07XXXXXXXX" maxlength="10">
                <button class="btn-malipo" id="btn-malipo" type="submit">
                    <span data-translate="lipia_btn">Lipia</span> Tsh <?php echo number_format($p_siku); ?>
                </button>
            </div>
        </form>

        <!-- MPANGO WA 2: TAYARI UNA VOCHA -->
        <div class="sehemu-vocha">
            <p class="kichwa-sehemu" data-translate="tayari_vocha" style="margin-top:0;">Tayari una Vocha?</p>
            <form action="unganisha_vocha.php" method="POST" id="form-vocha" class="input-vocha-container">
                <input type="text" name="kodi_vocha" id="kodi_vocha" placeholder="Ingiza namba ya vocha hapa">
                <button type="submit" class="btn-unganisha" data-translate="unganisha_btn">Unganisha</button>
            </form>
        </div>

        <!-- MPANGO WA 5: TRIAL -->
        <div class="sehemu-trial">
            <p class="kichwa-sehemu" style="margin-top:0;" data-translate="jaribu_kichwa">Mteja Mpya? Jaribu Bure</p>
            <form action="trial_handler.php" method="POST">
                <button type="submit" class="btn-trial" data-translate="jaribu_btn">Jaribu Dakika 5 Bure</button>
            </form>
        </div>
    </div>

    <div class="footer-sehemu">
        <p class="msaada-text">
            <i class="fa-solid fa-headset"></i> 
            <span data-translate="msaada">Unahitaji Msaada? Tupigie:</span> 
            <strong><?php echo htmlspecialchars($namba_ya_msaada); ?></strong>
        </p>
        <p class="copyright-text">
            &copy; <?php echo date('Y'); ?> Tech 5G Wi-Fi System. 
            <span data-translate="haki">Haki zote zimehifadhiwa.</span>
        </p>
    </div>
</div>

<!-- MPANGO WA TOAST MODAL HTML -->
<div id="toast" class="toast-container">
    <i class="fa-solid fa-circle-exclamation"></i>
    <span id="toast-message">Ujumbe hapa</span>
</div>

<script>
const tafsiri = {
    sw: {
        maelezo: "Chagua kifurushi ukipendacho na ulipie kwa akaunti yako ya simu kielektroniki.",
        heading2: "Jinsi ya kujiunga na Wi-Fi",
        hatua1: "1. Chagua Kifurushi & Lipia:",
        kif_siku: "Kifurushi cha Siku",
        kif_wiki: "Kifurushi cha Wiki",
        kif_mwezi: "Kifurushi cha Mwezi",
        lebo_namba: "Namba ya Simu:",
        lipia_btn: "Lipia",
        placeholder_simu: "07XXXXXXXX",
        tayari_vocha: "Tayari una Vocha?",
        placeholder_vocha: "Ingiza namba ya vocha hapa",
        unganisha_btn: "Unganisha",
        jaribu_kichwa: "Mteja Mpya? Jaribu Bure",
        jaribu_btn: "Jaribu Dakika 5 Bure",
        msaada: "Unahitaji Msaada? Tupigie:",
        haki: "Haki zote zimehifadhiwa.",
        kosa_simu: "Tafadhali ingiza namba ya simu sahihi ya tarakimu 10.",
        kosa_vocha: "Tafadhali ingiza kodi ya vocha kwanza."
    },
    en: {
        maelezo: "Choose your preferred package and pay electronically via your mobile money account.",
        heading2: "How to connect to Wi-Fi",
        hatua1: "1. Select Package & Pay:",
        kif_siku: "Daily Package",
        kif_wiki: "Weekly Package",
        kif_mwezi: "Monthly Package",
        lebo_namba: "Phone Number:",
        lipia_btn: "Pay",
        placeholder_simu: "07XXXXXXXX",
        tayari_vocha: "Already have a Voucher?",
        placeholder_vocha: "Enter voucher code here",
        unganisha_btn: "Connect",
        jaribu_kichwa: "New Customer? Try Free",
        jaribu_btn: "Try 5 Minutes Free",
        msaada: "Need Help? Call Us:",
        haki: "All rights reserved.",
        kosa_simu: "Please enter a valid 10-digit phone number.",
        kosa_vocha: "Please enter your voucher code first."
    }
};

let lughaYaSasa = 'sw';
let beiYaSasaMaandishi = '1,000';

function onyeshaToast(ujumbe) {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toast-message');
    toastMsg.innerText = ujumbe;
    toast.classList.add('show');
    setTimeout(() => { toast.classList.remove('show'); }, 6100);
}

function chaguaKifurushi(kiboksiIliyoguswa, kiasi, beiMaandishi, mudaAina) {
    document.querySelectorAll('.kiboksi').forEach(box => box.classList.remove('active'));
    kiboksiIliyoguswa.classList.add('active');
    document.getElementById('kifurushi_id').value = kiasi;
    document.getElementById('package_type_input').value = mudaAina;
    beiYaSasaMaandishi = beiMaandishi;
    document.getElementById("btn-malipo").innerHTML = `<span>${tafsiri[lughaYaSasa].lipia_btn}</span> Tsh ${beiMaandishi}`;
}

function badilishaLugha(lugha) {
    lughaYaSasa = lugha;
    document.querySelectorAll('.lang-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    document.querySelectorAll('[data-translate]').forEach(element => {
        const ufunguo = element.getAttribute('data-translate');
        if (tafsiri[lugha][ufunguo]) element.innerText = tafsiri[lugha][ufunguo];
    });
    document.getElementById('namba_simu').placeholder = tafsiri[lugha].placeholder_simu;
    document.getElementById('kodi_vocha').placeholder = tafsiri[lugha].placeholder_vocha;
    document.getElementById("btn-malipo").innerHTML = `<span>${tafsiri[lugha].lipia_btn}</span> Tsh ${beiYaSasaMaandishi}`;

    // TUMA LUGHA ILIYOCHAGULIWA KWENYE PHP SESSION
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'set_lang=' + lugha
    });
}

// Validation za Fomu kupitia Toast
document.getElementById('form-malipo').addEventListener('submit', function(e) {
    const namba = document.getElementById('namba_simu').value.trim();
    if (namba.length < 10 || isNaN(namba)) {
        e.preventDefault();
        onyeshaToast(tafsiri[lughaYaSasa].kosa_simu);
    }
});

document.getElementById('form-vocha').addEventListener('submit', function(e) {
    const vocha = document.getElementById('kodi_vocha').value.trim();
    if (vocha === "") {
        e.preventDefault();
        onyeshaToast(tafsiri[lughaYaSasa].kosa_vocha);
    }
});

// Somo la URL errors kutoka unganisha_vocha.php
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('error')) {
        onyeshaToast(urlParams.get('error'));
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
</script>
</body>
</html>