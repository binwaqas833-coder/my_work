<?php
/**
 * dalipay_client.php
 * ------------------------------------------------------------------
 * Mteja (client) mmoja wa ISP Gateway ya Dalipay — https://app.dalipay.co.tz
 *
 * Inatumika na NJIA MBILI za malipo yanayoingia:
 *   1. lipia.php                     — mteja wa hotspot ananunua vocha
 *   2. start_subscription_payment.php — reseller analipia plan yake
 *
 * Faili hii HAIGUSI database kabisa. Kazi yake ni kuongea na gateway tu
 * na kurudisha array iliyo rahisi kutumika. Kila function inarudisha
 * ['ok' => bool, ...] — hakuna exception inayotoka nje, ili ukurasa wa
 * malipo usivunjike kwa sababu ya hitilafu ya mtandao.
 *
 * SIRI (keys) hazipo hapa wala kwenye git — zinatoka env kupitia
 * config.php. Angalia DEPLOY_DALIPAY.md kwa jinsi ya kuziweka kwenye VPS.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

/**
 * Ramani ya prefix ya simu -> jina la provider LINALOKUBALIWA NA GATEWAY.
 *
 * MUHIMU: majina haya ni ya gateway (Airtel, Tigo, Azampesa, Halopesa,
 * Mpesa) — SIYO majina ya kuonyesha kwa mteja. Majina ya kuonyesha yako
 * kwenye tambuaMtandaoWaSimuHelper() ndani ya payment_helper.php.
 *
 * 'Azampesa' haipo hapa kwa makusudi: ni wallet, siyo mtandao wa simu,
 * hivyo haiwezi kutambuliwa kwa prefix.
 */
function dalipayProviderFromPhone(string $namba): ?string
{
    $namba = preg_replace('/[^0-9]/', '', $namba);
    if (strpos($namba, '255') === 0) {
        $namba = '0' . substr($namba, 3);
    }

    $ramani = [
        '074' => 'Mpesa',    '075' => 'Mpesa',    '076' => 'Mpesa',      // Vodacom
        '065' => 'Tigo',     '067' => 'Tigo',     '071' => 'Tigo',       // Yas/Tigo
        '077' => 'Tigo',
        '068' => 'Airtel',   '069' => 'Airtel',   '078' => 'Airtel',     // Airtel
        '061' => 'Halopesa', '062' => 'Halopesa',                        // Halotel
        // '073' (TTCL) HAIKUBALIWI na gateway — inarudisha null hapa chini.
    ];

    return $ramani[substr($namba, 0, 3)] ?? null;
}

/**
 * Ombi la msingi kwenda gateway. Inarudisha:
 *   ['ok' => bool, 'http' => int, 'body' => array|null, 'error' => string]
 */
function dalipayRequest(string $method, string $path, ?array $payload = null, int $timeout = 30): array
{
    if (!DALIPAY_ENABLED) {
        return ['ok' => false, 'http' => 0, 'body' => null,
                'error' => 'Gateway haijasanidiwa (DALIPAY_PUBLIC_KEY/DALIPAY_SECRET_KEY hazijawekwa).'];
    }

    $url = DALIPAY_BASE_URL . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    $headers = [
        'X-Public-Key: ' . DALIPAY_PUBLIC_KEY,
        'X-Secret-Key: ' . DALIPAY_SECRET_KEY,
        'Accept: application/json',
    ];

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
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Mtandao: ' . $cerr];
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'http' => $http, 'body' => null,
                'error' => 'Jibu la gateway halikueleweka (HTTP ' . $http . ').'];
    }

    $ok = ($http >= 200 && $http < 300) && !empty($body['success']);

    return [
        'ok'    => $ok,
        'http'  => $http,
        'body'  => $body,
        'error' => $ok ? '' : ($body['message'] ?? ('Gateway imekataa ombi (HTTP ' . $http . ').')),
    ];
}

/**
 * Anzisha collection — hii ndiyo inayosababisha USSD prompt kutokea
 * kwenye simu ya mteja.
 *
 * @param string $namba_simu  Muundo wa ndani: 07XXXXXXXX
 * @param float  $kiasi       TZS
 * @param string $external_id Rejea yetu (TXN-... au SUB-...), isizidi herufi 30
 *
 * @return array ['ok'=>bool, 'uuid'=>?string, 'reference'=>?string,
 *                'status'=>?string, 'error'=>string]
 */
function dalipayCreateCollection(string $namba_simu, float $kiasi, string $external_id, string $jina_mteja = ''): array
{
    $provider = dalipayProviderFromPhone($namba_simu);
    if ($provider === null) {
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null,
                'error' => 'Mtandao wa namba hii hauwezi kutumika kwa malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel.'];
    }

    if (strlen($external_id) > 30) {
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null,
                'error' => 'Rejea ya muamala ni ndefu kupita kiasi.'];
    }

    $payload = [
        'account_number' => $namba_simu,
        'amount'         => (int)round($kiasi),   // gateway inataka whole units (TZS)
        'currency'       => 'TZS',
        'provider'       => $provider,
        'external_id'    => $external_id,
    ];
    if ($jina_mteja !== '') {
        $payload['customer_name'] = mb_substr($jina_mteja, 0, 60);
    }

    $res = dalipayRequest('POST', '/collections', $payload);

    if (!$res['ok']) {
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null, 'error' => $res['error']];
    }

    $data = $res['body']['data'] ?? [];

    return [
        'ok'        => true,
        'uuid'      => $data['uuid']      ?? null,
        'reference' => $data['reference'] ?? null,
        'status'    => $data['status']    ?? 'processing',
        'error'     => '',
    ];
}

