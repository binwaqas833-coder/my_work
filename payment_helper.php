<?php
/**
 * payment_helper.php
 * -------------------------------------------------------------
 * Mantiki YA PAMOJA ya "malipo yamekamilika -> tengeneza voucher -> panda
 * MikroTik -> auto-login". Inaitwa na WATATU:
 *   1. snippe_webhook.php       - Snippe wanatuambia malipo yamekamilika
 *   2. check_payment_status.php - poll ya ukurasa wa mteja (KINGA, endapo
 *      webhook itapotea njiani)
 *   3. retry_payment.php        - admin abonyeza "Kukamilisha"
 *
 * Ulinzi wa claimed_at ni MUHIMU: webhook na poll zinaweza kufika sekunde
 * moja, na Snippe hujaribu webhook mara 5. Vocha inatengenezwa MARA MOJA tu.
 *
 * MUHIMU (MULTI-ROUTER): txn (payment_transactions) sasa ina router_id
 * yake yenyewe (iliyowekwa na lipia.php) - hii ndiyo chanzo cha ukweli
 * cha "vocha hii inaenda router ipi", SIYO tena "router ya kwanza ya
 * user huyu" kama ilivyokuwa awali.
 *
 * ── SHERIA MUHIMU KULIKO ZOTE HAPA (2026-09-04) ──
 * PESA IKISHAINGIA, MUAMALA HAUWEZI KAMWE KUWA 'failed'.
 *
 * Tarehe 2026-09-04 mteja alilipa TZS 1,000, Snippe wakapokea pesa,
 * lakini router ya reseller haikufikika kwenye tunnel. Mfumo uliweka
 * 'failed' - na kwa sababu completeVoucherPayment() inarudi mapema
 * kwa rekodi ya 'failed', hakuna kilichojaribu tena hata router
 * ilipofikika. Mteja alipoteza pesa kimya kimya.
 *
 * Sasa kuna hali ya kati: 'paid_pending_voucher' = "amelipa, vocha
 * bado". Ni DENI. retry_pending_vouchers.php inaendelea kujaribu
 * mpaka vocha itoke, na admin anaarifiwa ikichukua muda mrefu.
 *
 * Hivyo, unapoongeza tawi jipya la kushindwa hapa chini, jiulize swali
 * MOJA: "je pesa ya mteja tayari ipo kwa gateway?"
 *   NDIYO -> markTransactionPaidPendingVoucher()   (itajaribiwa tena)
 *   HAPANA -> markTransactionFailed()              (mwisho wa safari)
 * -------------------------------------------------------------
 */

