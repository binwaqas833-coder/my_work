<?php
/**
 * payment_helper.php
 * -------------------------------------------------------------
 * Mantiki YA PAMOJA ya "malipo yamekamilika -> tengeneza voucher -> panda
 * MikroTik -> auto-login". Inaitwa na WATU WAWILI:
 *   1. dalipay_webhook.php      - gateway inatuambia malipo yamekamilika
 *   2. check_payment_status.php - poll ya ukurasa wa mteja (backstop, kwa
 *      sababu gateway HAIRUDII webhook ikishindwa kufika mara ya kwanza)
 * na pia retry_payment.php pale admin anapobonyeza "Kukamilisha".
 *
 * MUHIMU (MULTI-ROUTER): txn (payment_transactions) sasa ina router_id
 * yake yenyewe (iliyowekwa na lipia.php) - hii ndiyo chanzo cha ukweli
 * cha "vocha hii inaenda router ipi", SIYO tena "router ya kwanza ya
 * user huyu" kama ilivyokuwa awali.
 * -------------------------------------------------------------
 */

require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';   // toleo JIPYA (multi-router)
require_once 'error_logger.php';

/**
 * Kamilisha malipo ya "pending" transaction: tengeneza voucher ya kipekee,
 * ipandishe MikroTik (router iliyohifadhiwa kwenye txn), fanya auto-login
 * kama mac/ip zipo, kisha sasisha rekodi za payment_transactions na vouchers.
 */