/**
 * Angalia hali ya collection kwenye gateway.
 *
 * @return array ['ok'=>bool, 'status'=>'processing'|'success'|'failed'|null, 'error'=>string]
 */
function dalipayCollectionStatus(string $uuid): array
{
    $res = dalipayRequest('GET', '/collections/' . rawurlencode($uuid) . '/status', null, 15);

    if (!$res['ok']) {
        return ['ok' => false, 'status' => null, 'error' => $res['error']];
    }

    return [
        'ok'     => true,
        'status' => $res['body']['data']['status'] ?? null,
        'error'  => '',
    ];
}

/**
 * Anzisha disbursement (KUTOA PESA) — malipo kutoka salio letu la Dalipay
 * kwenda akaunti ya mobile money ya mpokeaji.
 *
 * MUHIMU (production): jibu ni 202 na status 'awaiting_approval'. Pesa
 * inashikiliwa MARA MOJA kwenye salio letu, lakini HAIJATUMWA bado -
 * inasubiri operator wa Dalipay kuidhinisha. Hivyo "imeidhinishwa" kwetu
 * SIYO "mteja amepokea pesa". Hali halisi hufuatiliwa kwa
 * dalipayDisbursementStatus() (hakuna webhook ya disbursement).
 *
 * @param string $external_id Rejea yetu (PO-...), isizidi herufi 30
 *
 * @return array ['ok'=>bool, 'uuid'=>?string, 'reference'=>?string,
 *                'status'=>?string, 'error'=>string]
 */
function dalipayCreateDisbursement(string $namba_simu, float $kiasi, string $external_id, string $jina_mpokeaji = '', string $maelezo = ''): array
{
    $provider = dalipayProviderFromPhone($namba_simu);
    if ($provider === null) {
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null,
                'error' => 'Mtandao wa namba hii hauwezi kupokea malipo. Tumia Vodacom, Tigo/Yas, Airtel au Halotel.'];
    }
    if (strlen($external_id) > 30) {
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null,
                'error' => 'Rejea ya muamala ni ndefu kupita kiasi.'];
    }

    $kiasi_int = (int)round($kiasi);
    if ($kiasi_int < 1 || $kiasi_int > 5000000) {
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null,
                'error' => 'Kiasi lazima kiwe kati ya TSh 1 na TSh 5,000,000.'];
    }

    $payload = [
        'account_number' => $namba_simu,
        'amount'         => $kiasi_int,
        'provider'       => $provider,
        'external_id'    => $external_id,
    ];
    if ($jina_mpokeaji !== '') $payload['recipient_name'] = mb_substr($jina_mpokeaji, 0, 60);
    if ($maelezo !== '')       $payload['remarks']        = mb_substr($maelezo, 0, 120);

    $res = dalipayRequest('POST', '/disbursements', $payload);

    if (!$res['ok']) {
        // 'http' inarudishwa ili mwitaji atofautishe:
        //   http 4xx  = gateway IMEKATAA kwa uhakika -> ni salama kurudisha salio
        //   http 0    = mtandao umekatika, HATUJUI kama malipo yameanzishwa
        //               -> KAMWE usirudishe salio; yanahitaji ukaguzi wa mkono
        return ['ok' => false, 'uuid' => null, 'reference' => null, 'status' => null,
                'http' => $res['http'], 'error' => $res['error']];
    }

    $data = $res['body']['data'] ?? [];
    return [
        'ok'        => true,
        'uuid'      => $data['uuid']      ?? null,
        'reference' => $data['reference'] ?? null,
        'status'    => $data['status']    ?? 'awaiting_approval',
        'http'      => $res['http'],
        'error'     => '',
    ];
}

/**
 * Hali ya disbursement. TOFAUTI na collections, hapa gateway inatumia
 * REFERENCE (dsb_...), siyo uuid.
 *
 * @return array ['ok'=>bool, 'status'=>'awaiting_approval'|'success'|'failed'|'rejected'|null, 'error'=>string]
 */
function dalipayDisbursementStatus(string $reference): array
{
    $res = dalipayRequest('GET', '/disbursements/' . rawurlencode($reference) . '/status', null, 15);

    if (!$res['ok']) {
        return ['ok' => false, 'status' => null, 'error' => $res['error']];
    }

    return [
        'ok'     => true,
        'status' => $res['body']['data']['status'] ?? null,
        'error'  => '',
    ];
}

/**
 * Thibitisha saini ya webhook (X-Signature) — HMAC-SHA256 ya BODY GHAFI
 * (raw), siyo ya array iliyo-decode. Kila mabadiliko madogo ya body
 * huvunja saini, ndiyo maana lazima tutumie php://input moja kwa moja.
 */
function dalipayVerifyWebhook(string $rawBody, string $signature): bool
{
    if (DALIPAY_CALLBACK_SECRET === '' || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, DALIPAY_CALLBACK_SECRET);
    return hash_equals($expected, $signature);
}
