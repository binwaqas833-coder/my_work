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
                // MULTI-ROUTER: weka "active_router_id" ya session kutoka
                // last_active_router_id yake ya mwisho (kama bado ni yake),
                // au chagua router ya kwanza aliyonayo. Kama hana router
                // yoyote kabisa, mpeleke "My Mikrotiks" kwanza kabla ya
                // kufika dashboard (haiwezekani kuona dashboard bila
                // router iliyochaguliwa).
                // ══════════════════════════════════════════
                if ($user['role'] !== 'admin') {

                    if (!empty($user['last_active_router_id'])) {
                        $rchk = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE router_id=? AND user_id=?");
                        $rchk->bind_param("ii", $user['last_active_router_id'], $user['id']);
                        $rchk->execute();
                        if ($rchk->get_result()->num_rows > 0) {
                            $_SESSION['active_router_id'] = (int)$user['last_active_router_id'];
                        }
                        $rchk->close();
                    }

                    if (empty($_SESSION['active_router_id'])) {
                        $rc_stmt = $conn->prepare("SELECT router_id FROM mikrotik_configs WHERE user_id=? ORDER BY router_id ASC LIMIT 1");
                        $rc_stmt->bind_param("i", $user['id']);
                        $rc_stmt->execute();
                        $first_router = $rc_stmt->get_result()->fetch_assoc();
                        $rc_stmt->close();

                        if ($first_router) {
                            // Ana router(s) lakini hakuna "active" iliyowekwa bado - tumia ya kwanza
                            $_SESSION['active_router_id'] = (int)$first_router['router_id'];
                        } else {
                            // Hana router yoyote kabisa - lazima aongeze mmoja kwanza
                            header("Location: my_mikrotiks.php");
                            exit();
                        }
                    }
                }
                // ══════════════════════════════════════════
                // MWISHO WA NYONGEZA
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