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

            // ── Barua pepe lazima ithibitishwe kwanza ──
            // Tunamruhusu kwenda ukurasa wa OTP pekee (siyo kuingia mfumoni):
            // 'pending_verify_user_id' ni tofauti na 'user_id' anayotumia
            // auth_check.php, hivyo hawezi kufikia dashboard kwa session hii.
            if (isset($user['email_verified']) && (int)$user['email_verified'] === 0) {
                require_once __DIR__ . '/otp_helper.php';
                session_regenerate_id(true);
                $_SESSION['pending_verify_user_id'] = (int)$user['id'];
                otpGenerateAndSend($conn, (int)$user['id'], (string)$user['email'], (string)$user['username']);
                header("Location: verify_email.php?msg=" . urlencode('Thibitisha barua pepe yako kwanza. Tumekutumia code mpya.'));
                exit();
            }

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
        require_once __DIR__ . '/otp_helper.php';

        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $raw_pass = $_POST['password'] ?? '';

        // ── Ukaguzi wa msingi ──
        // Awali hakukuwa na ukaguzi wowote: email yoyote (hata tupu au ya
        // uongo) ilikubaliwa, na jina lililojirudia lilitoa "Kosa" tu bila
        // kueleza tatizo ni nini.
        if ($username === '' || $email === '' || $raw_pass === '') {
            header("Location: index.php?msg=Jaza username, email na password.");
            exit();
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: index.php?msg=Barua pepe si sahihi.");
            exit();
        }
        if (strlen($raw_pass) < 8) {
            header("Location: index.php?msg=Password iwe na herufi 8 au zaidi.");
            exit();
        }

        $dup = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
        $dup->bind_param("ss", $username, $email);
        $dup->execute();
        $dup_row = $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($dup_row) {
            $ni_jina = strcasecmp($dup_row['username'], $username) === 0;
            header("Location: index.php?msg=" . urlencode($ni_jina
                ? 'Username hii tayari imetumika. Chagua nyingine.'
                : 'Barua pepe hii tayari imesajiliwa. Tumia nyingine au ingia.'));
            exit();
        }

        $password = password_hash($raw_pass, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (username, email, phone, password, status, role, email_verified)
                VALUES (?, ?, ?, ?, 'pending', 'user', 0)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $username, $email, $phone, $password);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            // Mtumiaji anaruhusiwa kuthibitisha barua pepe pekee - siyo kuingia.
            // Hii SIYO session ya kuingia; auth_check.php inaangalia user_id.
            $_SESSION['pending_verify_user_id'] = $new_id;

            $res = otpGenerateAndSend($conn, $new_id, $email, $username);
            header("Location: verify_email.php?msg=" . urlencode($res['msg']));
            exit();
        } else {
            $stmt->close();
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