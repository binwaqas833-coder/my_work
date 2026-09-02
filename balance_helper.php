<?php
/**
 * balance_helper.php
 * ------------------------------------------------------------------
 * CHANZO KIMOJA CHA UKWELI (single source of truth) cha:
 *   • ada za gateway (Snippe) zinazopitishwa kwa mmiliki
 *   • kiasi halisi anachomiliki reseller/admin (net)
 *   • salio linaloweza kutolewa (available) kwa KILA ROUTER
 *
 * ── ADA: TECH5G HAITOZI CHOCHOTE ──
 * Ada zote hapa ni za SNIPPE, zinazopitishwa kwa mmiliki wa router
 * kama zilivyo. Tech5G haiongezi senti juu yake wala haichukui sehemu.
 * Ndiyo maana namba hizi LAZIMA zilingane na zile za Snippe:
 *
 *   Kuingiza (malipo):  2.50% ya kiasi
 *   Kutoa (cash-out):   TSh 1,500 FLAT kwa ombi
 *
 * Zikibadilika upande wa Snippe, badilisha HAPA - siyo mahali pengine.
 *
 * KANUNI KUU
 * ----------
 * 1. Ada ya kuingiza inakokotolewa MARA MOJA TU - wakati muamala
 *    unapokamilika (payment_helper.php::completeVoucherPayment).
 *    Inahifadhiwa kwenye payment_transactions.fee_amount / net_amount.
 *
 * 2. Cash-out HAIKATI asilimia tena. Inatumia net_amount iliyohifadhiwa.
 *    Kama ukiona asilimia ikikokotolewa mahali pengine popote nje ya
 *    calculateTransactionFee() hapa chini - ni hitilafu.
 *
 * 2b. ADA YA KUTOA (TSh 1,500) ni FLAT, hivyo haiwezi kutoka kwenye
 *    asilimia. Inahifadhiwa kwenye payout_requests.fee_amount na
 *    inashikiliwa PAMOJA na kiasi: mmiliki anayeomba TSh 5,000 anaona
 *    salio lake likipungua TSh 6,500, na mpokeaji anapata TSh 5,000
 *    kamili. Ombi likishindikana, VYOTE viwili vinarudi vyenyewe
 *    (hali inabadilika tu - hakuna "refund" ya kuongeza column).
 *
 * 3. Salio HALIHIFADHIWI kwenye column yoyote (users.balance HAITUMIKI
 *    tena). Linakokotolewa kila linapohitajika:
 *
 *        available(router) = SUM(net_amount ya malipo 'completed' ya router hii)
 *                          - SUM(amount + fee_amount ya maombi ya cash-out
 *                                ya router hii yasiyo 'failed'/'rejected')
 *
 *    Faida za kukokotoa badala ya kuhifadhi:
 *      • muamala mmoja hauwezi kutolewa mara mbili - ombi lililopo tayari
 *        linapunguza salio mara moja tu, hata ombi likirudiwa;
 *      • ombi likikataliwa/kushindikana, pesa inarudi YENYEWE (hali
 *        imebadilika tu) - hakuna "refund" ya kuongeza column, hivyo
 *        hakuna njia ya kuongeza salio mara mbili kimakosa;
 *      • pesa za router A haziwezi kuonekana kama salio la router B.
 * ------------------------------------------------------------------
 */

/**
 * Ada ya Snippe kwa malipo yanayoingia (%). HII NDIYO SEHEMU PEKEE
 * inayoifafanua. SIYO faida ya Tech5G - ni gharama halisi ya Snippe
 * inayopitishwa kwa mmiliki wa router.
 */
const GATEWAY_FEE_PERCENT = 2.5;

