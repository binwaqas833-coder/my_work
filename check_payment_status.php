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

$ref = trim($_GET['ref'] ?? '');
if ($ref === '') {
    jibu(['status' => 'failed', 'message' => 'Rejea ya muamala haipo.']);
}

$stmt = $conn->prepare(
    "SELECT transaction_id, status, voucher_code, gateway_reference, fail_reason, created_at
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

// ── MOCK (development bila API key): jikamilishe baada ya sekunde chache ──
if (PAYMENT_MOCK_MODE) {
    if ((time() - strtotime($txn['created_at'])) >= PAYMENT_MOCK_DELAY_SECONDS) {
        $res = completeVoucherPayment($conn, $ref);
        jibu(['status' => $res['status'], 'voucher_code' => $res['voucher_code'], 'message' => $res['message']]);
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
    $res = completeVoucherPayment($conn, $ref);
    jibu(['status' => $res['status'], 'voucher_code' => $res['voucher_code'], 'message' => $res['message']]);
}

if ($hali['status'] === 'failed') {
    markTransactionFailed($conn, $ref, 'Mteja hakukamilisha malipo (amekataa, salio halitoshi, au muda umeisha).');
    jibu(['status' => 'failed', 'message' => 'Malipo hayakukamilika. Hakikisha una salio kisha jaribu tena.']);
}

// bado 'pending' - mteja hajagusa prompt kwenye simu yake
jibu(['status' => 'pending']);
