<?php
/**
 * delete_voucher.php
 * Inafutwa na JS (fetch) kutoka user_dashboard.php — kwenye "Vocha Mfumo"
 * na "Vocha Zilizolipiwa Karibuni" (futaVocha / vmFutaMoja / delete nyingi).
 *
 * Inafuta vocha MOJA TU, na kuhakikisha vocha hiyo ni mali ya
 * mtumiaji aliyelogin (user_id), ili mtu asiweze kufuta vocha za mwingine.
 */

session_start();
include 'login_signup.php';   // Inaleta $conn

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Hujalogin. Tafadhali login kwanza.']);
    exit();
}

$my_id = $_SESSION['user_id'];
$vid   = (int)($_POST['id'] ?? 0);

if ($vid <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID ya vocha si sahihi.']);
    exit();
}

$d = $conn->prepare("DELETE FROM vouchers WHERE id = ? AND user_id = ?");
$d->bind_param("ii", $vid, $my_id);
$d->execute();

if ($d->affected_rows > 0) {
    echo json_encode(['status' => 'success', 'message' => 'Vocha imefutwa.']);
} else {
    // Hakuna mstari uliofutwa: au ID haipo, au vocha hiyo si ya user huyu
    echo json_encode(['status' => 'error', 'message' => 'Vocha haikupatikana au si yako.']);
}

$d->close();
