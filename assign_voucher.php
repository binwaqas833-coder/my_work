<?php
/**
 * assign_voucher.php
 * ---------------------------------------------------------
 * Inaitwa na JS (vmSaveAssign()) kwenye user_dashboard.php - "Vocha
 * Mfumo" - kitufe cha "Assign". Inaunganisha vocha ILIYOTENGENEZWA
 * TAYARI kwa "batch" (haina namba ya simu bado) na mteja mahususi
 * baada ya kuiuza kwa mkono (mfano fedha taslimu, au nje ya mfumo).
 *
 * HAITENGENEZI vocha mpya, na HAIPANDISHI MikroTik tena - vocha
 * hiyo tayari ipo kwenye database na (ikiwa mikrotik_synced=1)
 * tayari kwenye router. Kazi yake ni kuandika TU 'phone' na
 * 'payment_method' kwenye rekodi iliyopo, kwa ajili ya rejea/ripoti.
 *
 * MUHIMU (multi-router): sasa inathibitisha router_id pia (siyo
 * user_id peke yake) - vocha lazima iwe ya router "active" ya sasa.
 * ---------------------------------------------------------
 */

session_start();
include 'login_signup.php';
require_once 'error_logger.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Hujalogin. Tafadhali login kwanza.']);
    exit();
}

$my_id     = (int)$_SESSION['user_id'];
$router_id = (int)($_SESSION['active_router_id'] ?? 0);
$vid       = (int)($_POST['id'] ?? 0);
$phone     = trim($_POST['phone'] ?? '');
$payment   = trim($_POST['payment'] ?? '');

if ($router_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Hujachagua router. Nenda "My Mikrotiks" kwanza.']);
    exit();
}
if ($vid <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID ya vocha si sahihi.']);
    exit();
}
if (empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Ingiza namba ya simu ya mteja.']);
    exit();
}
if (!preg_match('/^0[67]\d{8}$/', $phone)) {
    echo json_encode(['status' => 'error', 'message' => "Namba ya simu '$phone' si sahihi. Tumia muundo: 07XXXXXXXX."]);
    exit();
}

// ── THIBITISHA VOCHA HII NI YAKO, NA YA ROUTER HII HII (IDOR protection) ──
$v_stmt = $conn->prepare("SELECT id, voucher_code, status FROM vouchers WHERE id = ? AND user_id = ? AND router_id = ? LIMIT 1");
$v_stmt->bind_param("iii", $vid, $my_id, $router_id);
$v_stmt->execute();
$voucher = $v_stmt->get_result()->fetch_assoc();
$v_stmt->close();

if (!$voucher) {
    echo json_encode(['status' => 'error', 'message' => 'Vocha haikupatikana au si yako.']);
    exit();
}

if ($voucher['status'] === 'used') {
    echo json_encode(['status' => 'error', 'message' => 'Vocha hii tayari imetumika - haiwezi kuassignwa tena.']);
    exit();
}

// ── SASISHA phone + payment_method ──
$upd = $conn->prepare("UPDATE vouchers SET phone = ?, payment_method = ? WHERE id = ? AND user_id = ? AND router_id = ?");
$upd->bind_param("ssiii", $phone, $payment, $vid, $my_id, $router_id);

if ($upd->execute() && $upd->affected_rows > 0) {
    echo json_encode(['status' => 'success', 'message' => "Vocha {$voucher['voucher_code']} imeassignwa kwa {$phone}."]);
} else {
    logSystemError($conn, 'assign_voucher.php', 'UPDATE haikubadilisha rows: ' . $conn->error, [
        'user_id' => $my_id, 'router_id' => $router_id,
    ]);
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuhifadhi mabadiliko.']);
}
$upd->close();
