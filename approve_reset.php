<?php
// 1. Unganisha faili la database (kama db.php)
include 'login_signup.php'; 
// 2. Unganisha faili la ulinzi (kuhakikisha ni Admin pekee)
include 'auth_check.php'; 

// 3. Hakikisha ombi limetoka kwenye fomu (POST method)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id'])) {
    
    $user_id = $_POST['user_id'];

    // HAPA NDIPO KODI ULIYOTAKA INAPOKAA
    $stmt = $pdo->prepare("UPDATE users SET password = pending_password, pending_password = NULL, status = 'active' WHERE id = ?");
    $stmt->execute([$user_id]);

    // 4. Rudisha Admin kwenye ukurasa wa admin baada ya kumaliza
    header("Location: admin.php?msg=Mtumiaji ameidhinishwa!");
    exit();
} else {
    // Ikiwa mtu akijaribu kuingia kwenye faili hili bila fomu, mrudishe admin
    header("Location: admin.php");
    exit();
}
?>