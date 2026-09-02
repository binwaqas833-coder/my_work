<?php
/**
 * snippe_client.php
 * ------------------------------------------------------------------
 * Mteja (client) MMOJA wa Snippe — https://snippe.sh (api.snippe.sh)
 *
 * Inachukua nafasi ya azampay_client.php (na dalipay_client.php kabla
 * yake). Zote mbili zimeondolewa.
 *
 * Inatumika na NJIA TATU:
 *   1. lipia.php                      — mteja wa hotspot ananunua vocha
 *   2. start_subscription_payment.php — reseller analipia plan yake
 *   3. payout_helper.php              — cash-out ya reseller
 *
 * Faili hii HAIGUSI database kabisa. Kila function inarudisha
 * ['ok' => bool, ...] — hakuna exception inayotoka nje, ili ukurasa wa
 * malipo usivunjike kwa hitilafu ya mtandao.
 *
 * SIRI (API key, webhook secret) hazipo hapa wala kwenye git —
 * zinatoka env kupitia config.php. Angalia DEPLOY_SNIPPE.md.
 *
 * ── KWA NINI SNIPPE NI RAHISI KULIKO ZILIZOTANGULIA ──
 *
 *  1. HAKUNA TOKEN YA KUBADILISHANA. API key moja (snp_...) kwenye
 *     header ya Authorization. Hakuna cache, hakuna kuisha muda,
 *     hakuna kuomba upya baada ya 401.
 *
 *  2. HALI YA MALIPO INAULIZIKA: GET /v1/payments/{reference}.
 *     AzamPay hawakuwa na hii, hivyo callback ilikuwa njia PEKEE.
 *     Sasa poll ya ukurasa wa mteja ni KINGA tena: webhook ikipotea,
 *     mteja aliyesimama pale bado anaunganishwa.
 *
 *  3. MTANDAO UNATAMBULIWA NA SNIPPE YENYEWE kutoka namba ya simu.
 *     Hatutumi jina la provider wala 'bankName' — hivyo hakuna ramani
 *     ya kudumisha, na cash-out inafanya kazi kwa mitandao yote
 *     (pamoja na Vodacom, ambayo AzamPay walikuwa hawaiwezi).
 *
 *  4. IDEMPOTENCY IMEJENGWA NDANI. Tunatuma rejea yetu (TXN-.../PO-...)
 *     kama Idempotency-Key, hivyo ombi lililorudiwa HALILIPI mara mbili.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

// ══════════════════════════════════════════════════════════════════
//  1. NAMBA ZA SIMU
// ══════════════════════════════════════════════════════════════════

/** Rudisha namba kwa muundo wa ndani: 0XXXXXXXXX */
function snippeLocalPhone(string $namba): string
{
    $namba = preg_replace('/[^0-9]/', '', $namba);
    if (strpos($namba, '255') === 0) {
        $namba = '0' . substr($namba, 3);
    }
    return $namba;
}

/** Muundo unaotakiwa na Snippe: 255XXXXXXXXX (bila '+'). */
function snippeMsisdn(string $namba): string
{
    return '255' . ltrim(snippeLocalPhone($namba), '0');
}

/**
 * Je namba hii ni ya mtandao wa mobile money unaotambuliwa?
 *
 * Snippe HAITUHITAJI kutaja provider — wanaitambua wenyewe kutoka
 * namba. Ukaguzi huu ni wa KWETU tu: kumzuia mteja asianzishe muamala
 * kwa namba ambayo hakika itakataliwa (mfano TTCL, ambayo Snippe
 * hawaiorodheshi popote), na kumpa ujumbe wazi mara moja.
 *
 * Inatumika kwa NJIA ZOTE MBILI (kulipa na kutoa): Snippe wanaunga
 * mkono mitandao ile ile pande zote.
 *
 * @return string|null jina la kuonyesha, au null kama haitambuliki
 */
function snippeNetworkName(string $namba): ?string
{
    $ramani = [
        '074' => 'Vodacom (M-Pesa)',      '075' => 'Vodacom (M-Pesa)', '076' => 'Vodacom (M-Pesa)',
        '065' => 'Yas/Tigo (Mixx by Yas)','067' => 'Yas/Tigo (Mixx by Yas)',
        '071' => 'Yas/Tigo (Mixx by Yas)','077' => 'Yas/Tigo (Mixx by Yas)',
        '068' => 'Airtel Money',          '069' => 'Airtel Money',     '078' => 'Airtel Money',
        '061' => 'Halotel (HaloPesa)',    '062' => 'Halotel (HaloPesa)',
        // '073' (TTCL) HAIKUBALIWI — Snippe hawaiorodheshi popote.
    ];

    return $ramani[substr(snippeLocalPhone($namba), 0, 3)] ?? null;
}

