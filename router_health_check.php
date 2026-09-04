<?php
/**
 * router_health_check.php — CLI TU (cron, kila dakika 5)
 * ------------------------------------------------------------------
 * Inapima kila router kwenye mikrotik_configs: je API yake inafikika
 * kupitia tunnel ya WireGuard SASA HIVI? Inaandika hali kwenye
 * router_health, na inatuma email pale hali INAPOBADILIKA (online ->
 * offline au offline -> online) - siyo kila mzunguko.
 *
 * ── KWA NINI IPO ──
 * Tulipofuatilia malipo yaliyopotea tarehe 2026-09-04 tuligundua kuwa
 * routers 1, 2 na 3 zilikuwa HAZIFIKIKI TANGU 2026-08-16 - wiki TATU.
 * Hakuna aliyejua. Mfumo uligundua tu pale mteja alipolipa na vocha
 * ikashindikana; yaani ilikuwa MTEJA ndiye alarm ya mfumo.
 *
 * Hii inageuza ukimya huo kuwa taarifa: reseller anajua router yake
 * imezima ndani ya dakika 10, siyo baada ya mteja kupoteza pesa.
 *
 * ── TOFAUTI NA check_stations.php ──
 * check_stations.php inapiga PING kwa access_points (AP za WiFi za
 * reseller). Hii inapima API ya MikroTik - kitu TOFAUTI kabisa, na
 * ndicho hasa kinachohitajika ili vocha itoke. Router inaweza kujibu
 * ping na bado API yake ikawa imezimwa au credentials zikawa zimeharibika.
 *
 * ── HAIGUSI ROUTER ──
 * Inafungua muunganisho, inathibitisha login, inakata. Hakuna amri
 * yoyote inayotumwa. Ni salama kabisa kuiendesha mara nyingi.
 *
 * Matumizi (VPS):
 *   set -a; . /root/.tech5g-credentials; set +a
 *   /usr/local/apps/php82/bin/php /var/www/tech5g/router_health_check.php
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
require_once $APP_DIR . '/login_signup.php';      // config.php + $conn
require_once $APP_DIR . '/routeros_api.class.php';
require_once $APP_DIR . '/error_logger.php';
require_once $APP_DIR . '/mailer_helper.php';

$ts = date('Y-m-d H:i:s');

// Majaribio mangapi mfululizo kabla ya kutangaza "imezima".
// 2 x dakika 5 = dakika 10. Inazuia alert za uongo kwa mtikisiko
// mfupi wa mtandao (router za 4G/LTE hukatika kwa sekunde mara kwa mara).
const MAJARIBIO_KABLA_YA_ONYO = 2;

// Sekunde za kusubiri TCP kufunguka. Router hai kwenye tunnel hujibu
// ndani ya milisekunde 200; sekunde 4 ni ukarimu wa kutosha.
const MUDA_WA_KUPIMA = 4;

$res = $conn->query(
    "SELECT mc.router_id, mc.user_id, mc.router_label, mc.mikrotik_ip, mc.api_port,
            mc.api_user, mc.api_pass,
            u.username,
            -- alert_email ni ya hiari na haikujazwa na mtu yeyote (NULL kwa
            -- wote tarehe 2026-09-04). users.email daima ipo, hivyo ndiyo
            -- ya kutegemea - alert isiyofika ni sawa na kutokuwepo.
            COALESCE(NULLIF(u.alert_email,''), u.email) AS alert_email,
            u.notify_station_offline,
            rh.status AS hali_ya_zamani, rh.consecutive_failures, rh.last_ok_at
       FROM mikrotik_configs mc
       JOIN users u          ON u.id = mc.user_id
       LEFT JOIN router_health rh ON rh.router_id = mc.router_id
      ORDER BY mc.router_id ASC"
);

if (!$res) {
    echo "[$ts] Query imeshindikana: " . $conn->error . "\n";
    exit(1);
}

$idadi = ['online' => 0, 'offline' => 0, 'zimezima' => 0, 'zimerudi' => 0];

while ($r = $res->fetch_assoc()) {
    $router_id = (int)$r['router_id'];
    $ip        = $r['mikrotik_ip'];
    $port      = (int)($r['api_port'] ?: 8728);

    [$ni_hai, $kosa] = pimaRouter($ip, $port, $r['api_user'], mt_decrypt($r['api_pass']));

    $hali_ya_zamani = $r['hali_ya_zamani'] ?? 'unknown';
    $mfululizo      = (int)($r['consecutive_failures'] ?? 0);

    if ($ni_hai) {
        $idadi['online']++;
        $hali_mpya = 'online';
        $mfululizo = 0;
    } else {
        $idadi['offline']++;
        $mfululizo++;
        // Bado tunaita 'online' mpaka majaribio yafikie kikomo - hivyo
        // mtikisiko wa dakika 5 hauzalishi email. Router isiyowahi kupimwa
        // ('unknown') inatangazwa 'offline' pale pale kikomo kinapofika.
        $hali_mpya = ($mfululizo >= MAJARIBIO_KABLA_YA_ONYO) ? 'offline' : $hali_ya_zamani;
        if ($hali_mpya === 'unknown') {
            $hali_mpya = 'offline';
        }
    }

    // ── Andika hali (INSERT ... ON DUPLICATE: router mpya inajiongeza yenyewe) ──
    $kosa_fupi = mb_substr((string)$kosa, 0, 255);
    $u = $conn->prepare(
        "INSERT INTO router_health
             (router_id, status, consecutive_failures, last_check_at, last_ok_at, last_error)
         VALUES (?, ?, ?, NOW(), " . ($ni_hai ? "NOW()" : "NULL") . ", ?)
         ON DUPLICATE KEY UPDATE
             status               = VALUES(status),
             consecutive_failures = VALUES(consecutive_failures),
             last_check_at        = NOW(),
             last_ok_at           = " . ($ni_hai ? "NOW()" : "last_ok_at") . ",
             last_error           = VALUES(last_error)"
    );
    $u->bind_param("isis", $router_id, $hali_mpya, $mfululizo, $kosa_fupi);
    $u->execute();
    $u->close();

    // ── Hali haijabadilika? Nyamaza. ──
    if ($hali_mpya === $hali_ya_zamani) {
        continue;
    }

    if ($hali_mpya === 'offline') {
        $idadi['zimezima']++;
        echo "[$ts] Router #{$router_id} ({$r['router_label']}, {$ip}) IMEZIMA - {$kosa}\n";

        logSystemError($conn, 'router_health_check.php',
            "Router '{$r['router_label']}' ({$ip}) haifikiki - vocha mpya HAZITATOKA hapa.",
            ['user_id' => (int)$r['user_id'], 'router_id' => $router_id,
             'context' => ['kosa' => $kosa, 'mfululizo' => $mfululizo]]);

        arifuReseller($r, true, $kosa);

    } elseif ($hali_mpya === 'online' && $hali_ya_zamani === 'offline') {
        $idadi['zimerudi']++;
        echo "[$ts] Router #{$router_id} ({$r['router_label']}, {$ip}) IMERUDI ONLINE\n";

        logSystemError($conn, 'router_health_check.php',
            "Router '{$r['router_label']}' ({$ip}) imerudi mtandaoni.",
            ['user_id' => (int)$r['user_id'], 'router_id' => $router_id]);

        arifuReseller($r, false, '');

        // Router imerudi - madeni ya vocha ya router hii yajaribiwe MARA
        // MOJA badala ya kusubiri backoff (inayoweza kuwa dakika 30).
        // Mteja aliyelipa asubiri sekunde chache tu baada ya router kurudi.
        $f = $conn->prepare(
            "UPDATE payment_transactions SET next_attempt_at = NOW()
              WHERE router_id = ? AND status = 'paid_pending_voucher'"
        );
        $f->bind_param("i", $router_id);
        $f->execute();
        if ($f->affected_rows > 0) {
            echo "[$ts]   -> madeni {$f->affected_rows} ya vocha yamepangwa kujaribiwa mara moja.\n";
        }
        $f->close();
    }
}

echo "[$ts] Jumla: online={$idadi['online']} offline={$idadi['offline']} "
   . "zimezima={$idadi['zimezima']} zimerudi={$idadi['zimerudi']}\n";

/**
 * Pima router MOJA. Inarudisha [bool $ni_hai, string $kosa].
 *
 * Hatua MBILI kwa makusudi, kwa sababu zinatofautisha matatizo mawili
 * tofauti kabisa yanayohitaji hatua tofauti:
 *   1. TCP haifunguki  -> tunnel/umeme/mtandao (au /ip service api imezimwa)
 *   2. TCP inafunguka lakini login inakataliwa -> api_user/api_pass
 *
 * Bila hatua ya pili, router yenye password iliyobadilishwa ingeonekana
 * "online" wakati hakuna vocha inayoweza kutoka.
 */
