<?php
require_once __DIR__ . '/config.php';

// PHP 8.1+ huwasha mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT) kwa
// default, hivyo mysqli_connect() HUTUPA mysqli_sql_exception badala ya kurudisha
// false. Bila try/catch hapa, database ikizimika kwa muda mtumiaji huona ukurasa
// mtupu (PHP Fatal error) badala ya ujumbe wa Kiswahili — ndivyo ilivyotokea
// tarehe 2026-08-08. Hivyo tunakamata exception na kushusha taratibu.
$db_error = '';
try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (\Throwable $e) {
    $conn     = false;
    $db_error = $e->getMessage();
}

if (!$conn) {
    if ($db_error === '') {
        $db_error = mysqli_connect_error() ?: 'unknown error';
    }
    error_log('DB connect failed: ' . $db_error);

    // 503 + Retry-After ili monitoring/proxy waone tatizo halisi badala ya "200 OK".
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
    }
    die(APP_ENV === 'production'
        ? 'Samahani, tatizo la muunganisho la muda. Tafadhali jaribu tena baadaye.'
        : ('Connection failed: ' . $db_error));
}
// Hakuna PHP closing tag mwishoni — kuepuka trailing whitespace inayovunja header()/redirect.