/**
 * Ada ya Snippe kwa kila cash-out (TSh, FLAT - siyo asilimia).
 *
 * ⚠️ KWA SABABU NI FLAT, ombi dogo linaumia sana: TSh 1,500 kwenye ombi
 * la TSh 5,000 ni 30%. Ndiyo maana MIN_PAYOUT (cash_out.php) ni kubwa
 * kuliko ada hii kwa mara kadhaa. Usishushe MIN_PAYOUT karibu na 1,500.
 */
const GATEWAY_PAYOUT_FEE = 1500.0;

/**
 * Hali za ombi la cash-out zinazoendelea KUSHIKILIA pesa.
 * 'success'  - pesa imeshatoka.
 * nyingine   - bado inasubiri, hivyo haiwezi kuombwa tena.
 * 'failed' / 'rejected' HAZIPO hapa: pesa inarudi kwa mmiliki yenyewe.
 */
function payoutHoldingStatuses(): array
{
    return ['pending', 'approved', 'awaiting_approval', 'success'];
}

/** Ada ya 3.8% ya kiasi kilicholipwa na mteja (gross). */
function calculateTransactionFee(float $gross): float
{
    return round($gross * GATEWAY_FEE_PERCENT / 100, 2);
}

/** Ada ya Snippe kwa ombi moja la cash-out (flat). */
function calculatePayoutFee(): float
{
    return GATEWAY_PAYOUT_FEE;
}

/** Jumla itakayokatwa kwenye salio ili mpokeaji apate $amount kamili. */
function payoutTotalCost(float $amount): float
{
    return round($amount + calculatePayoutFee(), 2);
}

/** Kiasi halisi anachomiliki reseller/admin baada ya ada kukatwa. */
function calculateNetAmount(float $gross): float
{
    return round($gross - calculateTransactionFee($gross), 2);
}

/**
 * Salio la KILA router ya mmiliki mmoja (reseller au admin).
 *
 * Inarudisha array yenye:
 *   'routers' => [ router_id => [ router_id, router_label, mikrotik_ip,
 *                                 gross, fees, net, txn_count,
 *                                 paid_out, held, available ] ]
 *   'totals'  => [ gross, fees, net, txn_count, paid_out, held,
 *                  legacy_held, available ]
 *
 * 'legacy_held' ni maombi ya zamani yasiyo na router_id (kabla ya
 * migration ya 2026-08-22). Yanapunguzwa kwenye jumla pekee kwa sababu
 * hatujui yalitoka router ipi. Kwenye production ni 0.
 */
