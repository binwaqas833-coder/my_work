<?php
session_start();
include 'login_signup.php';
require_once 'routeros_api.class.php';
require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)
require_once 'subscription_helper.php'; // startTrialSubscription(), n.k.
require_once 'payout_helper.php';       // sendPayoutToGateway() - kutoa pesa Dalipay

// ── SESSION TIMEOUT (dakika 15) ──
$timeout = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset(); session_destroy();
    header("Location: index.php?msg=Session yako imeisha.");
    exit();
}
$_SESSION['last_activity'] = time();

// ── LINDA UKURASA ──
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// ── AJAX ACTIONS (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $id     = intval($_POST['id'] ?? 0);

    if ($action === 'approve') {
        $check = mysqli_query($conn, "SELECT status FROM users WHERE id=$id");
        $row   = mysqli_fetch_assoc($check);
        if ($row['status'] === 'pending_reset') {
            mysqli_query($conn, "UPDATE users SET password=pending_password, pending_password=NULL, status='approved' WHERE id=$id");
            echo json_encode(['status'=>'success', 'msg'=>'Password Reset imeidhinishwa! ✅']);
        } else {
            mysqli_query($conn, "UPDATE users SET status='approved' WHERE id=$id");
            // ── Anzisha trial ya siku 7 (MARA MOJA TU - haitokei tena kama tayari ana subscription) ──
            startTrialSubscription($conn, $id);
            echo json_encode(['status'=>'success', 'msg'=>'Mtumiaji amekubaliwa na trial ya siku 7 imeanzishwa! ✅']);
        }
    } elseif ($action === 'make_admin') {
        mysqli_query($conn, "UPDATE users SET role='admin' WHERE id=$id");
        echo json_encode(['status'=>'success', 'msg'=>'Mtumiaji amekuwa Admin! 🎉']);
    } elseif ($action === 'delete') {
        mysqli_query($conn, "DELETE FROM users WHERE id=$id");
        echo json_encode(['status'=>'success', 'msg'=>'Mtumiaji amefutwa.']);
    
    // ── SUBSCRIPTION PLAN UPDATE ──
    } elseif ($action === 'update_plan') {
        $price       = (float)($_POST['price'] ?? 0);
        $max_routers = (int)($_POST['max_routers'] ?? 0);
        $is_active   = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;

        if ($price <= 0 || $max_routers <= 0) {
            echo json_encode(['status' => 'error', 'msg' => 'Bei na idadi ya routers lazima ziwe zaidi ya 0.']);
            exit();
        }

        $up = $conn->prepare("UPDATE subscription_plans SET price=?, max_routers=?, is_active=? WHERE id=?");
        $up->bind_param("diii", $price, $max_routers, $is_active, $id);
        if ($up->execute()) {
            echo json_encode(['status' => 'success', 'msg' => 'Plan imesasishwa! 🎉', 'price' => number_format($price), 'max_routers' => $max_routers]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Imeshindikana kuhifadhi.']);
        }
        $up->close();

    // ── PAYOUT ACTIONS ──
    } elseif ($action === 'payout_approve') {
        $stmt_user = mysqli_prepare($conn, "
            SELECT u.email, u.username, p.amount
            FROM payout_requests p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND p.status = 'pending'
        ");
        mysqli_stmt_bind_param($stmt_user, "i", $id);
        mysqli_stmt_execute($stmt_user);
        $reseller = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_user));
        mysqli_stmt_close($stmt_user);

        if ($reseller) {
            // Ituma KWELI kwenye Dalipay. sendPayoutToGateway() ndiyo inayodai
            // ombi kwa njia ya atomic, hivyo bofya-mara-mbili hakuwezi kutuma
            // malipo mawili. Salio lilishakatwa wakati reseller aliomba.
            $po = sendPayoutToGateway($conn, (int)$id, (int)$_SESSION['user_id']);

            if ($po['ok']) {
                // Barua pepe YA UKWELI: pesa BADO haijafika. Dalipay wanapaswa
                // kuidhinisha kwanza. Awali ujumbe ulisema "malipo yamekamilika"
                // jambo ambalo halikuwa kweli hata kabla ya API kuunganishwa.
                $email_sent = false;
                if (!empty($reseller['email'])) {
                    require_once __DIR__ . '/email_helper.php';

                    $subject = "Ombi Lako la Cash Out Limeidhinishwa ✅";
                    $msg = "Habari <strong>" . htmlspecialchars($reseller['username'], ENT_QUOTES) . "</strong>,<br><br>" .
                           "Ombi lako la Cash Out la <strong>TSh " . number_format($reseller['amount'], 2) . "</strong> " .
                           "limeidhinishwa na limepelekwa kwenye mfumo wa malipo.<br><br>" .
                           "Pesa itaingia kwenye namba yako ya simu muda mfupi ujao. " .
                           "Utapokea taarifa nyingine pindi itakapokamilika.<br><br>" .
                           "Asante kwa kuendelea kufanya kazi na Tech 5G!";

                    $email_sent = sendStatusEmail($reseller['email'], $reseller['username'], $subject, $msg);
                }

                echo json_encode([
                    'status' => 'success',
                    'msg'    => 'Imepelekwa Dalipay ✅ — inasubiri idhini yao. Pesa BADO haijatumwa.'
                              . ($email_sent ? ' Barua pepe imetumwa.' : ' (barua pepe haikutumwa)'),
                    'email_sent' => $email_sent,
                ]);
            } else {
                echo json_encode(['status' => 'error', 'msg' => $po['message']]);
            }
        } else {
            echo json_encode(['status'=>'error', 'msg'=>'Ombi halijapatikana au tayari lilishachakatwa.']);
        }

    } elseif ($action === 'payout_reject') {
        $stmt_get = mysqli_prepare($conn, "
            SELECT p.user_id, p.amount, u.email, u.username
            FROM payout_requests p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND p.status = 'pending'
        ");
        mysqli_stmt_bind_param($stmt_get, "i", $id);
        mysqli_stmt_execute($stmt_get);
        $res = mysqli_stmt_get_result($stmt_get);
        $p_data = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt_get);

        if ($p_data) {
            $p_user_id = (int)$p_data['user_id'];
            $p_amount  = (float)$p_data['amount'];

            mysqli_begin_transaction($conn);
            try {
                $stmt_rej = mysqli_prepare($conn, "UPDATE payout_requests SET status = 'rejected' WHERE id = ?");
                mysqli_stmt_bind_param($stmt_rej, "i", $id);
                mysqli_stmt_execute($stmt_rej);
                mysqli_stmt_close($stmt_rej);

                $stmt_ref = mysqli_prepare($conn, "UPDATE users SET balance = balance + ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt_ref, "di", $p_amount, $p_user_id);
                mysqli_stmt_execute($stmt_ref);
                mysqli_stmt_close($stmt_ref);

                mysqli_commit($conn);

                $email_sent = false;
                if (!empty($p_data['email'])) {
                    require_once __DIR__ . '/email_helper.php';

                    $subject = "Taarifa ya Ombi la Cash Out 🔄";
                    $msg = "Habari <strong>" . htmlspecialchars($p_data['username'], ENT_QUOTES) . "</strong>,<br><br>" .
                           "Ombi lako la Cash Out la <strong>TSh " . number_format($p_amount, 2) . "</strong> limekataliwa.<br>" .
                           "Kiasi hiki cha TSh " . number_format($p_amount, 2) . " kimerudishwa kwenye salio lako la reseller.<br><br>" .
                           "Tafadhali mawasiliana na Admin kwa maelezo zaidi.";

                    $email_sent = sendStatusEmail($p_data['email'], $p_data['username'], $subject, $msg);
                }

                $final_msg = $email_sent
                    ? ('Ombi limekataliwa, TSh ' . number_format($p_amount, 2) . ' imerejeshwa na Email imetumwa! 🔄')
                    : ('Ombi limekataliwa, TSh ' . number_format($p_amount, 2) . ' imerejeshwa, LAKINI barua pepe haikutumwa. ⚠️');

                echo json_encode(['status' => 'success', 'msg' => $final_msg, 'email_sent' => $email_sent]);
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                error_log("payout_reject error kwa payout_id={$id}: " . $e->getMessage());
                echo json_encode(['status'=>'error', 'msg'=>'Hitilafu wakati wa kuchakata ombi.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'msg'=>'Ombi halijapatikana au lilishachakatwa.']);
        }
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Kitendo hakijulikani.']);
    }
    exit();
}

// ── AJAX: HALI YA MIKROTIK KWA MTUMIAJI MMOJA (GET) ──
if (isset($_GET['ajax_mikrotik_status']) && isset($_GET['uid'])) {
    header('Content-Type: application/json');
    $uid_check = (int)$_GET['uid'];
    $ids_param = trim($_GET['router_ids'] ?? '');
    $router_ids = array_filter(array_map('intval', explode(',', $ids_param)));

    $online_count = 0;
    foreach ($router_ids as $rid) {
        $MK_CHECK = getMikrotikConnection($rid, $uid_check, $conn);
        if ($MK_CHECK) {
            $online_count++;
            $MK_CHECK->disconnect();
        }
    }
    echo json_encode(['status' => 'success', 'online' => $online_count, 'total' => count($router_ids)]);
    exit();
}

// ── HESABU ──
$total_pending  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE status='pending'"))['c'] ?? 0;
$total_resets   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE status='pending_reset'"))['c'] ?? 0;
$total_users    = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users"))['c'] ?? 0;
$total_admins   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'] ?? 0;
$total_payouts  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM payout_requests WHERE status='pending'"))['c'] ?? 0;

// ── VUTA WATUMIAJI WOTE ──
$result = mysqli_query($conn, "
    SELECT u.id, u.username, u.email, u.phone, u.role, u.status, u.created_at,
           (SELECT GROUP_CONCAT(mc.router_id ORDER BY mc.router_id)
              FROM mikrotik_configs mc WHERE mc.user_id = u.id)  AS router_ids,
           (SELECT COUNT(*) FROM mikrotik_configs mc WHERE mc.user_id = u.id) AS router_count,
           (SELECT COALESCE(SUM(v.price), 0) FROM vouchers v
              WHERE v.user_id = u.id AND v.type = 'paid'
                AND v.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS mapato_mwezi,
           (SELECT s.status FROM subscriptions s WHERE s.user_id = u.id
              ORDER BY s.created_at DESC LIMIT 1) AS sub_status,
           (SELECT s.expires_at FROM subscriptions s WHERE s.user_id = u.id
              ORDER BY s.created_at DESC LIMIT 1) AS sub_expires,
           (SELECT p.plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.user_id = u.id ORDER BY s.created_at DESC LIMIT 1) AS sub_plan_name,
           (SELECT s.grace_until FROM subscriptions s WHERE s.user_id = u.id
              ORDER BY s.created_at DESC LIMIT 1) AS sub_grace_until
    FROM users u
    ORDER BY u.created_at DESC
");

// Mapato ya resellers wote
$mapato_wote_mwezi = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(price), 0) AS total FROM vouchers
    WHERE type = 'paid' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
"))['total'] ?? 0;

// ── VUTA MAOMBI YA PAYOUT (PENDING) ──
$payout_result = mysqli_query($conn, "
    SELECT p.*, u.username 
    FROM payout_requests p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.status = 'pending' 
    ORDER BY p.id DESC
");

// ── VUTA SUBSCRIPTION PLANS ──
$plans_result = mysqli_query($conn, "SELECT * FROM subscription_plans ORDER BY price ASC");
?>
<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel · 5G Wi-Fi</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --surface: rgba(255,255,255,0.15);
    --surface2: rgba(255,255,255,0.08);
    --border: rgba(255,255,255,0.30);
    --border2: rgba(255,255,255,0.15);
    --accent:  #07f793;
    --accent2: #3fc7fd;
    --accent3: #ff6b35;
    --text: #fff;
    --text-dim: rgba(255,255,255,0.65);
    --text-muted: rgba(255,255,255,0.35);
    --red: #ff3d57;
    --radius: 14px;
    --blur: blur(18px);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background-image:linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg);background-size:cover;background-position:center;background-attachment:fixed;color:var(--text);min-height:100vh;padding:24px}
body::before{content:'';position:fixed;inset:0;background:rgba(0,0,0,0.38);pointer-events:none;z-index:0}
.wrapper{position:relative;z-index:1;max-width:1150px;margin:0 auto}

/* ── HEADER ── */
.page-header{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border);border-radius:var(--radius);padding:20px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;position:relative;overflow:hidden}
.page-header::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.4),transparent)}
.header-left h2{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px}
.header-left h2 i{color:var(--red)}
.header-left p{font-size:12px;color:var(--text-dim);margin-top:3px}
.header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* ── ALERT PENDING ── */
.alert-pending{background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.30);border-left:4px solid #f59e0b;border-radius:var(--radius);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;backdrop-filter:var(--blur);font-size:13px}
.alert-pending i{color:#f59e0b;font-size:18px;flex-shrink:0}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:18px;position:relative;overflow:hidden;transition:transform 0.2s,border-color 0.2s}
.stat-card:hover{transform:translateY(-2px);border-color:var(--border)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card.c1::before{background:linear-gradient(90deg,var(--accent),transparent)}
.stat-card.c2::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.stat-card.c3::before{background:linear-gradient(90deg,#f59e0b,transparent)}
.stat-card.c4::before{background:linear-gradient(90deg,var(--red),transparent)}
.stat-card.c5::before{background:linear-gradient(90deg,var(--accent3),transparent)}
.stat-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-dim);font-family:'Space Mono',monospace;margin-bottom:8px}
.stat-value{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:#fff;line-height:1}
.stat-sub{font-size:11px;color:var(--text-dim);margin-top:5px}
.stat-icon{position:absolute;right:16px;top:16px;font-size:22px;opacity:0.12}

/* ── PLANS SECTION STYLES ── */
.plans-cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}
.plan-mini-card{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:16px;text-align:center}
.plan-mini-name{font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:6px}
.plan-inactive-tag{display:block;font-size:9px;color:var(--red);font-weight:700;margin-top:3px}
.plan-mini-price{font-family:'Syne',sans-serif;font-weight:800;color:var(--accent);font-size:15px}
.plan-mini-routers{font-size:11px;color:var(--text-dim);margin-top:2px}
.plan-edit-field{margin-bottom:12px;text-align:left}
.plan-edit-field label{display:block;font-size:11px;color:var(--text-dim);margin-bottom:4px}
.plan-edit-field input[type=number]{width:100%;padding:9px 10px;border-radius:8px;border:1px solid var(--border2);background:rgba(0,0,0,0.2);color:var(--text);font-size:13px;outline:none}
.plan-edit-field label.checkbox-label{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text);cursor:pointer}

/* ── PANEL ── */
.panel{background:var(--surface);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);border:1px solid var(--border2);border-radius:var(--radius);padding:24px;margin-bottom:24px;position:relative;overflow:hidden}
.panel::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(7,247,147,0.3),transparent)}
.panel-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.panel-title h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.panel-title h3 i{color:var(--accent)}

