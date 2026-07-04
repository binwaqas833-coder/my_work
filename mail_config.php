<?php
/**
 * mail_config.php
 * Mipangilio ya SMTP ya Gmail kwa kutuma email za alert.
 *
 * ⚠️ MUHIMU: Faili hii ina siri (App Password). Usiipakie kwenye
 * GitHub au sehemu yoyote ya umma. Iweke nje ya 'public_html' kama
 * hosting yako inaruhusu, au angalau hakikisha haina ruhusa ya kusomwa
 * moja kwa moja kupitia URL (mfano kwa .htaccess).
 */

// Email ya Gmail ya mfumo (hii ndiyo "mtumaji" wa alert zote)
define('MAIL_FROM_ADDRESS', 'shaabansilima@gmail.com');      // ⚠️ Badilisha na Gmail yako halisi
define('MAIL_FROM_NAME',    'Tech 5G Wi-Fi');

// Gmail App Password (herufi 16, bila nafasi) - ⚠️ BADILISHA hii na yako halisi
define('MAIL_APP_PASSWORD', 'dfteypxzonlvcufj');

// Mipangilio ya SMTP ya Gmail (haya hayabadiliki)
define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_SECURE', 'tls');
