<?php
/**
 * email_helper.php
 * ------------------------------------------------------------------
 * sendStatusEmail() - barua pepe za hali kwa resellers (payout
 * imeidhinishwa/imekataliwa, akaunti imekubaliwa, n.k.).
 *
 * BADILIKO (2026-08-08): faili hii ilikuwa ina `require vendor/autoload.php`
 * ambayo HAIKUWEPO kwenye seva wala kwenye repo - hivyo kila jaribio la
 * kutuma email lilisababisha PHP Fatal error na kuvunja ukurasa uliokuwa
 * unaita (mfano admin.php wakati wa kuidhinisha payout). Sasa inatumia
 * mailer.php (PHPMailer iliyo-vendor-iwa + config kutoka environment).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/mailer.php';

/**
 * Tuma barua pepe ya hali kwa mtumiaji.
 *
 * @param string $toEmail        Anwani ya mpokeaji
 * @param string $username       Jina la mtumiaji (linaonekana kwenye salamu)
 * @param string $subject        Kichwa cha barua pepe
 * @param string $messageContent Ujumbe (HTML inakubalika)
 * @return bool true ikifanikiwa; false ikifeli (HAITUPI exception)
 */
function sendStatusEmail($toEmail, $username, $subject, $messageContent): bool
{
    return tech5gSendMail(
        (string)$toEmail,
        (string)$username,
        (string)$subject,
        tech5gEmailTemplate((string)$username, (string)$messageContent)
    );
}
