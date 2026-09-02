<?php
/**
 * snippe_webhook.php
 * ------------------------------------------------------------------
 * Inaitwa NA SNIPPE YENYEWE, siyo na browser ya mtu.
 *
 * URL hii inatumwa kwa Snippe kwenye KILA ombi (webhook_url), hivyo
 * hakuna cha kusanidi kwenye dashboard yao. Inashughulikia matukio YOTE:
 *
 *   payment.completed / payment.failed / payment.voided / payment.expired
 *   payout.completed  / payout.failed  / payout.reversed
 *
 * USALAMA: kila payload imesainiwa kwa HMAC-SHA256 juu ya
 * "{timestamp}.{body ghafi}". Tunathibitisha saini KABLA ya kuamini
 * kitu chochote — bila hivyo yeyote anayejua URL hii angeweza kutuma
 * "payment.completed" ya uongo na kujipa vocha bure.
 *
 * KURUDIWA (duplicates): Snippe wanajaribu tena mara 5 kwa exponential
 * backoff, hivyo tukio LILELILE linaweza kufika mara kadhaa. Ulinzi:
 *   - vocha: claimed_at ndani ya payment_helper.php (atomic)
 *   - cash-out: ukaguzi wa hali kabla ya kubadilisha
 * Hivyo hakuna haja ya jedwali la ku-dedupe kwa event id.
 *
 * TUNAJIBU HARAKA: Snippe wanataka 2xx ndani ya sekunde 30, na kila
 * jibu lisilo 2xx linaanzisha mzunguko wa kujaribu tena.
 * ------------------------------------------------------------------
 */

include 'login_signup.php';
require_once 'snippe_client.php';
require_once 'payment_helper.php';
require_once 'subscription_helper.php';
require_once 'payout_helper.php';
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

// ── 1. SOMA BODY GHAFI (siyo $_POST — saini ni ya bytes halisi) ──
$rawBody   = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

if (!snippeVerifyWebhook($rawBody, $timestamp, $signature)) {
    logSystemError($conn, 'snippe_webhook.php', 'Webhook yenye saini isiyo sahihi imekataliwa.', [
        'context' => [
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
            'ina_saini' => $signature !== '',
            'tukio'     => mb_substr((string)($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? ''), 0, 40),
        ],
    ]);
    webhookJibu(401, ['received' => false, 'message' => 'Saini si sahihi.']);
}

// ── 2. Soma tukio ──
$event = json_decode($rawBody, true);
if (!is_array($event)) {
    webhookJibu(400, ['received' => false, 'message' => 'Payload haieleweki.']);
}

// Muundo mpya (2026-01-25) hutumia 'type'; wa zamani (2026-01-01) 'event'.
// Tunakubali wote wawili ili toleo la API likibadilika tusipoteze malipo.
$aina = (string)($event['type'] ?? $event['event'] ?? '');
$data = is_array($event['data'] ?? null) ? $event['data'] : $event;

if ($aina === '') {
    webhookJibu(400, ['received' => false, 'message' => 'Aina ya tukio haipo.']);
}

$snippe_ref = trim((string)($data['reference'] ?? ''));
$metadata   = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
$hali       = snippeNormalizeStatus((string)($data['status'] ?? ''));

// Hali ambayo bado haijafika mwisho — kubali kimya kimya.
if ($hali === 'pending') {
    webhookJibu(200, ['received' => true, 'message' => 'Hali bado haijakamilika.']);
}

$ni_mafanikio = ($hali === 'success');

