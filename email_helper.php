<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// __DIR__ inahakikisha njia hii inapatikana bila kujali ni faili gani
// (admin.php, worker fulani, cron, n.k.) inayo-require email_helper.php,
// na bila kujali "working directory" ya sasa ya PHP wakati huo.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Loader nyepesi ya faili ya .env (bila kuhitaji composer package ya ziada).
 * Inasoma __DIR__ . '/.env' (kama ipo) na kuweka values kwenye environment
 * kwa kutumia putenv(), tu kama variable husika haijawekwa tayari.
 *
 * Muundo wa .env: MFANO_KEY=thamani (mstari mmoja kwa kila variable, # kwa comment)
 */
function loadEnvFile($path) {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}
loadEnvFile(__DIR__ . '/.env');

/**
 * Function ya kutuma Email kwa wateja/resellers
 *
 * @param string $toEmail Anwani ya email ya mpokeaji
 * @param string $username Jina la mtumiaji
 * @param string $subject Kichwa cha habari cha email
 * @param string $messageContent Ujumbe mkuu wa email (HTML supported)
 * @return bool Returns true ikifanikiwa, au false ikifeli
 */
function sendStatusEmail($toEmail, $username, $subject, $messageContent) {
    $gmailUser = getenv('GMAIL_USER');
    $gmailPass = getenv('GMAIL_APP_PASSWORD');

    if (!$gmailUser || !$gmailPass) {
        error_log("Email Sending Error kwa {$toEmail}: GMAIL_USER / GMAIL_APP_PASSWORD hazijawekwa kwenye environment (.env).");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        // ── MIKONFIGO YA GMAIL SMTP ──
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $gmailUser;
        $mail->Password   = $gmailPass;

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // ── ANWANI YA MTUMAJI NA MPOKEAJI ──
        $mail->setFrom($gmailUser, 'Tech 5G Wi-Fi');
        $mail->addAddress($toEmail, $username);

        // ── MAUDHUI YA EMAIL (DESIGN YA KISASA) ──
        $mail->isHTML(true);
        $mail->Subject = $subject;

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;'>
            <div style='max-width: 600px; background: #ffffff; padding: 20px; border-radius: 10px; margin: auto; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
                <div style='background: #0b1329; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px;'>
                    <h2 style='color: #07f793; margin: 0;'>Tech 5G Wi-Fi System</h2>
                    <span style='color: #a0aec0; font-size: 12px;'>tech5g.co.tz</span>
                </div>
                <p style='font-size: 16px; color: #1a202c;'>Habari <strong>" . htmlspecialchars($username, ENT_QUOTES) . "</strong>,</p>
                <div style='font-size: 14px; color: #4a5568; line-height: 1.6; background: #f8fafc; padding: 15px; border-left: 4px solid #07f793; border-radius: 4px; margin: 15px 0;'>
                    {$messageContent}
                </div>
                <hr style='border: none; border-top: 1px solid #edf2f7; margin: 20px 0;'>
                <p style='font-size: 11px; color: #a0aec0; text-align: center;'>Barua pepe hii imetumwa moja kwa moja kutoka kwenye mfumo wa Tech 5G. Tafadhali usijibu barua pepe hii.</p>
            </div>
        </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Weka ujumbe kamili wa error (siyo tu ErrorInfo) ili iwe rahisi
        // kutambua chanzo hasa (mfano: auth imekataliwa, connection timeout).
        error_log("Email Sending Error kwa {$toEmail}: " . $mail->ErrorInfo . " | " . $e->getMessage());
        return false;
    }
}