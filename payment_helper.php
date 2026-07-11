<?php
/**
 * payment_helper.php
 * -------------------------------------------------------------
 * Mantiki YA PAMOJA ya "malipo yamekamilika -> tengeneza voucher -> panda
 * MikroTik -> auto-login". Inatumika na sehemu MBILI tofauti:
 *
 *   1. check_payment_status.php (SASA, wakati AZAMPAY_MOCK_MODE = true) -
 *      inaiga muda wa kusubiri malipo bila AzamPay halisi.
 *   2. azampay_callback.php (BAADAYE, utakapopata AzamPay Client ID/Secret) -
 *      webhook halisi itakayoitwa na AzamPay yenyewe.
 *
 * completeVoucherPayment() HAIBADILIKI kati ya hizo mbili - ndiyo lengo la
 * kuwa na helper hii: siku utakapounganisha AzamPay ya kweli, unabadilisha
 * TU jinsi function hii inavyoitwa (webhook badala ya polling-timer), sio
 * jinsi voucher inavyotengenezwa.
 * -------------------------------------------------------------
 */

// ⚠️ BADILISHA KUWA false SIKU UTAKAPOUNGANISHA AZAMPAY HALISI.
// Ukiwa true: check_payment_status.php inajikamilishia malipo yenyewe baada
// ya MOCK_DELAY_SECONDS, ili uweze kutest mfumo mzima bila kusubiri AzamPay.
define('AZAMPAY_MOCK_MODE', true);
define('MOCK_DELAY_SECONDS', 6); // "muda wa kusubiri" tunaoiga kwenye mock

require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';

/**
 * Kamilisha malipo ya "pending" transaction: tengeneza voucher ya kipekee,
 * ipandishe MikroTik, fanya auto-login kama mac/ip zipo, kisha sasisha
 * rekodi ya payment_transactions na vouchers.
 *
 * @return array ['status' => 'completed'|'failed'|'pending', 'voucher_code' => string|null, 'message' => string]
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

    // Kama tayari imeshakamilika/kushindikana awali (mfano poll mbili karibu karibu), rudisha hali ile ile
    if ($txn['status'] === 'completed') {
        return ['status' => 'completed', 'voucher_code' => $txn['voucher_code'], 'message' => 'Malipo yamekamilika.'];
    }
    if ($txn['status'] === 'failed') {
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Malipo yalishindikana.'];
    }

    $user_id      = (int)$txn['user_id'];
    $package_type = $txn['package_type'];

    // Tariff halisi (chanzo cha ukweli - siyo bei iliyohifadhiwa kwenye transaction)
    $t2 = $conn->prepare("SELECT * FROM tariffs WHERE user_id = ? AND package_type = ? LIMIT 1");
    $t2->bind_param("is", $user_id, $package_type);
    $t2->execute();
    $tariff = $t2->get_result()->fetch_assoc();
    $t2->close();

    if (!$tariff) {
        markTransactionFailed($conn, $transaction_id, 'Kifurushi hakipatikani tena.');
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Kifurushi hakipatikani tena.'];
    }

    $price         = (float)$tariff['price'];
    $duration_days = (int)$tariff['duration_days'];
    $profile_name  = $tariff['profile_name'];

    // Voucher code ya kipekee
    do {
        $voucher_code = random_int(100000, 999999);
        $chk = $conn->query("SELECT id FROM vouchers WHERE voucher_code='$voucher_code' AND user_id='$user_id' LIMIT 1");
    } while ($chk && $chk->num_rows > 0);

    // Unganisha na MikroTik ya reseller huyu
    $API = getMikrotikConnection($user_id, $conn);
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
            (user_id, phone, mac_address, voucher_code, package_type, price, duration_days,
             mikrotik_profile, status, payment_method, type, mikrotik_synced, transaction_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)
    ");
    $ins->bind_param(
        "issssdisssis",
        $user_id, $txn['phone'], $txn['client_mac'], $voucher_code, $package_type, $price, $duration_days,
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

function markTransactionFailed($conn, $transaction_id, $reason)
{
    $u = $conn->prepare("UPDATE payment_transactions SET status='failed', updated_at=NOW() WHERE transaction_id=?");
    $u->bind_param("s", $transaction_id);
    $u->execute();
    $u->close();
}

/**
 * Jaribu tena kukamilisha transaction iliyokwama - mfano ilibaki 'pending'
 * kwa sababu mteja alifunga tab kabla webhook/mock haijamfikia, au
 * ilishindikana kimfumo ('failed' - mfano router ilikuwa chini wakati huo
 * lakini sasa iko juu). HAITUMII PESA MPYA - inadhania tayari umethibitisha
 * (kwa mfano kwenye AzamPay dashboard/M-Pesa statement) kuwa mteja KWELI
 * amelipa, na unataka tu kumpa voucher yake.
 *
 * Kama 'failed', kwanza tunaifungua tena kama 'pending' kabla ya kujaribu -
 * completeVoucherPayment() haitajaribu kukamilisha rekodi iliyo 'failed'.
 */
function retryPaymentTransaction($conn, $transaction_id)
{
    $u = $conn->prepare("UPDATE payment_transactions SET status='pending' WHERE transaction_id=? AND status='failed'");
    $u->bind_param("s", $transaction_id);
    $u->execute();
    $u->close();

    return completeVoucherPayment($conn, $transaction_id);
}

/**
 * Tambua mtandao wa simu kutoka prefix - nakala kutoka lipia.php ili
 * payment_helper.php isimame peke yake bila kutegemea lipia.php kuwa
 * imeshapakiwa kabla (azampay_callback.php haitapitia lipia.php kabisa).
 */
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
