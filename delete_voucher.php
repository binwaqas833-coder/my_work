<?php
/**
 * delete_voucher.php
 * Inafutwa na JS (fetch) kutoka user_dashboard.php — kwenye "Vocha Mfumo"
 * na "Vocha Zilizolipiwa Karibuni" (futaVocha / vmFutaMoja / delete nyingi).
 *
 * Inafuta vocha MOJA TU, na kuhakikisha vocha hiyo ni mali ya
 * mtumiaji aliyelogin (user_id), ili mtu asiweze kufuta vocha za mwingine.
 *
 * MUHIMU: kama vocha ilishapandwa kwenye MikroTik (mikrotik_synced=1),
 * lazima tuiondoe huko pia - la sivyo inabaki "hai" kwenye router hata
 * baada ya kufutwa kwenye dashboard, na mtu bado angeweza kuitumia.
 */

session_start();
include 'login_signup.php';         // Inaleta $conn
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php'; // getMikrotikConnection(), removeHotspotUserFromMikrotik()

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

// ── 1. THIBITISHA VOCHA HII NI YAKO KABLA ya kufanya lolote ──
$v_stmt = $conn->prepare("SELECT voucher_code, mikrotik_synced FROM vouchers WHERE id = ? AND user_id = ? LIMIT 1");
$v_stmt->bind_param("ii", $vid, $my_id);
$v_stmt->execute();
$voucher = $v_stmt->get_result()->fetch_assoc();
$v_stmt->close();

if (!$voucher) {
    echo json_encode(['status' => 'error', 'message' => 'Vocha haikupatikana au si yako.']);
    exit();
}

// ── 2. ONDOA KWENYE MIKROTIK KWANZA (kama ilishapandwa huko) ──
// Hatuzuii kufuta kwenye database hata kama router haipatikani (mfano iko
// offline) - admin bado anapaswa kuweza kusafisha dashboard yake. Lakini
// tunajaribu kwa uaminifu kabla, ili kuepuka "vocha halali" kubaki hai
// kwenye router baada ya kufutwa kwenye mfumo.
$mikrotik_removed = null; // null = haikuhitajika, true/false = ilijaribiwa
if (!empty($voucher['mikrotik_synced'])) {
    $API = getMikrotikConnection($my_id, $conn);
    if ($API) {
        $result = removeHotspotUserFromMikrotik($API, $voucher['voucher_code']);
        $mikrotik_removed = ($result !== false);
        $API->disconnect();
    } else {
        $mikrotik_removed = false;
    }
}

// ── 3. FUTA KWENYE DATABASE ──
$d = $conn->prepare("DELETE FROM vouchers WHERE id = ? AND user_id = ?");
$d->bind_param("ii", $vid, $my_id);
$d->execute();
$d->close();

if ($d->affected_rows > 0) {
    if ($mikrotik_removed === false) {
        echo json_encode(['status' => 'success', 'message' => 'Vocha imefutwa kwenye database, lakini imeshindikana kuiondoa kwenye MikroTik (router haipatikani). Iondoe huko wewe mwenyewe.']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Vocha imefutwa.']);
    }
} else {
    // Hakuna mstari uliofutwa: au ID haipo, au vocha hiyo si ya user huyu
    echo json_encode(['status' => 'error', 'message' => 'Vocha haikupatikana au si yako.']);
}