/**
 * Jenga sehemu ya 'customer'. Snippe INALAZIMISHA firstname, lastname
 * na email kwa kila malipo.
 *
 * MTEJA WA HOTSPOT hatupi jina wala barua pepe — anatoa namba ya simu
 * tu, na ndiyo maana flow hiyo ni ya haraka. Hivyo tunatengeneza
 * thamani za kujaza nafasi (placeholder) kutoka namba yake.
 * Barua pepe ni ya kiufundi (haitumiki kutuma chochote) — ipo ili
 * ombi likubalike tu.
 *
 * Pale tunapokuwa na taarifa HALISI (mfano reseller analipia plan
 * yake), tunazitumia hizo badala yake.
 */
function snippeCustomer(string $namba, string $jina = '', string $email = ''): array
{
    $local = snippeLocalPhone($namba);

    $jina = trim($jina);
    if ($jina !== '') {
        $sehemu    = preg_split('/\s+/', $jina, 2);
        $firstname = $sehemu[0];
        $lastname  = $sehemu[1] ?? 'Tech5G';
    } else {
        $firstname = 'Mteja';
        $lastname  = substr($local, -4) ?: 'Hotspot';
    }

    return [
        'firstname' => mb_substr($firstname, 0, 60),
        'lastname'  => mb_substr($lastname, 0, 60),
        'email'     => $email !== '' ? $email : ($local . '@' . SNIPPE_PLACEHOLDER_EMAIL_DOMAIN),
    ];
}

// ══════════════════════════════════════════════════════════════════
//  2. HTTP
// ══════════════════════════════════════════════════════════════════

/**
 * Ombi kwa Snippe. Inarudisha:
 *   ['ok'=>bool, 'sent'=>bool, 'http'=>int, 'data'=>?array,
 *    'error_code'=>string, 'error'=>string, 'definitive'=>bool]
 *
 * ── 'definitive' NDIYO SEHEMU MUHIMU KULIKO ZOTE ──
 * Inaonyesha kwamba Snippe WALIPOKEA ombi na WAKALIKATAA kwa uamuzi
 * wao — yaani HAKUNA PESA ILIYOSONGA. payout_helper.php hutumia
 * bendera hii kujua kwamba ni SALAMA kurudisha salio la reseller mara
 * moja. Ikiwa false, HATUJUI (mtandao ulikatika, jibu halikueleweka)
 * na salio HALIRUDISHWI — ombi linasubiri ukaguzi wa mkono.
 *
 * Kipimo: jibu lenye muundo wa error wa Snippe (error_code) linamaanisha
 * API yao ilifanya uamuzi. MUHIMU: Snippe hurudisha 500 kwa
 * "insufficient balance" — hiyo ni 500 YENYE UAMUZI, siyo hitilafu ya
 * seva. Ndiyo maana hatutegemei msimbo wa HTTP peke yake.
 */