/* ── SEARCH ── */
.search-wrap{margin-bottom:18px}
.search-input{width:100%;padding:11px 16px;border-radius:10px;border:1px solid rgba(255,255,255,0.20);background:rgba(255,255,255,0.08);color:#fff;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color 0.2s}
.search-input:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(7,247,147,0.10)}

/* ── TABLE ── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:850px}
thead th{padding:11px 14px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.50);font-family:'Space Mono',monospace;font-weight:400;border-bottom:1px solid rgba(255,255,255,0.10);text-align:left;white-space:nowrap}
tbody td{padding:13px 14px;border-bottom:1px solid rgba(255,255,255,0.05);color:#fff;vertical-align:middle}
tbody tr{transition:background 0.15s,opacity 0.4s,transform 0.4s}
tbody tr:hover{background:rgba(255,255,255,0.04)}

/* ── BADGES ── */
.badge{font-family:'Space Mono',monospace;font-size:9px;font-weight:700;padding:4px 9px;border-radius:5px;letter-spacing:0.5px;display:inline-block}
.badge-admin   {background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30)}
.badge-user    {background:rgba(63,199,253,0.12);color:var(--accent2);border:1px solid rgba(63,199,253,0.25)}
.badge-approved{background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.badge-pending {background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.30)}
.badge-reset   {background:rgba(63,199,253,0.12);color:var(--accent2);border:1px solid rgba(63,199,253,0.25)}
.badge-checking{background:rgba(255,255,255,0.08);color:var(--text-dim);border:1px solid rgba(255,255,255,0.15)}
.badge-online  {background:rgba(7,247,147,0.12);color:var(--accent);border:1px solid rgba(7,247,147,0.25)}
.badge-offline {background:rgba(255,61,87,0.12);color:var(--red,#ff3d57);border:1px solid rgba(255,61,87,0.25)}
.badge-me      {background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30);font-size:9px;padding:2px 7px;border-radius:4px;margin-left:6px;font-family:'Space Mono',monospace;font-weight:700}

/* ── BUTTONS ── */
.btn-nav{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:all 0.2s;font-family:'DM Sans',sans-serif}
.btn-nav.gray{background:rgba(255,255,255,0.10);color:var(--text-dim);border:1px solid var(--border2)}
.btn-nav.gray:hover{background:rgba(255,255,255,0.18);color:#fff}
.btn-nav.blue{background:rgba(63,199,253,0.15);color:var(--accent2);border:1px solid rgba(63,199,253,0.30)}
.btn-nav.red{background:rgba(255,61,87,0.15);color:var(--red);border:1px solid rgba(255,61,87,0.30)}

.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;font-family:'DM Sans',sans-serif;white-space:nowrap}
.btn-approve  {background:rgba(7,247,147,0.15);color:var(--accent);border:1px solid rgba(7,247,147,0.30)}
.btn-resetpass{background:rgba(63,199,253,0.15);color:var(--accent2);border:1px solid rgba(63,199,253,0.30)}
.btn-makeadmin{background:rgba(255,107,53,0.15);color:var(--accent3);border:1px solid rgba(255,107,53,0.30)}
.btn-delete   {background:rgba(255,61,87,0.15);color:var(--red);border:1px solid rgba(255,61,87,0.30)}
.actions-wrap{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;justify-content:center;align-items:center;z-index:1500}
.modal-overlay.active{display:flex}
.modal-content{background:rgba(15,30,50,0.92);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.15);padding:28px;border-radius:16px;width:90%;max-width:380px;color:#fff;text-align:center}
.modal-icon{font-size:44px;margin-bottom:14px}
.modal-btns{display:flex;gap:10px;justify-content:center;margin-top:16px;}
.btn-cancel{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.70);border:1px solid rgba(255,255,255,0.15);padding:10px 22px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;}
.btn-confirm{padding:10px 22px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;border:none;background:var(--accent);color:#000}

/* ── TOAST ── */
#toastContainer{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
.toast{min-width:300px;padding:14px 18px;border-radius:12px;color:#fff;display:flex;align-items:center;gap:12px;backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);font-size:13px}
.toast.success{background:rgba(7,247,147,0.15);border-left:4px solid var(--accent)}
.toast.error  {background:rgba(255,61,87,0.15); border-left:4px solid var(--red)}

/* ── FOOTER ── */
.footer{text-align:center;padding:22px;font-size:11px;color:rgba(255,255,255,0.35);font-family:'Space Mono',monospace}
</style>
</head>
<body>

<div id="toastContainer"></div>

<div class="wrapper">

    <!-- ── HEADER ── -->
    <div class="page-header">
        <div class="header-left">
            <h2><i class="fa-solid fa-user-shield"></i> Admin Panel</h2>
            <p>Usimamizi wa Watumiaji — Karibu, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></p>
        </div>
        <div class="header-right">
            <a href="admin.php" class="btn-nav blue"><i class="fa-solid fa-sliders"></i> Admin Dashboard</a>
            <a href="user_dashboard.php" class="btn-nav gray"><i class="fa-solid fa-chart-pie"></i> Billing</a>
            <a href="admin_error_logs.php" class="btn-nav gray"><i class="fa-solid fa-triangle-exclamation"></i> Error Logs</a>
            <a href="logout.php" class="btn-nav red"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <!-- ── SUBSCRIPTION PLANS SECTION ── -->
    <div class="panel">
        <div class="panel-title">
            <h3><i class="fa-solid fa-tags"></i> Bei za Subscription Plans</h3>
        </div>
        <div class="plans-cards-grid">
            <?php while ($plan = mysqli_fetch_assoc($plans_result)): ?>
            <div class="plan-mini-card" id="plan-card-<?php echo (int)$plan['id']; ?>">
                <div class="plan-mini-name">
                    <?php echo htmlspecialchars($plan['plan_name']); ?>
                    <?php if (!$plan['is_active']): ?><span class="plan-inactive-tag">IMEZIMWA</span><?php endif; ?>
                </div>
                <div class="plan-mini-price">Tsh <span class="plan-price-val"><?php echo number_format($plan['price']); ?></span>/mwaka</div>
                <div class="plan-mini-routers"><span class="plan-routers-val"><?php echo (int)$plan['max_routers']; ?></span> router(s)</div>
                <button class="btn-nav gray" style="width:100%;margin-top:10px;font-size:12px;padding:8px;"
                    onclick="funguaHaririPlan(<?php echo (int)$plan['id']; ?>, '<?php echo htmlspecialchars($plan['plan_name'], ENT_QUOTES); ?>', <?php echo (float)$plan['price']; ?>, <?php echo (int)$plan['max_routers']; ?>, <?php echo (int)$plan['is_active']; ?>)">
                    <i class="fa-solid fa-pen"></i> Hariri Bei
                </button>
            </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- ── ALERT: Maombi Yanayosubiri ── -->
    <?php if($total_pending > 0 || $total_resets > 0 || $total_payouts > 0): ?>
    <div class="alert-pending">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Maombi yanayosubiri idhini yako:</strong> &nbsp;&nbsp;
            <?php if($total_pending > 0): ?>
                <span style="color:var(--accent);"><i class="fa-solid fa-user-plus" style="font-size:11px;"></i> Usajili mpya: <strong><?php echo $total_pending; ?></strong></span>
            <?php endif; ?>
            <?php if($total_resets > 0): ?>
                &nbsp;·&nbsp;
                <span style="color:var(--accent2);"><i class="fa-solid fa-key" style="font-size:11px;"></i> Password Reset: <strong><?php echo $total_resets; ?></strong></span>
            <?php endif; ?>
            <?php if($total_payouts > 0): ?>
                &nbsp;·&nbsp;
                <span style="color:var(--accent3);"><i class="fa-solid fa-hand-holding-dollar" style="font-size:11px;"></i> Cash Out: <strong><?php echo $total_payouts; ?></strong></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── STAT CARDS ── -->
    <div class="stats-row">
        <div class="stat-card c1">
            <div class="stat-label">Watumiaji Wote</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-sub">Waliojisajili</div>
            <i class="fa-solid fa-users stat-icon"></i>
        </div>
        <div class="stat-card c2">
            <div class="stat-label">Admins</div>
            <div class="stat-value"><?php echo $total_admins; ?></div>
            <div class="stat-sub">Wasimamizi</div>
            <i class="fa-solid fa-user-shield stat-icon"></i>
        </div>
        <div class="stat-card c3">
            <div class="stat-label">Wanasubiri</div>
            <div class="stat-value"><?php echo $total_pending; ?></div>
            <div class="stat-sub">Usajili mpya</div>
            <i class="fa-solid fa-clock stat-icon"></i>
        </div>
        <div class="stat-card c5">
            <div class="stat-label">Cash Out Requests</div>
            <div class="stat-value"><?php echo $total_payouts; ?></div>
            <div class="stat-sub">Yanayosubiri Malipo</div>
            <i class="fa-solid fa-money-bill-transfer stat-icon"></i>
        </div>
        <div class="stat-card c1">
            <div class="stat-label">Mapato Mwezi Huu</div>
            <div class="stat-value" style="font-size:18px;">Tsh <?php echo number_format($mapato_wote_mwezi); ?></div>
            <div class="stat-sub">Vocha za 'paid'</div>
            <i class="fa-solid fa-sack-dollar stat-icon"></i>
        </div>
    </div>

    <!-- ── PAYOUT REQUESTS PANEL ── -->
    <div class="panel" style="border-color: rgba(255,107,53,0.30);">
        <div class="panel-title">
            <h3><i class="fa-solid fa-money-bill-transfer" style="color:var(--accent3);"></i> Maombi ya Cash Out (Payout Requests)</h3>
            <span style="font-family:'Space Mono',monospace;font-size:11px;color:var(--text-dim);"><?php echo $total_payouts; ?> yanayosubiri</span>
        </div>

        <div class="table-wrap">
            <?php if (mysqli_num_rows($payout_result) == 0): ?>
                <p style="text-align:center; color:var(--text-dim); padding:20px; font-size:13px;">
                    <i class="fa-solid fa-circle-check" style="color:var(--accent);"></i> Hakuna maombi mapya ya Cash Out kwa sasa.
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Reseller</th>
                            <th>Namba ya Simu</th>
                            <th>Kiwango (TSh)</th>
                            <th>Tarehe ya Ombi</th>
                            <th>Status</th>
                            <th>Vitendo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $p_no = 1; while($p = mysqli_fetch_assoc($payout_result)): ?>
                    <tr id="payout-row-<?php echo $p['id']; ?>">
                        <td style="color:var(--text-dim);font-family:'Space Mono',monospace;font-size:11px;"><?php echo $p_no++; ?></td>
                        <td><strong><?php echo htmlspecialchars($p['username']); ?></strong></td>
                        <td style="font-family:'Space Mono',monospace;font-size:12px;color:var(--accent2);"><?php echo htmlspecialchars($p['phone_number']); ?></td>
                        <td style="font-family:'Space Mono',monospace;font-size:13px;color:var(--accent);font-weight:700;">TSh <?php echo number_format($p['amount'], 2); ?></td>
                        <td style="font-size:11px;color:var(--text-dim);"><?php echo date('d M Y, H:i', strtotime($p['created_at'])); ?></td>
                        <td><span class="badge badge-pending">PENDING</span></td>
                        <td>
                            <div class="actions-wrap">
                                <button class="btn btn-approve" onclick="funguaConfirm('payout_approve', <?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['username'], ENT_QUOTES); ?>', '<?php echo number_format($p['amount'], 2); ?>')">
                                    <i class="fa-solid fa-check"></i> Approve
                                </button>
                                <button class="btn btn-delete" onclick="funguaConfirm('payout_reject', <?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['username'], ENT_QUOTES); ?>', '<?php echo number_format($p['amount'], 2); ?>')">
                                    <i class="fa-solid fa-xmark"></i> Reject
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── MAIN USERS TABLE ── -->
    <div class="panel">
        <div class="panel-title">
            <h3><i class="fa-solid fa-users-gear"></i> Orodha ya Watumiaji</h3>
            <span style="font-family:'Space Mono',monospace;font-size:11px;color:var(--text-dim);"><?php echo $total_users; ?> watumiaji wote</span>
        </div>

        <div class="search-wrap">
            <input type="text" class="search-input" id="liveSearch" placeholder="Tafuta kwa jina, email au namba ya simu..." onkeyup="searchTable()">
        </div>

        <div class="table-wrap">
            <table id="userTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Simu</th>
                        <th>Tarehe</th>
                        <th>Status</th>
                        <th>Role</th>
                        <th>Routers</th>
                        <th>MikroTik</th>
                        <th>Mapato Mwezi</th>
                        <th>Subscription</th>
                        <th>Vitendo</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $no = 1;
                while($row = mysqli_fetch_assoc($result)):
                    $is_me  = ($row['username'] === $_SESSION['username']);
                    $role   = $row['role']   ?? 'user';
                    $status = $row['status'] ?? 'approved';

                    if ($status === 'pending')
                        $status_badge = '<span class="badge badge-pending">PENDING</span>';
                    elseif ($status === 'pending_reset')
                        $status_badge = '<span class="badge badge-reset">RESET</span>';
                    else
                        $status_badge = '<span class="badge badge-approved">APPROVED</span>';

                    $role_badge = ($role === 'admin')
                        ? '<span class="badge badge-admin">ADMIN</span>'
                        : '<span class="badge badge-user">USER</span>';

                    $created = $row['created_at'] ? date('d M Y', strtotime($row['created_at'])) : '—';
                ?>
                <tr id="user-row-<?php echo $row['id']; ?>">
                    <td style="color:var(--text-dim);font-family:'Space Mono',monospace;font-size:11px;"><?php echo $no++; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['username']); ?></strong> <?php if($is_me): ?><span class="badge-me">Wewe</span><?php endif; ?></td>
                    <td style="color:var(--text-dim);font-size:12px;"><?php echo htmlspecialchars($row['email'] ?? '—'); ?></td>
                    <td style="font-family:'Space Mono',monospace;font-size:11px;color:var(--accent2);"><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></td>
                    <td style="font-size:11px;color:var(--text-dim);white-space:nowrap;"><?php echo $created; ?></td>
                    <td><?php echo $status_badge; ?></td>
                    <td><?php echo $role_badge; ?></td>
                    <td style="font-family:'Space Mono',monospace;font-size:12px;">
                        <?php if (!empty($row['router_ids'])): $rid_list = explode(',', $row['router_ids']); ?>
                            <?php foreach ($rid_list as $rid): ?>
                                <span style="color:var(--accent);font-weight:700;cursor:pointer;display:inline-block;margin:1px 3px 1px 0;"
                                      onclick="navigator.clipboard.writeText('<?php echo (int)$rid; ?>');showToast('Router ID imenakiliwa: <?php echo (int)$rid; ?>','info');">
                                    #<?php echo (int)$rid; ?>
                                </span>
                            <?php endforeach; ?>
                            <div style="color:var(--text-dim);font-size:10px;"><?php echo (int)$row['router_count']; ?> router(s)</div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;">Hajasajili</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['router_ids'])): ?>
                            <span class="badge badge-checking mikrotik-status" data-uid="<?php echo (int)$row['id']; ?>" data-router-ids="<?php echo htmlspecialchars($row['router_ids']); ?>">
                                <i class="fa-solid fa-circle-notch fa-spin"></i> Inakagua...
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;font-size:11.5px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:'Space Mono',monospace;font-size:12px;color:var(--accent);font-weight:700;">Tsh <?php echo number_format($row['mapato_mwezi'] ?? 0); ?></td>
                    <td style="font-size:11px;">
                        <?php
                        $ss = $row['sub_status'] ?? null;
                        $sub_colors = ['trial' => '#3fc7fd', 'active' => '#07f793', 'grace' => '#ffb020', 'expired' => '#ff5c5c'];
                        $sub_color  = $sub_colors[$ss] ?? '#8fa89c';
                        ?>
                        <?php if ($ss): ?>
                            <span style="color:<?php echo $sub_color; ?>;font-weight:700;text-transform:uppercase;"><?php echo htmlspecialchars($ss); ?></span>
                            <?php if ($row['sub_plan_name']): ?><div style="color:var(--text-dim);"><?php echo htmlspecialchars($row['sub_plan_name']); ?></div><?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-style:italic;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions-wrap">
                        <?php if(!$is_me): ?>
                            <?php if($status === 'pending'): ?>
                                <button class="btn btn-approve" onclick="funguaConfirm('approve',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')"><i class="fa-solid fa-check"></i> Kubali</button>
                            <?php elseif($status === 'pending_reset'): ?>
                                <button class="btn btn-resetpass" onclick="funguaConfirm('approve',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')"><i class="fa-solid fa-key"></i> Approve Pass</button>
                            <?php endif; ?>
                            <?php if($role !== 'admin'): ?>
                                <button class="btn btn-makeadmin" onclick="funguaConfirm('make_admin',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')"><i class="fa-solid fa-star"></i> Make Admin</button>
                            <?php endif; ?>
                            <button class="btn btn-delete" onclick="funguaConfirm('delete',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['username'],ENT_QUOTES); ?>')"><i class="fa-solid fa-trash-can"></i> Futa</button>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px;font-style:italic;">Mstari wako</span>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<footer class="footer">© <?php echo date('Y'); ?> Tech 5G Wi-Fi System &nbsp;·&nbsp; Haki zote zimehifadhiwa</footer>

<!-- ═══ MODAL: HARIRI PLAN ═══ -->
<div class="modal-overlay" id="planModal">
    <div class="modal-content">
        <div class="modal-icon">🏷️</div>
        <h3 id="planModalTitle" style="margin-bottom:16px;">Hariri Plan</h3>
        <input type="hidden" id="planEditId">
        <div class="plan-edit-field">
            <label>Bei (Tsh/mwaka)</label>
            <input type="number" id="planEditPrice" min="1" step="1">
        </div>
        <div class="plan-edit-field">
            <label>Idadi ya Routers</label>
            <input type="number" id="planEditRouters" min="1" step="1">
        </div>
        <div class="plan-edit-field">
            <label class="checkbox-label"><input type="checkbox" id="planEditActive"> Plan iko active (inaonekana kwa resellers)</label>
        </div>
        <div class="modal-btns">
            <button class="btn-cancel" onclick="fungaPlanModal()">Ghairi</button>
            <button class="btn-confirm" onclick="hifadhiPlan()">Hifadhi</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL: CONFIRM ACTION ═══ -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-content">
        <div class="modal-icon" id="modalIcon">⚠️</div>
        <h4 id="modalTitle">Una uhakika?</h4>
        <p id="modalMsg">Je, unataka kuendelea na kitendo hiki?</p>
        <div class="modal-btns">
            <button class="btn-cancel" onclick="fungaModal()">Ghairi</button>
            <button class="btn-confirm" id="confirmBtn" onclick="tekelezaAction()">
                <i class="fa-solid fa-check" id="confirmIcon"></i>
                <span id="confirmText">Ndiyo</span>
            </button>
        </div>
    </div>
</div>

<script>
function showToast(msg, type) {
    type = type || 'info';
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = '<span class="toast-msg">' + msg + '</span>';
    c.appendChild(t);
    setTimeout(() => { t.remove(); }, 3500);
}

function searchTable() {
    const input = document.getElementById('liveSearch').value.toLowerCase();
    document.querySelectorAll('#userTable tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(input) ? '' : 'none';
    });
}

