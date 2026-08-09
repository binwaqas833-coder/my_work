<?php
/**
 * walled_garden_sync.php — CLI TU
 * ------------------------------------------------------------------
 * Hakikisha kila router iliyosajiliwa ina walled-garden inayoruhusu
 * portal yetu KABLA mteja hajalipa.
 *
 * MUHIMU (MULTI-ROUTER): query hapa chini TAYARI inapitia kila ROUTER
 * (siyo kila USER) - ndiyo maana haihitaji kubadilika sana; ilikuwa
 * tayari sahihi kimuundo. Kilichobadilika ni jinsi getMikrotikConnection()
 * inavyoitwa - sasa inahitaji router_id NA user_id (siyo user_id peke
 * yake), kwa sababu reseller mmoja anaweza kuwa na routers kadhaa.
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

$APP_DIR = getenv('TECH5G_DIR') ?: __DIR__;
if (!file_exists($APP_DIR . '/login_signup.php') && file_exists('/var/www/tech5g/login_signup.php')) {
    $APP_DIR = '/var/www/tech5g';
}
chdir($APP_DIR);
require_once $APP_DIR . '/login_signup.php';       // config.php + $conn
require_once $APP_DIR . '/routeros_api.class.php';
require_once $APP_DIR . '/error_logger.php';
require_once $APP_DIR . '/mikrotik_helper.php';     // toleo JIPYA (multi-router)

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

// ── IP ya seva yetu ──
// MUHIMU: entry hii (walled-garden IP, siyo dst-host) ndiyo inayoruhusu HTTPS
// kufika portal. Ikikosekana au ikiwa ya seva ILIYOKUFA, mteja ambaye hajalipa
// huona ukurasa MTUPU.
//
// Tunaipata kutoka APP_BASE_URL badala ya kuiandika kwa mkono. Uhamiaji wa
// 2026-08-07 uliacha IP ya zamani (107.161.168.192) hapa, na kila router
// mpya ingeruhusu seva isiyokuwepo. Kuipata kutoka domain kunaondoa kabisa
// aina hii ya hitilafu.
// Chaguo la kwanza: TECH5G_PUBLIC_IP ikiwekwa wazi. La sivyo tumia domain.
// TAHADHARI: KAMWE usitegemee APP_BASE_URL peke yake hapa. Kwenye CLI, kama
// APP_ENV haijawekwa, config.php hurudi 'development' na APP_BASE_URL inakuwa
// http://localhost/my_work -> gethostbyname('localhost') = 127.0.0.1, na
// tungeruhusu 127.0.0.1 kwenye kila router (haina maana kabisa, na IP HALISI
// isingeruhusiwa). Ndiyo maana kuna ukaguzi mkali hapa chini.
$server_host = getenv('TECH5G_PUBLIC_IP') ?: (parse_url(APP_BASE_URL, PHP_URL_HOST) ?: '');
if ($server_host === '' || $server_host === 'localhost') {
    $server_host = 'tech5g.co.tz';   // domain halisi ya production
}

$server_ip = filter_var($server_host, FILTER_VALIDATE_IP) ? $server_host : gethostbyname($server_host);

// IP LAZIMA iwe ya umma. Loopback/private ingemaanisha usanidi mbovu.
$ni_ya_umma = filter_var(
    $server_ip,
    FILTER_VALIDATE_IP,
    FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
);

if (!$ni_ya_umma) {
    fwrite(STDERR,
        "HITILAFU: IP iliyopatikana kwa '{$server_host}' ni '{$server_ip}' - siyo IP ya umma.\n" .
        "Walled-garden ingeruhusu anwani isiyo sahihi na portal isingefikika.\n" .
        "Suluhisho: weka TECH5G_PUBLIC_IP=143.246.136.110 (au APP_BASE_URL sahihi) kisha jaribu tena.\n");
    exit(1);
}

$IPS = [
    $server_ip => "Tech5G backend ({$server_host})",
];

$apply  = in_array('--apply', $argv, true);
$only   = 0;
foreach ($argv as $a) {
    if (strpos($a, '--router=') === 0) { $only = (int)substr($a, 9); }
}

echo $apply ? "MODE: APPLY (inabadilisha router)\n\n" : "MODE: DRY-RUN (hakuna kinachobadilishwa; ongeza --apply)\n\n";

// Query hii TAYARI ni per-router (siyo per-user) - inapitia kila ROW
// ya mikrotik_configs, ambayo sasa inaweza kuwa nyingi kwa user mmoja.
$sql = "SELECT router_id, user_id, mikrotik_ip, router_label FROM mikrotik_configs";
if ($only > 0) { $sql .= " WHERE router_id = " . $only; }
$sql .= " ORDER BY router_id";

$res    = $conn->query($sql);
$jumla  = ['ok' => 0, 'imeshindikana' => 0, 'imeongezwa' => 0];

while ($cfg = $res->fetch_assoc()) {
    printf("=== Router %d (user %d, '%s') · %s ===\n", $cfg['router_id'], $cfg['user_id'], $cfg['router_label'], $cfg['mikrotik_ip']);

    // MUHIMU: sasa inahitaji router_id NA user_id (toleo jipya la helper)
    $API = getMikrotikConnection($cfg['router_id'], $cfg['user_id'], $conn);
    if (!$API) {
        echo "  !! Muunganisho wa API umeshindikana - imerukwa\n\n";
        $jumla['imeshindikana']++;
        logSystemError($conn, 'walled_garden_sync.php', "Muunganisho umeshindikana kwa router_id={$cfg['router_id']}", [
            'user_id' => $cfg['user_id'], 'router_id' => $cfg['router_id'],
        ]);
        continue;
    }

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