require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';   // toleo JIPYA (multi-router)
require_once 'error_logger.php';
require_once 'balance_helper.php';    // ada ya 3.8% - CHANZO KIMOJA cha ukweli

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
        // 'failed' sasa ina maana MOJA: hakuna pesa iliyopokelewa.
        // Muamala uliolipiwa hauwezi kufika hapa (angalia maelezo ya juu).
        return ['status' => 'failed', 'voucher_code' => null, 'message' => 'Malipo yalishindikana.'];
    }

    // ── ULINZI DHIDI YA VOCHA MBILI KWA MALIPO MAMOJA ──
    // Webhook ya gateway na poll ya ukurasa wa mteja zinaweza kufika
    // SEKUNDE MOJA. Bila ulinzi, zote mbili zingeona 'pending' na kila
    // moja ingetengeneza vocha yake - mteja mmoja, vocha mbili, hasara
    // kwa reseller. UPDATE ya masharti hapa chini inafanikiwa kwa MMOJA
    // tu (MySQL inaifanya atomic); mwingine anaona affected_rows = 0.
    //
    // 'paid_pending_voucher' inaruhusiwa kudaiwa pia: ndiyo hasa hali
    // ambayo cron ya kujaribu tena inaikuta, na ulinzi ule ule wa
    // claimed_at unaizuia isigongane na poll ya mteja.
    $claim = $conn->prepare(
        "UPDATE payment_transactions SET claimed_at = NOW()
         WHERE transaction_id = ?
           AND status IN ('pending','paid_pending_voucher')
           AND claimed_at IS NULL"
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

    // Kuanzia hapa tunajua PESA IMEINGIA (mwitaji - webhook, poll, au cron -
    // amethibitisha na gateway kabla ya kutuita). Kila njia ya kutoka chini
    // ni ama 'completed' ama 'paid_pending_voucher'; hakuna 'failed'.

    $user_id      = (int)$txn['user_id'];
    $router_id    = (int)($txn['router_id'] ?? 0);
    $package_type = $txn['package_type'];

    if ($router_id <= 0) {
        // Data mbovu - cron haiwezi kuitatua yenyewe, lakini pesa IPO.
        // Inabaki 'paid_pending_voucher' ili ionekane kwenye orodha ya
        // madeni na admin aingilie kwa mkono (siyo kufichwa kama 'failed').
        markTransactionPaidPendingVoucher($conn, $transaction_id, 'Router haijulikani kwenye transaction hii.');
        logSystemError($conn, 'payment_helper.php', "Transaction {$transaction_id} haina router_id.", ['user_id' => $user_id]);
        return ['status' => 'paid_pending_voucher', 'voucher_code' => null, 'message' => 'Router haijulikani kwenye transaction hii.'];
    }

    // Tariff HALISI - sasa kwa router_id (chanzo cha ukweli)
    $t2 = $conn->prepare("SELECT * FROM tariffs WHERE router_id = ? AND package_type = ? LIMIT 1");
    $t2->bind_param("is", $router_id, $package_type);
    $t2->execute();
    $tariff = $t2->get_result()->fetch_assoc();
    $t2->close();

    if (!$tariff) {
        // Reseller amefuta/kubadilisha tariff kati ya mteja kulipa na vocha
        // kutolewa. Ni tatizo linaloweza kurekebishwa (arudishe tariff), na
        // pesa tayari ipo - hivyo ni deni, siyo 'failed'.
        markTransactionPaidPendingVoucher($conn, $transaction_id, 'Kifurushi hakipatikani tena.');
        logSystemError($conn, 'payment_helper.php',
            "Tariff '{$package_type}' haipo tena kwa router_id={$router_id} - vocha ya {$transaction_id} imekwama.",
            ['user_id' => $user_id, 'router_id' => $router_id]);
        return ['status' => 'paid_pending_voucher', 'voucher_code' => null, 'message' => 'Kifurushi hakipatikani tena.'];
    }

    $duration_days = (int)$tariff['duration_days'];
    $profile_name  = $tariff['profile_name'];

    do {
        $voucher_code = random_int(100000, 999999);
        $chk = $conn->query("SELECT id FROM vouchers WHERE voucher_code='$voucher_code' AND user_id='$user_id' LIMIT 1");
    } while ($chk && $chk->num_rows > 0);

    // Unganisha na router SAHIHI (iliyohifadhiwa kwenye txn, siyo "ya kwanza tuliyoipata")
    // HII NDIYO SEHEMU ILIYOPOTEZA PESA YA MTEJA TAREHE 2026-09-04.
    // Router chini = tatizo LETU la muda, siyo mteja kushindwa kulipa.
    $API = getMikrotikConnection($router_id, $user_id, $conn);
    if (!$API) {
        markTransactionPaidPendingVoucher($conn, $transaction_id, 'Router ya mtoa huduma haipatikani.');
        return ['status' => 'paid_pending_voucher', 'voucher_code' => null,
                'message' => 'Malipo yamepokelewa. Vocha itatolewa router itakaporudi.'];
    }

    $limit_uptime = ($duration_days >= 1) ? ($duration_days . "d") : "1h";
    $add_response = addHotspotUserToMikrotik($API, $voucher_code, $voucher_code, $profile_name, ['limit-uptime' => $limit_uptime]);

    if (isset($add_response['!trap'])) {
        $API->disconnect();
        // Tunaunganika na router lakini imekataa amri (mfano profile
        // haipo, au user amejaa). Bado ni tatizo la router - pesa ipo.
        $sababu_trap = $add_response['!trap'][0]['message'] ?? '';
        markTransactionPaidPendingVoucher($conn, $transaction_id, 'Imeshindikana kupandisha MikroTik.');
        logSystemError($conn, 'payment_helper.php',
            "MikroTik imekataa kuunda hotspot user kwa {$transaction_id}: " . ($sababu_trap ?: 'hakuna maelezo'),
            ['user_id' => $user_id, 'router_id' => $router_id,
             'context' => ['profile' => $profile_name, 'trap' => $sababu_trap]]);
        return ['status' => 'paid_pending_voucher', 'voucher_code' => null,
                'message' => 'Malipo yamepokelewa. Vocha inaandaliwa.'];
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

    // ── ADA YA SNIPPE (2.5%) INAKOKOTOLEWA HAPA, MARA MOJA TU ──
    // Hii ndiyo sehemu PEKEE inayogeuza gross kuwa net. cash_out.php
    // inasoma net_amount hii kama ilivyo - HAIKATI asilimia mara ya pili.
    // SIYO faida ya Tech5G: ni gharama halisi ya Snippe inayopitishwa
    // kwa mmiliki wa router. (Angalia balance_helper.php.)
    $gross = (float)$txn['amount'];
    $fee   = calculateTransactionFee($gross);
    $net   = calculateNetAmount($gross);
    $fee_p = GATEWAY_FEE_PERCENT;

    // next_attempt_at inafutwa: deni limelipwa, cron isiiguse tena.
    // fail_reason inafutwa pia - vinginevyo sababu ya jaribio lililoshindwa
    // ingebaki ikionekana kwenye rekodi iliyofanikiwa.
    $u = $conn->prepare(
        "UPDATE payment_transactions
            SET status='completed', voucher_code=?,
                fee_percent=?, fee_amount=?, net_amount=?,
                fail_reason=NULL, next_attempt_at=NULL, updated_at=NOW()
          WHERE transaction_id=?"
    );
    $u->bind_param("sddds", $voucher_code, $fee_p, $fee, $net, $transaction_id);
    $u->execute();
    $u->close();

    return ['status' => 'completed', 'voucher_code' => $voucher_code, 'message' => 'Malipo yamekamilika.'];
}

/**
 * PESA IMEPOKELEWA, VOCHA HAIJATOKA - hali ya kati inayojaribiwa tena.
 *
 * Tumia HII (siyo markTransactionFailed) kila tatizo linapokuwa la KWETU:
 * router chini, MikroTik imekataa amri, tariff imefutwa. Mteja amelipa;
 * deni haliwezi kufutwa kwa kuandika 'failed'.
 *
 * BACKOFF: router iliyokufa isipigwe kila dakika 2 milele - ingejaza
 * error_logs na kuchelewesha cron. Muda unaongezeka 2, 4, 8, 16 dakika
 * ... mpaka dakika 30, kisha unabaki hapo. Router ikirudi baada ya saa
 * kadhaa, jaribio linalofuata bado litakuja - hakuna kikomo cha majaribio,
 * kwa sababu hakuna kikomo cha deni.
 *
 * claimed_at inarudishwa NULL: bila hivyo hakuna anayeweza kuudai tena
 * muamala huu (cron wala admin) na ungebaki umekwama milele.
 */
function markTransactionPaidPendingVoucher($conn, $transaction_id, $reason)
{
    $reason = mb_substr((string)$reason, 0, 255);

    // delivery_attempts + 1 inahesabiwa ndani ya SQL ili majaribio ya
    // wakati mmoja (webhook + cron) yasipoteze hesabu ya mwenzake.
    $u = $conn->prepare(
        "UPDATE payment_transactions
            SET status            = 'paid_pending_voucher',
                fail_reason       = ?,
                claimed_at        = NULL,
                delivery_attempts = delivery_attempts + 1,
                next_attempt_at   = DATE_ADD(NOW(), INTERVAL LEAST(POW(2, LEAST(delivery_attempts + 1, 5)), 30) MINUTE),
                updated_at        = NOW()
          WHERE transaction_id = ?
            AND status <> 'completed'"
    );
    $u->bind_param("ss", $reason, $transaction_id);
    $u->execute();
    $u->close();
}

/**
 * Weka alama ya kushindikana + SABABU. Sababu inahifadhiwa (fail_reason)
 * ili admin aone kwenye malipo_status.php kwa nini muamala ulikwama,
 * badala ya "failed" tupu isiyoeleza kitu.
 *
 * ⚠️ TUMIA HII PALE TU MTEJA HAKULIPA (amekataa prompt, salio halitoshi,
 * muda umeisha, gateway imesema 'failed'). Kwa tatizo lolote la KWETU
 * baada ya pesa kuingia, tumia markTransactionPaidPendingVoucher().
 *
 * Ulinzi: rekodi iliyokwisha kulipiwa haiwezi kurudishwa 'failed'.
 * 'completed' ni dhahiri; 'paid_pending_voucher' pia inalindwa kwa
 * sababu ni deni lililothibitishwa - webhook ya "failed" iliyochelewa
 * (mfano jaribio la pili la mteja lililoshindwa likifika baada ya la
 * kwanza kufanikiwa) isije ikafuta deni halali.
 *
 * claimed_at inarudishwa NULL ili "Kukamilisha" ya admin iweze kujaribu tena.
 */
function markTransactionFailed($conn, $transaction_id, $reason)
{
    $reason = mb_substr((string)$reason, 0, 255);
    $u = $conn->prepare(
        "UPDATE payment_transactions
         SET status='failed', fail_reason=?, claimed_at=NULL, next_attempt_at=NULL, updated_at=NOW()
         WHERE transaction_id=?
           AND status NOT IN ('completed','paid_pending_voucher')"
    );
    $u->bind_param("ss", $reason, $transaction_id);
    $u->execute();
    $u->close();
}

/**
 * Jaribu tena muamala uliokwama (button ya "Kukamilisha" kwenye
 * malipo_status.php). HAITUMII PESA MPYA - inadhania tayari umethibitisha
 * kwenye dashboard ya Snippe kuwa mteja KWELI amelipa.
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
         SET claimed_at=NULL, next_attempt_at=NULL
         WHERE transaction_id=? AND status IN ('failed','pending','paid_pending_voucher')"
    );
    $u->bind_param("s", $transaction_id);
    $u->execute();
    $u->close();

    // 'failed' pekee ndiyo inayohitaji kurudishwa 'pending' - hapo admin
    // anadai "amelipa kweli, nimethibitisha kwenye dashboard ya Snippe".
    // 'paid_pending_voucher' HAIGUSWI: tayari ni hali inayoruhusu kudaiwa,
    // na kuirudisha 'pending' kungefuta ushahidi kuwa pesa ilishaingia.
    $r = $conn->prepare(
        "UPDATE payment_transactions SET status='pending' WHERE transaction_id=? AND status='failed'"
    );
    $r->bind_param("s", $transaction_id);
    $r->execute();
    $r->close();

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