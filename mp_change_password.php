<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id    = (int)$_SESSION['user_id'];
$password = $_POST['password'] ?? '';

if (strlen($password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password lazima iwe na herufi 6 au zaidi.']);
    exit();
}

// MUHIMU: password lazima ihifadhiwe ikiwa imefichwa (hashed), kamwe siyo maandishi wazi (plain text).
$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
$stmt->bind_param("si", $hashed, $my_id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Password imebadilishwa.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kubadilisha password.']);
}
$stmt->close();
