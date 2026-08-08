<?php
/**
 * mailer_helper.php
 * ------------------------------------------------------------------
 * tumaEmailAlert() - alert za "station offline" (inaitwa na check_stations.php).
 *
 * BADILIKO (2026-08-08): awali faili hii ilihitaji PHPMailer/src/* ambayo
 * haikuwepo, na ilisoma password ya Gmail iliyoandikwa WAZI ndani ya
 * mail_config.php (faili iliyokuwa imepakiwa GitHub). Sasa inatumia
 * mailer.php - PHPMailer iliyo-vendor-iwa, na siri kutoka environment.
 *
 * mail_config.php haitumiki tena na imeondolewa kwenye git.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/mailer.php';

/**
 * Tuma email moja ya alert.
 *
 * @param string $kwa_email   Email ya mpokeaji (reseller's alert_email)
 * @param string $mada        Subject ya email
 * @param string $ujumbe_html Mwili wa email (HTML inakubalika)
 * @return array ['status' => 'success'|'error', 'message' => string]
 */
function tumaEmailAlert($kwa_email, $mada, $ujumbe_html): array
{
    if (empty($kwa_email) || !filter_var($kwa_email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'error', 'message' => 'Email ya mpokeaji si sahihi.'];
    }

    $ok = tech5gSendMail(
        (string)$kwa_email,
        '',
        (string)$mada,
        tech5gEmailTemplate('', (string)$ujumbe_html)
    );

    return $ok
        ? ['status' => 'success', 'message' => 'Email imetumwa.']
        : ['status' => 'error',   'message' => 'Imeshindikana kutuma email (angalia log ya seva).'];
}
