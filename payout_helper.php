<?php
/**
 * payout_helper.php
 * ------------------------------------------------------------------
 * KUTOA PESA (cash-out ya reseller) kupitia Dalipay Disbursements API.
 *
 * Mtiririko kamili:
 *   1. cash_out.php   - reseller anaomba. Salio lake linakatwa MARA MOJA
 *                       (linashikiliwa), ombi linakuwa 'pending'.
 *   2. admin.php      - admin abonyeza "Thibitisha" -> sendPayoutToGateway()
 *                       inaituma Dalipay. Hali inakuwa 'awaiting_approval'.
 *   3. Dalipay        - operator wao anaidhinisha, kisha pesa inatumwa.
 *   4. poll_payouts.php (cron) - inauliza hali kila baada ya muda mpaka
 *                       'success' au 'failed'/'rejected'.
 *
 * KANUNI YA MSINGI YA PESA ZINAZOTOKA: kamwe usirudishe salio ikiwa
 * HUJUI hali halisi ya malipo. Kurudisha salio kimakosa kunamaanisha
 * reseller ana pesa yake mkononi NA salio lake bado lipo - hasara mara
 * mbili. Ni bora ombi likwame likisubiri ukaguzi wa mkono.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/dalipay_client.php';
require_once __DIR__ . '/error_logger.php';

/**
 * Rudisha salio la reseller na weka hali ya mwisho ya ombi.
 * Inatumika pale TU tunapojua kwa uhakika pesa HAIKUTOKA.
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

        $r = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $r->bind_param("di", $row['amount'], $row['user_id']);
        $r->execute();
        $r->close();

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
 * Tuma ombi la cash-out kwenye Dalipay. Inaitwa na admin.php baada ya
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
    $res = dalipayCreateDisbursement(
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
        $g->bind_param("ssi", $res['uuid'], $res['reference'], $payout_id);
        $g->execute();
        $g->close();

        return ['ok' => true, 'status' => 'awaiting_approval',
                'message' => 'Ombi limetumwa Dalipay na linasubiri idhini yao. Pesa BADO haijatumwa.'];
    }

    // ── 4. Imeshindikana: tofautisha "imekataliwa" na "hatujui" ──
    $http = (int)($res['http'] ?? 0);

    if ($http >= 400 && $http < 500) {
        // Gateway imekataa kwa uhakika (mfano salio la merchant halitoshi,
        // namba si sahihi, KYC). Hakuna malipo yaliyoanzishwa -> rudisha salio.
        refundPayout($conn, $payout_id, 'failed', $res['error']);
        logSystemError($conn, 'payout_helper.php', 'Disbursement imekataliwa: ' . $res['error'],
            ['user_id' => (int)$po['user_id'], 'context' => ['payout_id' => $payout_id, 'http' => $http]]);
        return ['ok' => false, 'status' => 'failed',
                'message' => 'Imekataliwa na gateway: ' . $res['error'] . ' (salio limerudishwa)'];
    }

    // Mtandao umekatika / 5xx: HATUJUI kama malipo yameanzishwa.
    // Usirudishe salio. Ombi linabaki 'awaiting_approval' likisubiri ukaguzi.
    $note = 'HALI HAIJULIKANI - kagua dashboard ya Dalipay kabla ya kujaribu tena: ' . $res['error'];
    $u = $conn->prepare("UPDATE payout_requests SET fail_reason=?, updated_at=NOW() WHERE id=?");
    $nt = mb_substr($note, 0, 255);
    $u->bind_param("si", $nt, $payout_id);
    $u->execute();
    $u->close();

    logSystemError($conn, 'payout_helper.php', $note,
        ['user_id' => (int)$po['user_id'], 'context' => ['payout_id' => $payout_id, 'external_id' => $external_id]]);

    return ['ok' => false, 'status' => 'awaiting_approval',
            'message' => 'Mawasiliano na gateway yamekatika. Salio HALIJARUDISHWA kwa makusudi - '
                       . 'kagua Dalipay kama malipo yameanzishwa (rejea: ' . $external_id . ').'];
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

    $hali = dalipayDisbursementStatus($payout['gateway_reference']);
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

    if ($hali['status'] === 'failed' || $hali['status'] === 'rejected') {
        // Dalipay hurudisha pesa kwenye salio LETU la merchant; sisi
        // tunarudisha kwenye salio la reseller ndani ya mfumo wetu.
        refundPayout($conn, $id, $hali['status'],
            $hali['status'] === 'rejected' ? 'Imekataliwa na Dalipay.' : 'Malipo yameshindikana Dalipay.');
        return $hali['status'];
    }

    return 'awaiting_approval';
}
