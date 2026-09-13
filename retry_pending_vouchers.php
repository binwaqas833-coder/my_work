<?php
/**
 * retry_pending_vouchers.php — CLI TU (cron, kila dakika 2)
 * ------------------------------------------------------------------
 * HII NDIYO AHADI YA MFUMO KWA MTEJA ALIYELIPA:
 * "Ukishatoa pesa, vocha yako itafika - hata kama router yako
 *  imezima kwa siku tatu."
 *
 * Inachukua kila muamala wa 'paid_pending_voucher' (pesa imethibitika
 * kwa gateway, vocha bado haijatoka) na kuujaribu tena. Deni linaisha
 * pale tu vocha inapotoka.
 *
 * ── KWA NINI IPO ──
 * Tarehe 2026-09-04, mteja alilipa TZS 1,000. Snippe walipokea pesa.
 * Router ya reseller (10.60.0.10) haikufikika kwenye tunnel ya
 * WireGuard, hivyo vocha haikutengenezwa - na mfumo ukauandika muamala
 * kama 'failed'. Hakuna kilichojaribu tena. Pesa ilipotea kimya kimya.
 *
 * Webhook ya Snippe hujaribu mara 5 kisha wanaacha; poll ya mteja
 * inaisha akifunga ukurasa. Bila cron hii, muamala uliokwama unategemea
 * mtu kuugundua kwa macho - na hakuna anayeangalia saa 3 usiku.
 *
 * ── HAILIPI PESA MPYA ──
 * Inatengeneza tu vocha ya malipo YALIYOKWISHA THIBITIKA. Muamala
 * unafika 'paid_pending_voucher' pale tu webhook/poll ilipothibitisha
 * na gateway kuwa pesa imeingia.
 *
 * ── NI SALAMA KUIENDESHA MARA NYINGI ──
 * completeVoucherPayment() ina ulinzi wa claimed_at (atomic UPDATE),
 * hivyo hata kama cron mbili zikianza kwa pamoja, vocha inatoka MARA
 * MOJA tu. Muamala uliokwisha kuwa 'completed' hauguswi kabisa.
 *
 * Matumizi (VPS):
 *   set -a; . /var/www/tech5g/private/secrets.env; set +a
 *   php /var/www/tech5g/app/retry_pending_vouchers.php
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

$APP_DIR = getenv('TECH5G_DIR') ?: __DIR__;
if (!file_exists($APP_DIR . '/login_signup.php') && file_exists('/var/www/tech5g/app/login_signup.php')) {
    // Kwenye VPS mpya code iko /var/www/tech5g/app, siyo /var/www/tech5g.
    // Ukaguzi na thamani LAZIMA vilingane - vikitofautiana, $APP_DIR
    // ingeelekezwa kwenye folder isiyo na login_signup.php na require
    // ingefeli.
    $APP_DIR = '/var/www/tech5g/app';
}
chdir($APP_DIR);
require_once $APP_DIR . '/login_signup.php';    // config.php + $conn
require_once $APP_DIR . '/payment_helper.php';
require_once $APP_DIR . '/error_logger.php';
require_once $APP_DIR . '/mailer_helper.php';

$ts = date('Y-m-d H:i:s');

// Baada ya dakika hizi bila vocha, admin na reseller wanaarifiwa.
// Dakika 20 = router imezima kweli, siyo mtikisiko wa mtandao wa sekunde.
const ONYA_BAADA_YA_DAKIKA = 20;

// ── 0. FUNGUA MIAMALA ILIYOKWAMA KWENYE "INACHAKATWA" ──
// claimed_at ni ulinzi dhidi ya vocha mbili: mchakato unaoshughulikia
// muamala unaiweka, na hakuna mwingine anayeweza kuudai. Lakini kama
// mchakato huo UKIFA katikati (PHP timeout, FPM restart, seva kuzimwa),
// alama inabaki - na muamala hauwezi kudaiwa tena na YEYOTE. Ungebaki
// umekwama milele, hata na cron hii.
//
// Njia ndefu zaidi ya halali ni sekunde ~15 (kuunganisha router sekunde 9
// + amri). Dakika 5 ni ukarimu mkubwa: alama iliyozidi hapo ni ya mchakato
// uliokufa, siyo unaoendelea.
$stale = $conn->query(
    "UPDATE payment_transactions
        SET claimed_at = NULL
      WHERE claimed_at IS NOT NULL
        AND status IN ('pending','paid_pending_voucher')
        AND claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
);
if ($stale && $conn->affected_rows > 0) {
    echo "[$ts] Miamala {$conn->affected_rows} iliyokwama kwenye 'inachakatwa' imefunguliwa.\n";
}

// ── 1. CHUKUA MADENI YALIYOFIKA MUDA WA KUJARIBU ──
// next_attempt_at ni backoff iliyowekwa na markTransactionPaidPendingVoucher().
// IS NULL inashika rekodi zilizookolewa na migration (hazina backoff bado).
//
// LIMIT 50: router iliyokufa inagharimu sekunde 9 kwa kila muamala.
// Bila kikomo, cron ya dakika 2 ingeingiliana na yenyewe. Yaliyobaki
// yanachukuliwa na mzunguko unaofuata - hakuna deni linalopotea.
$res = $conn->query(
    "SELECT transaction_id, user_id, router_id, phone, amount, delivery_attempts,
            created_at, alerted_at, fail_reason
       FROM payment_transactions
      WHERE status = 'paid_pending_voucher'
        AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
      ORDER BY created_at ASC
      LIMIT 50"
);

if (!$res) {
    echo "[$ts] Query imeshindikana: " . $conn->error . "\n";
    exit(1);
}

if ($res->num_rows === 0) {
    echo "[$ts] Hakuna deni la vocha linalosubiri.\n";
    exit(0);
}

$idadi = ['imetolewa' => 0, 'bado' => 0, 'imeonywa' => 0];

while ($txn = $res->fetch_assoc()) {
    $ref = $txn['transaction_id'];

    $out = completeVoucherPayment($conn, $ref);

    if ($out['status'] === 'completed') {
        $idadi['imetolewa']++;
        $dakika = (int)round((time() - strtotime($txn['created_at'])) / 60);
        echo "[$ts] {$ref} ({$txn['phone']}, TSh {$txn['amount']}): "
           . "vocha {$out['voucher_code']} imetolewa baada ya dakika {$dakika} "
           . "na majaribio {$txn['delivery_attempts']}.\n";

        logSystemError($conn, 'retry_pending_vouchers.php',
            "Deni la vocha limelipwa: {$ref} baada ya dakika {$dakika}.",
            ['user_id' => (int)$txn['user_id'], 'router_id' => (int)$txn['router_id'],
             'context' => ['voucher_code' => $out['voucher_code'],
                           'majaribio'    => (int)$txn['delivery_attempts'] + 1]]);
        continue;
    }

    // Bado imekwama. markTransactionPaidPendingVoucher() ndani ya
    // completeVoucherPayment() tayari imeongeza backoff.
    $idadi['bado']++;
    $dakika_tangu = (int)round((time() - strtotime($txn['created_at'])) / 60);
    echo "[$ts] {$ref} ({$txn['phone']}, TSh {$txn['amount']}): bado imekwama "
       . "baada ya dakika {$dakika_tangu} - {$out['message']}\n";

    // ── ONYA MARA MOJA TU (siyo kila mzunguko wa dakika 2) ──
    if ($dakika_tangu >= ONYA_BAADA_YA_DAKIKA && empty($txn['alerted_at'])) {
        if (onyaDeniLaVocha($conn, $txn, $out['message'], $dakika_tangu)) {
            $idadi['imeonywa']++;
        }
        $a = $conn->prepare("UPDATE payment_transactions SET alerted_at = NOW() WHERE transaction_id = ?");
        $a->bind_param("s", $ref);
        $a->execute();
        $a->close();
    }
}

echo "[$ts] Jumla: zimetolewa={$idadi['imetolewa']} bado={$idadi['bado']} onyo={$idadi['imeonywa']}\n";

/**
 * Arifu reseller (na andika kwenye error_logs kwa admin) kuwa mteja
 * wake amelipa lakini bado hajapata vocha.
 *
 * Email inaweza kushindwa (seva hii ina tatizo la port 25 kwenda nje) -
 * ndiyo maana logSystemError() inakuja KWANZA na bila masharti: admin
 * ataliona kwenye admin_error_logs.php hata barua isipotoka kabisa.
 */