function getOwnerRouterBalances($conn, int $user_id): array
{
    $routers = [];

    $r = $conn->prepare(
        "SELECT router_id, router_label, mikrotik_ip
           FROM mikrotik_configs
          WHERE user_id = ?
          ORDER BY router_id ASC"
    );
    $r->bind_param("i", $user_id);
    $r->execute();
    $rs = $r->get_result();
    while ($row = $rs->fetch_assoc()) {
        $rid = (int)$row['router_id'];
        $routers[$rid] = [
            'router_id'    => $rid,
            'router_label' => $row['router_label'],
            'mikrotik_ip'  => $row['mikrotik_ip'],
            'gross'        => 0.0,
            'fees'         => 0.0,
            'net'          => 0.0,
            'txn_count'    => 0,
            'paid_out'     => 0.0,
            'held'         => 0.0,
            'available'    => 0.0,
        ];
    }
    $r->close();

    // ── Mapato: malipo yaliyokamilika pekee ──
    // fee_amount / net_amount zilishakokotolewa wakati muamala ulipokamilika.
    // HATUKOKOTOI 3.8% tena hapa.
    $t = $conn->prepare(
        "SELECT router_id,
                COUNT(*)                    AS txn_count,
                COALESCE(SUM(amount), 0)     AS gross,
                COALESCE(SUM(fee_amount), 0) AS fees,
                COALESCE(SUM(net_amount), 0) AS net
           FROM payment_transactions
          WHERE user_id = ? AND status = 'completed' AND router_id IS NOT NULL
          GROUP BY router_id"
    );
    $t->bind_param("i", $user_id);
    $t->execute();
    $ts = $t->get_result();
    while ($row = $ts->fetch_assoc()) {
        $rid = (int)$row['router_id'];
        if (!isset($routers[$rid])) {
            continue; // router imefutwa - pesa zake haziombeki tena
        }
        $routers[$rid]['txn_count'] = (int)$row['txn_count'];
        $routers[$rid]['gross']     = (float)$row['gross'];
        $routers[$rid]['fees']      = (float)$row['fees'];
        $routers[$rid]['net']       = (float)$row['net'];
    }
    $t->close();

    // ── Maombi ya cash-out yanayoshikilia pesa ──
    $holding = payoutHoldingStatuses();
    $place   = implode(',', array_fill(0, count($holding), '?'));

    $p = $conn->prepare(
        "SELECT router_id,
                -- kiasi + ada ya Snippe: ndicho kinachotoka kwenye salio kweli.
                -- COALESCE(fee_amount,0) inalinda rekodi za zamani (kabla ya
                -- column hii kuwepo) zisihesabike kama NULL.
                COALESCE(SUM(CASE WHEN status =  'success' THEN amount + COALESCE(fee_amount,0) ELSE 0 END), 0) AS paid_out,
                COALESCE(SUM(CASE WHEN status <> 'success' THEN amount + COALESCE(fee_amount,0) ELSE 0 END), 0) AS held
           FROM payout_requests
          WHERE user_id = ? AND status IN ($place)
          GROUP BY router_id"
    );
    $types = 'i' . str_repeat('s', count($holding));
    $args  = array_merge([$user_id], $holding);
    $p->bind_param($types, ...$args);
    $p->execute();
    $ps = $p->get_result();

    $legacy_held = 0.0;
    while ($row = $ps->fetch_assoc()) {
        $paid = (float)$row['paid_out'];
        $held = (float)$row['held'];

        if ($row['router_id'] === null) {
            $legacy_held += $paid + $held; // ombi la zamani - router haijulikani
            continue;
        }
        $rid = (int)$row['router_id'];
        if (!isset($routers[$rid])) {
            $legacy_held += $paid + $held; // router imefutwa baada ya ombi
            continue;
        }
        $routers[$rid]['paid_out'] = $paid;
        $routers[$rid]['held']     = $held;
    }
    $p->close();

    $totals = ['gross' => 0.0, 'fees' => 0.0, 'net' => 0.0, 'txn_count' => 0,
               'paid_out' => 0.0, 'held' => 0.0, 'legacy_held' => $legacy_held,
               'available' => 0.0];

    foreach ($routers as $rid => $b) {
        // Salio la router hii: net iliyoingia, kasoro kila kilichoshaombwa.
        $available = round($b['net'] - $b['paid_out'] - $b['held'], 2);
        $routers[$rid]['available'] = max(0.0, $available);

        $totals['gross']     += $b['gross'];
        $totals['fees']      += $b['fees'];
        $totals['net']       += $b['net'];
        $totals['txn_count'] += $b['txn_count'];
        $totals['paid_out']  += $b['paid_out'];
        $totals['held']      += $b['held'];
        $totals['available'] += $routers[$rid]['available'];
    }

    $totals['available'] = max(0.0, round($totals['available'] - $legacy_held, 2));

    return ['routers' => $routers, 'totals' => $totals];
}

/** Salio la router MOJA. Rudisha null kama router si ya mtumiaji huyu. */
function getRouterBalance($conn, int $user_id, int $router_id): ?array
{
    $all = getOwnerRouterBalances($conn, $user_id);
    return $all['routers'][$router_id] ?? null;
}

