<?php
/**
 * check_subscription_status.php
 * -------------------------------------------------------------
 * Inapigwa poll na JS ya start_subscription_payment.php kila
 * sekunde 3. Sawa na check_payment_status.php (vocha), lakini kwa
 * malipo ya subscription. MOCK: baada ya MOCK_DELAY_SECONDS_SUB
 * kupita tangu ombi lilipoanzishwa, tunajikamilishia wenyewe kwa
 * kuita completeSubscriptionPayment().
 * -------------------------------------------------------------
 */
session_start();
include 'login_signup.php';
require_once 'subscription_helper.php';
header('Content-Type: application/json');

define('MOCK_DELAY_SECONDS_SUB', 6);

$ref = trim($_GET['ref'] ?? '');
if ($ref === '') {
    echo json_encode(['status' => 'failed', 'message' => 'Rejea haipo.']);
    exit();
}

$stmt = $conn->prepare("SELECT id, user_id, status, starts_at FROM subscriptions WHERE payment_transaction_id = ? LIMIT 1");
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

// bado 'pending_payment' - angalia kama muda wa MOCK umepita
$sekunde_zimepita = time() - strtotime($row['starts_at']);
if ($sekunde_zimepita >= MOCK_DELAY_SECONDS_SUB) {
    $result = completeSubscriptionPayment($conn, $ref);
    echo json_encode($result);
} else {
    echo json_encode(['status' => 'pending']);
}
