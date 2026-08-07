<?php
/**
 * poll_payouts.php — CLI TU (cron)
 * ------------------------------------------------------------------
 * Inauliza Dalipay hali ya kila ombi la cash-out lililo 'awaiting_approval'
 * na kulikamilisha: 'success' (pesa imefika) au 'failed'/'rejected'
 * (salio la reseller linarudishwa kiotomatiki).
 *
 * KWA NINI CRON NA SIYO WEBHOOK? Dalipay hutuma webhook kwa COLLECTIONS
 * pekee (collection.success / collection.failed). Hakuna tukio la
 * disbursement, hivyo njia PEKEE ya kujua kama malipo yamefika ni kuuliza.
 *
 * Matumizi (VPS):
 *   set -a; . /root/.tech5g-credentials; set +a
 *   /usr/local/apps/php82/bin/php /var/www/tech5g/poll_payouts.php
 *
 * Cron (kila dakika 5):
 *   *(slash)5 * * * * root set -a; . /root/.tech5g-credentials; set +a; \
 *     /usr/local/apps/php82/bin/php /var/www/tech5g/poll_payouts.php >> /var/log/tech5g-payouts.log 2>&1
 *
 * Ni salama kuiendesha mara nyingi: pollPayoutStatus() hubadilisha rekodi
 * pale tu hali imebadilika, na refundPayout() haigusi ombi lililokwisha fika mwisho.
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
require_once $APP_DIR . '/login_signup.php';    // config.php + $conn
require_once $APP_DIR . '/payout_helper.php';

$ts = date('Y-m-d H:i:s');

if (!DALIPAY_ENABLED) {
    echo "[$ts] Gateway haijasanidiwa (DALIPAY_* hazipo). Hakuna kilichofanyika.\n";
    exit(0);
}

$res = $conn->query(
    "SELECT id, user_id, amount, gateway_reference, external_id
       FROM payout_requests
      WHERE status = 'awaiting_approval'
      ORDER BY id ASC
      LIMIT 200"
);

if (!$res || $res->num_rows === 0) {
    echo "[$ts] Hakuna ombi linalosubiri.\n";
    exit(0);
}

$idadi = ['success' => 0, 'failed' => 0, 'rejected' => 0, 'bado' => 0, 'bila_rejea' => 0];

while ($po = $res->fetch_assoc()) {
    if (empty($po['gateway_reference'])) {
        // Halikuwahi kufika gateway (mtandao ulikatika wakati wa kutuma).
        // HALIGUSWI kiotomatiki - linahitaji ukaguzi wa mkono kwenye Dalipay.
        $idadi['bila_rejea']++;
        echo "[$ts] Ombi #{$po['id']} ({$po['external_id']}) halina gateway_reference - KAGUA KWA MKONO.\n";
        continue;
    }

    $hali = pollPayoutStatus($conn, $po);

    if ($hali === 'success') {
        $idadi['success']++;
        echo "[$ts] Ombi #{$po['id']} imekamilika (TSh {$po['amount']}).\n";

        // Mjulishe reseller kuwa sasa pesa IMEFIKA kweli
        $u = $conn->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
        $u->bind_param("i", $po['user_id']);
        $u->execute();
        $mtu = $u->get_result()->fetch_assoc();
        $u->close();

        if ($mtu && !empty($mtu['email'])) {
            require_once $APP_DIR . '/email_helper.php';
            sendStatusEmail(
                $mtu['email'],
                $mtu['username'],
                'Pesa Yako Imetumwa! 💸',
                'Habari <strong>' . htmlspecialchars($mtu['username'], ENT_QUOTES) . '</strong>,<br><br>'
                . 'Cash Out yako ya <strong>TSh ' . number_format((float)$po['amount'], 2) . '</strong> '
                . 'imetumwa kikamilifu kwenye namba yako ya simu.<br><br>'
                . 'Asante kwa kuendelea kufanya kazi na Tech 5G!'
            );
        }
    } elseif ($hali === 'failed' || $hali === 'rejected') {
        $idadi[$hali]++;
        echo "[$ts] Ombi #{$po['id']} $hali - salio la reseller limerudishwa.\n";
    } else {
        $idadi['bado']++;
    }
}

echo "[$ts] Jumla: success={$idadi['success']} failed={$idadi['failed']} "
   . "rejected={$idadi['rejected']} bado={$idadi['bado']} bila_rejea={$idadi['bila_rejea']}\n";