// ── FUNCTIONS ZA PLAN ──
function funguaHaririPlan(id, jina, price, maxRouters, isActive) {
    document.getElementById('planModalTitle').textContent = 'Hariri: ' + jina;
    document.getElementById('planEditId').value = id;
    document.getElementById('planEditPrice').value = price;
    document.getElementById('planEditRouters').value = maxRouters;
    document.getElementById('planEditActive').checked = (isActive == 1);
    document.getElementById('planModal').classList.add('active');
}

function fungaPlanModal() {
    document.getElementById('planModal').classList.remove('active');
}

function hifadhiPlan() {
    const id         = document.getElementById('planEditId').value;
    const price      = document.getElementById('planEditPrice').value;
    const maxRouters = document.getElementById('planEditRouters').value;
    const isActive   = document.getElementById('planEditActive').checked ? '1' : '0';

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=update_plan&id=' + id + '&price=' + price + '&max_routers=' + maxRouters + '&is_active=' + isActive
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            const card = document.getElementById('plan-card-' + id);
            if (card) {
                card.querySelector('.plan-price-val').textContent = data.price;
                card.querySelector('.plan-routers-val').textContent = data.max_routers;
            }
            showToast(data.msg, 'success');
            fungaPlanModal();
            setTimeout(() => { location.reload(); }, 1200);
        } else {
            showToast(data.msg || 'Hitilafu.', 'error');
        }
    })
    .catch(() => showToast('Tatizo la mtandao au mfumo.', 'error'));
}

