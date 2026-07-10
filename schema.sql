-- ============================================================
-- schema.sql — Muundo wa database ya mfumo (login_signup)
-- Imetengenezwa kutokana na queries zote zilizomo kwenye code.
--
-- Kuitumia (terminal):
--   mysql -u root < schema.sql
--
-- Kutengeneza admin wa kwanza (baada ya kuinstall PHP):
--   php -r "echo password_hash('WEKA_PASSWORD_HAPA', PASSWORD_DEFAULT), PHP_EOL;"
--   kisha:
--   INSERT INTO users (username, email, phone, password, status, role)
--   VALUES ('admin', 'wewe@mfano.com', '07XXXXXXXX', '<hash_hapo_juu>', 'approved', 'admin');
-- ============================================================

CREATE DATABASE IF NOT EXISTS login_signup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE login_signup;

-- ── WATUMIAJI (admin + resellers) ──
CREATE TABLE IF NOT EXISTS users (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    username               VARCHAR(50)  NOT NULL UNIQUE,
    email                  VARCHAR(100) NOT NULL,
    phone                  VARCHAR(20)  NOT NULL,
    password               VARCHAR(255) NOT NULL,
    pending_password       VARCHAR(255) NULL,      -- password mpya inayosubiri admin a-approve (reset flow)
    role                   ENUM('user','admin') NOT NULL DEFAULT 'user',
    status                 ENUM('pending','approved','pending_reset') NOT NULL DEFAULT 'pending',
    alert_email            VARCHAR(100) NULL,      -- email ya kupokea alerts za station offline
    notify_station_offline TINYINT(1)   NOT NULL DEFAULT 1,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── VIFURUSHI/BEI ZA KILA RESELLER ──
CREATE TABLE IF NOT EXISTS tariffs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    package_type  VARCHAR(30)   NOT NULL,          -- 'daily' | 'weekly' | 'monthly'
    price         DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_days INT           NOT NULL DEFAULT 1,
    speed         VARCHAR(20)   NULL,              -- mfano '2M/2M'
    profile_name  VARCHAR(50)   NOT NULL,          -- LAZIMA ifanane na hotspot user profile ya MikroTik
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_package (user_id, package_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── ROUTER YA MIKROTIK YA KILA RESELLER (moja kwa reseller) ──
-- UNIQUE(user_id) inahitajika na save_mikrotik.php (ON DUPLICATE KEY UPDATE).
-- router_id inajazwa otomatiki na save_mikrotik.php (= id ya row) na ndiyo
-- namba inayowekwa kwenye login.html (var routerID) ya router husika.
CREATE TABLE IF NOT EXISTS mikrotik_configs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL UNIQUE,
    router_id   INT NULL UNIQUE,
    mikrotik_ip VARCHAR(45)  NOT NULL,
    api_user    VARCHAR(50)  NOT NULL,
    api_pass    VARCHAR(100) NOT NULL,
    api_port    INT          NOT NULL DEFAULT 8728,
    allowed_ips TEXT         NULL,                 -- whitelist (save_whitelist.php)
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── VOCHA ──
CREATE TABLE IF NOT EXISTS vouchers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,                 -- reseller mwenye vocha hii
    phone            VARCHAR(20)  NOT NULL DEFAULT '',
    mac_address      VARCHAR(20)  NULL,
    voucher_code     VARCHAR(20)  NOT NULL,
    package_type     ENUM('daily','weekly','monthly') NOT NULL,
    price            DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_days    INT          NOT NULL DEFAULT 1,
    mikrotik_profile VARCHAR(50)  NULL,
    status           ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
    payment_method   VARCHAR(40)  NULL,            -- 'Vodacom (M-Pesa)', 'Bure', n.k.
    type             ENUM('paid','free') NOT NULL DEFAULT 'paid',
    mikrotik_synced  TINYINT(1)   NOT NULL DEFAULT 0,
    transaction_id   VARCHAR(50)  NULL,
    expiry_date      DATETIME     NULL,
    last_login_at    DATETIME     NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_code (user_id, voucher_code),
    KEY idx_phone (phone),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── ANTENA/ACCESS POINTS (kwa monitoring ya check_stations.php) ──
CREATE TABLE IF NOT EXISTS access_points (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    jina_la_ap          VARCHAR(100) NOT NULL,
    ip_address          VARCHAR(45)  NOT NULL,
    eneo_ilipo          VARCHAR(100) NULL,
    tarehe_ya_kufungwa  DATETIME     NULL,
    status              ENUM('online','offline') NOT NULL DEFAULT 'offline',
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
