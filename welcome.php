<?php
session_start();
// Kama mtumiaji ameshajilogi, mpeleke moja kwa moja kwenye dashboard yake
if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] == 'admin' ? "dashboard_chaguo.php" : "user_dashboard.php"));
    exit();
}
// Hakuna cookie/redirect nyingine hapa kwa makusudi — welcome.php ni
// landing page ya wazi kwa kila mtu asiyeingia. Mtu anayejua mfumo
// anaweza kubonyeza "Ingia" moja kwa moja, au kutumia link ya index.php.
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tech 5G Wi-Fi · Mfumo wa Malipo na Vocha</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">

<!-- ══ OPEN GRAPH / KUSHIRIKISHA KWENYE WHATSAPP, FACEBOOK, N.K. ══ -->
<meta property="og:type" content="website">
<meta property="og:title" content="Tech 5G Wi-Fi · Mfumo wa Malipo na Vocha">
<meta property="og:description" content="Simamia vocha na malipo ya Wi-Fi yako kwa urahisi. Malipo kupitia M-Pesa, Airtel Money, Tigo Pesa na Halotel. Fungua akaunti yako leo.">
<meta property="og:image" content="logo.png">
<meta property="og:url" content="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">
<meta property="og:site_name" content="Tech 5G Wi-Fi">
<meta property="og:locale" content="sw_TZ">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Tech 5G Wi-Fi · Mfumo wa Malipo na Vocha">
<meta name="twitter:description" content="Simamia vocha na malipo ya Wi-Fi yako kwa urahisi kupitia M-Pesa, Airtel Money, Tigo Pesa na Halotel.">
<meta name="twitter:image" content="logo.png">
<meta name="description" content="Tech 5G Wi-Fi ni mfumo kamili wa kusimamia hotspot za Wi-Fi Zanzibar — vocha za kiotomatiki, malipo ya mtandao wa simu, na udhibiti wa router zako sehemu moja.">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{
    --surface: rgba(255,255,255,0.18);
    --surface2: rgba(255,255,255,0.10);
    --border: rgba(255,255,255,0.35);
    --border2: rgba(255,255,255,0.20);
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
html{scroll-behavior:smooth}
body{
    font-family:'DM Sans',sans-serif;
    background-color:#0d1b17;
    background-image:linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg);
    background-size:cover;background-position:center;background-attachment:fixed;
    color:var(--text);min-height:100vh;overflow-x:hidden;
}
body::before{content:'';position:fixed;inset:0;background:rgba(0,0,0,0.30);pointer-events:none;z-index:0}
.wrap{position:relative;z-index:1}

