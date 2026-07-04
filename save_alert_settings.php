<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id   = (int)$_SESSION['user_id'];
$email   = trim($_POST['email'] ?? '');
$enabled = intval($_POST['enabled'] ?? 0) ? 1 : 0;

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Email ya alert siyo sahihi.']);
    exit();
}

$stmt = $conn->prepare("UPDATE users SET alert_email=?, notify_station_offline=? WHERE id=?");
$stmt->bind_param("sii", $email, $enabled, $my_id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Mipangilio ya alert imehifadhiwa.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuhifadhi mipangilio.']);
}
$stmt->close();