function snippeRequest(string $method, string $path, ?array $payload = null, array $extra_headers = [], int $timeout = 30): array
{
    if (!SNIPPE_ENABLED) {
        return ['ok' => false, 'sent' => false, 'http' => 0, 'data' => null,
                'error_code' => 'not_configured', 'definitive' => true,
                'error' => 'Gateway haijasanidiwa (SNIPPE_API_KEY haijawekwa).'];
    }

    $ch = curl_init(SNIPPE_BASE_URL . '/' . ltrim($path, '/'));

    $headers = array_merge([
        'Authorization: Bearer ' . SNIPPE_API_KEY,
        'Accept: application/json',
    ], $extra_headers);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);

    if ($raw === false) {
        // Ombi halikufika (au jibu halikurudi). HATUJUI hali halisi.
        return ['ok' => false, 'sent' => false, 'http' => 0, 'data' => null,
                'error_code' => 'network', 'definitive' => false,
                'error' => 'Mtandao: ' . $cerr];
    }

    $body = json_decode($raw, true);

    if (!is_array($body)) {
        // Jibu lipo lakini halieleweki (mfano ukurasa wa HTML wa proxy).
        // Ombi LILIFIKA mahali fulani — hatuwezi kusema halikuchakatwa.
        return ['ok' => false, 'sent' => true, 'http' => $http, 'data' => null,
                'error_code' => 'bad_response', 'definitive' => false,
                'error' => 'Jibu la gateway halikueleweka (HTTP ' . $http . ').'];
    }

    $ok = ($http >= 200 && $http < 300) && (($body['status'] ?? '') === 'success');

    if ($ok) {
        return ['ok' => true, 'sent' => true, 'http' => $http,
                'data' => $body['data'] ?? [], 'error_code' => '',
                'definitive' => true, 'error' => ''];
    }

    $error_code = (string)($body['error_code'] ?? '');
    $ujumbe     = (string)($body['message'] ?? '');

    return [
        'ok'         => false,
        'sent'       => true,
        'http'       => $http,
        'data'       => null,
        'error_code' => $error_code,
        // error_code ikiwepo, Snippe walifanya uamuzi -> hakuna pesa iliyosonga.
        'definitive' => $error_code !== '',
        'error'      => $ujumbe !== '' ? $ujumbe : ('Gateway imekataa ombi (HTTP ' . $http . ').'),
    ];
}

// ══════════════════════════════════════════════════════════════════
//  3. MALIPO YANAYOINGIA (vocha + subscription)
// ══════════════════════════════════════════════════════════════════

/**
 * Anzisha malipo — hii ndiyo inayosababisha USSD prompt kwenye simu
 * ya mteja.
 *
 * @param string $namba_simu  Muundo wa ndani: 07XXXXXXXX
 * @param float  $kiasi       TZS
 * @param string $external_id Rejea YETU (TXN-... au SUB-...)
 *
 * @return array ['ok'=>bool, 'sent'=>bool, 'reference'=>?string,
 *                'status'=>?string, 'definitive'=>bool, 'error'=>string]
 */
function snippeCreatePayment(string $namba_simu, float $kiasi, string $external_id, string $jina = '', string $email = ''): array
{
    $kosa = function (string $ujumbe): array {
        return ['ok' => false, 'sent' => false, 'reference' => null, 'status' => null,
                'definitive' => true, 'error' => $ujumbe];
    };

    if (snippeNetworkName($namba_simu) === null) {
        return $kosa('Mtandao wa namba hii hauwezi kutumika kwa malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel.');
    }

    // Idempotency-Key ya Snippe: herufi 30 au chini. Rejea zetu ni ~16.
    if (strlen($external_id) > 30) {
        return $kosa('Rejea ya muamala ni ndefu kupita kiasi.');
    }

    $kiasi_int = (int)round($kiasi);
    if ($kiasi_int < SNIPPE_MIN_AMOUNT) {
        return $kosa('Kiasi cha chini kinachokubalika ni TSh ' . number_format(SNIPPE_MIN_AMOUNT) . '.');
    }

    $payload = [
        'payment_type' => 'mobile',
        'details'      => ['amount' => $kiasi_int, 'currency' => 'TZS'],
        'phone_number' => snippeMsisdn($namba_simu),
        'customer'     => snippeCustomer($namba_simu, $jina, $email),
        'webhook_url'  => snippeWebhookUrl(),
        // Rejea YETU inasafiri hapa. MUHIMU: hii ndiyo njia ya kurudi
        // kwenye muamala wetu endapo jibu la ombi hili litapotea njiani
        // (tusipopata 'reference' ya Snippe kuihifadhi).
        'metadata'     => ['transaction_id' => $external_id],
    ];

    $res = snippeRequest('POST', '/v1/payments', $payload, ['Idempotency-Key: ' . $external_id]);

    if (!$res['ok']) {
        return ['ok' => false, 'sent' => $res['sent'], 'reference' => null, 'status' => null,
                'definitive' => $res['definitive'], 'error' => $res['error']];
    }

    return [
        'ok'         => true,
        'sent'       => true,
        'reference'  => $res['data']['reference'] ?? null,
        'status'     => snippeNormalizeStatus((string)($res['data']['status'] ?? 'pending')),
        'definitive' => true,
        'error'      => '',
    ];
}

/**
 * Uliza hali ya malipo. HII NDIYO KINGA iliyokosekana kwa AzamPay:
 * webhook ikipotea, poll ya ukurasa wa mteja bado inamuunganisha.
 *
 * @return array ['ok'=>bool, 'status'=>'pending'|'success'|'failed'|null, 'error'=>string]
 */