/* ── NAV ── */
.nav{
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 28px;background:var(--surface2);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
    border-bottom:1px solid var(--border2);position:sticky;top:0;z-index:50;
}
.nav-brand{display:flex;align-items:center;gap:10px;font-family:'Syne',sans-serif;font-weight:800;font-size:17px}
.nav-brand img{width:38px;height:38px;border-radius:9px;object-fit:cover}
.nav-actions{display:flex;gap:10px;align-items:center}
.lang-toggle{display:flex;background:var(--surface2);border:1px solid var(--border2);border-radius:20px;padding:3px;gap:2px}
.lang-btn{
    border:none;background:transparent;color:var(--text-dim);font-size:11.5px;font-weight:700;
    padding:6px 12px;border-radius:16px;cursor:pointer;transition:all 0.2s;font-family:'DM Sans',sans-serif;
}
.lang-btn.active{background:var(--accent);color:#04231a}
.btn{
    display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;
    font-weight:700;font-size:13.5px;cursor:pointer;border:none;text-decoration:none;transition:all 0.2s;
    font-family:'DM Sans',sans-serif;
}
.btn-primary{background:var(--accent);color:#04231a}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn-outline:hover{background:var(--surface2);border-color:var(--accent)}

/* ── HERO ── */
.hero{
    max-width:900px;margin:0 auto;padding:90px 24px 60px;text-align:center;
}
.hero .badge{
    display:inline-flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border2);
    padding:7px 16px;border-radius:30px;font-size:12.5px;color:var(--accent);margin-bottom:22px;
}
.hero h1{
    font-family:'Syne',sans-serif;font-weight:800;font-size:clamp(28px,5vw,48px);line-height:1.15;margin-bottom:18px;
}
.hero h1 span{background:linear-gradient(90deg,var(--accent),var(--accent2));-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p{color:var(--text-dim);font-size:15.5px;line-height:1.75;max-width:620px;margin:0 auto 32px}
.hero-actions{display:flex;gap:14px;justify-content:center;flex-wrap:wrap}
.hero-actions .btn{padding:14px 28px;font-size:14.5px}

/* ── STATS STRIP ── */
.stats{
    max-width:1000px;margin:0 auto 70px;display:grid;grid-template-columns:repeat(5,1fr);gap:14px;padding:0 24px;
}
.stat-card{
    background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
    border:1px solid var(--border2);border-radius:var(--radius);padding:18px 14px;text-align:center;
}
.stat-card .num{font-family:'Syne',sans-serif;font-weight:800;font-size:22px;color:var(--accent)}
.stat-card .lbl{font-size:11.5px;color:var(--text-dim);margin-top:4px}

/* ── FEATURES ── */
.section{max-width:1100px;margin:0 auto;padding:20px 24px 80px}
.section-head{text-align:center;margin-bottom:44px}
.section-head .eyebrow{color:var(--accent2);font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px}
.section-head h2{font-family:'Syne',sans-serif;font-weight:800;font-size:clamp(22px,3.5vw,32px)}
.section-head p{color:var(--text-dim);font-size:14px;margin-top:10px;max-width:520px;margin-left:auto;margin-right:auto}

.features-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.feature-card{
    background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
    border:1px solid var(--border2);border-radius:var(--radius);padding:26px 22px;transition:transform 0.25s,border-color 0.25s;
}
.feature-card:hover{transform:translateY(-4px);border-color:var(--accent)}
.feature-icon{
    width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:18px;margin-bottom:16px;
}
.feature-icon.g{background:rgba(7,247,147,0.15);color:var(--accent)}
.feature-icon.b{background:rgba(63,199,253,0.15);color:var(--accent2)}
.feature-icon.o{background:rgba(255,107,53,0.15);color:var(--accent3)}
.feature-card h3{font-size:15.5px;font-weight:700;margin-bottom:8px}
.feature-card p{color:var(--text-dim);font-size:13px;line-height:1.65}

/* ── HOW IT WORKS ── */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.step-card{position:relative;padding:24px 18px;text-align:center}
.step-num{
    width:38px;height:38px;border-radius:50%;background:var(--surface);border:1px solid var(--border);
    display:grid;place-items:center;margin:0 auto 14px;font-family:'Space Mono',monospace;font-weight:700;color:var(--accent);
}
.step-card h4{font-size:14px;font-weight:700;margin-bottom:6px}
.step-card p{font-size:12.5px;color:var(--text-dim);line-height:1.6}

/* ── PAYMENT METHODS STRIP (logo zinazotembea kama ulaini) ── */
.pay-strip{
    max-width:1100px;margin:0 auto 80px;padding:26px 0;background:var(--surface2);
    border:1px solid var(--border2);border-radius:var(--radius);overflow:hidden;
}
.pay-strip .pay-label{
    text-align:center;color:var(--text-dim);font-size:12px;text-transform:uppercase;
    letter-spacing:1.5px;margin-bottom:16px;font-weight:600;
}
.logo-za-simu{overflow:hidden;width:100%;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);}
.logo-slide{display:flex;width:fit-content;align-items:center;animation:tembeaLogo 26s linear infinite;will-change:transform;}
.logo-slide img{
    height:34px;width:auto;margin:0 26px;flex-shrink:0;display:block;
    filter:drop-shadow(0 2px 8px rgba(0,0,0,0.35));
    opacity:0.92;transition:opacity 0.2s,transform 0.2s;
}
.logo-slide img:hover{opacity:1;transform:scale(1.06)}
@keyframes tembeaLogo{from{transform:translateX(0)}to{transform:translateX(-25%)}}

/* ── FINAL CTA ── */
.cta-section{
    max-width:760px;margin:0 auto 80px;padding:46px 32px;text-align:center;
    background:linear-gradient(135deg,rgba(7,247,147,0.12),rgba(63,199,253,0.10));
    border:1px solid var(--border2);border-radius:20px;
}
.cta-section h2{font-family:'Syne',sans-serif;font-weight:800;font-size:24px;margin-bottom:12px}
.cta-section p{color:var(--text-dim);font-size:14px;margin-bottom:24px}

/* ── DASHBOARD PREVIEW MOCKUP ── */
.preview-wrap{
    max-width:1000px;margin:0 auto;background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
    border:1px solid var(--border2);border-radius:20px;padding:16px;box-shadow:0 20px 60px rgba(0,0,0,0.35);
}
.preview-topbar{display:flex;align-items:center;gap:7px;padding:6px 10px 14px}
.preview-topbar span{width:11px;height:11px;border-radius:50%;background:rgba(255,255,255,0.25)}
.preview-body{display:grid;grid-template-columns:1.3fr 1fr;gap:14px}
.preview-stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.preview-stat{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:14px}
.preview-stat .p-num{font-family:'Syne',sans-serif;font-weight:800;font-size:19px;color:var(--accent)}
.preview-stat .p-lbl{font-size:10.5px;color:var(--text-dim);margin-top:3px}
.preview-chart{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:16px;display:flex;align-items:flex-end;gap:8px;height:130px}
.preview-chart .bar{flex:1;background:linear-gradient(180deg,var(--accent),var(--accent2));border-radius:4px 4px 0 0;opacity:0.85}
.preview-list{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px}
.preview-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding-bottom:9px;border-bottom:1px dashed var(--border2)}
.preview-row:last-child{border-bottom:none;padding-bottom:0}
.preview-row .code{font-family:'Space Mono',monospace;color:var(--text-dim)}
.preview-row .ok{color:var(--accent);font-weight:700;font-size:10.5px;background:rgba(7,247,147,0.15);padding:2px 8px;border-radius:20px}
@media(max-width:700px){.preview-body{grid-template-columns:1fr}}

/* ── TRUST BADGES ── */
.trust-strip{
    max-width:1000px;margin:0 auto 80px;display:flex;justify-content:center;gap:36px;flex-wrap:wrap;padding:0 24px;
}
.trust-item{display:flex;align-items:center;gap:9px;color:var(--text-dim);font-size:12.5px;font-weight:600}
.trust-item i{color:var(--accent);font-size:16px}

/* ── MAWASILIANO / LOCATION ── */
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:stretch}
.contact-info{
    background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
    border:1px solid var(--border2);border-radius:var(--radius);padding:28px;display:flex;flex-direction:column;gap:18px;justify-content:center;
}
.contact-row{display:flex;align-items:center;gap:14px}
.contact-row .c-icon{
    width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:16px;flex-shrink:0;
    background:rgba(7,247,147,0.15);color:var(--accent);
}
.contact-row .c-text .c-lbl{font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.5px}
.contact-row .c-text a, .contact-row .c-text span{color:var(--text);font-weight:700;font-size:14.5px;text-decoration:none}
.contact-row .c-text a:hover{color:var(--accent)}
.map-wrap{border-radius:var(--radius);overflow:hidden;border:1px solid var(--border2);min-height:280px;position:relative}
.map-wrap iframe{width:100%;height:100%;min-height:280px;border:0;filter:grayscale(0.15) contrast(1.05);display:block}
.map-fallback{
    position:absolute;bottom:10px;right:10px;background:rgba(13,27,23,0.85);backdrop-filter:blur(8px);
    color:var(--text);font-size:11.5px;font-weight:600;padding:7px 12px;border-radius:8px;
    text-decoration:none;display:flex;align-items:center;gap:6px;border:1px solid var(--border2);transition:background 0.2s;
}
.map-fallback:hover{background:rgba(7,247,147,0.25);color:var(--accent)}
@media(max-width:700px){.contact-grid{grid-template-columns:1fr}}

