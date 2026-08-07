<?php
/**
 * check_payment_status.php
 * ------------------------------------------------------------------
 * Inapigwa poll na JS ya lipia.php kila sekunde 3 mpaka malipo yaishe.
 *
 * KAZI YAKE HALISI: kuuliza gateway "je mteja amekubali USSD prompt?",
 * na malipo yakithibitika, kuita completeVoucherPayment() itakayotengeneza
 * vocha na kumuunganisha mteja.
 *
 * KWA NINI POLL WAKATI KUNA WEBHOOK? Dalipay HAIRUDII webhook ikishindwa
 * kufika mara ya kwanza (hakuna retry). Poll hii ndiyo kinga: hata kama
 * webhook haikufika kabisa, mteja aliyesimama pale bado anaunganishwa.
 * Kama zote mbili zikifika kwa pamoja, ulinzi wa claimed_at ndani ya
 * payment_helper.php unahakikisha vocha inatengenezwa MARA MOJA tu.
 *
 * Ukurasa huu ni WA UMMA (mteja hajalogin) - ulinzi wake ni kwamba
 * transaction_id ni ya nasibu (random) na haiwezi kubahatishwa.
 * ------------------------------------------------------------------
 */

session_start();
include 'login_signup.php';
require_once 'payment_helper.php';
require_once 'dalipay_client.php';

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
    "SELECT transaction_id, status, voucher_code, gateway_uuid, fail_reason, created_at
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

// ── MOCK (development bila keys): jikamilishe baada ya sekunde chache ──
if (PAYMENT_MOCK_MODE) {
    if ((time() - strtotime($txn['created_at'])) >= PAYMENT_MOCK_DELAY_SECONDS) {
        $res = completeVoucherPayment($conn, $ref);
        jibu(['status' => $res['status'], 'voucher_code' => $res['voucher_code'], 'message' => $res['message']]);
    }
    jibu(['status' => 'pending']);
}

// ── HALISI: uliza gateway hali ya collection ──
if (empty($txn['gateway_uuid'])) {
    // Ombi la kuanzisha malipo halikufanikiwa kabisa (mfano gateway ilikuwa
    // chini wakati wa lipia.php). Hakuna cha kuuliza - subiri tu, admin
    // anaweza kutumia "Kukamilisha" endapo pesa ilitoka kweli.
    jibu(['status' => 'pending']);
}

$hali = dalipayCollectionStatus($txn['gateway_uuid']);

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

// bado 'processing' - mteja hajagusa prompt kwenye simu yake
jibu(['status' => 'pending']);
