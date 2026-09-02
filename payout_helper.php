<?php
/**
 * payout_helper.php
 * ------------------------------------------------------------------
 * KUTOA PESA (cash-out ya reseller) kupitia Snippe Payouts API.
 *
 * Mtiririko kamili:
 *   1. cash_out.php   - reseller anaomba kwa ROUTER MOJA. Ombi lenyewe
 *                       ndilo linaloshikilia pesa (balance_helper.php
 *                       inalipunguza kwenye salio la router hiyo mara moja),
 *                       ombi linakuwa 'pending'.
 *   2. admin.php      - admin abonyeza "Thibitisha" -> sendPayoutToGateway()
 *                       inaituma Snippe. Hali inakuwa 'awaiting_approval'.
 *   3. Snippe         - wanachakata malipo kwenda mtandao wa mpokeaji.
 *   4. snippe_webhook.php (haraka) + poll_payouts.php (cron, kinga) -
 *                       zinathibitisha hali mpaka 'success' au 'failed'.
 *
 * MITANDAO: Snippe hutambua mtandao WENYEWE kutoka namba ya simu, hivyo
 * hatutumi jina la provider. Mitandao yote minne (Vodacom, Tigo/Yas,
 * Airtel, Halotel) inafanya kazi - ikiwemo Vodacom, ambayo gateway
 * iliyotangulia haikuweza kuilipa.
 *
 * ⚠️ ADA YA SNIPPE: Snippe hukata KIASI + ADA YAO kwenye salio LETU la
 * merchant. Ada hiyo ni ya kwetu kubeba - reseller anapokea kiasi kamili
 * alichoomba. Hivyo salio letu la Snippe lazima liwe kubwa kuliko jumla
 * ya maombi yanayosubiri.
 *
 * KANUNI YA MSINGI YA PESA ZINAZOTOKA: kamwe usirudishe salio ikiwa
 * HUJUI hali halisi ya malipo. Kurudisha salio kimakosa kunamaanisha
 * reseller ana pesa yake mkononi NA salio lake bado lipo - hasara mara
 * mbili. Ni bora ombi likwame likisubiri ukaguzi wa mkono.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/snippe_client.php';
require_once __DIR__ . '/error_logger.php';

/**
 * Rudisha salio la reseller na weka hali ya mwisho ya ombi.
 * Inatumika pale TU tunapojua kwa uhakika pesa HAIKUTOKA.
 *
 * TANGU 2026-08-22: salio HALIHIFADHIWI kwenye users.balance tena -
 * linakokotolewa kutoka payment_transactions.net_amount kasoro maombi
 * yaliyopo (balance_helper.php). Hivyo "kurudisha salio" ni kubadilisha
 * hali ya ombi kuwa 'failed'/'rejected' TU: mara hali inapobadilika,
 * ombi hilo halihesabiki tena kwenye vilivyoshikiliwa na pesa inarudi
 * YENYEWE kwenye salio la router husika.
 *
 * Faida: hakuna njia ya kuongeza salio mara mbili kwa ombi lilelile.
 * Ulinzi wa 'FOR UPDATE' + ukaguzi wa hali umebaki kwa sababu bado
 * hatutaki ombi lililofika mwisho likabadilishwa tena.
 */
function refundPayout($conn, int $payout_id, string $status, string $reason): bool
{
    $reason = mb_substr($reason, 0, 255);
    $conn->begin_transaction();
    try {
        // Shika row ili tusirudishe salio mara mbili kwa ombi lilelile
        $g = $conn->prepare("SELECT user_id, amount, status FROM payout_requests WHERE id = ? FOR UPDATE");
        $g->bind_param("i", $payout_id);
        $g->execute();
        $row = $g->get_result()->fetch_assoc();
        $g->close();

        if (!$row || in_array($row['status'], ['success', 'failed', 'rejected'], true)) {
            $conn->rollback();
            return false; // tayari imefika mwisho - usiguse salio tena
        }

        $u = $conn->prepare("UPDATE payout_requests SET status=?, fail_reason=?, updated_at=NOW() WHERE id=?");
        $u->bind_param("ssi", $status, $reason, $payout_id);
        $u->execute();
        $u->close();

        // Hakuna "UPDATE users SET balance = balance + ?" hapa tena - angalia
        // maelezo juu. UPDATE ya hali hapo juu ndiyo inayorudisha pesa.

        $conn->commit();
        return true;
    } catch (\Throwable $e) {
        $conn->rollback();
        logSystemError($conn, 'payout_helper.php', 'refundPayout imeshindikana: ' . $e->getMessage(),
            ['context' => ['payout_id' => $payout_id]]);
        return false;
    }
}

