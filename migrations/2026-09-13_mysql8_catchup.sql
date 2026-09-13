-- ============================================================
-- 2026-09-13_mysql8_catchup.sql
-- ------------------------------------------------------------
-- MIGRATION MOJA YA KUFUKUZA: inaleta database YOYOTE ya tech5g
-- (mpya kutoka schema.sql, au dump ya zamani ya MariaDB) kwenye
-- hali inayotakiwa na code ya sasa.
--
-- ── KWA NINI IMEANDIKWA ──
-- VPS mpya (66.29.143.116) ina **MySQL 8.0**, siyo MariaDB. Migrations
-- za zamani zilitumia syntax ya MariaDB PEKEE:
--
--     ALTER TABLE x ADD COLUMN IF NOT EXISTS ...
--     ALTER TABLE x ADD KEY    IF NOT EXISTS ...
--
-- MySQL 8 HAIKUBALI `IF NOT EXISTS` kwenye ADD COLUMN/ADD KEY - inatupa
-- syntax error na migration NZIMA inasimama. Faili hizi ndizo:
--     2026-08-07_dalipay_gateway.sql
--     2026-08-07_dalipay_disbursements.sql
--     2026-09-02_snippe_gateway.sql
--     2026-09-04_paid_pending_voucher.sql
--
-- Faili hii inafanya kazi ile ile kwa kutumia information_schema +
-- prepared statements - inayokubalika kwenye MariaDB NA MySQL 8.
--
-- ── TATIZO HALISI LILILOSABABISHA (2026-09-13) ──
-- Database ya VPS mpya iliundwa kutoka schema.sql pekee. schema.sql
-- ilikuwa HAINA column za OTP wala trial_enabled, hivyo kurasa mbili
-- zilikuwa zinatoa HTTP 500 kwa kila mtumiaji:
--
--   my_mikrotiks.php   -> Unknown column 'trial_enabled' in 'field list'
--                         (mikrotik_helper.php:244, getUserRouters)
--   process_engine.php -> Unknown column 'email_verified' in 'field list'
--                         (usajili mpya haukuwezekana KABISA)
--
-- schema.sql sasa imerekebishwa pia, lakini database zilizoundwa kabla
-- ya tarehe hii zinahitaji faili hii.
--
-- ── KUITUMIA ──
--   set -a; . /var/www/tech5g/private/secrets.env; set +a
--   CNF=$(mktemp); chmod 600 "$CNF"
--   printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' \
--       "$DB_HOST" "$DB_USER" "$DB_PASS" > "$CNF"
--   mysql --defaults-extra-file="$CNF" "$DB_NAME" \
--       < /var/www/tech5g/private/migrations/2026-09-13_mysql8_catchup.sql
--   rm -f "$CNF"
--
-- NI SALAMA KUIENDESHA MARA NYINGI KADRI UTAKAVYO. Kila hatua
-- inaangalia kwanza kama tayari imefanyika. HAIFUTI wala HAIBADILISHI
-- data iliyopo (isipokuwa kuweka email_verified=1 kwa watumiaji
-- waliokuwepo, ili wasijifungiwe nje).
-- ============================================================

-- ── VISAIDIZI: ongeza tu kama hakipo ──
DELIMITER //

DROP PROCEDURE IF EXISTS tech5g_add_col //
CREATE PROCEDURE tech5g_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl
           AND COLUMN_NAME = col) = 0
    THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //

DROP PROCEDURE IF EXISTS tech5g_add_idx //
CREATE PROCEDURE tech5g_add_idx(IN tbl VARCHAR(64), IN idx VARCHAR(64),
                                IN cols TEXT, IN uniq TINYINT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl
           AND INDEX_NAME = idx) = 0
    THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD ',
                        IF(uniq = 1, 'UNIQUE ', ''), 'KEY `', idx, '` (', cols, ')');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //

