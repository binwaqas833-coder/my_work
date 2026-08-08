<?php
/**
 * mailer.php
 * ------------------------------------------------------------------
 * SEHEMU MOJA ya kutuma barua pepe kwenye mfumo mzima.
 *
 * Awali kulikuwa na njia MBILI zilizovunjika:
 *   - email_helper.php  -> ilihitaji vendor/autoload.php (composer)
 *                          ambayo HAIKUWEPO kabisa -> PHP Fatal error
 *                          kila mara mfumo ulipojaribu kutuma email.
 *   - mailer_helper.php -> ilihitaji PHPMailer/src/* (haikuwepo pia) na
 *                          ilisoma password ya Gmail ILIYOANDIKWA WAZI
 *                          ndani ya mail_config.php (iliyokuwa git-ni).
 *
 * Sasa: PHPMailer ime-"vendor"-iwa ndani ya PHPMailer/src/, na siri zote
 * zinatoka kwenye environment (env[...] ya FPM pool), siyo kwenye code.
 *
 * MIPANGILIO (weka kwenye /etc/php-fpm-tech5g/pool.d/tech5g.conf):
 *   env[SMTP_HOST]      = "smtp.zoho.com"
 *   env[SMTP_PORT]      = "587"
 *   env[SMTP_SECURE]    = "tls"            ; 'tls' (587) au 'ssl' (465)
 *   env[SMTP_USER]      = "support@tech5g.co.tz"
 *   env[SMTP_PASS]      = "..."
 *   env[MAIL_FROM]      = "support@tech5g.co.tz"
 *   env[MAIL_FROM_NAME] = "Tech 5G Wi-Fi"
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Soma mpangilio wa SMTP kutoka environment.
 * Inarudisha null ikiwa hakuna username/password - mwitaji anaamua la kufanya.
 */
function tech5gMailConfig(): ?array
{
    // GMAIL_* ni majina ya zamani - yanakubaliwa bado ili mfumo usisimame
    // ghafla wakati wa kuhamia Zoho, lakini SMTP_* ndiyo sahihi sasa.
    $user = getenv('SMTP_USER') ?: getenv('GMAIL_USER');
    $pass = getenv('SMTP_PASS') ?: getenv('GMAIL_APP_PASSWORD');

    // php-fpm HAIKUBALI env[] yenye thamani tupu, hivyo pool ina placeholder
    // mpaka App Password halisi ya Zoho itakapowekwa. Tuitambue hapa ili
    // tusipoteze muda kujaribu ku-authenticate kwa password ya uongo.
    if ($pass === 'WEKA_APP_PASSWORD_YA_ZOHO_HAPA') {
        $pass = '';
    }

    if (!$user || !$pass) {
        return null;
    }

    $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls');
    $port   = (int)(getenv('SMTP_PORT') ?: ($secure === 'ssl' ? 465 : 587));

    return [
        'host'      => getenv('SMTP_HOST') ?: 'smtp.zoho.com',
        'port'      => $port,
        'secure'    => $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS,
        'user'      => $user,
        'pass'      => $pass,
        'from'      => getenv('MAIL_FROM') ?: $user,
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'Tech 5G Wi-Fi',
    ];
}

/**
 * Tuma barua pepe moja. HAITUPI exception - inarudisha bool, kwa sababu
 * kushindwa kutuma email KAMWE kusisimamishe kitendo halisi (mfano
 * kuidhinisha payout au kusajili mtumiaji).
 *
 * @param string $to      Anwani ya mpokeaji
 * @param string $toName  Jina la mpokeaji (linaweza kuwa tupu)
 * @param string $subject Kichwa
 * @param string $htmlBody Mwili wa HTML (tayari umeandaliwa)
 * @return bool
 */
function tech5gSendMail(string $to, string $toName, string $subject, string $htmlBody): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("mailer.php: anwani si sahihi: {$to}");
        return false;
    }

    $cfg = tech5gMailConfig();
    if ($cfg === null) {
        error_log('mailer.php: SMTP_USER/SMTP_PASS hazijawekwa kwenye environment - email haijatumwa.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['user'];
        $mail->Password   = $cfg['pass'];
        $mail->SMTPSecure = $cfg['secure'];
        $mail->Port       = $cfg['port'];
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $mail->setFrom($cfg['from'], $cfg['from_name']);
        $mail->addAddress($to, $toName);
        $mail->addReplyTo($cfg['from'], $cfg['from_name']);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log("mailer.php: kutuma kwa {$to} kumeshindikana: " . $mail->ErrorInfo . ' | ' . $e->getMessage());
        return false;
    } catch (\Throwable $e) {
        error_log("mailer.php: hitilafu isiyotarajiwa kwa {$to}: " . $e->getMessage());
        return false;
    }
}

/**
 * Funika ujumbe kwenye muundo wa Tech 5G (headers, rangi, footer).
 * Tumia hii ili barua pepe zote za mfumo zifanane.
 */
function tech5gEmailTemplate(string $username, string $bodyHtml): string
{
    $jina = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $mwaka = date('Y');

    return "
    <div style='font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;'>
        <div style='max-width: 600px; background: #ffffff; padding: 20px; border-radius: 10px; margin: auto; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
            <div style='background: #0b1329; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px;'>
                <h2 style='color: #07f793; margin: 0;'>Tech 5G Wi-Fi System</h2>
                <span style='color: #a0aec0; font-size: 12px;'>tech5g.co.tz</span>
            </div>
            <p style='font-size: 16px; color: #1a202c;'>Habari <strong>{$jina}</strong>,</p>
            <div style='font-size: 14px; color: #4a5568; line-height: 1.6; background: #f8fafc; padding: 15px; border-left: 4px solid #07f793; border-radius: 4px; margin: 15px 0;'>
                {$bodyHtml}
            </div>
            <hr style='border: none; border-top: 1px solid #edf2f7; margin: 20px 0;'>
            <p style='font-size: 11px; color: #a0aec0; text-align: center;'>
                &copy; {$mwaka} Tech 5G Wi-Fi. Barua pepe hii imetumwa moja kwa moja na mfumo.<br>
                Ukihitaji msaada, andika: support@tech5g.co.tz
            </p>
        </div>
    </div>";
}
