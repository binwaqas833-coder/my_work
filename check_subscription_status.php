<?php
/**
 * check_subscription_status.php
 * -------------------------------------------------------------
 * Inapigwa poll na JS ya start_subscription_payment.php kila
 * sekunde 3. Sawa kabisa na check_payment_status.php (vocha), lakini
 * kwa malipo ya subscription: inauliza gateway kama reseller amekubali
 * USSD prompt, kisha inaita completeSubscriptionPayment().
 * -------------------------------------------------------------
 */
session_start();
include 'login_signup.php';
require_once 'subscription_helper.php';
require_once 'dalipay_client.php';
header('Content-Type: application/json');

$ref = trim($_GET['ref'] ?? '');
if ($ref === '') {
    echo json_encode(['status' => 'failed', 'message' => 'Rejea haipo.']);
    exit();
}

$stmt = $conn->prepare("SELECT id, user_id, status, starts_at, gateway_uuid FROM subscriptions WHERE payment_transaction_id = ? LIMIT 1");
$stmt->bind_param("s", $ref);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['status' => 'failed', 'message' => 'Malipo hayakupatikana.']);
    exit();
}

if ($row['status'] === 'active') {
    $p = $conn->query("SELECT p.plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON p.id = s.plan_id WHERE s.id = " . (int)$row['id'])->fetch_assoc();
    echo json_encode(['status' => 'completed', 'plan_name' => $p['plan_name'] ?? '']);
    exit();
}
if ($row['status'] === 'expired') {
    echo json_encode(['status' => 'failed', 'message' => 'Malipo yameshindikana au muda umeisha.']);
    exit();
}

// ── bado 'pending_payment' ──

// MOCK (development bila keys): jikamilishe baada ya sekunde chache
if (PAYMENT_MOCK_MODE) {
    if ((time() - strtotime($row['starts_at'])) >= PAYMENT_MOCK_DELAY_SECONDS) {
        echo json_encode(completeSubscriptionPayment($conn, $ref));
    } else {
        echo json_encode(['status' => 'pending']);
    }
    exit();
}

// HALISI: uliza gateway
if (empty($row['gateway_uuid'])) {
    echo json_encode(['status' => 'pending']);
    exit();
}

$hali = dalipayCollectionStatus($row['gateway_uuid']);

if (!$hali['ok']) {
    // Hitilafu ya mtandao SIYO malipo kushindikana - jaribu tena poll ijayo.
    echo json_encode(['status' => 'pending']);
    exit();
}

if ($hali['status'] === 'success') {
    echo json_encode(completeSubscriptionPayment($conn, $ref));
    exit();
}

if ($hali['status'] === 'failed') {
    markSubscriptionPaymentFailed($conn, $ref, 'Malipo hayakukamilika.');
    echo json_encode(['status' => 'failed', 'message' => 'Malipo hayakukamilika. Hakikisha una salio kisha jaribu tena.']);
    exit();
}

echo json_encode(['status' => 'pending']);
