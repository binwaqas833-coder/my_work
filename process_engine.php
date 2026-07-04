<?php
session_start();
include 'login_signup.php'; 

$action = $_POST['action'] ?? '';

switch($action) {
    case 'login':
        $username = $_POST['username'];
        $password = $_POST['password'];

        $sql = "SELECT * FROM users WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] == 'approved') {
                session_regenerate_id(true);
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                // ══════════════════════════════════════════
                // MPYA: ANGALIA KAMA USER (siyo admin) ANA TARIFFS
                // Kama hana, mpeleke kwenye setup_tariffs.php kwanza
                // ══════════════════════════════════════════
                if ($user['role'] !== 'admin') {
                    $tariff_check = $conn->prepare("SELECT COUNT(*) as c FROM tariffs WHERE user_id = ?");
                    $tariff_check->bind_param("i", $user['id']);
                    $tariff_check->execute();
                    $has_tariffs = $tariff_check->get_result()->fetch_assoc()['c'] > 0;

                    if (!$has_tariffs) {
                        header("Location: setup_tariffs.php");
                        exit();
                    }
                }
                // ══════════════════════════════════════════
                // MWISHO WA NYONGEZA MPYA
                // ══════════════════════════════════════════

                header("Location: " . ($user['role'] == 'admin' ? "dashboard_chaguo.php" : "user_dashboard.php"));
                exit();
            } else {
                header("Location: index.php?msg=Akaunti yako bado iko pending au haijakubaliwa.");
                exit();
            }
        } else {
            header("Location: index.php?msg=Username au Password si sahihi!");
            exit();
        }
        break;

    case 'signup':
        $username = $_POST['username'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (username, email, phone, password, status, role) VALUES (?, ?, ?, ?, 'pending', 'user')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $username, $email, $phone, $password);
        
        if ($stmt->execute()) {
            header("Location: index.php?msg=Usajili umekamilika! Subiri kuidhinishwa.");
            exit();
        } else {
            header("Location: index.php?msg=Kosa: Imeshindwa kusajili.");
            exit();
        }
        break;

    case 'reset':
        $username = $_POST['username'];
        $new_password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $hashed_pass = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET pending_password = ?, status = 'pending_reset' WHERE id = ?");
            $stmt->bind_param("si", $hashed_pass, $user['id']);
            $stmt->execute();
            
            header("Location: index.php?msg=Ombi lako la kubadili password limepokelewa. Subiri Admin aku-approve.");
            exit();
        } else {
            header("Location: index.php?msg=Username haijapatikana.");
            exit();
        }
        break;

    default:
        header("Location: index.php");
        exit();
}