// ══════════════════════════════════════════════════════════════════
//  MALIPO YANAYOINGIA (vocha / subscription)
// ══════════════════════════════════════════════════════════════════
if (strncmp($aina, 'payment.', 8) === 0) {

    // Rejea YETU: kwanza kutoka metadata (inafanya kazi hata kama jibu
    // la kuanzisha malipo lilipotea njiani na hatukuhifadhi reference ya
    // Snippe), kisha kwa reference ya Snippe tuliyoihifadhi.
    $external_id = trim((string)($metadata['transaction_id'] ?? ''));

    if ($external_id === '' && $snippe_ref !== '') {
        $q = $conn->prepare("SELECT transaction_id FROM payment_transactions WHERE gateway_reference = ? LIMIT 1");
        $q->bind_param("s", $snippe_ref);
        $q->execute();
        if ($row = $q->get_result()->fetch_assoc()) $external_id = $row['transaction_id'];
        $q->close();

        if ($external_id === '') {
            $q2 = $conn->prepare("SELECT payment_transaction_id FROM subscriptions WHERE gateway_reference = ? LIMIT 1");
            $q2->bind_param("s", $snippe_ref);
            $q2->execute();
            if ($row = $q2->get_result()->fetch_assoc()) $external_id = $row['payment_transaction_id'];
            $q2->close();
        }
    }

    if ($external_id === '') {
        logSystemError($conn, 'snippe_webhook.php',
            "Webhook ya malipo haikutambulika (hakuna metadata wala reference inayolingana).",
            ['context' => ['tukio' => $aina, 'snippe_ref' => $snippe_ref]]);
        webhookJibu(200, ['received' => true, 'message' => 'Rejea haitambuliki hapa.']);
    }

    // Hifadhi reference ya Snippe (ya kunukuu kwa support yao)
    if ($snippe_ref !== '') {
        if (strncmp($external_id, 'SUB-', 4) === 0) {
            $g = $conn->prepare("UPDATE subscriptions SET gateway_reference=? WHERE payment_transaction_id=?");
        } else {
            $g = $conn->prepare("UPDATE payment_transactions SET gateway_reference=? WHERE transaction_id=?");
        }
        $g->bind_param("ss", $snippe_ref, $external_id);
        $g->execute();
        $g->close();
    }

    if (strncmp($external_id, 'SUB-', 4) === 0) {
        if ($ni_mafanikio) {
            completeSubscriptionPayment($conn, $external_id);
        } else {
            markSubscriptionPaymentFailed($conn, $external_id,
                mb_substr('Malipo hayakukamilika: ' . ($data['failure_reason'] ?? $aina), 0, 255));
        }
        webhookJibu(200, ['received' => true]);
    }

    if ($ni_mafanikio) {
        $res = completeVoucherPayment($conn, $external_id);
        if ($res['status'] === 'failed') {
            logSystemError($conn, 'snippe_webhook.php',
                'Malipo yamefanikiwa Snippe lakini vocha imeshindikana: ' . $res['message'],
                ['context' => ['transaction_id' => $external_id, 'snippe_ref' => $snippe_ref]]);
        }
    } else {
        markTransactionFailed($conn, $external_id,
            mb_substr('Malipo hayakukamilika: ' . ($data['failure_reason'] ?? $aina), 0, 255));
    }
    webhookJibu(200, ['received' => true]);
}

// ══════════════════════════════════════════════════════════════════
//  KUTOA PESA (cash-out)
// ══════════════════════════════════════════════════════════════════
if (strncmp($aina, 'payout.', 7) === 0) {

    $external_id = trim((string)($metadata['payout_id'] ?? ''));

    $q = $conn->prepare(
        "SELECT id, user_id, amount, status FROM payout_requests
          WHERE " . ($external_id !== '' ? "external_id = ?" : "gateway_reference = ?") . "
          LIMIT 1"
    );
    $kitambulisho = $external_id !== '' ? $external_id : $snippe_ref;
    $q->bind_param("s", $kitambulisho);
    $q->execute();
    $po = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$po) {
        webhookJibu(200, ['received' => true, 'message' => 'Ombi halijapatikana.']);
    }

    // Tayari limefika mwisho — usiliguse tena (webhook zinaweza kurudiwa).
    if (in_array($po['status'], ['success', 'failed', 'rejected'], true)) {
        webhookJibu(200, ['received' => true, 'message' => 'Ombi tayari limefika mwisho.']);
    }

    if ($ni_mafanikio) {
        $u = $conn->prepare("UPDATE payout_requests SET status='success', updated_at=NOW()
                              WHERE id=? AND status='awaiting_approval'");
        $u->bind_param("i", $po['id']);
        $u->execute();
        $imebadilika = ($u->affected_rows === 1);
        $u->close();

        if ($imebadilika) {
            payoutNotifyResellerSuccess($conn, (int)$po['user_id'], (float)$po['amount']);
        }
    } else {
        // Snippe hurudisha pesa kwenye salio LETU la merchant kiotomatiki;
        // hapa tunarudisha kwenye salio la reseller ndani ya mfumo wetu.
        $sababu = $aina === 'payout.reversed'
            ? 'Malipo yamerudishwa (reversed) na Snippe.'
            : ('Malipo yameshindikana: ' . ($data['failure_reason'] ?? 'Snippe imekataa.'));
        refundPayout($conn, (int)$po['id'], 'failed', $sababu);
    }

    webhookJibu(200, ['received' => true]);
}

// Tukio tusilolijua — kubali ili Snippe wasiendelee kujaribu.
webhookJibu(200, ['received' => true, 'message' => 'Tukio halihitaji hatua.']);
