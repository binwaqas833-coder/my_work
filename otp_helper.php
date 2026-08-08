<?php
/**
 * otp_helper.php
 * ------------------------------------------------------------------
 * OTP ya kuthibitisha barua pepe wakati wa usajili.
 *
 * KANUNI ZA USALAMA ZILIZOTUMIKA:
 *  - OTP HAIHIFADHIWI WAZI. Tunahifadhi password_hash() yake tu, hivyo
 *    database ikivuja code zilizopo haziwezi kutumika.
 *  - Ulinganishaji ni password_verify() (constant-time) - siyo '=='.
 *  - Code inaisha baada ya dakika 10.
 *  - Majaribio 5 tu kwa code moja; ikizidi code inafutwa kabisa.
 *  - Kutuma tena: mara 1 kwa sekunde 60, na si zaidi ya 5 kwa saa.
 *  - Ujumbe wa makosa haufichui kama email fulani ipo au haipo.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/mailer.php';

define('OTP_LENGTH',           6);
define('OTP_TTL_MINUTES',      10);
define('OTP_MAX_ATTEMPTS',     5);
define('OTP_RESEND_SECONDS',   60);   // muda wa chini kati ya kutuma na kutuma
define('OTP_MAX_SENDS_HOUR',   5);    // kikomo cha kutuma kwa saa moja

/**
 * Tengeneza OTP mpya, ihifadhi (hash) na uitume kwa email.
 *
 * @return array ['ok'=>bool, 'msg'=>string, 'wait'=>int sekunde zilizobaki]
 */
function otpGenerateAndSend($conn, int $user_id, string $email, string $username): array
{
    // ── Rate limit ──
    $q = $conn->prepare(
        "SELECT otp_sent_at, otp_sends_hour, otp_window_start,
                TIMESTAMPDIFF(SECOND, otp_sent_at, NOW())      AS tangu_mwisho,
                TIMESTAMPDIFF(SECOND, otp_window_start, NOW()) AS tangu_dirisha
           FROM users WHERE id = ? LIMIT 1"
    );
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) {
        return ['ok' => false, 'msg' => 'Akaunti haijapatikana.', 'wait' => 0];
    }

    if ($row['otp_sent_at'] !== null && (int)$row['tangu_mwisho'] < OTP_RESEND_SECONDS) {
        $wait = OTP_RESEND_SECONDS - (int)$row['tangu_mwisho'];
        return ['ok' => false, 'msg' => "Subiri sekunde {$wait} kabla ya kuomba code nyingine.", 'wait' => $wait];
    }

    // Dirisha la saa moja: lianze upya likishapita
    $sends = (int)$row['otp_sends_hour'];
    $reset_window = ($row['otp_window_start'] === null || (int)$row['tangu_dirisha'] >= 3600);
    if ($reset_window) {
        $sends = 0;
    } elseif ($sends >= OTP_MAX_SENDS_HOUR) {
        return ['ok' => false, 'msg' => 'Umeomba code mara nyingi mno. Jaribu tena baada ya saa moja.', 'wait' => 3600];
    }

    // ── Tengeneza code ── (random_int ni cryptographically secure)
    $code = '';
    for ($i = 0; $i < OTP_LENGTH; $i++) {
        $code .= random_int(0, 9);
    }
    $hash = password_hash($code, PASSWORD_DEFAULT);

    $u = $conn->prepare(
        "UPDATE users
            SET otp_hash = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL " . OTP_TTL_MINUTES . " MINUTE),
                otp_attempts = 0, otp_sent_at = NOW(),
                otp_sends_hour = ?, otp_window_start = " . ($reset_window ? "NOW()" : "otp_window_start") . "
          WHERE id = ?"
    );
    $sends_new = $sends + 1;
    $u->bind_param("sii", $hash, $sends_new, $user_id);
    $u->execute();
    $u->close();

    // ── Tuma ──
    $body = "
        <p style='margin:0 0 12px;'>Karibu Tech 5G! Tumia namba hii kuthibitisha barua pepe yako:</p>
        <p style='font-size:32px;font-weight:bold;letter-spacing:8px;color:#0b1329;
                  background:#ffffff;border:2px dashed #07f793;border-radius:8px;
                  padding:14px;text-align:center;margin:0 0 12px;'>{$code}</p>
        <p style='margin:0;'>Code hii itaisha baada ya <strong>" . OTP_TTL_MINUTES . " dakika</strong>.</p>
        <p style='margin:12px 0 0;font-size:13px;color:#718096;'>
            Kama hukuomba code hii, ipuuze - hakuna kitakachobadilika kwenye akaunti yako.
            KAMWE usimpe mtu yeyote code hii.
        </p>";

    $sent = tech5gSendMail($email, $username, 'Code yako ya uthibitisho - Tech 5G', tech5gEmailTemplate($username, $body));

    if (!$sent) {
        return ['ok' => false, 'msg' => 'Imeshindikana kutuma barua pepe. Hakikisha anwani ni sahihi au wasiliana na support@tech5g.co.tz.', 'wait' => 0];
    }

    return ['ok' => true, 'msg' => 'Tumekutumia code kwenye barua pepe yako.', 'wait' => OTP_RESEND_SECONDS];
}

/**
 * Thibitisha code aliyoweka mtumiaji.
 *
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function otpVerify($conn, int $user_id, string $code): array
{
    $code = preg_replace('/\D/', '', $code);
    if ($code === '' || strlen($code) !== OTP_LENGTH) {
        return ['ok' => false, 'msg' => 'Weka code ya tarakimu ' . OTP_LENGTH . '.'];
    }

    $q = $conn->prepare(
        "SELECT otp_hash, otp_attempts, email_verified,
                (otp_expires_at IS NOT NULL AND otp_expires_at > NOW()) AS bado_hai
           FROM users WHERE id = ? LIMIT 1"
    );
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) {
        return ['ok' => false, 'msg' => 'Akaunti haijapatikana.'];
    }
    if ((int)$row['email_verified'] === 1) {
        return ['ok' => true, 'msg' => 'Barua pepe yako tayari imethibitishwa.'];
    }
    if (empty($row['otp_hash']) || (int)$row['bado_hai'] !== 1) {
        return ['ok' => false, 'msg' => 'Code imeisha muda. Bonyeza "Tuma tena" upate mpya.'];
    }
    if ((int)$row['otp_attempts'] >= OTP_MAX_ATTEMPTS) {
        // Futa code ili kubahatisha kusiendelee
        $conn->query("UPDATE users SET otp_hash = NULL WHERE id = " . (int)$user_id);
        return ['ok' => false, 'msg' => 'Umejaribu mara nyingi mno. Omba code mpya.'];
    }

    if (!password_verify($code, $row['otp_hash'])) {
        $i = $conn->prepare("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?");
        $i->bind_param("i", $user_id);
        $i->execute();
        $i->close();
        $zilizobaki = OTP_MAX_ATTEMPTS - ((int)$row['otp_attempts'] + 1);
        return ['ok' => false, 'msg' => 'Code si sahihi. Umebakiza majaribio ' . max(0, $zilizobaki) . '.'];
    }

    // ── Imefanikiwa: safisha kila kitu cha OTP ──
    $ok = $conn->prepare(
        "UPDATE users
            SET email_verified = 1, otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0
          WHERE id = ?"
    );
    $ok->bind_param("i", $user_id);
    $ok->execute();
    $ok->close();

    return ['ok' => true, 'msg' => 'Barua pepe yako imethibitishwa!'];
}
