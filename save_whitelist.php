<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tafadhali login tena.']);
    exit();
}

$my_id = (int)$_SESSION['user_id'];
$ips   = trim($_POST['ips'] ?? '');

// ── VALIDATION YA HIARI: kama kuna IP, hakikisha ni muundo sahihi ──
if (!empty($ips)) {
    $list = array_map('trim', explode(',', $ips));
    foreach ($list as $ip) {
        if (!empty($ip) && !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['status' => 'error', 'message' => "IP isiyo sahihi: $ip"]);
            exit();
        }
    }
}

$stmt = $conn->prepare("UPDATE mikrotik_configs SET allowed_ips=? WHERE user_id=?");
$stmt->bind_param("si", $ips, $my_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows >= 0) {
        echo json_encode(['status' => 'success', 'message' => 'Whitelist imehifadhiwa.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Hakuna router lililosajiliwa kwa akaunti yako.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kuhifadhi whitelist.']);
}
$stmt->close();