function onyaDeniLaVocha($conn, array $txn, string $sababu, int $dakika): bool
{
    $ref = $txn['transaction_id'];

    logSystemError($conn, 'retry_pending_vouchers.php',
        "MTEJA AMELIPA HAJAPATA VOCHA: {$txn['phone']} TSh {$txn['amount']} "
      . "(dakika {$dakika}) - {$sababu}",
        ['user_id' => (int)$txn['user_id'], 'router_id' => (int)$txn['router_id'],
         'context' => ['transaction_id' => $ref, 'majaribio' => (int)$txn['delivery_attempts']]]);

    // Email ya reseller mwenye router hii.
    //
    // alert_email ni ya HIARI na kwa kweli HAKUNA mtumiaji hata mmoja
    // aliyeijaza (ilikuwa NULL kwa wote wanne tarehe 2026-09-04). Bila
    // fallback hii, kila alert ingekufa kimya kimya - onyo lisilofika
    // ni sawa na kutokuwepo kabisa. users.email daima ipo (ndiyo ya
    // kuingilia), hivyo ndiyo anwani ya mwisho ya kutegemea.
    $q = $conn->prepare("SELECT username, COALESCE(NULLIF(alert_email,''), email) AS anwani FROM users WHERE id = ? LIMIT 1");
    $q->bind_param("i", $txn['user_id']);
    $q->execute();
    $mtumiaji = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$mtumiaji || empty($mtumiaji['anwani'])) {
        return false;
    }

    $jibu = tumaEmailAlert(
        $mtumiaji['anwani'],
        "⚠️ Mteja amelipa hajapata vocha: {$txn['phone']}",
        "<p>Habari {$mtumiaji['username']},</p>
         <p>Mteja <strong>{$txn['phone']}</strong> alilipa <strong>TSh "
         . number_format((float)$txn['amount']) . "</strong> dakika <strong>{$dakika}</strong>
         zilizopita, lakini vocha yake bado haijatolewa.</p>
         <p>Sababu: <strong>" . htmlspecialchars($sababu) . "</strong></p>
         <p>Namba ya rejea: <strong>{$ref}</strong></p>
         <p>Mfumo unaendelea kujaribu kila baada ya muda kiotomatiki - vocha
         itatoka yenyewe mara router itakaporudi mtandaoni. <strong>Angalia
         umeme na intaneti kwenye eneo la router yako.</strong></p>
         <p style='color:#888;font-size:12px;'>Taarifa hii imetumwa moja kwa moja na mfumo wako wa Tech 5G Wi-Fi.</p>"
    );

    return $jibu['status'] === 'success';
}
