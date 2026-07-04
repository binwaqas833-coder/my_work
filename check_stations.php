<?php
/**
 * check_stations.php
 * Inapigwa na cron-job.org kila baada ya dakika 5-10.
 * Inapitia access_points zote (za resellers wote), inapiga ping,
 * na inatuma email TU pale hali imebadilika (online->offline au offline->online).
 *
 * Hii faili haina session - inafanya kazi peke yake (cron), siyo kwa
 * ombi la mtumiaji aliyelogin.
 */

include 'login_signup.php';      // Inaleta $conn
require_once 'mailer_helper.php'; // Inaleta tumaEmailAlert()

header('Content-Type: text/plain'); // matokeo ni kwa logs za cron, siyo kwa user

$jumla_zilizoangaliwa = 0;
$jumla_zilizobadilika = 0;

// ── PITIA ACCESS POINTS ZOTE, PAMOJA NA TAARIFA ZA MWENYEWE (reseller) ──
$sql = "
    SELECT ap.id, ap.user_id, ap.jina_la_ap, ap.ip_address, ap.status AS status_ya_zamani,
           ap.eneo_ilipo,
           u.alert_email, u.notify_station_offline, u.username
    FROM access_points ap
    JOIN users u ON ap.user_id = u.id
";
$result = $conn->query($sql);

if (!$result) {
    echo "Kosa la query: " . $conn->error;
    exit();
}

while ($station = $result->fetch_assoc()) {
    $jumla_zilizoangaliwa++;

    $ip            = $station['ip_address'];
    $status_zamani = $station['status_ya_zamani']; // 'online' au 'offline' kabla ya check hii
    $jina          = $station['jina_la_ap'];
    $username      = $station['username'];
    $eneo          = $station['eneo_ilipo'];
    $alert_email   = $station['alert_email'];
    $notify_on     = (int)($station['notify_station_offline'] ?? 1);

    // ── PIGA PING (OS-aware: Windows na Linux zina flags tofauti) ──
    $output = [];
    $return_code = 1;
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows: -n = idadi ya packets, -w = muda wa kusubiri kwa MILISEKUNDI
        exec("ping -n 1 -w 1000 " . escapeshellarg($ip), $output, $return_code);
    } else {
        // Linux/Unix/Mac: -c = idadi ya packets, -W = muda wa kusubiri kwa SEKUNDE
        exec("ping -c 1 -W 1 " . escapeshellarg($ip), $output, $return_code);
    }
    $status_sasa = ($return_code === 0) ? 'online' : 'offline';

    // ── ANDIKA STATUS MPYA KWENYE DATABASE (kila wakati, hata bila mabadiliko) ──
    $upd = $conn->prepare("UPDATE access_points SET status = ? WHERE id = ?");
    $upd->bind_param("si", $status_sasa, $station['id']);
    $upd->execute();
    $upd->close();

    // ── KAMA HAKUNA MABADILIKO, RUKA (usitume email) ──
    if ($status_sasa === $status_zamani) {
        continue;
    }

    $jumla_zilizobadilika++;

    // ── KAMA NOTIFICATION IMEZIMWA AU HAKUNA ALERT EMAIL, RUKA KUTUMA ──
    if (!$notify_on || empty($alert_email)) {
        continue;
    }

    // ── TENGENEZA NA TUMA EMAIL ──
    if ($status_sasa === 'offline') {
        $mada = "⚠️ Station Imezima: {$jina}";
        $ujumbe = "
            <p>Habari! Ndugu</p>
            <p>{$username}</p>
            <p>Station yako <strong>{$jina}</strong> yenye (IP: {$ip}) iliopo <strong>{$eneo}</strong> imekuwa <strong style='color:#ff3d57;'>OFFLINE</strong>.</p>
            <p>Tafadhali angalia hali ya Umeme au Mtandao kwenye eneo hilo Asante.</p>
            <p style='color:#888;font-size:12px;'>Taarifa hii imetumwa moja kwa moja na mfumo wako wa Tech 5G Wi-Fi Asante.</p>
        ";
    } else {
        $mada = "✅ Station Imerudi Online: {$jina}";
        $ujumbe = "
            <p>Habari! Ndugu</p>
            <p>{$username}</p>
            <p>Station yako <strong>{$jina}</strong> yenye (IP: {$ip}) iliopo <strong>{$eneo}</strong> imerudi <strong style='color:#07f793;'>ONLINE</strong> Asante kwa kuchagua Tech 5G Wi-Fi kuwa msimamizi ya mfumo wako.</p>
            <p style='color:#888;font-size:12px;'>Taarifa hii imetumwa moja kwa moja na mfumo wako wa Tech 5G Wi-Fi.</p>
        ";
    }

    $jibu = tumaEmailAlert($alert_email, $mada, $ujumbe);
    $maelezo = ($jibu['status'] === 'success') ? $jibu['status'] : $jibu['status'] . ' - ' . $jibu['message'];
    echo "[{$jina}] {$status_zamani} -> {$status_sasa} | Email: {$maelezo}\n";
}

echo "\nJumla zilizoangaliwa: {$jumla_zilizoangaliwa} | Zilizobadilika: {$jumla_zilizobadilika}\n";