function snippePaymentStatus(string $reference): array
{
    $res = snippeRequest('GET', '/v1/payments/' . rawurlencode($reference), null, [], 20);

    if (!$res['ok']) {
        return ['ok' => false, 'status' => null, 'error' => $res['error']];
    }

    return ['ok' => true,
            'status' => snippeNormalizeStatus((string)($res['data']['status'] ?? '')),
            'error' => ''];
}

// ══════════════════════════════════════════════════════════════════
//  4. KUTOA PESA (cash-out ya reseller)
// ══════════════════════════════════════════════════════════════════

/**
 * Tuma pesa kwa mpokeaji.
 *
 * ⚠️ ADA: Snippe hukata KIASI + ADA YAO kwenye salio letu la merchant
 * ('total' kwenye jibu). Ada hiyo ni YETU kubeba — HAIMPUNGUZII
 * reseller kitu; anapokea kiasi kamili alichoomba. Angalia
 * snippePayoutFee() ili kuijua kabla.
 *
 * ⚠️ 'definitive' => true inamaanisha Snippe wamekataa kwa uamuzi
 * (hakuna pesa iliyosonga) -> ni SALAMA kurudisha salio la reseller.
 * false inamaanisha HATUJUI -> KAMWE usirudishe salio.
 *
 * @param string $external_id Rejea YETU (PO-...), herufi 30 au chini
 *
 * @return array ['ok'=>bool, 'sent'=>bool, 'reference'=>?string,
 *                'status'=>?string, 'fee'=>?int, 'total'=>?int,
 *                'definitive'=>bool, 'error'=>string]
 */
function snippeCreatePayout(string $namba_simu, float $kiasi, string $external_id, string $jina_mpokeaji = '', string $maelezo = ''): array
{
    $kosa = function (string $ujumbe): array {
        return ['ok' => false, 'sent' => false, 'reference' => null, 'status' => null,
                'fee' => null, 'total' => null, 'definitive' => true, 'error' => $ujumbe];
    };

    if (snippeNetworkName($namba_simu) === null) {
        return $kosa('Mtandao wa namba hii hauwezi kupokea malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel.');
    }
    if (strlen($external_id) > 30) {
        return $kosa('Rejea ya muamala ni ndefu kupita kiasi.');
    }

    $kiasi_int = (int)round($kiasi);
    if ($kiasi_int < SNIPPE_MIN_AMOUNT) {
        return $kosa('Kiasi cha chini kinachokubalika ni TSh ' . number_format(SNIPPE_MIN_AMOUNT) . '.');
    }

    $payload = [
        'amount'          => $kiasi_int,
        'channel'         => 'mobile',
        'recipient_phone' => snippeMsisdn($namba_simu),
        'recipient_name'  => $jina_mpokeaji !== '' ? mb_substr($jina_mpokeaji, 0, 60) : 'Mpokeaji',
        'narration'       => $maelezo !== '' ? mb_substr($maelezo, 0, 120) : 'Cash-out Tech5G',
        'webhook_url'     => snippeWebhookUrl(),
        'metadata'        => ['payout_id' => $external_id],
    ];

    $res = snippeRequest('POST', '/v1/payouts/send', $payload, ['Idempotency-Key: ' . $external_id]);

    if (!$res['ok']) {
        return ['ok' => false, 'sent' => $res['sent'], 'reference' => null, 'status' => null,
                'fee' => null, 'total' => null,
                'definitive' => $res['definitive'], 'error' => $res['error']];
    }

    $d = $res['data'];
    return [
        'ok'         => true,
        'sent'       => true,
        'reference'  => $d['reference'] ?? null,
        'status'     => snippeNormalizeStatus((string)($d['status'] ?? 'pending')),
        'fee'        => isset($d['fees']['value'])  ? (int)$d['fees']['value']  : null,
        'total'      => isset($d['total']['value']) ? (int)$d['total']['value'] : null,
        'definitive' => true,
        'error'      => '',
    ];
}

/**
 * Hali ya cash-out.
 *
 * @return array ['ok'=>bool, 'status'=>'pending'|'success'|'failed'|null, 'error'=>string]
 */
