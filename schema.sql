-- ============================================================
-- schema_unified_empty.sql — Muundo WA PAMOJA wa database (login_signup)
-- Hii ni MUUNGANO wa schema.sql (GitHub) + login_signup(14).sql (live/production)
-- HAINA DATA — muundo (structure) tu, kwa install mpya au kulinganisha.
--
-- Kuitumia (terminal):
--   mysql -u root < schema_unified_empty.sql
--
-- Kutengeneza admin wa kwanza (baada ya kuinstall PHP):
--   php -r "echo password_hash('WEKA_PASSWORD_HAPA', PASSWORD_DEFAULT), PHP_EOL;"
--   kisha:
--   INSERT INTO users (username, email, phone, password, status, role)
--   VALUES ('admin', 'wewe@mfano.com', '07XXXXXXXX', '<hash_hapo_juu>', 'approved', 'admin');
-- ============================================================

CREATE DATABASE IF NOT EXISTS login_signup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE login_signup;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS voucher_attempts;
DROP TABLE IF EXISTS payment_transactions;
DROP TABLE IF EXISTS active_users;
DROP TABLE IF EXISTS access_points;
DROP TABLE IF EXISTS vouchers;
DROP TABLE IF EXISTS mikrotik_configs;
DROP TABLE IF EXISTS tariffs;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ── WATUMIAJI (admin + resellers) ──
CREATE TABLE users (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    username                VARCHAR(100) NOT NULL UNIQUE,
    email                   VARCHAR(100) NULL,
    phone                   VARCHAR(20)  NULL,
    password                VARCHAR(255) NOT NULL,
    pending_password        VARCHAR(255) NULL,       -- password mpya inayosubiri admin a-approve (reset flow)
    parent_admin_id         INT NULL,                -- reseller huyu ni wa admin gani (hierarchy)
    role                    ENUM('user','admin') NOT NULL DEFAULT 'user',
    status                  ENUM('pending','approved','pending_reset') NOT NULL DEFAULT 'pending',
    alert_email             VARCHAR(150) NULL,        -- email ya kupokea alerts za station offline
    notify_station_offline  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parent_admin (parent_admin_id),
    FOREIGN KEY (parent_admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VIFURUSHI/BEI ZA KILA RESELLER ──
CREATE TABLE tariffs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    package_type  VARCHAR(50)   NOT NULL,             -- 'daily' | 'weekly' | 'monthly'
    price         DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_days INT           NOT NULL DEFAULT 1,
    speed         VARCHAR(100)  NULL,                 -- mfano '2M/2M' au '6 Mbps'
    profile_name  VARCHAR(50)   NOT NULL,              -- LAZIMA ifanane na hotspot user profile ya MikroTik
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_package (user_id, package_type),
    KEY idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ROUTER YA MIKROTIK YA KILA RESELLER (moja kwa reseller) ──
-- router_id ndiyo PRIMARY KEY (kama inavyotumika saivi na save_mikrotik.php,
-- ambayo hufanya ON DUPLICATE KEY UPDATE kwa UNIQUE(user_id)).
CREATE TABLE mikrotik_configs (
    router_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL UNIQUE,
    mikrotik_ip VARCHAR(50)  NOT NULL,
    api_user    VARCHAR(50)  NOT NULL,
    api_pass    VARCHAR(100) NOT NULL,
    api_port    INT          NOT NULL DEFAULT 8728,
    allowed_ips TEXT         NULL,                    -- whitelist (save_whitelist.php)
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VOCHA ──
CREATE TABLE vouchers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,                    -- reseller mwenye vocha hii
    phone            VARCHAR(20)  NOT NULL DEFAULT '',
    mac_address      VARCHAR(17)  NULL,
    voucher_code     VARCHAR(20)  NOT NULL DEFAULT 'N/A',
    package_type     ENUM('daily','weekly','monthly') NOT NULL,
    price            DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_days    INT          NOT NULL DEFAULT 1,
    mikrotik_profile VARCHAR(50)  NULL,
    status           ENUM('unused','used','expired','pending') NOT NULL DEFAULT 'unused',
    payment_method   VARCHAR(40)  NULL,                -- 'Vodacom (M-Pesa)', 'Bure', n.k.
    type             ENUM('paid','free') NOT NULL DEFAULT 'paid',
    mikrotik_synced  TINYINT(1)   NOT NULL DEFAULT 0,
    transaction_id   VARCHAR(50)  NULL,
    expiry_date      DATETIME     NULL,
    last_login_at    DATETIME     NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_code (user_id, voucher_code),
    KEY idx_phone (phone),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ANTENA/ACCESS POINTS (kwa monitoring ya check_stations.php) ──
CREATE TABLE access_points (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    jina_la_ap          VARCHAR(100) NOT NULL,
    ip_address          VARCHAR(45)  NOT NULL,
    eneo_ilipo          VARCHAR(255) NULL,
    tarehe_ya_kufungwa  DATE         NULL,
    status              ENUM('online','offline') NOT NULL DEFAULT 'offline',
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WATUMIAJI WALIO ONLINE SASA (live sessions kwenye MikroTik) ──
CREATE TABLE active_users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    phone        VARCHAR(20) NULL,
    package_type VARCHAR(20) NULL,
    ip_address   VARCHAR(20) NULL,
    mac_address  VARCHAR(17) NOT NULL,
    data_used    VARCHAR(20) NULL,
    status       VARCHAR(10) DEFAULT 'online',
    user_id      INT NULL,
    KEY idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MALIPO (M-Pesa/Vodacom n.k.) ──
CREATE TABLE payment_transactions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    phone          VARCHAR(20)  NOT NULL,
    package_type   VARCHAR(100) NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    transaction_id VARCHAR(64)  NOT NULL UNIQUE,
    status         ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    voucher_code   VARCHAR(20)  NULL,
    client_mac     VARCHAR(20)  NULL,
    client_ip      VARCHAR(45)  NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL,
    KEY idx_user (user_id),
    KEY idx_txn (transaction_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── KUZUIA MAJARIBIO YA VOCHA MAKOSA MAKOSA (rate limiting) ──
CREATE TABLE voucher_attempts (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    client_key        VARCHAR(128) NOT NULL UNIQUE,
    attempts          INT NOT NULL DEFAULT 1,
    first_attempt_at  DATETIME NOT NULL,
    blocked_until     DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