// ── CONFIRMATION MODALS & ACTIONS ──
let pendingAction = null;
let pendingId     = null;

const modalConfigs = {
    approve: { icon: '✅', title: 'Kubali Mtumiaji', msg: (u)=>'Kumkubali <strong>'+u+'</strong>?', btnClass: 'green', btnIcon: 'fa-check', btnText: 'Ndiyo, Kubali' },
    make_admin: { icon: '⭐', title: 'Fanya Admin', msg: (u)=>'Kumfanya <strong>'+u+'</strong> kuwa Admin?', btnClass: 'orange', btnIcon: 'fa-star', btnText: 'Ndiyo, Fanya Admin' },
    delete: { icon: '🗑️', title: 'Futa Mtumiaji', msg: (u)=>'Kumfuta <strong>'+u+'</strong>?', btnClass: 'red', btnIcon: 'fa-trash-can', btnText: 'Ndiyo, Futa' },
    payout_approve: { icon: '💰', title: 'Thibitisha Cash Out', msg: (u, amt)=>'Umelipa TSh <strong>'+amt+'</strong> kwa <strong>'+u+'</strong>?', btnClass: 'green', btnIcon: 'fa-check', btnText: 'Ndiyo, Nimefanya Malipo' },
    payout_reject: { icon: '❌', title: 'Kataa Cash Out', msg: (u, amt)=>'Kukataa ombi la TSh <strong>'+amt+'</strong> la <strong>'+u+'</strong>?', btnClass: 'red', btnIcon: 'fa-xmark', btnText: 'Ndiyo, Kataa & Rejesha' }
};

