<?php
/**
 * test_router.php — Jaribio la muunganisho wa MikroTik API (CLI tu)
 *
 * Matumizi (terminal):
 *   php test_router.php <ip> <username> <password> [port]
 *
 * Mfano:
 *   php test_router.php 192.168.10.1 klikcell-api 123456789
 *
 * Hii inafanya EXACTLY kile save_mikrotik.php inachofanya kabla ya kuhifadhi:
 * inajaribu ku-connect, na ikifanikiwa inaonyesha taarifa za router.
 */

if (PHP_SAPI !== 'cli') {
    exit("Script hii inatumika kwenye terminal tu.\n");
}

require_once __DIR__ . '/routeros_api.class.php';

$ip   = $argv[1] ?? '';
$user = $argv[2] ?? '';
$pass = $argv[3] ?? '';
$port = (int)($argv[4] ?? 8728);

if (!$ip || !$user || !$pass) {
    exit("Matumizi: php test_router.php <ip> <username> <password> [port]\n");
}

echo "Inajaribu kuunganisha na $ip:$port kama '$user' ...\n";

$API = new RouterosAPI();
$API->debug   = false;
$API->port    = $port;
$API->timeout = 5;
$API->attempts = 1;

if (!$API->connect($ip, $user, $pass)) {
    echo "❌ IMESHINDWA kuunganisha.\n";
    echo "Kagua:\n";
    echo "  1. Uko kwenye network moja na router? (ping $ip)\n";
    echo "  2. API service imewashwa? Kwenye router: /ip service print (api lazima iwe enabled, port $port)\n";
    echo "  3. Username/password ni sahihi? Kwenye router: /user print\n";
    echo "  4. Kama API ina 'address' restriction, IP ya server hii imeruhusiwa?\n";
    exit(1);
}

echo "✅ IMEFANIKIWA! Router imejibu.\n\n";

$res = $API->comm('/system/resource/print');
$id  = $API->comm('/system/identity/print');

echo "Jina (identity) : " . ($id[0]['name'] ?? '?') . "\n";
echo "Board / Model   : " . ($res[0]['board-name'] ?? '?') . "\n";
echo "RouterOS        : " . ($res[0]['version'] ?? '?') . "\n";
echo "Uptime          : " . ($res[0]['uptime'] ?? '?') . "\n";

$hs = $API->comm('/ip/hotspot/print');
echo "Hotspot servers : " . count($hs) . (count($hs) === 0 ? "  ⚠️ Hotspot bado haijasetiwa (/ip hotspot setup)" : "") . "\n";

$profiles = $API->comm('/ip/hotspot/user/profile/print');
$names = array_map(fn($p) => $p['name'] ?? '?', $profiles);
echo "User profiles   : " . implode(', ', $names) . "\n";
echo "\nKumbuka: mfumo unahitaji profiles: daily_profile, weekly_profile, monthly_profile\n";

$API->disconnect();
echo "\nSasa unaweza kuisajili router hii kupitia Admin Dashboard (itapewa Router ID).\n";