/**
 * Tengeneza ombi la cash-out la router MOJA.
 *
 * Ukaguzi wa salio unafanyika NDANI ya transaction, baada ya kushika row
 * ya mtumiaji kwa FOR UPDATE. Bila lock hiyo, mibofyo miwili ya haraka
 * (au tabo mbili) ingesoma salio LILELILE kabla hakuna ombi lililoingia,
 * na yote mawili yangepita - reseller angeomba mara mbili kiasi
 * anachomiliki. Lock inazifanya zipangane foleni: ya pili inasoma salio
 * likiwa tayari limepungua na inakataliwa.
 *
 * @return array ['ok' => bool, 'msg' => string, 'available' => float]
 */
function requestRouterPayout($conn, int $user_id, int $router_id, string $phone,
                             float $amount, float $min, float $max): array
{
    $conn->begin_transaction();
    try {
        // Foleni kwa kila mmiliki (siyo kwa kila router - mmiliki mmoja
        // hana sababu ya kuomba routers mbili kwa sekunde moja).
        $lk = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $lk->bind_param("i", $user_id);
        $lk->execute();
        $lk->get_result();
        $lk->close();

        // Thibitisha router ni YAKE (usalama - router_id inatoka kwa mteja)
        $own = $conn->prepare("SELECT router_label FROM mikrotik_configs WHERE router_id = ? AND user_id = ? LIMIT 1");
        $own->bind_param("ii", $router_id, $user_id);
        $own->execute();
        $owns = $own->get_result()->fetch_assoc();
        $own->close();

        if (!$owns) {
            $conn->rollback();
            return ['ok' => false, 'msg' => 'Router hii si yako.', 'available' => 0.0];
        }

        $bal       = getRouterBalance($conn, $user_id, $router_id);
        $available = (float)($bal['available'] ?? 0);

        if ($amount <= 0) {
            $conn->rollback();
            return ['ok' => false, 'msg' => 'Tafadhali weka kiasi sahihi cha fedha.', 'available' => $available];
        }
        if ($amount < $min) {
            $conn->rollback();
            return ['ok' => false, 'msg' => 'Kiasi cha chini kwa ombi moja ni TSh ' . number_format($min) . '.', 'available' => $available];
        }
        if ($amount > $max) {
            $conn->rollback();
            return ['ok' => false, 'msg' => 'Kiasi cha juu kwa ombi moja ni TSh ' . number_format($max) . '. Gawa ombi lako.', 'available' => $available];
        }
        // Ada ya Snippe (flat) inatoka kwenye salio la mmiliki, siyo kwa
        // Tech5G: mpokeaji anapata $amount KAMILI, salio linapungua jumla.
        $fee   = calculatePayoutFee();
        $jumla = payoutTotalCost($amount);

        if ($jumla > $available) {
            $conn->rollback();
            return ['ok' => false,
                    'msg' => 'Salio halitoshi. Kutoa TSh ' . number_format($amount, 2)
                           . ' kunahitaji TSh ' . number_format($jumla, 2)
                           . ' (pamoja na ada ya Snippe TSh ' . number_format($fee) . '), '
                           . 'lakini salio la router hii ni TSh ' . number_format($available, 2) . '.',
                    'available' => $available];
        }

        $ins = $conn->prepare(
            "INSERT INTO payout_requests (user_id, router_id, phone_number, amount, fee_amount, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $ins->bind_param("iisdd", $user_id, $router_id, $phone, $amount, $fee);
        $ins->execute();
        $ins->close();

        $conn->commit();

        return ['ok' => true,
                'msg' => 'Ombi lako la Cash Out la TSh ' . number_format($amount, 2)
                       . ' kwa ' . $owns['router_label'] . ' limepelekwa kwa Admin kikamilifu! '
                       . '(Jumla iliyokatwa: TSh ' . number_format($jumla, 2)
                       . ' - ikijumuisha ada ya Snippe TSh ' . number_format($fee) . ')',
                'available' => round($available - $jumla, 2)];

    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('balance_helper.php requestRouterPayout: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Hitilafu imetokea, ombi halikupokelewa. Jaribu tena.', 'available' => 0.0];
    }
}