-- Badilisha ENUM TU kama haina thamani inayotakiwa. MODIFY COLUMN
-- hujenga upya jedwali zima, hivyo tusiifanye bure kila mara.
DROP PROCEDURE IF EXISTS tech5g_ensure_enum //
CREATE PROCEDURE tech5g_ensure_enum(IN tbl VARCHAR(64), IN col VARCHAR(64),
                                    IN needle VARCHAR(64), IN ddl TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl
           AND COLUMN_NAME = col
           AND COLUMN_TYPE LIKE CONCAT('%''', needle, '''%')) = 0
    THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` MODIFY COLUMN ', ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //

DELIMITER ;


-- ════════════════════════════════════════════════════════════
-- 1. USERS - uthibitisho wa barua pepe kwa OTP
--    (kutoka 2026-08-08_email_otp_verification.sql)
-- ════════════════════════════════════════════════════════════
-- OTP HAIHIFADHIWI WAZI: hash tu, ili database ikivuja code
-- zilizopo zisiweze kutumika. Angalia otp_helper.php.
CALL tech5g_add_col('users', 'email_verified',
    'email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email');
CALL tech5g_add_col('users', 'otp_hash',
    'otp_hash VARCHAR(255) NULL AFTER email_verified');
CALL tech5g_add_col('users', 'otp_expires_at',
    'otp_expires_at DATETIME NULL AFTER otp_hash');
CALL tech5g_add_col('users', 'otp_attempts',
    'otp_attempts TINYINT NOT NULL DEFAULT 0 AFTER otp_expires_at');
CALL tech5g_add_col('users', 'otp_sent_at',
    'otp_sent_at DATETIME NULL AFTER otp_attempts');
CALL tech5g_add_col('users', 'otp_sends_hour',
    'otp_sends_hour TINYINT NOT NULL DEFAULT 0 AFTER otp_sent_at');
CALL tech5g_add_col('users', 'otp_window_start',
    'otp_window_start DATETIME NULL AFTER otp_sends_hour');

CALL tech5g_add_idx('users', 'idx_users_email', '`email`', 0);

-- Watumiaji WALIOPO wasifungiwe nje: walioumbwa kabla ya uthibitisho
-- kuanza (mfano admin) tunawahesabu kama wamethibitishwa.
UPDATE users SET email_verified = 1 WHERE email_verified = 0;


-- ════════════════════════════════════════════════════════════
-- 2. MIKROTIK_CONFIGS - kuwasha/kuzima trial ya dakika 5
--    (kutoka 2026-08-09_router_trial_toggle.sql)
-- ════════════════════════════════════════════════════════════
-- Default 1 = imewashwa, ili tabia ya routers zilizopo isibadilike.
CALL tech5g_add_col('mikrotik_configs', 'trial_enabled',
    'trial_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER allowed_ips');


-- ════════════════════════════════════════════════════════════
-- 3. PAYMENT_TRANSACTIONS - gateway, ada 3.8%, na kufuatilia vocha
--    (2026-08-07_dalipay_gateway + 2026-08-22 + 2026-09-02 + 2026-09-04)
-- ════════════════════════════════════════════════════════════
CALL tech5g_add_col('payment_transactions', 'gateway_uuid',
    'gateway_uuid VARCHAR(64) NULL COMMENT ''Snippe payment reference (nakala)'' AFTER transaction_id');
CALL tech5g_add_col('payment_transactions', 'gateway_reference',
    'gateway_reference VARCHAR(64) NULL COMMENT ''Snippe payment reference - hali huulizwa kwa hii'' AFTER gateway_uuid');

-- Ada ya 3.8%: inahifadhiwa MARA MOJA muamala unapokamilika, ili
-- isikatwe tena wakati wa cash-out. Angalia balance_helper.php.
CALL tech5g_add_col('payment_transactions', 'fee_percent',
    'fee_percent DECIMAL(6,3) NOT NULL DEFAULT 3.800 AFTER amount');
CALL tech5g_add_col('payment_transactions', 'fee_amount',
    'fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fee_percent');
CALL tech5g_add_col('payment_transactions', 'net_amount',
    'net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fee_amount');

CALL tech5g_add_col('payment_transactions', 'fail_reason',
    'fail_reason VARCHAR(255) NULL AFTER status');
-- ULINZI WA VOCHA MBILI: webhook na poll zinaweza kufika pamoja.
-- Anayeweka alama hii kwanza ndiye pekee anayetengeneza vocha.
CALL tech5g_add_col('payment_transactions', 'claimed_at',
    'claimed_at DATETIME NULL AFTER fail_reason');
CALL tech5g_add_col('payment_transactions', 'delivery_attempts',
    'delivery_attempts INT NOT NULL DEFAULT 0 AFTER fail_reason');
CALL tech5g_add_col('payment_transactions', 'next_attempt_at',
    'next_attempt_at DATETIME NULL AFTER delivery_attempts');
CALL tech5g_add_col('payment_transactions', 'alerted_at',
    'alerted_at DATETIME NULL AFTER next_attempt_at');
CALL tech5g_add_col('payment_transactions', 'updated_at',
    'updated_at DATETIME NULL AFTER created_at');

-- 'paid_pending_voucher' = PESA IMEPOKELEWA, vocha bado haijatoka.
-- Bila hali hii, muamala uliolipiwa uliishia 'failed' (= "hakulipa")
-- na HAKUNA kilichojaribu tena - ndipo pesa ya mteja ilipopotea.
CALL tech5g_ensure_enum('payment_transactions', 'status', 'paid_pending_voucher',
    'status ENUM(''pending'',''paid_pending_voucher'',''completed'',''failed'') NOT NULL DEFAULT ''pending''');

CALL tech5g_add_idx('payment_transactions', 'idx_gateway_uuid', '`gateway_uuid`', 0);
CALL tech5g_add_idx('payment_transactions', 'idx_gateway_reference', '`gateway_reference`', 0);
CALL tech5g_add_idx('payment_transactions', 'idx_owner_router_status', '`user_id`,`router_id`,`status`', 0);
CALL tech5g_add_idx('payment_transactions', 'idx_status_next_attempt', '`status`,`next_attempt_at`', 0);

-- Backfill ya ada kwa miamala iliyokamilika kabla ya ada kuanza.
-- Kanuni ile ile inayotumika kwenye PHP: ROUND(gross * 3.8 / 100, 2).
UPDATE payment_transactions
   SET fee_percent = 3.800,
       fee_amount  = ROUND(amount * 3.800 / 100, 2),
       net_amount  = amount - ROUND(amount * 3.800 / 100, 2)
 WHERE status = 'completed' AND net_amount = 0.00;


-- ════════════════════════════════════════════════════════════
-- 4. SUBSCRIPTIONS - vitambulisho vya gateway
-- ════════════════════════════════════════════════════════════
CALL tech5g_add_col('subscriptions', 'gateway_uuid',
    'gateway_uuid VARCHAR(64) NULL COMMENT ''Snippe payment reference (nakala)'' AFTER payment_transaction_id');
CALL tech5g_add_col('subscriptions', 'gateway_reference',
    'gateway_reference VARCHAR(64) NULL COMMENT ''Snippe payment reference'' AFTER gateway_uuid');
CALL tech5g_add_idx('subscriptions', 'idx_gateway_reference', '`gateway_reference`', 0);


-- ════════════════════════════════════════════════════════════
-- 5. PAYOUT_REQUESTS - cash-out kwa kila router
--    (2026-08-07_dalipay_disbursements + 2026-08-22 + 2026-09-02)
-- ════════════════════════════════════════════════════════════
-- NULL = ombi la zamani lisilojulikana router yake; balance_helper.php
-- linalipunguza kwenye jumla ya mmiliki, siyo router mahsusi.
CALL tech5g_add_col('payout_requests', 'router_id',
    'router_id INT NULL AFTER user_id');
-- Ada FLAT ya Snippe, inashikiliwa pamoja na amount (siyo kukokotolewa
-- wakati wa kusoma) ili salio la zamani libaki sahihi ada ikibadilika.
CALL tech5g_add_col('payout_requests', 'fee_amount',
    'fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT ''Ada ya Snippe (flat)'' AFTER amount');
CALL tech5g_add_col('payout_requests', 'external_id',
    'external_id VARCHAR(30) NULL AFTER fee_amount');
CALL tech5g_add_col('payout_requests', 'gateway_uuid',
    'gateway_uuid VARCHAR(64) NULL AFTER external_id');
CALL tech5g_add_col('payout_requests', 'gateway_reference',
    'gateway_reference VARCHAR(64) NULL COMMENT ''Snippe payout reference'' AFTER gateway_uuid');
CALL tech5g_add_col('payout_requests', 'fail_reason',
    'fail_reason VARCHAR(255) NULL AFTER status');
CALL tech5g_add_col('payout_requests', 'approved_by',
    'approved_by INT NULL AFTER fail_reason');
CALL tech5g_add_col('payout_requests', 'approved_at',
    'approved_at DATETIME NULL AFTER approved_by');
CALL tech5g_add_col('payout_requests', 'updated_at',
    'updated_at DATETIME NULL AFTER created_at');

CALL tech5g_ensure_enum('payout_requests', 'status', 'awaiting_approval',
    'status ENUM(''pending'',''approved'',''awaiting_approval'',''success'',''failed'',''rejected'') NOT NULL DEFAULT ''pending''');

-- external_id ya KIPEKEE ndiyo inayozuia malipo kutumwa MARA MBILI
-- endapo mtu atabofya "Thibitisha" mara mbili.
CALL tech5g_add_idx('payout_requests', 'uq_external_id', '`external_id`', 1);
CALL tech5g_add_idx('payout_requests', 'idx_status', '`status`', 0);
CALL tech5g_add_idx('payout_requests', 'idx_gateway_reference', '`gateway_reference`', 0);
CALL tech5g_add_idx('payout_requests', 'idx_owner_router_status', '`user_id`,`router_id`,`status`', 0);


-- ════════════════════════════════════════════════════════════
-- 6. ROUTER_HEALTH - afya ya kila router
-- ════════════════════════════════════════════════════════════
-- Rekodi MOJA kwa kila router: hii ni hali ya SASA, siyo historia
-- (historia ipo error_logs). Jedwali hili linageuza ukimya kuwa alert -
-- routers 1,2,3 zilikuwa hazifikiki tangu 2026-08-16 bila mtu kujua.
CREATE TABLE IF NOT EXISTS router_health (
    router_id            INT NOT NULL PRIMARY KEY,
    status               ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    consecutive_failures INT          NOT NULL DEFAULT 0,
    last_check_at        DATETIME     NULL,
    last_ok_at           DATETIME     NULL,
    last_error           VARCHAR(255) NULL,
    alerted_at           DATETIME     NULL,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 'unknown' mpaka check ya kwanza ipite - tusitoe alert za uongo za
-- "imerudi online" kwa router ambayo hatujawahi kuipima.
INSERT IGNORE INTO router_health (router_id, status)
SELECT router_id, 'unknown' FROM mikrotik_configs;


-- ── Safisha visaidizi ──
DROP PROCEDURE IF EXISTS tech5g_add_col;
DROP PROCEDURE IF EXISTS tech5g_add_idx;
DROP PROCEDURE IF EXISTS tech5g_ensure_enum;


-- ════════════════════════════════════════════════════════════
-- UTHIBITISHO - column zote 8 zilizokuwa zinakosekana 2026-09-13
-- ════════════════════════════════════════════════════════════
SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS `column imepatikana`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'users' AND COLUMN_NAME IN
            ('email_verified','otp_hash','otp_expires_at','otp_attempts',
             'otp_sent_at','otp_sends_hour','otp_window_start'))
     OR (TABLE_NAME = 'mikrotik_configs' AND COLUMN_NAME = 'trial_enabled'))
 ORDER BY TABLE_NAME, COLUMN_NAME;
