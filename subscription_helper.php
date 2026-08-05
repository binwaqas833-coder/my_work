<?php
/**
 * subscription_helper.php
 * -------------------------------------------------------------
 * Mantiki yote ya Subscription Plans (Tech Solo/Pro/Max) + Trial.
 *
 * MUHIMU (jinsi hali inavyobadilika - "lazy transition", HAKUNA cron
 * inayohitajika): kila wakati getSubscriptionStatus() inapoitwa,
 * inaangalia kama muda umepita na kubadilisha hali papo hapo:
 *   trial   -> expired   (baada ya siku 7, moja kwa moja - hakuna grace)
 *   active  -> grace     (baada ya mwaka 1 kwisha)
 *   grace   -> expired   (baada ya GRACE_DAYS za ziada)
 *
 * "expired" inamaanisha: user_dashboard.php/my_mikrotiks.php
 * zinamzuia reseller (anaelekezwa subscribe.php), LAKINI routers
 * zake zinaendelea kufanya kazi kwa wateja wake (hatuzigusi hapa
 * kabisa - kizuizi ni kwenye PHP dashboard tu).
 * -------------------------------------------------------------
 */

define('TRIAL_DAYS', 7);
define('GRACE_DAYS', 5); // siku za onyo baada ya mwaka kuisha kabla ya 'expired' halisi

/**
 * Anzisha trial ya siku 7 kwa reseller mpya. Inaitwa MARA MOJA TU
 * (wakati Admin anapo-approve akaunti mpya kwa mara ya kwanza) -
 * haziongezi rekodi ya pili endapo tayari ana subscription yoyote
 * (mfano password-reset approval isije ikamrudishia trial mpya).
 */
