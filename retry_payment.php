<?php
/**
 * retry_payment.php
 * ------------------------------------------------------------------
 * Inaitwa na button "Kukamilisha" kwenye malipo_status.php (fetch POST).
 *
 * Matumizi: muamala umekwama - mfano mteja alilipa kweli lakini router
 * ilikuwa chini wakati huo, au webhook haikufika na mteja alifunga tab
 * kabla poll haijamaliza. Reseller/admin anathibitisha kwenye dashboard
 * ya Dalipay kuwa pesa ilitoka, kisha anabonyeza button hii.
 *
 * HAITUMII PESA MPYA - inatengeneza tu vocha ya malipo yaliyokwisha
 * kufanyika.
 *
 * USALAMA: LAZIMA mtu awe amelogin, na anaruhusiwa kugusa miamala YA
 * KWAKE pekee (admin anaruhusiwa yote). Bila kizuizi hiki reseller
 * mmoja angeweza kukamilisha muamala wa reseller mwingine.
 * ------------------------------------------------------------------
 */

session_start();
include 'auth_check.php';
include 'login_signup.php';
require_once 'payment_helper.php';

header('Content-Type: application/json');

function jibu(array $data) {
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jibu(['status' => 'failed', 'message' => 'Njia isiyoruhusiwa.']);
}

$transaction_id = trim($_POST['transaction_id'] ?? '');
if ($transaction_id === '') {
    jibu(['status' => 'failed', 'message' => 'Rejea ya muamala haipo.']);
}

$my_id    = (int)$_SESSION['user_id'];
$ni_admin = (($_SESSION['role'] ?? '') === 'admin');

// ── Thibitisha umiliki wa muamala huu ──
$stmt = $conn->prepare("SELECT user_id FROM payment_transactions WHERE transaction_id = ? LIMIT 1");
$stmt->bind_param("s", $transaction_id);
$stmt->execute();
$txn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$txn) {
    jibu(['status' => 'failed', 'message' => 'Muamala huu haujapatikana.']);
}
if (!$ni_admin && (int)$txn['user_id'] !== $my_id) {
    http_response_code(403);
    jibu(['status' => 'failed', 'message' => 'Huna ruhusa kwenye muamala huu.']);
}

// retryPaymentTransaction() inafuta claimed_at na kurudisha 'pending'
// kabla ya kujaribu - hiyo ndiyo inayoruhusu muamala uliokwama kuendelea.
// Muamala uliokwisha kamilika haubadiliki: UPDATE yake haigusi rekodi
// yenye status='completed', na jibu linabaki lile lile.
$res = retryPaymentTransaction($conn, $transaction_id);

jibu([
    'status'       => $res['status'],
    'voucher_code' => $res['voucher_code'],
    'message'      => $res['message'],
]);
