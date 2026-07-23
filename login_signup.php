<?php
require_once __DIR__ . '/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    error_log('DB connect failed: ' . mysqli_connect_error());
    die(APP_ENV === 'production'
        ? 'Samahani, tatizo la muunganisho la muda. Tafadhali jaribu tena baadaye.'
        : ('Connection failed: ' . mysqli_connect_error()));
}
// Hakuna closing tag "?>" — kuepuka trailing whitespace inayovunja header()/redirect.
