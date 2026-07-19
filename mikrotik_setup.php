<?php
/**
 * nyaraka.php
 * ---------------------------------------------------------
 * Ukurasa wa "Nyaraka za Kufunga Router" - reseller mpya
 * anaweza kupakua faili za setup moja kwa moja kutoka
 * dashboard yake, badala ya kuomba faili kwako moja kwa moja.
 * ---------------------------------------------------------
 */
session_start();
include 'auth_check.php'; // hakikisha reseller amelogin kabla ya kuona ukurasa huu

// ── Faili zinazoruhusiwa kupakuliwa (WHITELIST - usalama) ──
// Hii inazuia mtu kujaribu kupakua faili nyingine yoyote kwenye
// server kwa kubadilisha jina kwenye URL (path traversal attack).
$faili_zinazoruhusiwa = [
    'rsc' => [
        'jina_kwa_mtumiaji' => 'tech5g_router_setup.rsc',
        'path' => __DIR__ . '/downloads/tech5g_router_setup.rsc',
        'maelezo' => 'Script ya kusanidi router mpya ya MikroTik (bridge, hotspot, trial, walled garden).',
        'icon' => 'fa-terminal',
    ],
    'maelezo' => [
        'jina_kwa_mtumiaji' => 'Maelezo_ya_Setup_MikroTik.md',
        'path' => __DIR__ . '/downloads/maelezo_ya_setup_mikrotik.md',
        'maelezo' => 'Maelezo kamili ya kila hatua ya script, na sehemu unazopaswa kubadilisha.',
        'icon' => 'fa-file-lines',
    ],
    'login' => [
        'jina_kwa_mtumiaji' => 'login.html',
        'path' => __DIR__ . '/login.html',
        'maelezo' => 'Ukurasa wa Login wa Hotspot - pakua kisha upande kwenye Files za MikroTik.',
        'icon' => 'fa-right-to-bracket',
    ],
    'status' => [
        'jina_kwa_mtumiaji' => 'status.html',
        'path' => __DIR__ . '/status.html',
        'maelezo' => 'Ukurasa wa Status ya Hotspot unaomuonyesha mteja muda uliobaki.',
        'icon' => 'fa-circle-info',
    ],
    'zanzibar' => [
        'jina_kwa_mtumiaji' => 'zanzibar.jpg',
        'path' => __DIR__ . '/zanzibar.jpg',
        'maelezo' => 'Picha ya background inayotumika kwenye ukurasa wa Login wa Hotspot.',
        'icon' => 'fa-image',
    ],
];

// ── Kupakua faili (?pakua=rsc au ?pakua=maelezo) ──
if (isset($_GET['pakua']) && array_key_exists($_GET['pakua'], $faili_zinazoruhusiwa)) {
    $f = $faili_zinazoruhusiwa[$_GET['pakua']];
    if (!file_exists($f['path'])) {
        http_response_code(404);
        die('Faili halipatikani. Wasiliana na msimamizi.');
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f['jina_kwa_mtumiaji'] . '"');
    header('Content-Length: ' . filesize($f['path']));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($f['path']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MikroTik Setup · Tech 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
<link rel="preload" as="image" href="beach5.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'DM Sans',Arial,sans-serif;background-color:#0d1b17;background-image:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)),url(beach5.jpg);background-size:cover;background-position:center;background-attachment:fixed;color:#eaf6f5;padding:30px 16px;min-height:100vh;}
  .wrap{max-width:720px;margin:0 auto;}
  .topnav{display:flex;justify-content:flex-end;margin-bottom:14px;}
  .back-btn{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,0.10);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.20);color:#eaf6f5;padding:9px 16px;border-radius:9px;text-decoration:none;font-weight:600;font-size:12.5px;transition:all 0.2s;}
  .back-btn:hover{background:rgba(255,255,255,0.16);border-color:rgba(255,255,255,0.35);}
  h1{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:6px;}
  p.sub{color:#8aa3a8;font-size:13.5px;margin-bottom:26px;}
  .card{background:rgba(13,34,48,0.55);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.10);border-radius:14px;padding:20px;margin-bottom:16px;display:flex;align-items:center;gap:16px;}
  .card i{font-size:26px;color:#18e7d3;}
  .card .info{flex:1;}
  .card .info h3{font-size:15px;margin-bottom:4px;}
  .card .info p{font-size:12.5px;color:#8aa3a8;margin:0;}
  .btn{background:#18e7d3;color:#07151c;padding:10px 18px;border-radius:9px;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap;}
  .onyo{background:rgba(255,138,138,0.1);border:1px solid rgba(255,138,138,0.3);color:#ff8a8a;padding:14px 16px;border-radius:10px;font-size:12.5px;margin-top:24px;}
  .footer{text-align:center;padding:22px 0 4px;font-size:11px;color:rgba(255,255,255,0.35);font-family:'Space Mono',monospace;}
</style>
</head>
<body>

<div class="wrap">

<div class="topnav">
    <a class="back-btn" href="user_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Rudi Dashboard</a>
</div>

<h1>📥 MikroTik Setup</h1>
<p class="sub">Pakua faili hizi kabla ya kuanza kusanidi router mpya ya MikroTik.</p>

<?php foreach ($faili_zinazoruhusiwa as $key => $f): ?>
<div class="card">
    <i class="fa-solid <?php echo $f['icon']; ?>"></i>
    <div class="info">
        <h3><?php echo htmlspecialchars($f['jina_kwa_mtumiaji']); ?></h3>
        <p><?php echo htmlspecialchars($f['maelezo']); ?></p>
    </div>
    <a class="btn" href="?pakua=<?php echo urlencode($key); ?>"><i class="fa-solid fa-download"></i> Pakua</a>
</div>
<?php endforeach; ?>

<div class="onyo">
  ⚠️ Baada ya kupakua <code>tech5g_router_setup.rsc</code>, jaza sehemu zote zenye alama <code>&lt;&lt;&lt; &gt;&gt;&gt;</code> kabla ya kuiendesha kwenye router — soma <strong>Maelezo_ya_Setup_MikroTik.md</strong> kwa mwongozo kamili.
</div>

<?php if (isset($_GET['debug'])): ?>
<div class="onyo" style="border-color:rgba(63,199,253,0.35);color:#3fc7fd;background:rgba(63,199,253,0.08);">
  <strong>🔎 DEBUG — Hali ya kila faili kwenye server:</strong><br><br>
  <?php foreach ($faili_zinazoruhusiwa as $key => $f):
      $lipo = file_exists($f['path']);
  ?>
    <div style="margin-bottom:8px;font-family:'Space Mono',monospace;font-size:11.5px;line-height:1.6;">
      <?php echo $lipo ? '✅' : '❌'; ?> <strong><?php echo htmlspecialchars($f['jina_kwa_mtumiaji']); ?></strong><br>
      Path: <?php echo htmlspecialchars($f['path']); ?><br>
      Hali: <?php echo $lipo ? 'IPO (readable: '.(is_readable($f['path'])?'ndiyo':'HAPANA - angalia permissions').')' : 'HAIPO kwenye path hiyo hasa'; ?>
    </div>
  <?php endforeach; ?>
  <em>Ondoa <code>?debug=1</code> kwenye URL baada ya kumaliza kuangalia (au niambie niondoe hii panel kabisa kwenye code).</em>
</div>
<?php endif; ?>

<footer class="footer">© <?php echo date('Y'); ?> Tech 5G Wi-Fi Billing System &nbsp;·&nbsp; Haki zote zimehifadhiwa</footer>

</div>

</body>
</html>