function completeVoucherPayment($conn, $transaction_id)
{
    $t_stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE transaction_id = ? LIMIT 1");
    $t_stmt->bind_param("s", $transaction_id);
    $t_stmt->execute();
    $txn = $t_stmt->get_result()->fetch_assoc();
    $t_stmt->close();

    if (!$txn) {
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Transaction haipo.'];
    }

    if ($txn['status'] === 'completed') {
        return ['status' => 'completed', 'voucher_code' => $txn['voucher_code'], 'message' => 'Malipo yamekamilika.'];
    }
    if ($txn['status'] === 'failed') {
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Malipo yalishindikana.'];
    }

    // ── ULINZI DHIDI YA VOCHA MBILI KWA MALIPO MAMOJA ──
    // Webhook ya gateway na poll ya ukurasa wa mteja zinaweza kufika
    // SEKUNDE MOJA. Bila ulinzi, zote mbili zingeona 'pending' na kila
    // moja ingetengeneza vocha yake - mteja mmoja, vocha mbili, hasara
    // kwa reseller. UPDATE ya masharti hapa chini inafanikiwa kwa MMOJA
    // tu (MySQL inaifanya atomic); mwingine anaona affected_rows = 0.
    $claim = $conn->prepare(
        "UPDATE payment_transactions SET claimed_at = NOW()
         WHERE transaction_id = ? AND status = 'pending' AND claimed_at IS NULL"
    );
    $claim->bind_param("s", $transaction_id);
    $claim->execute();
    $nimeidai = ($claim->affected_rows === 1);
    $claim->close();

    if (!$nimeidai) {
        // Mwingine anaishughulikia SASA HIVI. Poll ijayo ya mteja itaona
        // 'completed' pindi atakapomaliza.
        return ['status' => 'pending', 'voucher_code' => null, 'message' => 'Malipo yanachakatwa...'];
    }

    $user_id      = (int)$txn['user_id'];
    $router_id    = (int)($txn['router_id'] ?? 0);
    $package_type = $txn['package_type'];

    if ($router_id <= 0) {
        markTransactionFailed($conn, $transaction_id, 'Router haijulikani kwenye transaction hii.');
        logSystemError($conn, 'payment_helper.php', "Transaction {$transaction_id} haina router_id.", ['user_id' => $user_id]);
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Router haijulikani kwenye transaction hii.'];
    }

    // Tariff HALISI - sasa kwa router_id (chanzo cha ukweli)
    $t2 = $conn->prepare("SELECT * FROM tariffs WHERE router_id = ? AND package_type = ? LIMIT 1");
    $t2->bind_param("is", $router_id, $package_type);
    $t2->execute();
    $tariff = $t2->get_result()->fetch_assoc();
    $t2->close();

    if (!$tariff) {
        markTransactionFailed($conn, $transaction_id, 'Kifurushi hakipatikani tena.');
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Kifurushi hakipatikani tena.'];
    }

    $duration_days = (int)$tariff['duration_days'];
    $profile_name  = $tariff['profile_name'];

    do {
        $voucher_code = random_int(100000, 999999);
        $chk = $conn->query("SELECT id FROM vouchers WHERE voucher_code='$voucher_code' AND user_id='$user_id' LIMIT 1");
    } while ($chk && $chk->num_rows > 0);

    // Unganisha na router SAHIHI (iliyohifadhiwa kwenye txn, siyo "ya kwanza tuliyoipata")
    $API = getMikrotikConnection($router_id, $user_id, $conn);
    if (!$API) {
        markTransactionFailed($conn, $transaction_id, 'Router ya mtoa huduma haipatikani.');
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Router ya mtoa huduma haipatikani.'];
    }

    $limit_uptime = ($duration_days >= 1) ? ($duration_days . "d") : "1h";
    $add_response = addHotspotUserToMikrotik($API, $voucher_code, $voucher_code, $profile_name, ['limit-uptime' => $limit_uptime]);

    if (isset($add_response['!trap'])) {
        $API->disconnect();
        markTransactionFailed($conn, $transaction_id, 'Imeshindikana kupandisha MikroTik.');
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Imeshindikana kupandisha MikroTik.'];
    }

    $mikrotik_synced   = 1;
    $login_imefanikiwa = false;

    if (!empty($txn['client_mac']) && !empty($txn['client_ip'])) {
        $login_response = loginHotspotUser($API, $voucher_code, $voucher_code, $txn['client_mac'], $txn['client_ip']);
        if (!isset($login_response['!trap'])) {
            $login_imefanikiwa = true;
        }
    }
    $API->disconnect();

    $status_voucher  = $login_imefanikiwa ? 'used' : 'unused';
    $mtandao_wa_simu = tambuaMtandaoWaSimuHelper($txn['phone']);

    $ins = $conn->prepare("
        INSERT INTO vouchers
            (user_id, router_id, phone, mac_address, voucher_code, package_type, price, duration_days,
             mikrotik_profile, status, payment_method, type, mikrotik_synced, transaction_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)
    ");
    $ins->bind_param(
        "iissssdisssis",
        $user_id, $router_id, $txn['phone'], $txn['client_mac'], $voucher_code, $package_type, $tariff['price'], $duration_days,
        $profile_name, $status_voucher, $mtandao_wa_simu, $mikrotik_synced, $transaction_id
    );
    $ins->execute();
    $voucher_db_id = $conn->insert_id;
    $ins->close();

    if ($login_imefanikiwa) {
        $conn->query("UPDATE vouchers SET expiry_date = DATE_ADD(NOW(), INTERVAL $duration_days DAY), last_login_at = NOW() WHERE id = $voucher_db_id");
    }

    $u = $conn->prepare("UPDATE payment_transactions SET status='completed', voucher_code=?, updated_at=NOW() WHERE transaction_id=?");
    $u->bind_param("ss", $voucher_code, $transaction_id);
    $u->execute();
    $u->close();

    return ['status' => 'completed', 'voucher_code' => $voucher_code, 'message' => 'Malipo yamekamilika.'];
}

/**
 * Weka alama ya kushindikana + SABABU. Sababu inahifadhiwa (fail_reason)
 * ili admin aone kwenye malipo_status.php kwa nini muamala ulikwama,
 * badala ya "failed" tupu isiyoeleza kitu.
 *
 * claimed_at inarudishwa NULL ili "Kukamilisha" ya admin iweze kujaribu tena.
 */
function markTransactionFailed($conn, $transaction_id, $reason)
{
    $reason = mb_substr((string)$reason, 0, 255);
    $u = $conn->prepare(
        "UPDATE payment_transactions
         SET status='failed', fail_reason=?, claimed_at=NULL, updated_at=NOW()
         WHERE transaction_id=?"
    );
    $u->bind_param("ss", $reason, $transaction_id);
    $u->execute();
    $u->close();
}

/**
 * Jaribu tena muamala uliokwama (button ya "Kukamilisha" kwenye
 * malipo_status.php). HAITUMII PESA MPYA - inadhania tayari umethibitisha
 * kwenye dashboard ya Dalipay kuwa mteja KWELI amelipa.
 *
 * Inafuta claimed_at kwanza: muamala unaweza kuwa umekwama kwa sababu
 * mchakato uliokuwa umeudai ulikufa katikati (mfano PHP timeout wakati
 * router ilikuwa chini), na bila kufuta alama hiyo hakuna anayeweza
 * kuudai tena - ungebaki 'pending' milele.
 */
function retryPaymentTransaction($conn, $transaction_id)
{
    $u = $conn->prepare(
        "UPDATE payment_transactions
         SET status='pending', claimed_at=NULL
         WHERE transaction_id=? AND status IN ('failed','pending')"
    );
    $u->bind_param("s", $transaction_id);
    $u->execute();
    $u->close();

    return completeVoucherPayment($conn, $transaction_id);
}

function tambuaMtandaoWaSimuHelper($namba)
{
    $namba = preg_replace('/[^0-9]/', '', $namba);
    if (strpos($namba, '255') === 0) {
        $namba = '0' . substr($namba, 3);
    }
    $prefix3 = substr($namba, 0, 3);

    $ramani = [
        '074' => 'Vodacom (M-Pesa)', '075' => 'Vodacom (M-Pesa)', '076' => 'Vodacom (M-Pesa)',
        '065' => 'Yas/Tigo (Mixx by Yas)', '067' => 'Yas/Tigo (Mixx by Yas)', '071' => 'Yas/Tigo (Mixx by Yas)',
        '068' => 'Airtel Money', '069' => 'Airtel Money', '078' => 'Airtel Money',
        '061' => 'Halotel (HaloPesa)', '062' => 'Halotel (HaloPesa)',
        '077' => 'Yas/Tigo (Mixx by Yas)',
        '073' => 'TTCL',
    ];

    return $ramani[$prefix3] ?? 'Haijatambulika';
}