/**
 * Tuma ombi la cash-out kwenye Snippe. Inaitwa na admin.php baada ya
 * admin kuidhinisha.
 *
 * @return array ['ok'=>bool, 'status'=>string, 'message'=>string]
 */
function sendPayoutToGateway($conn, int $payout_id, int $admin_id): array
{
    // ── 1. DAI OMBI HILI (atomic) ──
    // external_id inawekwa HAPA, kabla ya kuita API, na ni UNIQUE. Hii
    // ndiyo inayozuia malipo mawili endapo admin atabofya mara mbili au
    // admin wawili watabofya kwa pamoja: mmoja tu ndiye atafanikiwa.
    $external_id = 'PO-' . strtoupper(bin2hex(random_bytes(6)));

    $claim = $conn->prepare(
        "UPDATE payout_requests
            SET status='awaiting_approval', external_id=?, approved_by=?, approved_at=NOW(), updated_at=NOW()
          WHERE id=? AND status='pending'"
    );
    $claim->bind_param("sii", $external_id, $admin_id, $payout_id);
    $claim->execute();
    $claimed = ($claim->affected_rows === 1);
    $claim->close();

    if (!$claimed) {
        return ['ok' => false, 'status' => 'unchanged',
                'message' => 'Ombi halijapatikana au tayari lilishachakatwa.'];
    }

    // ── 2. Taarifa za mpokeaji ──
    $q = $conn->prepare(
        "SELECT p.amount, p.phone_number, u.username, u.id AS user_id
           FROM payout_requests p JOIN users u ON u.id = p.user_id
          WHERE p.id = ? LIMIT 1"
    );
    $q->bind_param("i", $payout_id);
    $q->execute();
    $po = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$po) {
        return ['ok' => false, 'status' => 'awaiting_approval', 'message' => 'Ombi halijapatikana.'];
    }

    // ── 3. Ituma ──
    // external_id inatumika pia kama Idempotency-Key ya Snippe: hata kama
    // ombi hili litatumwa mara mbili (mfano timeout kisha jaribio jipya),
    // Snippe hulipa MARA MOJA tu.
    $res = snippeCreatePayout(
        $po['phone_number'],
        (float)$po['amount'],
        $external_id,
        $po['username'],
        'Cash-out Tech5G'
    );

    if ($res['ok']) {
        $g = $conn->prepare(
            "UPDATE payout_requests SET gateway_uuid=?, gateway_reference=?, updated_at=NOW() WHERE id=?"
        );
        $g->bind_param("ssi", $res['reference'], $res['reference'], $payout_id);
        $g->execute();
        $g->close();

        $ada = $res['fee'] !== null
             ? ' (ada ya Snippe: TSh ' . number_format($res['fee']) . ' - inatoka kwetu, siyo kwa reseller)'
             : '';

        return ['ok' => true, 'status' => 'awaiting_approval',
                'message' => 'Ombi limetumwa Snippe na linachakatwa. Pesa BADO haijathibitishwa kufika.' . $ada];
    }

    // ── 4. Imeshindikana: tofautisha "imekataliwa" na "hatujui" ──
    //
    // Hii ndiyo sehemu nyeti kuliko zote. Kurudisha salio kimakosa
    // kunamaanisha reseller ana pesa yake mkononi NA salio lake bado lipo.
    //
    // 'definitive' inatoka snippe_client.php: Snippe walipokea ombi na
    // wakalikataa kwa uamuzi (jibu lao lina error_code), hivyo HAKUNA
    // PESA ILIYOSONGA -> ni salama kurudisha salio.
    //
    // MUHIMU: hatutegemei msimbo wa HTTP hapa. Snippe hurudisha 500 kwa
    // "insufficient balance" - hiyo ni 500 YENYE UAMUZI. Kanuni ya zamani
    // ya "4xx = imekataliwa, 5xx = hatujui" ingelishikilia salio la
    // reseller milele kwa hitilafu ya kawaida kabisa ya salio letu.
    if ($res['definitive']) {
        refundPayout($conn, $payout_id, 'failed', $res['error']);
        logSystemError($conn, 'payout_helper.php', 'Cash-out imekataliwa: ' . $res['error'],
            ['user_id' => (int)$po['user_id'],
             'context' => ['payout_id' => $payout_id, 'http' => (int)($res['http'] ?? 0),
                           'ilitumwa' => (bool)($res['sent'] ?? false)]]);
        return ['ok' => false, 'status' => 'failed',
                'message' => $res['error'] . ' (salio limerudishwa - hakuna pesa iliyotoka)'];
    }

    // Mtandao umekatika au jibu halikueleweka: HATUJUI kama malipo
    // yameanzishwa. Usirudishe salio. Ombi linabaki 'awaiting_approval'
    // likisubiri ukaguzi - na poll_payouts.php itaendelea kuuliza.
    $note = 'HALI HAIJULIKANI - kagua dashboard ya Snippe kabla ya kujaribu tena: ' . $res['error'];
    $u = $conn->prepare("UPDATE payout_requests SET fail_reason=?, updated_at=NOW() WHERE id=?");
    $nt = mb_substr($note, 0, 255);
    $u->bind_param("si", $nt, $payout_id);
    $u->execute();
    $u->close();

    logSystemError($conn, 'payout_helper.php', $note,
        ['user_id' => (int)$po['user_id'], 'context' => ['payout_id' => $payout_id, 'external_id' => $external_id]]);

    return ['ok' => false, 'status' => 'awaiting_approval',
            'message' => 'Mawasiliano na gateway yamekatika. Salio HALIJARUDISHWA kwa makusudi - '
                       . 'kagua Snippe kama malipo yameanzishwa (rejea: ' . $external_id . ').'];
}