function startTrialSubscription($conn, $user_id): bool
{
    $user_id = (int)$user_id;

    $chk = $conn->prepare("SELECT COUNT(*) c FROM subscriptions WHERE user_id = ?");
    $chk->bind_param("i", $user_id);
    $chk->execute();
    $has_one = (int)$chk->get_result()->fetch_assoc()['c'] > 0;
    $chk->close();

    if ($has_one) {
        return false; // tayari ana subscription - usianzishe nyingine
    }

    $stmt = $conn->prepare(
        "INSERT INTO subscriptions (user_id, plan_id, status, starts_at, expires_at)
         VALUES (?, NULL, 'trial', NOW(), DATE_ADD(NOW(), INTERVAL " . TRIAL_DAYS . " DAY))"
    );
    $stmt->bind_param("i", $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    // Sasisha cache kwenye users (kwa haraka ya auth checks)
    if ($ok) {
        $u = $conn->prepare("UPDATE users SET subscription_status='trial', subscription_expires=DATE_ADD(NOW(), INTERVAL " . TRIAL_DAYS . " DAY) WHERE id=?");
        $u->bind_param("i", $user_id);
        $u->execute();
        $u->close();
    }
    return $ok;
}

/**
 * Pata hali ya sasa ya subscription ya reseller huyu, ikifanya
 * "lazy transition" endapo muda umepita tangu mara ya mwisho
 * ilipoangaliwa.
 *
 * @return array{status:string, plan_name:?string, max_routers:int, expires_at:?string, grace_until:?string}
 */
function getSubscriptionStatus($conn, $user_id): array
{
    $user_id = (int)$user_id;

    $stmt = $conn->prepare(
        "SELECT s.id, s.status, s.expires_at, s.grace_until, s.plan_id,
                p.plan_name, p.max_routers
         FROM subscriptions s
         LEFT JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.user_id = ?
         ORDER BY s.created_at DESC LIMIT 1"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        // Hakuna subscription kabisa (haipaswi kutokea kwa reseller
        // aliye-approved kwa njia ya kawaida, lakini tunajilinda).
        return ['status' => 'no_subscription', 'plan_name' => null, 'max_routers' => 0, 'expires_at' => null, 'grace_until' => null];
    }

    $now = time();

    // ── trial -> expired (bila grace) ──
    if ($row['status'] === 'trial' && $row['expires_at'] && strtotime($row['expires_at']) < $now) {
        _setSubscriptionStatus($conn, $row['id'], $user_id, 'expired');
        $row['status'] = 'expired';
    }
    // ── active -> grace ──
    elseif ($row['status'] === 'active' && $row['expires_at'] && strtotime($row['expires_at']) < $now) {
        $grace_until = date('Y-m-d H:i:s', strtotime($row['expires_at']) + GRACE_DAYS * 86400);
        _setSubscriptionStatus($conn, $row['id'], $user_id, 'grace', $grace_until);
        $row['status']      = 'grace';
        $row['grace_until'] = $grace_until;
    }
    // ── grace -> expired ──
    elseif ($row['status'] === 'grace' && $row['grace_until'] && strtotime($row['grace_until']) < $now) {
        _setSubscriptionStatus($conn, $row['id'], $user_id, 'expired');
        $row['status'] = 'expired';
    }

    // Trial: router 1 pekee (hakuna plan_id bado)
    $max_routers = $row['max_routers'] !== null ? (int)$row['max_routers'] : (($row['status'] === 'trial') ? 1 : 0);

    return [
        'status'      => $row['status'],
        'plan_name'   => $row['plan_name'],
        'max_routers' => $max_routers,
        'expires_at'  => $row['expires_at'],
        'grace_until' => $row['grace_until'],
    ];
}

/**
 * Helper ya ndani: badilisha status ya subscription (na cache ya users).
 */
function _setSubscriptionStatus($conn, $subscription_id, $user_id, $status, $grace_until = null): void
{
    $stmt = $conn->prepare("UPDATE subscriptions SET status=?, grace_until=? WHERE id=?");
    $stmt->bind_param("ssi", $status, $grace_until, $subscription_id);
    $stmt->execute();
    $stmt->close();

    $u = $conn->prepare("UPDATE users SET subscription_status=? WHERE id=?");
    $u->bind_param("si", $status, $user_id);
    $u->execute();
    $u->close();
}

/**
 * Anzisha "malipo yanayosubiriwa" ya plan fulani (STK Push - MOCK kwa
 * sasa, kama lipia.php). Inatengeneza rekodi mpya ya subscriptions
 * yenye status='pending_payment'.
 *
 * @return string transaction_id iliyotengenezwa
 */
function createPendingSubscriptionPayment($conn, $user_id, $plan_id): string
{
    $user_id = (int)$user_id;
    $plan_id = (int)$plan_id;
    $transaction_id = 'SUB-' . strtoupper(bin2hex(random_bytes(6)));

    $stmt = $conn->prepare(
        "INSERT INTO subscriptions (user_id, plan_id, status, starts_at, expires_at, payment_transaction_id)
         VALUES (?, ?, 'pending_payment', NOW(), NOW(), ?)"
    );
    $stmt->bind_param("iis", $user_id, $plan_id, $transaction_id);
    $stmt->execute();
    $stmt->close();

    return $transaction_id;
}

/**
 * Kamilisha malipo ya subscription (inaitwa na check_subscription_status.php
 * baada ya "mock delay", sawa na completeVoucherPayment() kwenye
 * payment_helper.php). Inabadilisha rekodi 'pending_payment' kuwa 'active'
 * yenye mwaka mzima wa muda, na kufuta rekodi za zamani zilizopitwa.
 *
 * @return array{status:string, message:string, plan_name?:string}
 */
function completeSubscriptionPayment($conn, $transaction_id): array
{
    $stmt = $conn->prepare(
        "SELECT s.id, s.user_id, s.plan_id, s.status, p.plan_name, p.price
         FROM subscriptions s
         LEFT JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.payment_transaction_id = ? LIMIT 1"
    );
    $stmt->bind_param("s", $transaction_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['status' => 'failed', 'message' => 'Malipo hayakupatikana.'];
    }
    if ($row['status'] === 'active') {
        return ['status' => 'completed', 'message' => 'Malipo yamekamilika.', 'plan_name' => $row['plan_name']];
    }
    if ($row['status'] !== 'pending_payment') {
        return ['status' => 'failed', 'message' => 'Malipo haya hayapo tena kwenye hali ya kusubiri.'];
    }

    $u = $conn->prepare(
        "UPDATE subscriptions
         SET status='active', starts_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL 1 YEAR), amount_paid=?
         WHERE id=?"
    );
    $u->bind_param("di", $row['price'], $row['id']);
    $u->execute();
    $u->close();

    $uu = $conn->prepare("UPDATE users SET subscription_status='active', subscription_expires=DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id=?");
    $uu->bind_param("i", $row['user_id']);
    $uu->execute();
    $uu->close();

    return ['status' => 'completed', 'message' => 'Malipo yamekamilika.', 'plan_name' => $row['plan_name']];
}

function markSubscriptionPaymentFailed($conn, $transaction_id, $reason = ''): void
{
    $stmt = $conn->prepare("UPDATE subscriptions SET status='expired' WHERE payment_transaction_id=? AND status='pending_payment'");
    $stmt->bind_param("s", $transaction_id);
    $stmt->execute();
    $stmt->close();
}
