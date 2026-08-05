<?php
/**
 * error_logger.php
 * -------------------------------------------------------------
 * Function moja: logSystemError() — inatumika na faili LOLOTE
 * (MikroTik, malipo, database, cron, n.k.) kuandika makosa kwenye
 * jedwali la error_logs, ili Admin aone kwenye admin.php badala
 * ya kutafuta kwenye PHP error log ya server.
 *
 * MUHIMU: function hii HAILAZIMU kufanikiwa. Ikiwa database yenyewe
 * ndiyo yenye tatizo (mfano $conn imekufa), tunanyamaza kimya (@) na
 * kuruka - kuandika error KUHUSU kushindwa kuandika error kunaweza
 * kusababisha mzunguko usio na mwisho au kuvunja ukurasa halisi
 * uliokuwa unafanya kazi yake.
 *
 * Matumizi:
 *   require_once 'error_logger.php';
 *   logSystemError($conn, 'save_mikrotik.php', 'Muunganisho wa MikroTik umeshindikana', [
 *       'user_id'   => $user_id,
 *       'router_id' => $router_id,       // hiari
 *       'context'   => ['ip' => $mikrotik_ip],  // hiari, array yoyote
 *   ]);
 * -------------------------------------------------------------
 */

/**
 * Andika error kwenye jedwali la error_logs.
 *
 * @param mysqli $conn    Muunganisho wa database ($conn kutoka login_signup.php)
 * @param string $source  Jina la faili/component lililotoa error, mfano 'save_mikrotik.php'
 * @param string $message Ujumbe wa error wenyewe (usiweke password/siri ndani yake)
 * @param array  $extra   Hiari: ['user_id' => int, 'router_id' => int, 'context' => array]
 * @return bool           true kama imefanikiwa kuandikwa, false vinginevyo (haitupi exception)
 */
function logSystemError($conn, string $source, string $message, array $extra = []): bool
{
    if (!$conn) {
        return false; // hakuna connection ya kuandikia - achana nayo kimya kimya
    }

    $user_id   = isset($extra['user_id'])   ? (int)$extra['user_id']   : null;
    $router_id = isset($extra['router_id']) ? (int)$extra['router_id'] : null;
    $context   = isset($extra['context']) && is_array($extra['context'])
        ? json_encode($extra['context'], JSON_UNESCAPED_UNICODE)
        : null;

    try {
        $stmt = $conn->prepare(
            "INSERT INTO error_logs (source, user_id, router_id, message, context) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("siiss", $source, $user_id, $router_id, $message, $context);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (\Throwable $e) {
        // Kuandika error kusishindwe kusimamisha ukurasa halisi - nyamaza kimya.
        return false;
    }
}