function pimaRouter(string $ip, int $port, string $user, string $pass): array
{
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($ip, $port, $errno, $errstr, MUDA_WA_KUPIMA);
    if (!$sock) {
        return [false, "TCP {$ip}:{$port} haifunguki (" . ($errstr ?: "kosa {$errno}") . ")"];
    }
    fclose($sock);

    $API = new RouterosAPI();
    $API->debug    = false;
    $API->port     = $port;
    $API->attempts = 1;   // TCP tayari imethibitika; hakuna sababu ya kurudia
    $API->timeout  = MUDA_WA_KUPIMA;
    $API->delay    = 0;

    if (!$API->connect($ip, $user, $pass)) {
        return [false, "API login imekataliwa (angalia api_user/api_pass)"];
    }
    $API->disconnect();

    return [true, ''];
}

/**
 * Email kwa reseller. Inaheshimu notify_station_offline (mpangilio ule
 * ule anaotumia kwa AP zake) - mtu asiyetaka email asipate za hapa pia.
 */
function arifuReseller(array $r, bool $imezima, string $kosa): void
{
    if (empty($r['alert_email']) || !(int)($r['notify_station_offline'] ?? 1)) {
        return;
    }

    if ($imezima) {
        $mada = "🔴 Router imezima: {$r['router_label']}";
        $ujumbe = "
            <p>Habari {$r['username']},</p>
            <p>Router yako <strong>{$r['router_label']}</strong> ({$r['mikrotik_ip']})
               haifikiki na mfumo wa Tech 5G.</p>
            <p><strong style='color:#ff3d57;'>Wateja wanaolipa kwenye router hii
               hawatapata vocha mpaka irudi mtandaoni.</strong> Pesa zao
               HAZITAPOTEA - mfumo utatoa vocha zao kiotomatiki router
               itakaporudi.</p>
            <p>Sababu ya kiufundi: " . htmlspecialchars($kosa) . "</p>
            <p>Tafadhali angalia: <strong>umeme</strong>, <strong>intaneti ya
               router</strong>, na kwamba <strong>WireGuard (wg-tech5g)</strong>
               bado inafanya kazi.</p>
            <p style='color:#888;font-size:12px;'>Taarifa hii imetumwa moja kwa moja na mfumo wako wa Tech 5G Wi-Fi.</p>
        ";
    } else {
        $mada = "🟢 Router imerudi online: {$r['router_label']}";
        $ujumbe = "
            <p>Habari {$r['username']},</p>
            <p>Router yako <strong>{$r['router_label']}</strong> ({$r['mikrotik_ip']})
               <strong style='color:#07f793;'>imerudi mtandaoni</strong>.</p>
            <p>Vocha zote za wateja waliolipa wakati router ilikuwa imezima
               zinatolewa sasa hivi kiotomatiki.</p>
            <p style='color:#888;font-size:12px;'>Taarifa hii imetumwa moja kwa moja na mfumo wako wa Tech 5G Wi-Fi.</p>
        ";
    }

    tumaEmailAlert($r['alert_email'], $mada, $ujumbe);
}