function funguaConfirm(action, id, username, extraParam = '') {
    pendingAction = action;
    pendingId     = id;
    const cfg = modalConfigs[action];
    document.getElementById('modalIcon').textContent   = cfg.icon;
    document.getElementById('modalTitle').textContent  = cfg.title;
    document.getElementById('modalMsg').innerHTML      = cfg.msg(username, extraParam);
    document.getElementById('confirmBtn').className    = 'btn-confirm ' + cfg.btnClass;
    document.getElementById('confirmIcon').className   = 'fa-solid ' + cfg.btnIcon;
    document.getElementById('confirmText').textContent = cfg.btnText;
    document.getElementById('confirmModal').classList.add('active');
}

function fungaModal() {
    document.getElementById('confirmModal').classList.remove('active');
    pendingAction = null; pendingId = null;
}

function tekelezaAction() {
    if (!pendingAction || !pendingId) return;
    const action = pendingAction;
    const id     = pendingId;
    fungaModal();

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=' + action + '&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.msg, 'success');
            setTimeout(() => { location.reload(); }, 1000);
        } else {
            showToast(data.msg || 'Imeshindikana.', 'error');
        }
    })
    .catch(() => showToast('Tatizo la mtandao.', 'error'));
}

// ── MIKROTIK STATUS CHECK ──
(function() {
    const badges = Array.from(document.querySelectorAll('.mikrotik-status[data-uid]'));
    let i = 0;
    function angaliaMmoja() {
        if (i >= badges.length) return;
        const badge = badges[i];
        const uid = badge.getAttribute('data-uid');
        const routerIds = badge.getAttribute('data-router-ids') || '';

        fetch('admin.php?ajax_mikrotik_status=1&uid=' + encodeURIComponent(uid) + '&router_ids=' + encodeURIComponent(routerIds))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success' && data.total > 0 && data.online === data.total) {
                    badge.className = 'badge badge-online';
                    badge.innerHTML = '<i class="fa-solid fa-circle" style="font-size:6px;"></i> ' + data.online + '/' + data.total + ' ONLINE';
                } else if (data.status === 'success' && data.online > 0) {
                    badge.className = 'badge badge-checking';
                    badge.innerHTML = '<i class="fa-solid fa-circle" style="font-size:6px;"></i> ' + data.online + '/' + data.total + ' ONLINE';
                } else {
                    badge.className = 'badge badge-offline';
                    badge.innerHTML = '<i class="fa-solid fa-circle" style="font-size:6px;"></i> OFFLINE';
                }
            })
            .catch(() => {
                badge.className = 'badge badge-offline';
                badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="font-size:9px;"></i> HITILAFU';
            })
            .finally(() => { i++; angaliaMmoja(); });
    }
    if (badges.length > 0) angaliaMmoja();
})();
</script>

</body>
</html>