<?php
session_start();
header('Content-Type: application/json');
include 'login_signup.php';             // Inaleta $conn
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)

// Thibitisha kama user amesaini (Logged in)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Huruhusiwi kufanya hivi. Tafadhali login tena.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Ombi batili.']);
    exit;
}

$user_id    = $_SESSION['user_id'];
$bando_id   = intval($_POST['id'] ?? 0);
// Tunatumia floatval kwa sababu 'price' kwenye database ni DECIMAL(10,2)
$price_mpya = floatval($_POST['price'] ?? 0);

if ($bando_id <= 0 || $price_mpya <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Taarifa ulizotuma si sahihi.']);
    exit;
}

// 1. UPDATE bei - tunathibitisha kama bando ni la mtumiaji huyu (user_id = ?) ndani ya tariffs
$stmt = $conn->prepare("UPDATE `tariffs` SET `price` = ? WHERE `id` = ? AND `user_id` = ?");
$stmt->bind_param("dii", $price_mpya, $bando_id, $user_id);

if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Imeshindikana kusave kwenye database.']);
    $stmt->close();
    exit;
}
$stmt->close();

// 2. Sync na MikroTik (Pro Step) - haisimamishi mafanikio ya update kama itashindwa
$sync_warning = null;
try {
    // Chota profile + router ya hili bando (prepared statement, salama).
    // router_id LAZIMA itoke kwenye tariff yenyewe: kila router ina tariffs
    // zake, na getMikrotikConnection() inahitaji ($router_id, $user_id, $conn)
    // tangu mfumo uhamie multi-router.
    $p_stmt = $conn->prepare("SELECT profile_name, router_id FROM tariffs WHERE id = ? AND user_id = ?");
    $p_stmt->bind_param("ii", $bando_id, $user_id);
    $p_stmt->execute();
    $bando = $p_stmt->get_result()->fetch_assoc();
    $p_stmt->close();

    $API = $bando ? getMikrotikConnection((int)$bando['router_id'], $user_id, $conn) : null;

    if ($API) {
        if ($bando && !empty($bando['profile_name'])) {
            $API->comm("/ip/hotspot/user/profile/set", [
                ".id"     => $bando['profile_name'],
                "comment" => "Bei: " . $price_mpya . " Tsh"
            ]);
        }
        $API->disconnect();
    }
} catch (\Throwable $e) {
    // \Throwable siyo Exception: makosa kama ArgumentCountError/TypeError ni Error,
    // hayakukamatwa na `catch (Exception)` - ukurasa ulikufa kimya badala ya kutoa JSON.
    // Database imeshafanikiwa kubadilika; MikroTik sync ikishindwa, mwambie mtumiaji bila kuzuia mafanikio
    $sync_warning = 'Bei imehifadhiwa, lakini sync na router imeshindikana.';
}

if ($sync_warning) {
    echo json_encode(['status' => 'success', 'message' => $sync_warning]);
} else {
    echo json_encode(['status' => 'success']);
}