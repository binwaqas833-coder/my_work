<?php
/**
 * config.php — Mipangilio ya kati ya mfumo (production-ready)
 * ------------------------------------------------------------------
 * Hii ndiyo sehemu MOJA ya kuweka: database, URL ya umma ya seva,
 * ufunguo wa encryption, na mipangilio ya error/HTTPS.
 *
 * Inaletwa moja kwa moja na login_signup.php, hivyo kila ukurasa
 * unaotumia $conn tayari una config hii.
 *
 * KWENYE PRODUCTION (VPS): weka thamani kupitia environment variables
 * (mfano kwenye Apache/nginx au .env) BADALA ya kuandika siri hapa.
 * ------------------------------------------------------------------
 */

// ── Mazingira: 'development' (PC yako) au 'production' (VPS ya online) ──
// Badilisha kuwa 'production' pindi utakapoiweka online (au weka env APP_ENV).
define('APP_ENV', getenv('APP_ENV') ?: 'development');

// ── Database ──
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'login_signup');

// ── URL ya umma ya seva (BILA '/' mwishoni) ──
// Hii ndiyo inayowekwa kwenye login.html (serverPHPUrl) na callbacks za malipo.
// Production hutumia domain halisi; development hutumia localhost. Unaweza
// ku-override kila wakati kwa environment variable APP_BASE_URL.
define('APP_BASE_URL', rtrim(
    getenv('APP_BASE_URL') ?: (APP_ENV === 'production'
        ? 'https://tech5g.co.tz'
        : 'http://localhost/my_work'),
    '/'
));

// ── Ufunguo wa ku-encrypt API password za MikroTik (mikrotik_configs.api_pass) ──
// LAZIMA uwe siri na UBAKI ILE ILE. Ukiubadilisha, password zilizo-encrypt-iwa
// hazitasomeka tena. Tengeneza mpya kwa: php -r "echo bin2hex(random_bytes(32));"
define('MIKROTIK_ENC_KEY', getenv('MIKROTIK_ENC_KEY') ?: 'CHANGE_ME_dev_key_override_in_production');

// ── Error display: zima kwenye production (log badala ya kuonyesha) ──
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

/**
 * Lazimisha HTTPS kwenye production (GET pages tu).
 * - Haiguzi CLI (test_router.php n.k.), localhost, wala POST (callbacks za malipo/fomu).
 * - Inaongeza HSTS header pindi tayari uko kwenye HTTPS.
 */
function force_https_if_production() {
    if (APP_ENV !== 'production' || PHP_SAPI === 'cli') return;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    if ($https) {
        if (!headers_sent()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        return;
    }
    // Redirect GET tu (tusipoteze data ya POST)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && !empty($_SERVER['HTTP_HOST']) && !headers_sent()) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? ''), true, 301);
        exit();
    }
}
force_https_if_production();

/**
 * mt_encrypt() — encrypt API password ya MikroTik kabla ya kuihifadhi.
 * Inarudisha 'enc:v1:<base64(iv|tag|ciphertext)>' (AES-256-GCM).
 */
function mt_encrypt($plaintext) {
    if ($plaintext === null || $plaintext === '') return '';
    $key = hash('sha256', MIKROTIK_ENC_KEY, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return $plaintext; // fallback (haitokei kawaida)
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

/**
 * mt_decrypt() — soma API password.
 * Backward-compatible: kama thamani si ya 'enc:v1:' (data ya zamani ya plaintext),
 * inairudisha kama ilivyo. Hivyo rows za zamani zinaendelea kufanya kazi mpaka
 * zitakapohifadhiwa upya (ndipo zitakuwa encrypted).
 */
function mt_decrypt($stored) {
    if ($stored === null || $stored === '') return '';
    if (strncmp($stored, 'enc:v1:', 7) !== 0) {
        return $stored; // legacy plaintext
    }
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $key = hash('sha256', MIKROTIK_ENC_KEY, true);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}
