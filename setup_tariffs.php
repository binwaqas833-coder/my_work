<?php
session_start();
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)


if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$my_id = $_SESSION['user_id'];

// ── KAMA TAYARI ANA TARIFFS, MPELEKE DASHBOARD MOJA KWA MOJA ──
$check = $conn->prepare("SELECT COUNT(*) as c FROM tariffs WHERE user_id = ?");
$check->bind_param("i", $my_id);
$check->execute();
$has_tariffs = $check->get_result()->fetch_assoc()['c'] > 0;

if ($has_tariffs) {
    header("Location: user_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weka Bei za Vifurushi Vyako · Bin Waqas Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root{
    --accent:#07f793; --accent2:#3fc7fd; --accent3:#ff6b35; --red:#ff3d57;
    --text-dim:rgba(255,255,255,0.65);
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'DM Sans',sans-serif}
body{
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    background-image:linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg);background-size:cover;background-position:center;
    background-attachment:fixed;padding:20px;
}
body::before{content:'';position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:0}
.wrap{position:relative;z-index:1;max-width:640px;width:100%}
.card{
    background:rgba(255,255,255,0.12);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.25);border-radius:20px;padding:36px;color:#fff;
    box-shadow:0 8px 40px rgba(0,0,0,0.4);
}
.head{text-align:center;margin-bottom:28px}
.head .icon{font-size:40px;color:var(--accent);margin-bottom:12px}
.head h1{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:8px}
.head p{color:var(--text-dim);font-size:13.5px;line-height:1.6}
.pkg-card{
    background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.18);
    border-radius:14px;padding:18px;margin-bottom:14px;transition:border-color 0.2s;
}
.pkg-card.invalid{border-color:rgba(255,61,87,0.5);background:rgba(255,61,87,0.05);}
.pkg-card .pkg-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.pkg-icon{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;font-size:14px;flex-shrink:0}
.pkg-icon.g{background:rgba(7,247,147,0.15);color:var(--accent)}
.pkg-icon.b{background:rgba(63,199,253,0.15);color:var(--accent2)}
.pkg-icon.o{background:rgba(255,107,53,0.15);color:var(--accent3)}
.pkg-title{font-weight:700;font-size:14px}
.pkg-sub{font-size:11px;color:var(--text-dim)}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.field-label{font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;display:block}
.field-input{
    width:100%;padding:10px 12px;border-radius:9px;border:1px solid rgba(255,255,255,0.2);
    background:rgba(255,255,255,0.08);color:#fff;font-size:14px;outline:none;transition:border-color 0.2s;
}
.field-input:focus{border-color:var(--accent)}
.field-input::placeholder{color:rgba(255,255,255,0.35)}
.actions{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}
.btn{
    flex:1;padding:14px;border-radius:10px;border:none;cursor:pointer;font-weight:700;font-size:14px;
    display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.2s;
    font-family:'DM Sans',sans-serif;min-width:140px;
}
.btn-primary{background:var(--accent);color:#000}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-primary:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
.btn-skip{background:rgba(255,255,255,0.08);color:var(--text-dim);border:1px solid rgba(255,255,255,0.18)}
.btn-skip:hover{background:rgba(255,255,255,0.14);color:#fff}

/* ── TOAST (sawa na user_dashboard.php) ── */
#toastContainer{position:fixed;top:20px;right:20px;z-index:2500;display:flex;flex-direction:column;gap:10px}
.toast{min-width:280px;max-width:90vw;padding:14px 18px;border-radius:12px;color:#fff;display:flex;align-items:center;gap:12px;backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 32px rgba(0,0,0,0.3);animation:toastIn 0.4s ease;font-size:13px}
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

/* ── MODAL CONFIRM (sawa na user_dashboard.php) ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;justify-content:center;align-items:center;z-index:1500}
.modal-overlay.active{display:flex}
.modal-content{background:rgba(15,30,50,0.90);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 8px 40px rgba(0,0,0,0.5);padding:28px;border-radius:16px;width:90%;max-width:380px;color:#fff;animation:modalIn 0.3s ease;text-align:center}
@keyframes modalIn{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-content .micon{font-size:44px;margin-bottom:14px}
.modal-content h4{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:8px}
.modal-content p{color:var(--text-dim);font-size:13px;margin-bottom:22px;line-height:1.6}
.modal-footer-center{display:flex;gap:10px;justify-content:center}
.btn-cancel{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.70);border:1px solid rgba(255,255,255,0.15);padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s}
.btn-cancel:hover{background:rgba(255,255,255,0.14);color:#fff}
.btn-confirm-skip{background:var(--accent3);color:#000;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:all 0.2s}
.btn-confirm-skip:hover{filter:brightness(1.1)}

@media(max-width:480px){
    .card{padding:24px 18px}
    .field-row{grid-template-columns:1fr}
    .actions{flex-direction:column}
}
</style>
</head>
<body>

<div id="toastContainer"></div>

<div class="wrap">
    <div class="card">
        <div class="head">
            <div class="icon"><i class="fa-solid fa-tags"></i></div>
            <h1>Karibu! Weka Bei za Vifurushi Vyako</h1>
            <p>Kabla ya kuingia kwenye dashboard, weka bei za vifurushi vitatu vya msingi.
               Unaweza kuvibadilisha wakati wowote baadaye kwenye Mipangilio.</p>
        </div>

        <form id="tariffSetupForm">
            <!-- SIKU -->
            <div class="pkg-card" id="card-siku">
                <div class="pkg-head">
                    <div class="pkg-icon g"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div class="pkg-title">Kifurushi cha Siku</div>
                        <div class="pkg-sub">Daily Package</div>
                    </div>
                </div>
                <div class="field-row">
                    <div>
                        <label class="field-label">Bei (Tsh)</label>
                        <input type="number" class="field-input" name="siku_price" placeholder="1000" min="1">
                    </div>
                    <div>
                        <label class="field-label">Speed</label>
                        <input type="text" class="field-input" name="siku_speed" placeholder="4 Mbps">
                    </div>
                </div>
            </div>

            <!-- WIKI -->
            <div class="pkg-card" id="card-wiki">
                <div class="pkg-head">
                    <div class="pkg-icon b"><i class="fa-solid fa-calendar-days"></i></div>
                    <div>
                        <div class="pkg-title">Kifurushi cha Wiki</div>
                        <div class="pkg-sub">Weekly Package</div>
                    </div>
                </div>
                <div class="field-row">
                    <div>
                        <label class="field-label">Bei (Tsh)</label>
                        <input type="number" class="field-input" name="wiki_price" placeholder="5000" min="1">
                    </div>
                    <div>
                        <label class="field-label">Speed</label>
                        <input type="text" class="field-input" name="wiki_speed" placeholder="4 Mbps">
                    </div>
                </div>
            </div>

            <!-- MWEZI -->
            <div class="pkg-card" id="card-mwezi">
                <div class="pkg-head">
                    <div class="pkg-icon o"><i class="fa-solid fa-calendar-plus"></i></div>
                    <div>
                        <div class="pkg-title">Kifurushi cha Mwezi</div>
                        <div class="pkg-sub">Monthly Package</div>
                    </div>
                </div>
                <div class="field-row">
                    <div>
                        <label class="field-label">Bei (Tsh)</label>
                        <input type="number" class="field-input" name="mwezi_price" placeholder="15000" min="1">
                    </div>
                    <div>
                        <label class="field-label">Speed</label>
                        <input type="text" class="field-input" name="mwezi_speed" placeholder="10 Mbps">
                    </div>
                </div>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-skip" onclick="askSkipSetup()">
                    <i class="fa-solid fa-forward"></i> Ruka kwa Sasa
                </button>
                <button type="submit" class="btn btn-primary" id="saveBtn">
                    <i class="fa-solid fa-floppy-disk"></i> Hifadhi na Endelea
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: CONFIRM SKIP -->
<div class="modal-overlay" id="skipModal">
    <div class="modal-content">
        <div class="micon">⏭️</div>
        <h4>Una uhakika unataka kuruka?</h4>
        <p>Hutaweza kuuza vocha hadi uweke bei za vifurushi baadaye kwenye Mipangilio.</p>
        <div class="modal-footer-center">
            <button class="btn-cancel" onclick="fungaModal('skipModal')">Ghairi</button>
            <button class="btn-confirm-skip" onclick="confirmSkip()">
                <i class="fa-solid fa-forward"></i> Ndiyo, Ruka
            </button>
        </div>
    </div>
</div>

<script>
// ══ TOAST (sawa na user_dashboard.php) ══
function showToast(msg, type='success') {
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const c=document.getElementById('toastContainer');
    const t=document.createElement('div');
    t.className='toast '+type;
    t.innerHTML=`<i class="fa-solid ${icons[type]||'fa-circle-info'} ti"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(()=>{t.style.animation='toastOut 0.4s ease forwards';setTimeout(()=>t.remove(),400);},3500);
}

// ══ MODALS ══
function funguaModal(id){document.getElementById(id).classList.add('active');}
function fungaModal(id){document.getElementById(id).classList.remove('active');}

// ══ VALIDATION YA FOMU (kuonyesha toast badala ya alert/error box) ══
function validateForm() {
    const fields = [
        {name:'siku_price',  card:'card-siku',  label:'Bei ya Siku'},
        {name:'siku_speed',  card:'card-siku',  label:'Speed ya Siku'},
        {name:'wiki_price',  card:'card-wiki',  label:'Bei ya Wiki'},
        {name:'wiki_speed',  card:'card-wiki',  label:'Speed ya Wiki'},
        {name:'mwezi_price', card:'card-mwezi', label:'Bei ya Mwezi'},
        {name:'mwezi_speed', card:'card-mwezi', label:'Speed ya Mwezi'},
    ];

    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('invalid'));

    let firstError = null;
    for (const f of fields) {
        const input = document.querySelector(`[name="${f.name}"]`);
        const value = input.value.trim();
        if (!value || (input.type === 'number' && parseFloat(value) <= 0)) {
            document.getElementById(f.card).classList.add('invalid');
            if (!firstError) firstError = f.label;
        }
    }

    if (firstError) {
        showToast(`Tafadhali jaza: ${firstError} (na sehemu zingine zilizoangaziwa kwa rangi nyekundu).`, 'error');
        return false;
    }
    return true;
}

// ══ SUBMIT FOMU ══
document.getElementById('tariffSetupForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!validateForm()) return;

    const btn = document.getElementById('saveBtn');
    const orig = btn.innerHTML;
    const formData = new FormData(this);
    const params = new URLSearchParams(formData);

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inahifadhi...';

    fetch('save_initial_tariffs.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            showToast('Vifurushi vimehifadhiwa! karibu kwenye dashboard yako... 🎉', 'success');
            setTimeout(() => { window.location.href = 'user_dashboard.php'; }, 2000);
        } else {
            showToast(d.message || 'Imeshindikana kuhifadhi. Jaribu tena.', 'error');
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    })
    .catch(() => {
        showToast('Tatizo la mtandao. Jaribu tena.', 'error');
        btn.disabled = false;
        btn.innerHTML = orig;
    });
});

// ══ SKIP SETUP (modal badala ya confirm()) ══
function askSkipSetup() {
    funguaModal('skipModal');
}

function confirmSkip() {
    fungaModal('skipModal');
    showToast('Sawa, unaelekezwa kwenye dashboard...', 'info');
    setTimeout(() => {
        window.location.href = 'user_dashboard.php?skip_setup=1';
    }, 900);
}
</script>
</body>
</html>