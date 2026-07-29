<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id     = (int)$_SESSION['user_id'];
$router_id = (int)($_SESSION['active_router_id'] ?? 0);
$tariff_id = intval($_POST['id'] ?? 0);

if ($router_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Hujachagua router. Nenda "My Mikrotiks" kwanza.']);
    exit();
}
if ($tariff_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Kifurushi si sahihi.']);
    exit();
}

// Futa tu kama ni cha huyu user NA router hii (kuzuia mtu kufuta
// vifurushi vya router nyingine kwa kubahatisha ID)
$stmt = $conn->prepare("DELETE FROM tariffs WHERE id=? AND user_id=? AND router_id=?");
$stmt->bind_param("iii", $tariff_id, $my_id, $router_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['status' => 'success', 'message' => 'Kifurushi kimefutwa.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Kifurushi hakipatikani au huna ruhusa nacho.']);
}
$stmt->close();