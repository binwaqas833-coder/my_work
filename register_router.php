<?php
/**
 * register_router.php
 * ---------------------------------------------------------
 * Endpoint hii INAITWA NA ROUTER YENYEWE (kupitia /tool fetch
 * ndani ya tech5g_router_setup.rsc, Hatua 16) - siyo na browser
 * ya mtumiaji wa kawaida. Inaandika taarifa za router mpya
 * kwenye mikrotik_configs moja kwa moja, bila kuhitaji mtu
 * kufungua phpMyAdmin kwa mkono.
 *
 * USALAMA: "secret" LAZIMA ilingane na SIRI_YA_USAJILI iliyowekwa
 * kwenye script ya router. Bila hii, mtu yeyote mwenye kujua URL
 * hii angeweza kuingiza routers za uongo kwenye database yako.
 * BADILISHA thamani ya REGISTER_SECRET hapa chini kabla ya matumizi,
 * na itumie THAMANI ILE ILE ndani ya <<< SIRI_YA_USAJILI >>> kwenye
 * tech5g_router_setup.rsc.
 * ---------------------------------------------------------
 */

// ── BADILISHA HII kuwa neno la siri lako mwenyewe (refu, gumu kubahatisha) ──
define('REGISTER_SECRET', 'BADILISHA_HII_KWA_SIRI_YAKO_MWENYEWE');

header('Content-Type: text/plain');

require_once 'login_signup.php'; // hii lazima itoe $conn (mysqli) kama ilivyo kwenye faili zako nyingine

// ── 1. Thibitisha ni request ya POST na siri iko sahihi ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Njia isiyoruhusiwa. Tumia POST.";
    exit();
}

$secret = $_POST['secret'] ?? '';
if (!hash_equals(REGISTER_SECRET, $secret)) {
    http_response_code(403);
    echo "ERROR: Siri (secret) si sahihi. Usajili umekataliwa.";
    exit();
}

// ── Ulinzi wa ziada: ruhusu tu requests kutoka mitandao ya ndani ──
// (routers zako ziko kwenye 10.x.x.x au 192.168.x.x, siyo internet
// ya wazi). Hii inazuia mtu kutoka nje ya nchi kujaribu kubahatisha
// secret yako kwa kutuma maelfu ya requests (brute force).
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ni_ndani = (strpos($remote_ip, '10.') === 0) ||
            (strpos($remote_ip, '192.168.') === 0) ||
            (strpos($remote_ip, '172.16.') === 0) ||
            ($remote_ip === '127.0.0.1');
if (!$ni_ndani) {
    http_response_code(403);
    echo "ERROR: Ombi hili laweza kutumwa tu kutoka kwenye mtandao wa ndani.";
    exit();
}

// ── 2. Kagua taarifa zote muhimu zipo ──
$user_id  = intval($_POST['user_id'] ?? 0);
$identity = trim($_POST['identity'] ?? '');
$ip       = trim($_POST['ip'] ?? '');
$api_user = trim($_POST['api_user'] ?? '');
$api_pass = trim($_POST['api_pass'] ?? '');
$api_port = intval($_POST['api_port'] ?? 8728);

if ($user_id <= 0 || $ip === '' || $api_user === '' || $api_pass === '') {
    http_response_code(400);
    echo "ERROR: Taarifa hazikamiliki (user_id, ip, api_user, api_pass zinahitajika).";
    exit();
}

// ── 3. Thibitisha user_id hii ipo kweli kwenye jedwali la users ──
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(404);
    echo "ERROR: user_id $user_id haipo kwenye jedwali la users.";
    exit();
}
$stmt->close();

// ── 4. Kagua kama IP hii tayari imesajiliwa (epuka nakala mbili) ──
$stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE mikrotik_ip = ? LIMIT 1");
$stmt->bind_param("s", $ip);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    // IP hii tayari ipo - SASISHA badala ya kuongeza row mpya
    $router_id = $existing['router_id'];
    $stmt = $conn->prepare("UPDATE mikrotik_configs SET api_user=?, api_pass=?, api_port=?, user_id=? WHERE router_id=?");
    $stmt->bind_param("ssiii", $api_user, $api_pass, $api_port, $user_id, $router_id);
    $stmt->execute();
    $stmt->close();
    echo "OK\nrouter_id: $router_id\naction: updated\nidentity: $identity";
    exit();
}

// ── 5. Ongeza row mpya ──
$stmt = $conn->prepare("INSERT INTO mikrotik_configs (user_id, mikrotik_ip, api_user, api_pass, api_port, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("isssi", $user_id, $ip, $api_user, $api_pass, $api_port);
$stmt->execute();
$new_router_id = $stmt->insert_id;
$stmt->close();

echo "OK\nrouter_id: $new_router_id\naction: created\nidentity: $identity\n";
echo "MUHIMU: badilisha 'var routerID = \"$new_router_id\";' ndani ya login.html na status.html kabla ya kuzipandisha!";
