<?php
/**
 * walled_garden_sync.php — CLI TU
 * ------------------------------------------------------------------
 * Hakikisha kila router iliyosajiliwa ina walled-garden inayoruhusu
 * portal yetu KABLA mteja hajalipa. Bila hizi, mteja anayeunganisha
 * anaona ukurasa mtupu: login.html inamhamishia tech5g.co.tz lakini
 * hotspot inazuia traffic hiyo kwa sababu hajalogin bado.
 *
 * Matumizi (kwenye VPS):
 *   set -a; . /root/.tech5g-credentials; set +a
 *   /usr/local/emps/bin/php /var/www/tech5g/walled_garden_sync.php          # dry-run
 *   /usr/local/emps/bin/php /var/www/tech5g/walled_garden_sync.php --apply  # tekeleza
 *   ... --apply --router=1     # router moja tu
 *
 * Ni idempotent: inaruka entry zilizopo, inaongeza zilizopungua tu.
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

// Ruhusu ku-endeshwa hata ikiwa nakala ya script iko nje ya webroot (mfano /tmp).
// TECH5G_DIR inaweza kuwekwa kama env; vinginevyo tunatumia folda ya script au webroot.
$APP_DIR = getenv('TECH5G_DIR') ?: __DIR__;
if (!file_exists($APP_DIR . '/login_signup.php') && file_exists('/var/www/tech5g/login_signup.php')) {
    $APP_DIR = '/var/www/tech5g';
}
chdir($APP_DIR);
require_once $APP_DIR . '/login_signup.php';       // config.php + $conn
require_once $APP_DIR . '/routeros_api.class.php';
require_once $APP_DIR . '/mikrotik_helper.php';

// ── Host zinazohitajika na mteja KABLA ya kulipa ──
// Kila moja ina sababu: bila sababu, usiiongeze (walled-garden pana =
// mtu anavinjari bure bila kulipa).
$HOSTS = [
    'tech5g.co.tz'         => 'Portal yenyewe (index_backup.php)',
    '*.tech5g.co.tz'       => 'Subdomain zozote za portal',
    'cdnjs.cloudflare.com' => 'Font Awesome icons za index_backup.php/welcome.php',
    'fonts.googleapis.com' => 'Fonts za portal',
    'fonts.gstatic.com'    => 'Fonts za portal (files)',
    '*.googleapis.com'     => 'Fonts/CSS za Google',
    '*.gstatic.com'        => 'Fonts/CSS za Google (files)',
    'wa.me'                => 'Link ya msaada wa WhatsApp kwenye portal',
    'api.whatsapp.com'     => 'wa.me hu-redirect hapa',
];

// ── IP zinazohitajika (ngazi ya IP - hufanya kazi hata HTTPS/SNI ikishindikana) ──
$IPS = [
    '107.161.168.192' => 'Tech5G backend (VPS)',
];

$apply  = in_array('--apply', $argv, true);
$only   = 0;
foreach ($argv as $a) {
    if (strpos($a, '--router=') === 0) { $only = (int)substr($a, 9); }
}

echo $apply ? "MODE: APPLY (inabadilisha router)\n\n" : "MODE: DRY-RUN (hakuna kinachobadilishwa; ongeza --apply)\n\n";

$sql = "SELECT router_id, user_id, mikrotik_ip FROM mikrotik_configs";
if ($only > 0) { $sql .= " WHERE router_id = " . $only; }
$sql .= " ORDER BY router_id";

$res    = $conn->query($sql);
$jumla  = ['ok' => 0, 'imeshindikana' => 0, 'imeongezwa' => 0];

while ($cfg = $res->fetch_assoc()) {
    printf("=== Router %d (user %d) · %s ===\n", $cfg['router_id'], $cfg['user_id'], $cfg['mikrotik_ip']);

    $API = getMikrotikConnection($cfg['user_id'], $conn);
    if (!$API) {
        echo "  !! Muunganisho wa API umeshindikana - imerukwa\n\n";
        $jumla['imeshindikana']++;
        continue;
    }

    // Zilizopo sasa
    $zilizopo_host = [];
    foreach ($API->comm('/ip/hotspot/walled-garden/print') as $w) {
        if (is_array($w) && isset($w['dst-host'])) { $zilizopo_host[$w['dst-host']] = true; }
    }
    $zilizopo_ip = [];
    foreach ($API->comm('/ip/hotspot/walled-garden/ip/print') as $w) {
        if (is_array($w) && isset($w['dst-address'])) { $zilizopo_ip[$w['dst-address']] = true; }
    }

    foreach ($HOSTS as $host => $sababu) {
        if (isset($zilizopo_host[$host])) { echo "  = ipo      $host\n"; continue; }
        if (!$apply) { echo "  + itaongezwa $host   ($sababu)\n"; $jumla['imeongezwa']++; continue; }
        $API->comm('/ip/hotspot/walled-garden/add', [
            'dst-host' => $host,
            'action'   => 'allow',
            'comment'  => 'tech5g: ' . $sababu,
        ]);
        echo "  + imeongezwa $host\n";
        $jumla['imeongezwa']++;
    }

    foreach ($IPS as $ip => $sababu) {
        if (isset($zilizopo_ip[$ip])) { echo "  = ipo      $ip (IP)\n"; continue; }
        if (!$apply) { echo "  + itaongezwa $ip (IP)   ($sababu)\n"; $jumla['imeongezwa']++; continue; }
        $API->comm('/ip/hotspot/walled-garden/ip/add', [
            'dst-address' => $ip,
            'action'      => 'accept',
            'comment'     => 'tech5g: ' . $sababu,
        ]);
        echo "  + imeongezwa $ip (IP)\n";
        $jumla['imeongezwa']++;
    }

    $API->disconnect();
    $jumla['ok']++;
    echo "\n";
}

printf("Router zilizofikiwa: %d · zilizoshindikana: %d · entry %s: %d\n",
    $jumla['ok'], $jumla['imeshindikana'],
    $apply ? 'zilizoongezwa' : 'zinazokosekana', $jumla['imeongezwa']);

$conn->close();
