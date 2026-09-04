<?php
/**
 * check_payment_status.php
 * ------------------------------------------------------------------
 * Inapigwa poll na JS ya lipia.php kila sekunde 3 mpaka malipo yaishe.
 *
 * KAZI YAKE HALISI: kuuliza Snippe "je mteja amekubali USSD prompt?",
 * na malipo yakithibitika, kuita completeVoucherPayment() itakayotengeneza
 * vocha na kumuunganisha mteja.
 *
 * KWA NINI POLL WAKATI KUNA WEBHOOK? Webhook inaweza kupotea (mtandao,
 * seva ilikuwa busy, firewall). Poll hii ndiyo KINGA: hata kama webhook
 * haikufika kabisa, mteja aliyesimama pale bado anaunganishwa. Kama zote
 * mbili zikifika kwa pamoja, ulinzi wa claimed_at ndani ya
 * payment_helper.php unahakikisha vocha inatengenezwa MARA MOJA tu.
 *
 * (Gateway iliyotangulia - AzamPay - haikuwa na API ya kuuliza hali,
 * hivyo kinga hii haikuwepo kwa muda. Snippe wanayo: GET /v1/payments/
 * {reference}.)
 *
 * Ukurasa huu ni WA UMMA (mteja hajalogin) - ulinzi wake ni kwamba
 * transaction_id ni ya nasibu (random) na haiwezi kubahatishwa.
 * ------------------------------------------------------------------
 */

session_start();
include 'login_signup.php';
require_once 'payment_helper.php';
require_once 'snippe_client.php';

header('Content-Type: application/json');

function jibu(array $data) {
    echo json_encode($data);
    exit();
}

/**
 * Geuza jibu la completeVoucherPayment() kuwa jibu la JSON kwa ukurasa.
 *
 * 'paid_pending_voucher' ni neno la ndani (database). Kwa mteja ni
 * 'processing': pesa imepokelewa, vocha inaandaliwa. JS ya lipia.php
 * inaendelea kusubiri badala ya kuonyesha "Hitilafu" - kumwambia mtu
 * aliyelipa kuwa malipo yameshindikana ndilo kosa tunalozuia hapa.
 */
function jibuLaVocha(array $res): array {
    if ($res['status'] === 'paid_pending_voucher') {
        return [
            'status'  => 'processing',
            'message' => 'Malipo yako yamepokelewa. Tunaandaa vocha yako...',
        ];
    }
    return [
        'status'       => $res['status'],
        'voucher_code' => $res['voucher_code'],
        'message'      => $res['message'],
    ];
}

$ref = trim($_GET['ref'] ?? '');
if ($ref === '') {
    jibu(['status' => 'failed', 'message' => 'Rejea ya muamala haipo.']);
}

$stmt = $conn->prepare(
    "SELECT transaction_id, status, voucher_code, gateway_reference, fail_reason, created_at,
            next_attempt_at
     FROM payment_transactions WHERE transaction_id = ? LIMIT 1"
);
$stmt->bind_param("s", $ref);
$stmt->execute();
$txn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$txn) {
    jibu(['status' => 'failed', 'message' => 'Muamala huu haujapatikana.']);
}

// ── Tayari umeisha? Rudisha jibu la mwisho moja kwa moja ──
if ($txn['status'] === 'completed') {
    jibu(['status' => 'completed', 'voucher_code' => $txn['voucher_code']]);
}
if ($txn['status'] === 'failed') {
    jibu(['status' => 'failed', 'message' => $txn['fail_reason'] ?: 'Malipo yameshindikana.']);
}

// ── PESA IMEINGIA LAKINI VOCHA HAIJATOKA ──
// Mteja hapa AMELIPA. Hatumwambii "imeshindikana" - hilo ndilo kosa
// lililomkasirisha mteja wa tarehe 2026-09-04. Tunamwambia ukweli:
// pesa ipo, vocha inakuja.
//
// Mteja bado yupo kwenye ukurasa, hivyo huu ndio wakati wa haraka
// zaidi wa kujaribu tena - haraka kuliko cron ya kila dakika 2.
// Lakini tunaheshimu backoff ya next_attempt_at: bila hiyo, kila poll
// ya sekunde 3 ingeshikilia FPM worker kwa sekunde 9 ikisubiri router
// iliyokufa, na wateja wachache wangeitosha seva nzima.
if ($txn['status'] === 'paid_pending_voucher') {
    $muda_umefika = empty($txn['next_attempt_at']) || strtotime($txn['next_attempt_at']) <= time();

    if ($muda_umefika) {
        $res = completeVoucherPayment($conn, $ref);
        if ($res['status'] === 'completed') {
            jibu(['status' => 'completed', 'voucher_code' => $res['voucher_code']]);
        }
    }

    jibu([
        'status'  => 'processing',
        'message' => 'Malipo yako yamepokelewa. Tunaandaa vocha yako...',
    ]);
}

// ── MOCK (development bila API key): jikamilishe baada ya sekunde chache ──
if (PAYMENT_MOCK_MODE) {
    if ((time() - strtotime($txn['created_at'])) >= PAYMENT_MOCK_DELAY_SECONDS) {
        jibu(jibuLaVocha(completeVoucherPayment($conn, $ref)));
    }
    jibu(['status' => 'pending']);
}

// ── HALISI: uliza Snippe hali ya malipo ──
if (empty($txn['gateway_reference'])) {
    // Ombi la kuanzisha malipo halikufanikiwa kabisa (mfano gateway ilikuwa
    // chini wakati wa lipia.php). Hakuna cha kuuliza - subiri webhook, au
    // admin atumie "Kukamilisha" endapo pesa ilitoka kweli.
    jibu(['status' => 'pending']);
}

$hali = snippePaymentStatus($txn['gateway_reference']);

if (!$hali['ok']) {
    // Hitilafu ya mtandao kwenda gateway SIYO sawa na malipo kushindikana -
    // usimhukumu mteja. Rudisha 'pending'; poll ijayo itajaribu tena.
    jibu(['status' => 'pending']);
}

if ($hali['status'] === 'success') {
    jibu(jibuLaVocha(completeVoucherPayment($conn, $ref)));
}

if ($hali['status'] === 'failed') {
    markTransactionFailed($conn, $ref, 'Mteja hakukamilisha malipo (amekataa, salio halitoshi, au muda umeisha).');
    jibu(['status' => 'failed', 'message' => 'Malipo hayakukamilika. Hakikisha una salio kisha jaribu tena.']);
}

// bado 'pending' - mteja hajagusa prompt kwenye simu yake
jibu(['status' => 'pending']);
