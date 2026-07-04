<?php
/**
 * mailer_helper.php
 * Function moja ya kutuma email kupitia PHPMailer + Gmail SMTP.
 * Tumia: tumaEmailAlert($kwa_email, $mada, $ujumbe_html);
 */

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Tuma email moja.
 *
 * @param string $kwa_email   Email ya mpokeaji (reseller's alert_email)
 * @param string $mada        Subject ya email
 * @param string $ujumbe_html Mwili wa email (HTML inakubalika)
 * @return array ['status' => 'success'|'error', 'message' => string]
 */
function tumaEmailAlert($kwa_email, $mada, $ujumbe_html) {
    if (empty($kwa_email) || !filter_var($kwa_email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'error', 'message' => 'Email ya mpokeaji si sahihi.'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_FROM_ADDRESS;
        $mail->Password   = MAIL_APP_PASSWORD;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;

        // Suluhisho la kawaida kwa Windows/XAMPP: PHP local mara nyingi
        // haina CA-certificate bundle iliyosasishwa, hivyo SSL handshake
        // na Gmail inaweza kushindwa hata password ikiwa sahihi.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($kwa_email);

        $mail->isHTML(true);
        $mail->Subject = $mada;
        $mail->Body    = $ujumbe_html;
        $mail->AltBody  = strip_tags($ujumbe_html);

        $mail->send();
        return ['status' => 'success', 'message' => 'Email imetumwa.'];

    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Imeshindikana kutuma: ' . $mail->ErrorInfo];
    }
}