/* ── FAQ (MASWALI) ── */
.faq-wrap{max-width:760px;margin:0 auto}
.faq-item{
    background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
    border:1px solid var(--border2);border-radius:14px;margin-bottom:12px;overflow:hidden;
    transition:transform 0.25s,border-color 0.25s,box-shadow 0.25s;
}
.faq-item:hover{transform:translateX(4px);border-color:var(--accent);box-shadow:0 8px 24px rgba(0,0,0,0.25)}
.faq-q{
    display:flex;align-items:center;justify-content:space-between;gap:14px;
    padding:18px 22px;cursor:pointer;font-weight:700;font-size:14.5px;user-select:none;
}
.faq-q i{color:var(--accent);transition:transform 0.3s;flex-shrink:0}
.faq-item.open .faq-q i{transform:rotate(45deg)}
.faq-a{max-height:0;overflow:hidden;transition:max-height 0.35s ease,padding 0.35s ease}
.faq-a p{padding:0 22px 18px;color:var(--text-dim);font-size:13.5px;line-height:1.7}
.faq-item.open .faq-a{max-height:220px}

/* ── KITUFE CHA WHATSAPP KINACHOELEA ── */
.whatsapp-float{
    position:fixed;bottom:22px;right:22px;z-index:200;
    width:56px;height:56px;border-radius:50%;
    background:#25D366;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:26px;text-decoration:none;
    box-shadow:0 6px 20px rgba(37,211,102,0.5);
    animation:waPulse 2.4s ease-in-out infinite;
}
.whatsapp-float:hover{transform:scale(1.08)}
@keyframes waPulse{
    0%{box-shadow:0 6px 20px rgba(37,211,102,0.5),0 0 0 0 rgba(37,211,102,0.5)}
    70%{box-shadow:0 6px 20px rgba(37,211,102,0.5),0 0 0 16px rgba(37,211,102,0)}
    100%{box-shadow:0 6px 20px rgba(37,211,102,0.5),0 0 0 0 rgba(37,211,102,0)}
}
@media(max-width:520px){
    .whatsapp-float{width:50px;height:50px;font-size:23px;bottom:16px;right:16px}
}

/* ── FOOTER ── */
.footer{
    text-align:center;padding:22px 16px;border-top:1px solid var(--border2);
    background:var(--surface2);color:var(--text-dim);font-size:12.5px;
}

/* ── ANIMATIONS ── */
@keyframes slideInLeft{
    from{opacity:0;transform:translateX(-60px)}
    to{opacity:1;transform:translateX(0)}
}
@keyframes slideInRight{
    from{opacity:0;transform:translateX(60px)}
    to{opacity:1;transform:translateX(0)}
}
@keyframes popIn{
    from{opacity:0;transform:scale(0.85) translateY(-14px)}
    to{opacity:1;transform:scale(1) translateY(0)}
}
@keyframes fadeUp{
    from{opacity:0;transform:translateY(26px)}
    to{opacity:1;transform:translateY(0)}
}
@keyframes floatY{
    0%,100%{transform:translateY(0)}
    50%{transform:translateY(-6px)}
}

.nav-brand img{animation:popIn 0.7s cubic-bezier(.34,1.56,.64,1) both}
.nav-actions .btn-outline{animation:slideInLeft 0.7s ease 0.15s both}
.nav-actions .btn-primary{animation:slideInRight 0.7s ease 0.15s both}

.hero .badge{animation:fadeUp 0.6s ease 0.1s both}
.hero h1{animation:fadeUp 0.7s ease 0.25s both}
.hero p{animation:fadeUp 0.7s ease 0.4s both}
.hero-actions .btn-outline{animation:slideInLeft 0.8s ease 0.55s both}
.hero-actions .btn-primary{animation:slideInRight 0.8s ease 0.55s both}

/* Elementi zinazoingia scroll-ndani: zote zinaanzia hazionekani (opacity:0)  */
/* kisha JS inaongeza .in-view kwa mfululizo (staggered) kupitia IntersectionObserver */
.reveal-left, .reveal-right, .reveal-up{opacity:0}
.reveal-left.in-view{animation:slideInLeft 0.7s cubic-bezier(.25,.8,.25,1) both}
.reveal-right.in-view{animation:slideInRight 0.7s cubic-bezier(.25,.8,.25,1) both}
.reveal-up.in-view{animation:fadeUp 0.7s cubic-bezier(.25,.8,.25,1) both}