function snippePayoutStatus(string $reference): array
{
    $res = snippeRequest('GET', '/v1/payouts/' . rawurlencode($reference), null, [], 20);

    if (!$res['ok']) {
        return ['ok' => false, 'status' => null, 'error' => $res['error']];
    }

    return ['ok' => true,
            'status' => snippeNormalizeStatus((string)($res['data']['status'] ?? '')),
            'error' => ''];
}

/**
 * Ada ya cash-out kwa kiasi fulani (kabla ya kutuma).
 *
 * @return array ['ok'=>bool, 'fee'=>?int, 'total'=>?int, 'error'=>string]
 */
function snippePayoutFee(float $kiasi): array
{
    $res = snippeRequest('GET', '/v1/payouts/fee?amount=' . (int)round($kiasi), null, [], 15);

    if (!$res['ok']) {
        return ['ok' => false, 'fee' => null, 'total' => null, 'error' => $res['error']];
    }

    return [
        'ok'    => true,
        'fee'   => isset($res['data']['fee_amount'])   ? (int)$res['data']['fee_amount']   : null,
        'total' => isset($res['data']['total_amount']) ? (int)$res['data']['total_amount'] : null,
        'error' => '',
    ];
}

/**
 * Salio letu la merchant kwenye Snippe (siyo salio la reseller!).
 *
 * @return array ['ok'=>bool, 'available'=>?int, 'error'=>string]
 */
function snippeBalance(): array
{
    $res = snippeRequest('GET', '/v1/payments/balance', null, [], 15);

    if (!$res['ok']) {
        return ['ok' => false, 'available' => null, 'error' => $res['error']];
    }

    return ['ok' => true,
            'available' => isset($res['data']['available']['value']) ? (int)$res['data']['available']['value'] : null,
            'error' => ''];
}

// ══════════════════════════════════════════════════════════════════
//  5. HALI + WEBHOOK
// ══════════════════════════════════════════════════════════════════

/**
 * Geuza hali za Snippe kuwa maneno YETU MATATU.
 *
 * Snippe: pending | completed | failed | voided | expired  (malipo)
 *         pending | completed | failed | reversed          (cash-out)
 *
 * 'reversed' inahesabiwa 'failed': pesa imerudi kwetu, hivyo ombi
 * halikufanikiwa — na kwa upande wa reseller salio linarudi.
 *
 * Hali TUSIYOIJUA inarudi 'pending' kwa makusudi: hatumhukumu mteja
 * kwa sababu tu jina la hali halifahamiki.
 */
function snippeNormalizeStatus(string $ghafi): string
{
    $s = strtolower(trim($ghafi));

    if (in_array($s, ['completed', 'complete', 'success', 'successful', 'settled', 'paid'], true)) {
        return 'success';
    }
    if (in_array($s, ['failed', 'failure', 'fail', 'voided', 'void', 'expired',
                      'reversed', 'cancelled', 'canceled', 'declined'], true)) {
        return 'failed';
    }
    return 'pending';
}

/** URL ya webhook tunayowapa Snippe kwa kila ombi. */
function snippeWebhookUrl(): string
{
    return APP_BASE_URL . '/snippe_webhook.php';
}

/**
 * Thibitisha saini ya webhook.
 *
 *   X-Webhook-Signature = hex(HMAC-SHA256(secret, "{timestamp}.{raw_body}"))
 *
 * MUHIMU: $rawBody LAZIMA iwe bytes HALISI zilizopokelewa (php://input).
 * Ukiipitisha kwenye json_decode kisha json_encode, nafasi na mpangilio
 * wa funguo hubadilika na saini huvunjika.
 *
 * Ukaguzi wa muda (dakika 5) unazuia mtu kurudia (replay) webhook halali
 * ya zamani. hash_equals() inazuia timing attack.
 */
function snippeVerifyWebhook(string $rawBody, string $timestamp, string $signature): bool
{
    if (SNIPPE_WEBHOOK_SECRET === '' || $signature === '' || $timestamp === '') {
        return false;
    }

    if (!ctype_digit($timestamp)) {
        return false;
    }

    // Kukataa za zamani (replay) NA za baadaye sana (saa isiyolingana).
    $umri = time() - (int)$timestamp;
    if ($umri > SNIPPE_WEBHOOK_MAX_AGE || $umri < -SNIPPE_WEBHOOK_MAX_AGE) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, SNIPPE_WEBHOOK_SECRET);

    return hash_equals($expected, $signature);
}
