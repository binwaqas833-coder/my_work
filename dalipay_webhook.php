<?php
/**
 * dalipay_webhook.php
 * ------------------------------------------------------------------
 * Inaitwa NA GATEWAY YENYEWE (Dalipay), siyo na browser ya mtu. Pindi
 * mteja anapokubali au kukataa USSD prompt, Dalipay inatuma POST hapa.
 *
 * Weka URL hii kwenye Settings za merchant kwenye Dalipay:
 *   https://tech5g.co.tz/dalipay_webhook.php
 *
 * USALAMA: kila payload imesainiwa kwa HMAC-SHA256 ya BODY GHAFI kwa
 * kutumia callback secret. Tunathibitisha saini KABLA ya kuamini kitu
 * chochote ndani yake - bila hivyo mtu yeyote anayejua URL hii angeweza
 * kutuma "collection.success" ya uongo na kujipa vocha bure.
 *
 * MUHIMU: Dalipay HAIRUDII (no retry) endapo ombi hili litashindwa.
 * Ndiyo maana check_payment_status.php bado inapoll kama kinga.
 * ------------------------------------------------------------------
 */

include 'login_signup.php';
require_once 'dalipay_client.php';
require_once 'payment_helper.php';
require_once 'subscription_helper.php';
require_once 'error_logger.php';

header('Content-Type: application/json');

function webhookJibu(int $code, array $data) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhookJibu(405, ['received' => false, 'message' => 'POST pekee.']);
}

// ── 1. SOMA BODY GHAFI (siyo $_POST - saini ni ya bytes halisi) ──
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!dalipayVerifyWebhook($rawBody, $signature)) {
    logSystemError($conn, 'dalipay_webhook.php', 'Webhook yenye saini isiyo sahihi imekataliwa.', [
        'context' => ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ina_saini' => $signature !== ''],
    ]);
    webhookJibu(401, ['received' => false, 'message' => 'Saini si sahihi.']);
}

// ── 2. Soma tukio ──
$event = json_decode($rawBody, true);
if (!is_array($event) || empty($event['event'])) {
    webhookJibu(400, ['received' => false, 'message' => 'Payload haieleweki.']);
}

$aina        = $event['event'];
$data        = $event['data'] ?? [];
$external_id = trim($data['external_id'] ?? '');

if ($external_id === '') {
    webhookJibu(400, ['received' => false, 'message' => 'external_id haipo.']);
}

// ── 3. Peleka kwenye mtiririko sahihi ──
// external_id yetu inaanza na 'TXN-' (vocha ya mteja) au 'SUB-' (plan ya
// reseller). Prefix hii ndiyo inayotuambia jedwali la kushughulikia.
$ni_mafanikio = ($aina === 'collection.success');
$ni_kushindwa = ($aina === 'collection.failed');

if (!$ni_mafanikio && !$ni_kushindwa) {
    // Tukio tusilolijua (mfano la disbursement) - kubali kimya kimya ili
    // gateway isilione kama hitilafu.
    webhookJibu(200, ['received' => true, 'message' => 'Tukio halihitaji hatua.']);
}

if (strncmp($external_id, 'SUB-', 4) === 0) {
    if ($ni_mafanikio) {
        completeSubscriptionPayment($conn, $external_id);
    } else {
        markSubscriptionPaymentFailed($conn, $external_id, 'Malipo hayakukamilika (webhook).');
    }
    webhookJibu(200, ['received' => true]);
}

if (strncmp($external_id, 'TXN-', 4) === 0) {
    if ($ni_mafanikio) {
        $res = completeVoucherPayment($conn, $external_id);
        if ($res['status'] === 'failed') {
            logSystemError($conn, 'dalipay_webhook.php',
                'Malipo yamefanikiwa gateway lakini vocha imeshindikana: ' . $res['message'],
                ['context' => ['transaction_id' => $external_id]]);
        }
    } else {
        markTransactionFailed($conn, $external_id, 'Mteja hakukamilisha malipo (webhook).');
    }
    webhookJibu(200, ['received' => true]);
}

// external_id isiyo yetu - kubali ili gateway isiendelee kujaribu.
webhookJibu(200, ['received' => true, 'message' => 'Rejea haitambuliki hapa.']);