.feature-card:hover .feature-icon{animation:floatY 1.2s ease-in-out infinite}
@media(max-width:820px){
    .stats{grid-template-columns:repeat(3,1fr)}
    .features-grid{grid-template-columns:1fr}
    .steps{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:520px){
    .nav{padding:14px 16px}
    .nav-brand span.name{display:none}
    .hero{padding:60px 18px 40px}
    .hero-actions{flex-direction:column}
    .hero-actions .btn{width:100%;justify-content:center}
    .stats{grid-template-columns:repeat(2,1fr)}
    .steps{grid-template-columns:1fr}
    .logo-slide img{height:22px;margin:0 16px}
}
</style>
</head>
<body>
<div class="wrap">

    <!-- ══ NAV ══ -->
    <nav class="nav">
        <div class="nav-brand">
            <img src="logo.png" alt="Tech 5G Wi-Fi">
            <span class="name">Tech 5G Wi-Fi</span>
        </div>
        <div class="nav-actions">
            <div class="lang-toggle">
                <button class="lang-btn active" id="btn-lang-sw" onclick="badilishaLugha('sw')">SW</button>
                <button class="lang-btn" id="btn-lang-en" onclick="badilishaLugha('en')">EN</button>
            </div>
            <a href="index.php" class="btn btn-outline" data-translate="nav_ingia">Ingia</a>
            <a href="index.php#signup" class="btn btn-primary" data-translate="nav_jisajili">Jisajili</a>
        </div>
    </nav>

    <!-- ══ HERO ══ -->
    <section class="hero">
        <div class="badge"><i class="fa-solid fa-circle-check"></i> <span data-translate="hero_badge">Mfumo Rasmi wa Malipo ya Wi-Fi Tanzania</span></div>
        <h1><span data-translate="hero_h1_pre">Simamia Vocha na Malipo ya Wi-Fi Yako</span> <span class="highlight" data-translate="hero_h1_span">Kwa Urahisi</span></h1>
        <p data-translate="hero_p">
            Tech 5G Wi-Fi ni mfumo kamili wa kusimamia hotspot za Wi-Fi — kutengeneza vocha
            kiotomatiki, kupokea malipo kupitia M-Pesa, Airtel Money na mitandao mingine ya simu,
            kufuatilia mapato, na kudhibiti wateja wote sehemu moja.
        </p>
        <div class="hero-actions">
            <a href="index.php" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> <span data-translate="hero_btn_login">Ingia Kwenye Akaunti</span></a>
            <a href="index.php#signup" class="btn btn-outline"><i class="fa-solid fa-user-plus"></i> <span data-translate="hero_btn_signup">Fungua Akaunti Mpya</span></a>
        </div>
    </section>

    <!-- ══ STATS ══ -->
    <div class="stats">
        <div class="stat-card reveal-up"><div class="num">24/7</div><div class="lbl" data-translate="stat_lbl1">Mfumo Unafanya Kazi</div></div>
        <div class="stat-card reveal-up"><div class="num" data-count="100" data-suffix="%">0%</div><div class="lbl" data-translate="stat_lbl2">Vocha za Kiotomatiki</div></div>
        <div class="stat-card reveal-up"><div class="num" data-count="3" data-suffix="+">0+</div><div class="lbl" data-translate="stat_lbl3">Njia za Malipo</div></div>
        <div class="stat-card reveal-up"><div class="num">Live</div><div class="lbl" data-translate="stat_lbl4">Ufuatiliaji wa Router</div></div>
        <div class="stat-card reveal-up"><div class="num" data-count="158" data-suffix="+">0+</div><div class="lbl" data-translate="stat_lbl5">Reseller Wanaotumia Sasa</div></div>
    </div>

    <!-- ══ FEATURES ══ -->
    <section class="section">
        <div class="section-head">
            <div class="eyebrow" data-translate="features_eyebrow">Vipengele</div>
            <h2 data-translate="features_h2">Kila Kitu Unachohitaji Kuendesha Biashara Yako</h2>
            <p data-translate="features_p">Mfumo mmoja unaokusaidia kuuza, kufuatilia na kudhibiti mtandao wako wa Wi-Fi bila usumbufu.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card reveal-left">
                <div class="feature-icon g"><i class="fa-solid fa-bolt"></i></div>
                <h3 data-translate="feat1_h">Vocha za Haraka</h3>
                <p data-translate="feat1_p">Tengeneza na uuze vocha za Wi-Fi papo hapo, zikiwa zinasajiliwa moja kwa moja kwenye router yako ya MikroTik.</p>
            </div>
            <div class="feature-card reveal-up">
                <div class="feature-icon b"><i class="fa-solid fa-mobile-screen-button"></i></div>
                <h3 data-translate="feat2_h">Malipo ya Mtandao wa Simu</h3>
                <p data-translate="feat2_p">Wateja wanalipia kwa urahisi kupitia M-Pesa, Tigo Pesa, Airtel Money na huduma nyingine za pesa za simu.</p>
            </div>
            <div class="feature-card reveal-right">
                <div class="feature-icon o"><i class="fa-solid fa-chart-line"></i></div>
                <h3 data-translate="feat3_h">Takwimu za Mapato</h3>
                <p data-translate="feat3_p">Fuatilia mauzo, wateja hai, na hali ya malipo kwa muda halisi kupitia dashboard rahisi kuelewa.</p>
            </div>
            <div class="feature-card reveal-left">
                <div class="feature-icon g"><i class="fa-solid fa-tower-broadcast"></i></div>
                <h3 data-translate="feat4_h">Ufuatiliaji wa Router</h3>
                <p data-translate="feat4_p">Angalia hali ya station zako za MikroTik moja kwa moja — mtandaoni au chini — na upate taarifa mapema.</p>
            </div>
            <div class="feature-card reveal-up">
                <div class="feature-icon b"><i class="fa-solid fa-tags"></i></div>
                <h3 data-translate="feat5_h">Bei Zinazobadilika</h3>
                <p data-translate="feat5_p">Weka bei na speed za vifurushi vyako vya Siku, Wiki na Mwezi — badilisha wakati wowote unavyotaka.</p>
            </div>
            <div class="feature-card reveal-right">
                <div class="feature-icon o"><i class="fa-solid fa-shield-halved"></i></div>
                <h3 data-translate="feat6_h">Usalama wa Hali ya Juu</h3>
                <p data-translate="feat6_p">Ulinzi dhidi ya majaribio ya kubashiri vocha, akaunti tofauti kwa kila muuzaji (reseller), na taarifa salama za wateja.</p>
            </div>
        </div>
    </section>

    <!-- ══ DASHBOARD PREVIEW ══ -->
    <section class="section">
        <div class="section-head reveal-up">
            <div class="eyebrow" data-translate="preview_eyebrow">Muonekano wa Ndani</div>
            <h2 data-translate="preview_h2">Dashboard Inayokupa Udhibiti Kamili</h2>
            <p data-translate="preview_p">Fuatilia mapato, vocha, na hali ya router zako zote kwa dakika — bila kuhitaji ujuzi wa kiufundi.</p>
        </div>
        <div class="preview-wrap reveal-up">
            <div class="preview-topbar"><span></span><span></span><span></span></div>
            <div class="preview-stat-row">
                <div class="preview-stat"><div class="p-num">Tsh 486K</div><div class="p-lbl" data-translate="preview_lbl1">Mapato ya Leo</div></div>
                <div class="preview-stat"><div class="p-num">37</div><div class="p-lbl" data-translate="preview_lbl2">Vocha Zilizouzwa</div></div>
                <div class="preview-stat"><div class="p-num" data-translate="preview_online">Online</div><div class="p-lbl" data-translate="preview_lbl3">Hali ya Router</div></div>
            </div>
            <div class="preview-body">
                <div class="preview-chart">
                    <div class="bar" style="height:35%"></div>
                    <div class="bar" style="height:55%"></div>
                    <div class="bar" style="height:40%"></div>
                    <div class="bar" style="height:72%"></div>
                    <div class="bar" style="height:50%"></div>
                    <div class="bar" style="height:88%"></div>
                    <div class="bar" style="height:65%"></div>
                </div>
                <div class="preview-list">
                    <div class="preview-row"><span class="code">TXN-A72F91</span><span class="ok">COMPLETED</span></div>
                    <div class="preview-row"><span class="code">TXN-B10C44</span><span class="ok">COMPLETED</span></div>
                    <div class="preview-row"><span class="code">TXN-D93E02</span><span class="ok">COMPLETED</span></div>
                    <div class="preview-row"><span class="code">TXN-F55A81</span><span class="ok">COMPLETED</span></div>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ HOW IT WORKS ══ -->
    <section class="section">
        <div class="section-head">
            <div class="eyebrow" data-translate="how_eyebrow">Jinsi Inavyofanya Kazi</div>
            <h2 data-translate="how_h2">Hatua Nne Rahisi</h2>
        </div>
        <div class="steps">
            <div class="step-card reveal-left">
                <div class="step-num">1</div>
                <h4 data-translate="step1_h">Mteja Anachagua Kifurushi</h4>
                <p data-translate="step1_p">Anaunganisha na Wi-Fi na kuchagua kifurushi cha Siku, Wiki au Mwezi kwenye ukurasa wa malipo.</p>
            </div>
            <div class="step-card reveal-up">
                <div class="step-num">2</div>
                <h4 data-translate="step2_h">Analipa kwa Simu</h4>
                <p data-translate="step2_p">Analipa kupitia M-Pesa, Airtel Money au mtandao mwingine wa pesa za simu aliopo nao.</p>
            </div>
            <div class="step-card reveal-up">
                <div class="step-num">3</div>
                <h4 data-translate="step3_h">Vocha Inatengenezwa</h4>
                <p data-translate="step3_p">Mfumo unatengeneza vocha kiotomatiki na kuiunganisha na router yako ya MikroTik.</p>
            </div>
            <div class="step-card reveal-right">
                <div class="step-num">4</div>
                <h4 data-translate="step4_h">Anaunganishwa Papo Hapo</h4>
                <p data-translate="step4_p">Mteja anaingia mtandaoni moja kwa moja bila kusubiri — huduma inaanza mara moja.</p>
            </div>
        </div>
    </section>

    <!-- ══ PAYMENT METHODS (logo zinazotembea) ══ -->
    <div class="pay-strip reveal-up">
        <p class="pay-label" data-translate="pay_label">Tunakubali Malipo Kupitia</p>
        <div class="logo-za-simu">
            <div class="logo-slide">
                <img src="mixx.PNG" alt="Mixx by Yas">
                <img src="halopesa.png" alt="HaloPesa">
                <img src="Airtel-Money.png" alt="Airtel Money">
                <img src="m-pesa.png" alt="M-Pesa">
                <img src="mixx.PNG" alt="Mixx by Yas">
                <img src="halopesa.png" alt="HaloPesa">
                <img src="Airtel-Money.png" alt="Airtel Money">
                <img src="m-pesa.png" alt="M-Pesa">
                <img src="mixx.PNG" alt="Mixx by Yas">
                <img src="halopesa.png" alt="HaloPesa">
                <img src="Airtel-Money.png" alt="Airtel Money">
                <img src="m-pesa.png" alt="M-Pesa">
                <img src="mixx.PNG" alt="Mixx by Yas">
                <img src="halopesa.png" alt="HaloPesa">
                <img src="Airtel-Money.png" alt="Airtel Money">
                <img src="m-pesa.png" alt="M-Pesa">
            </div>
        </div>
    </div>

    <!-- ══ TRUST BADGES ══ -->
    <div class="trust-strip reveal-up">
        <div class="trust-item"><i class="fa-solid fa-certificate"></i> <span data-translate="trust1">Biashara Iliyosajiliwa Rasmi</span></div>
        <div class="trust-item"><i class="fa-solid fa-lock"></i> <span data-translate="trust2">Malipo Salama na Yaliyofichwa</span></div>
        <div class="trust-item"><i class="fa-solid fa-headset"></i> <span data-translate="trust3">Msaada wa Haraka</span></div>
        <div class="trust-item"><i class="fa-solid fa-clock-rotate-left"></i> <span data-translate="trust4">Mfumo wa Muda Halisi</span></div>
    </div>

    <!-- ══ MAWASILIANO NA MAHALI TULIPO ══ -->
    <section class="section">
        <div class="section-head">
            <div class="eyebrow" data-translate="contact_eyebrow">Wasiliana Nasi</div>
            <h2 data-translate="contact_h2">Tuko Tayari Kukusaidia</h2>
            <p data-translate="contact_p">Una swali au unahitaji msaada wa kuanzisha akaunti yako? Tupigie au tutembelee.</p>
        </div>
        <div class="contact-grid">
            <div class="contact-info reveal-left">
                <div class="contact-row">
                    <div class="c-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="c-text">
                        <div class="c-lbl" data-translate="c_lbl_phone">Simu / WhatsApp</div>
                        <a href="tel:+255777360300">+255 777 360 300</a>
                    </div>
                </div>
                <div class="contact-row">
                    <div class="c-icon"><i class="fa-brands fa-whatsapp"></i></div>
                    <div class="c-text">
                        <div class="c-lbl" data-translate="c_lbl_whatsapp">Tuandikie WhatsApp</div>
                        <a href="https://wa.me/255777360300" target="_blank" rel="noopener" data-translate="c_whatsapp_btn">Bofya Kuzungumza</a>
                    </div>
                </div>
                <div class="contact-row">
                    <div class="c-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div class="c-text">
                        <div class="c-lbl" data-translate="c_lbl_email">Barua Pepe</div>
                        <a href="mailto:shaabansilima@gmail.com">shaabansilima@gmail.com</a>
                    </div>
                </div>
                <div class="contact-row">
                    <div class="c-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="c-text">
                        <div class="c-lbl" data-translate="c_lbl_location">Mahali Tulipo</div>
                        <span>Tech 5G Headquarter, Zanzibar</span>
                    </div>
                </div>
            </div>
            <div class="map-wrap reveal-right">
                <iframe
                    src="https://maps.google.com/maps?q=-6.161942,39.215844(Tech%205G%20Headquarter)&z=17&ie=UTF8&iwloc=&output=embed"
                    width="100%" height="100%" style="border:0;min-height:280px"
                    allowfullscreen="" loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    title="Tech 5G Headquarter - Zanzibar">
                </iframe>
                <a href="https://maps.app.goo.gl/poxL4wo2CJAkjuPu7"
                   target="_blank" rel="noopener" class="map-fallback">
                    <i class="fa-solid fa-up-right-from-square"></i> <span data-translate="map_fallback">Fungua kwenye Google Maps</span>
                </a>
            </div>
        </div>
    </section>

    <!-- ══ MASWALI YANAYOULIZWA MARA KWA MARA (FAQ) ══ -->
    <section class="section">
        <div class="section-head">
            <div class="eyebrow" data-translate="faq_eyebrow">Maswali</div>
            <h2 data-translate="faq_h2">Maswali Yanayoulizwa Mara kwa Mara</h2>
        </div>
        <div class="faq-wrap">
            <div class="faq-item reveal-left">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span data-translate="faqQ1">Inachukua muda gani kuanzisha akaunti?</span>
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="faq-a"><p data-translate="faqA1">Baada ya kujisajili na kuweka bei za vifurushi vyako, akaunti yako iko tayari kutumika mara moja — hakuna kusubiri.</p></div>
            </div>
            <div class="faq-item reveal-right">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span data-translate="faqQ2">Je, ninaweza kutumia mitandao yote ya pesa za simu?</span>
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="faq-a"><p data-translate="faqA2">Ndiyo. Mfumo unakubali M-Pesa (Vodacom), Airtel Money, Tigo Pesa, na Halotel — wateja wako wanaweza kulipa kwa mtandao wowote wanaotumia.</p></div>
            </div>
            <div class="faq-item reveal-left">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span data-translate="faqQ3">Nini kinatokea kama mteja amelipa lakini hakupata vocha?</span>
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="faq-a"><p data-translate="faqA3">Dashboard yako ina ukurasa maalum wa "Hali za Malipo" unaokuruhusu kutafuta malipo yaliyokwama na kuyakamilisha kwa kubonyeza kitufe kimoja.</p></div>
            </div>
            <div class="faq-item reveal-right">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span data-translate="faqQ4">Je, ninaweza kubadilisha bei na speed za vifurushi baadaye?</span>
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="faq-a"><p data-translate="faqA4">Ndiyo, unaweza kubadilisha bei, siku, na speed za vifurushi vyako wakati wowote kupitia Mipangilio kwenye dashboard yako.</p></div>
            </div>
            <div class="faq-item reveal-left">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span data-translate="faqQ5">Je, taarifa za wateja wangu ziko salama?</span>
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="faq-a"><p data-translate="faqA5">Ndiyo. Mfumo unatumia usalama wa hali ya juu kulinda taarifa za malipo na akaunti — kila muuzaji (reseller) anaona taarifa za wateja wake pekee.</p></div>
            </div>
        </div>
    </section>

    <!-- ══ FINAL CTA ══ -->
    <section class="cta-section reveal-up">
        <h2 data-translate="cta_h2">Uko Tayari Kuanza?</h2>
        <p data-translate="cta_p">Fungua akaunti yako ya muuzaji (reseller) leo na uanze kuuza vocha za Wi-Fi kwa dakika chache.</p>
        <div class="hero-actions">
            <a href="index.php#signup" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> <span data-translate="cta_btn_signup">Jisajili Sasa</span></a>
            <a href="index.php" class="btn btn-outline"><i class="fa-solid fa-right-to-bracket"></i> <span data-translate="cta_btn_login">Nina Akaunti Tayari</span></a>
        </div>
    </section>

    <footer class="footer">© <?php echo date('Y'); ?> Tech 5G Wi-Fi Billing System &nbsp;·&nbsp; <span data-translate="footer_rights">Haki zote zimehifadhiwa</span></footer>

</div>

<a href="https://wa.me/255777360300" target="_blank" rel="noopener" class="whatsapp-float" title="Tuandikie WhatsApp">
    <i class="fa-brands fa-whatsapp"></i>
</a>

<script>
// ══ Scroll-reveal na animation ya mfululizo (staggered) ══
// Kila elementi yenye class reveal-left/right/up inaonekana taratibu
// inapoingia kwenye screen wakati mtumiaji anaskroli chini.
(function(){
    const els = document.querySelectorAll('.reveal-left, .reveal-right, .reveal-up');

    // Delay ya mfululizo ndani ya kundi moja (mfano features-grid) kwa mpangilio wa DOM
    const containers = document.querySelectorAll('.features-grid, .steps, .stats, .faq-wrap');
    containers.forEach(container => {
        const items = container.querySelectorAll('.reveal-left, .reveal-right, .reveal-up');
        items.forEach((item, i) => {
            item.style.animationDelay = (i * 0.12) + 's';
        });
    });

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    els.forEach(el => observer.observe(el));

    // ══ Namba zinazojihesabu (count-up) kwenye sehemu ya STATS ══
    function hesabuNamba(el) {
        const target = parseInt(el.getAttribute('data-count'), 10);
        const suffix = el.getAttribute('data-suffix') || '';
        const muda = 1400; // milisekunde
        const anzo = performance.now();

        function tick(sasa) {
            const p = Math.min((sasa - anzo) / muda, 1);
            const rahisishwa = 1 - Math.pow(1 - p, 3); // ease-out cubic
            const thamani = Math.round(target * rahisishwa);
            el.textContent = thamani + suffix;
            if (p < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    const nambaObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                hesabuNamba(entry.target);
                nambaObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.4 });

    document.querySelectorAll('.num[data-count]').forEach(el => nambaObserver.observe(el));
})();

// ══ FAQ accordion ══
function toggleFaq(qEl) {
    const item = qEl.closest('.faq-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
}

// ══ LUGHA MBILI (SWAHILI / ENGLISH) ══
const tafsiri = {
    sw: {
        nav_ingia: "Ingia", nav_jisajili: "Jisajili",
        hero_badge: "Mfumo Rasmi wa Malipo ya Wi-Fi Tanzania",
        hero_h1_pre: "Simamia Vocha na Malipo ya Wi-Fi Yako",
        hero_h1_span: "Kwa Urahisi",
        hero_p: "Tech 5G Wi-Fi ni mfumo kamili wa kusimamia hotspot za Wi-Fi — kutengeneza vocha kiotomatiki, kupokea malipo kupitia Mixx by yas, Hao Pesa na mitandao mingine ya simu, kufuatilia mapato, na kudhibiti wateja wote sehemu moja.",
        hero_btn_login: "Ingia Kwenye Akaunti", hero_btn_signup: "Fungua Akaunti Mpya",
        stat_lbl1: "Mfumo Unafanya Kazi", stat_lbl2: "Vocha za Kiotomatiki", stat_lbl3: "Njia za Malipo",
        stat_lbl4: "Ufuatiliaji wa Router", stat_lbl5: "Reseller Wanaotumia Sasa",
        features_eyebrow: "Vipengele", features_h2: "Kila Kitu Unachohitaji Kuendesha Biashara Yako",
        features_p: "Mfumo mmoja unaokusaidia kuuza, kufuatilia na kudhibiti mtandao wako wa Wi-Fi bila usumbufu.",
        feat1_h: "Vocha za Haraka", feat1_p: "Tengeneza na uuze vocha za Wi-Fi papo hapo, zikiwa zinasajiliwa moja kwa moja kwenye router yako ya MikroTik.",
        feat2_h: "Malipo ya Mtandao wa Simu", feat2_p: "Wateja wanalipia kwa urahisi kupitia M-Pesa, Tigo Pesa, Airtel Money na huduma nyingine za pesa za simu.",
        feat3_h: "Takwimu za Mapato", feat3_p: "Fuatilia mauzo, wateja hai, na hali ya malipo kwa muda halisi kupitia dashboard rahisi kuelewa.",
        feat4_h: "Ufuatiliaji wa Router", feat4_p: "Angalia hali ya station zako za MikroTik moja kwa moja — mtandaoni au chini — na upate taarifa mapema.",
        feat5_h: "Bei Zinazobadilika", feat5_p: "Weka bei na speed za vifurushi vyako vya Siku, Wiki na Mwezi — badilisha wakati wowote unavyotaka.",
        feat6_h: "Usalama wa Hali ya Juu", feat6_p: "Ulinzi dhidi ya majaribio ya kubashiri vocha, akaunti tofauti kwa kila muuzaji (reseller), na taarifa salama za wateja.",
        preview_eyebrow: "Muonekano wa Ndani", preview_h2: "Dashboard Inayokupa Udhibiti Kamili",
        preview_p: "Fuatilia mapato, vocha, na hali ya router zako zote kwa dakika — bila kuhitaji ujuzi wa kiufundi.",
        preview_lbl1: "Mapato ya Leo", preview_lbl2: "Vocha Zilizouzwa", preview_lbl3: "Hali ya Router", preview_online: "Online",
        how_eyebrow: "Jinsi Inavyofanya Kazi", how_h2: "Hatua Nne Rahisi",
        step1_h: "Mteja Anachagua Kifurushi", step1_p: "Anaunganisha na Wi-Fi na kuchagua kifurushi cha Siku, Wiki au Mwezi kwenye ukurasa wa malipo.",
        step2_h: "Analipa kwa Simu", step2_p: "Analipa kupitia M-Pesa, Airtel Money au mtandao mwingine wa pesa za simu aliopo nao.",
        step3_h: "Vocha Inatengenezwa", step3_p: "Mfumo unatengeneza vocha kiotomatiki na kuiunganisha na router yako ya MikroTik.",
        step4_h: "Anaunganishwa Papo Hapo", step4_p: "Mteja anaingia mtandaoni moja kwa moja bila kusubiri — huduma inaanza mara moja.",
        pay_label: "Tunakubali Malipo Kupitia",
        trust1: "Biashara Iliyosajiliwa Rasmi", trust2: "Malipo Salama na Yaliyofichwa",
        trust3: "Msaada wa Haraka", trust4: "Mfumo wa Muda Halisi",
        contact_eyebrow: "Wasiliana Nasi", contact_h2: "Tuko Tayari Kukusaidia",
        contact_p: "Una swali au unahitaji msaada wa kuanzisha akaunti yako? Tupigie au tutembelee.",
        c_lbl_phone: "Simu / WhatsApp", c_lbl_whatsapp: "Tuandikie WhatsApp", c_whatsapp_btn: "Bofya Kuzungumza",
        c_lbl_email: "Barua Pepe", c_lbl_location: "Mahali Tulipo", map_fallback: "Fungua kwenye Google Maps",
        faq_eyebrow: "Maswali", faq_h2: "Maswali Yanayoulizwa Mara kwa Mara",
        faqQ1: "Inachukua muda gani kuanzisha akaunti?",
        faqA1: "Baada ya kujisajili na kuweka bei za vifurushi vyako, akaunti yako iko tayari kutumika mara moja — hakuna kusubiri.",
        faqQ2: "Je, ninaweza kutumia mitandao yote ya pesa za simu?",
        faqA2: "Ndiyo. Mfumo unakubali M-Pesa (Vodacom), Airtel Money, Tigo Pesa, na Halotel — wateja wako wanaweza kulipa kwa mtandao wowote wanaotumia.",
        faqQ3: "Nini kinatokea kama mteja amelipa lakini hakupata vocha?",
        faqA3: "Dashboard yako ina ukurasa maalum wa \"Hali za Malipo\" unaokuruhusu kutafuta malipo yaliyokwama na kuyakamilisha kwa kubonyeza kitufe kimoja.",
        faqQ4: "Je, ninaweza kubadilisha bei na speed za vifurushi baadaye?",
        faqA4: "Ndiyo, unaweza kubadilisha bei, siku, na speed za vifurushi vyako wakati wowote kupitia Mipangilio kwenye dashboard yako.",
        faqQ5: "Je, taarifa za wateja wangu ziko salama?",
        faqA5: "Ndiyo. Mfumo unatumia usalama wa hali ya juu kulinda taarifa za malipo na akaunti — kila muuzaji (reseller) anaona taarifa za wateja wake pekee.",
        cta_h2: "Uko Tayari Kuanza?", cta_p: "Fungua akaunti yako ya muuzaji (reseller) leo na uanze kuuza vocha za Wi-Fi kwa dakika chache.",
        cta_btn_signup: "Jisajili Sasa", cta_btn_login: "Nina Akaunti Tayari",
        footer_rights: "Haki zote zimehifadhiwa"
    },
    en: {
        nav_ingia: "Login", nav_jisajili: "Sign Up",
        hero_badge: "Official Wi-Fi Billing System in Tanzania",
        hero_h1_pre: "Manage Your Wi-Fi Vouchers and Payments",
        hero_h1_span: "In Easy Way",
        hero_p: "Tech 5G Wi-Fi is a complete hotspot management system — generate vouchers automatically, receive payments via Mixx by yas, Halo Pesa and other mobile networks, track revenue, and manage all your customers in one place.",
        hero_btn_login: "Login to Your Account", hero_btn_signup: "Create New Account",
        stat_lbl1: "System Always Online", stat_lbl2: "Automatic Vouchers", stat_lbl3: "Payment Methods",
        stat_lbl4: "Router Monitoring", stat_lbl5: "Active Resellers",
        features_eyebrow: "Features", features_h2: "Everything You Need to Run Your Business",
        features_p: "One system to help you sell, track, and manage your Wi-Fi network without hassle.",
        feat1_h: "Instant Vouchers", feat1_p: "Generate and sell Wi-Fi vouchers instantly, automatically registered on your MikroTik router.",
        feat2_h: "Mobile Money Payments", feat2_p: "Customers pay easily via M-Pesa, Tigo Pesa, Airtel Money and other mobile money services.",
        feat3_h: "Revenue Analytics", feat3_p: "Track sales, active customers, and payment status in real time through an easy-to-understand dashboard.",
        feat4_h: "Router Monitoring", feat4_p: "Check the status of your MikroTik stations instantly — online or offline — and get early alerts.",
        feat5_h: "Flexible Pricing", feat5_p: "Set prices and speeds for your Daily, Weekly, and Monthly packages — change them anytime you want.",
        feat6_h: "Advanced Security", feat6_p: "Protection against voucher brute-force attempts, separate accounts per reseller, and secure customer data.",
        preview_eyebrow: "Behind the Scenes", preview_h2: "A Dashboard That Gives You Full Control",
        preview_p: "Track revenue, vouchers, and the status of all your routers in seconds — no technical skills required.",
        preview_lbl1: "Today's Revenue", preview_lbl2: "Vouchers Sold", preview_lbl3: "Router Status", preview_online: "Online",
        how_eyebrow: "How It Works", how_h2: "Four Simple Steps",
        step1_h: "Customer Selects a Package", step1_p: "Connects to the Wi-Fi and selects a Daily, Weekly, or Monthly package on the payment page.",
        step2_h: "Pays via Mobile Money", step2_p: "Pays through M-Pesa, Airtel Money, or any other mobile money network they use.",
        step3_h: "Voucher is Generated", step3_p: "The system automatically generates a voucher and registers it on your MikroTik router.",
        step4_h: "Instantly Connected", step4_p: "The customer gets online right away without waiting — service starts immediately.",
        pay_label: "We Accept Payments Via",
        trust1: "Officially Registered Business", trust2: "Secure & Encrypted Payments",
        trust3: "Fast Support", trust4: "Real-Time System",
        contact_eyebrow: "Contact Us", contact_h2: "We're Ready to Help",
        contact_p: "Have a question or need help setting up your account? Call us or visit.",
        c_lbl_phone: "Phone / WhatsApp", c_lbl_whatsapp: "Message Us on WhatsApp", c_whatsapp_btn: "Tap to Chat",
        c_lbl_email: "Email", c_lbl_location: "Our Location", map_fallback: "Open in Google Maps",
        faq_eyebrow: "FAQ", faq_h2: "Frequently Asked Questions",
        faqQ1: "How long does it take to set up an account?",
        faqA1: "After signing up and setting your package prices, your account is ready to use immediately — no waiting.",
        faqQ2: "Can I use all mobile money networks?",
        faqA2: "Yes. The system accepts M-Pesa (Vodacom), Airtel Money, Tigo Pesa, and Halotel — your customers can pay with whichever network they use.",
        faqQ3: "What happens if a customer paid but didn't receive a voucher?",
        faqA3: "Your dashboard has a dedicated \"Payment Status\" page that lets you find stuck payments and complete them with one click.",
        faqQ4: "Can I change package prices and speeds later?",
        faqA4: "Yes, you can change the price, duration, and speed of your packages anytime through Settings on your dashboard.",
        faqQ5: "Is my customers' data safe?",
        faqA5: "Yes. The system uses advanced security to protect payment and account data — each reseller only sees their own customers' information.",
        cta_h2: "Ready to Get Started?", cta_p: "Create your reseller account today and start selling Wi-Fi vouchers in minutes.",
        cta_btn_signup: "Sign Up Now", cta_btn_login: "I Already Have an Account",
        footer_rights: "All rights reserved"
    }
};

function badilishaLugha(lugha) {
    document.querySelectorAll('[data-translate]').forEach(el => {
        const key = el.getAttribute('data-translate');
        if (tafsiri[lugha][key]) el.textContent = tafsiri[lugha][key];
    });
    document.getElementById('btn-lang-sw').classList.toggle('active', lugha === 'sw');
    document.getElementById('btn-lang-en').classList.toggle('active', lugha === 'en');
    document.documentElement.setAttribute('lang', lugha);
    try { localStorage.setItem('tsg_lugha', lugha); } catch(e) {}
}

// Rudisha lugha aliyochagua mtumiaji hapo awali (kama browser inaruhusu localStorage)
(function(){
    try {
        const saved = localStorage.getItem('tsg_lugha');
        if (saved === 'en') badilishaLugha('en');
    } catch(e) {}
})();
</script>
</body>
</html>