/**
 * Uliza gateway hali ya ombi moja lililo 'awaiting_approval' na ukamilishe.
 * Inaitwa na poll_payouts.php (cron).
 *
 * @return string hali mpya ('awaiting_approval' kama bado haijabadilika)
 */
function pollPayoutStatus($conn, array $payout): string
{
    if (empty($payout['gateway_reference'])) {
        return 'awaiting_approval'; // haikuwahi kufika gateway - ukaguzi wa mkono
    }

    $hali = snippePayoutStatus($payout['gateway_reference']);
    if (!$hali['ok']) {
        return 'awaiting_approval'; // hitilafu ya mtandao - jaribu tena baadaye
    }

    $id = (int)$payout['id'];

    if ($hali['status'] === 'success') {
        $u = $conn->prepare("UPDATE payout_requests SET status='success', updated_at=NOW() WHERE id=? AND status='awaiting_approval'");
        $u->bind_param("i", $id);
        $u->execute();
        $u->close();
        return 'success';
    }

    if ($hali['status'] === 'failed') {
        // Snippe hurudisha pesa kwenye salio LETU la merchant kiotomatiki;
        // sisi tunarudisha kwenye salio la reseller ndani ya mfumo wetu.
        refundPayout($conn, $id, 'failed', 'Malipo yameshindikana Snippe.');
        return 'failed';
    }

    return 'awaiting_approval';
}

/**
 * Mjulishe reseller kuwa pesa IMEFIKA kweli.
 *
 * Iko hapa (siyo ndani ya poll_payouts.php) kwa sababu njia MBILI sasa
 * zinaweza kukamilisha ombi: cron na snippe_webhook.php.
 * Ujumbe ni mmoja, sehemu moja.
 */
function payoutNotifyResellerSuccess($conn, int $user_id, float $amount): bool
{
    $u = $conn->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
    $u->bind_param("i", $user_id);
    $u->execute();
    $mtu = $u->get_result()->fetch_assoc();
    $u->close();

    if (!$mtu || empty($mtu['email'])) {
        return false;
    }

    require_once __DIR__ . '/email_helper.php';

    return (bool)sendStatusEmail(
        $mtu['email'],
        $mtu['username'],
        'Pesa Yako Imetumwa! 💸',
        'Habari <strong>' . htmlspecialchars($mtu['username'], ENT_QUOTES) . '</strong>,<br><br>'
        . 'Cash Out yako ya <strong>TSh ' . number_format($amount, 2) . '</strong> '
        . 'imetumwa kikamilifu kwenye namba yako ya simu.<br><br>'
        . 'Asante kwa kuendelea kufanya kazi na Tech 5G!'
    );
}
