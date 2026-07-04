<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id = (int)$_SESSION['user_id'];
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// ── VALIDATION YA HIARI: kama email imejazwa, lazima iwe sahihi ──
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Email siyo sahihi.']);
    exit();
}

if (empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Namba ya simu inahitajika.']);
    exit();
}

$stmt = $conn->prepare("UPDATE users SET email=?, phone=? WHERE id=?");
$stmt->bind_param("ssi", $email, $phone, $my_id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Taarifa zimehifadhiwa.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuhifadhi taarifa.']);
}
$stmt->close();
