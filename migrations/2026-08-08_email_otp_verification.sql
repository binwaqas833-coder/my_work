-- ============================================================
-- 2026-08-08_email_otp_verification.sql
-- Uthibitisho wa barua pepe kwa OTP wakati wa usajili.
--
-- KWA NINI: awali usajili ulikubali email yoyote (hata isiyokuwepo) na
-- hakukuwa na njia ya kuthibitisha kuwa ni ya mtumiaji halisi. Barua pepe
-- ndiyo njia ya mfumo kutuma taarifa za payout na alerts, hivyo lazima
-- ithibitishwe kabla mtu hajaingia.
--
-- OTP HAIHIFADHIWI WAZI: tunahifadhi hash (password_hash) tu, ili database
-- ikivuja code zisiweze kutumika. Angalia otp_helper.php.
--
-- Kuitumia kwenye VPS:
--   set -a; . /root/.tech5g-credentials; set +a
--   mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-08-08_email_otp_verification.sql
--
-- Salama kuiendesha zaidi ya mara moja.
-- ============================================================

-- ── Helper: ongeza column tu kama haipo ──
DELIMITER //
DROP PROCEDURE IF EXISTS tech5g_add_col //
CREATE PROCEDURE tech5g_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

CALL tech5g_add_col('users', 'email_verified',
    'email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email');

-- Hash ya OTP (siyo OTP yenyewe). NULL = hakuna code inayosubiri.
CALL tech5g_add_col('users', 'otp_hash',
    'otp_hash VARCHAR(255) NULL AFTER email_verified');

CALL tech5g_add_col('users', 'otp_expires_at',
    'otp_expires_at DATETIME NULL AFTER otp_hash');

-- Majaribio ya kuweka code (kuzuia kubahatisha code 6 za tarakimu).
CALL tech5g_add_col('users', 'otp_attempts',
    'otp_attempts TINYINT NOT NULL DEFAULT 0 AFTER otp_expires_at');

-- Wakati code ya mwisho ilipotumwa (kuzuia kubonyeza "Tuma tena" bila kikomo).
CALL tech5g_add_col('users', 'otp_sent_at',
    'otp_sent_at DATETIME NULL AFTER otp_attempts');

-- Ngapi zimetumwa ndani ya saa moja iliyopita (rate limit).
CALL tech5g_add_col('users', 'otp_sends_hour',
    'otp_sends_hour TINYINT NOT NULL DEFAULT 0 AFTER otp_sent_at');

CALL tech5g_add_col('users', 'otp_window_start',
    'otp_window_start DATETIME NULL AFTER otp_sends_hour');

DROP PROCEDURE IF EXISTS tech5g_add_col;

-- ── Watumiaji WALIOPO wasifungiwe nje ──
-- Wale walioumbwa kabla ya uthibitisho kuanza (mfano admin) tunawahesabu
-- kama wamethibitishwa, la sivyo wangeshindwa kuingia baada ya migration hii.
UPDATE users SET email_verified = 1 WHERE email_verified = 0;

-- ── Index ya kutafuta kwa email (usajili unaangalia email inayojirudia) ──
SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_email'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE users ADD KEY idx_users_email (email)',
    'SELECT ''idx_users_email tayari ipo.'' AS hali');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT id, username, email, email_verified FROM users;
