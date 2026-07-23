<?php
// Usiweke session_start() hapa kama itakuwepo kwenye kurasa zingine, 
// lakini kwa usalama, ni bora kuweka session_start() kama haipo.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. ANGALIA KAMA MTUMIAJI AME-LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?msg=Tafadhali ingia kwanza ili uendelee.");
    exit();
}

// 2. TIMEOUT (Dakika 15 = 900 sekunde)
$timeout_duration = 900; 

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    // Futa session zote
    session_unset();
    session_destroy();
    header("Location: index.php?msg=Muda wako umeisha! Tafadhali ingia tena.");
    exit();
}

// 3. SASISHA MUDA WA MWISHO WA SHUGHULI
$_SESSION['last_activity'] = time();

// MUHIMU: faili hii haina PHP closing tag mwishoni, wala nafasi/mstari mtupu
// chini yake. Trailing whitespace hutuma output mapema na kusababisha "headers
// already sent", hivyo header("Location:") kwenye save_mikrotik.php n.k.
// inashindwa (ukurasa unakuwa mtupu badala ya ku-redirect na kuonyesha toast).
