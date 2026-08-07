-- ============================================================
-- schema_unified.sql — Muundo WA PAMOJA wa database (login_signup)
-- Hii ni MUUNGANO wa schema.sql (GitHub) + login_signup.sql (live/production)
-- HAINA DATA — muundo (structure) tu, kwa install mpya au kulinganisha.
--
-- Live DB ilikuwa "mbele zaidi" kuliko schema.sql ya GitHub - schema hii
-- imechukua muundo wa LIVE kama chanzo cha ukweli kwa kila jedwali, kisha
-- imeongeza baadhi ya FOREIGN KEY ambazo live DB bado haina (zimewekwa
-- alama "NDIO ZIADA" hapo chini) ili kulinda uadilifu wa data siku zijazo.
--
-- MABADILIKO MAKUBWA kulinganisha na schema.sql ya zamani (GitHub):
--   • users: + subscription_status, subscription_expires,
--            last_active_router_id, balance
--   • mikrotik_configs: + router_label; user_id SIYO UNIQUE tena
--            (reseller mmoja anaweza kuwa na routers kadhaa)
--   • tariffs / vouchers: + router_id (bei na vocha sasa ni per-router)
--   • JEDWALI MAPYA (yalikuwa hayapo kwenye schema.sql ya GitHub):
--            payout_requests, subscriptions, subscription_plans, error_logs
--
-- Kuitumia (terminal):
--   mysql -u root < schema_unified.sql
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

DROP TABLE IF EXISTS error_logs;
DROP TABLE IF EXISTS voucher_attempts;
DROP TABLE IF EXISTS payout_requests;
DROP TABLE IF EXISTS payment_transactions;
DROP TABLE IF EXISTS active_users;
DROP TABLE IF EXISTS access_points;
DROP TABLE IF EXISTS vouchers;
DROP TABLE IF EXISTS tariffs;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS subscription_plans;
DROP TABLE IF EXISTS mikrotik_configs;
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
    subscription_status     ENUM('trial','active','grace','expired') NOT NULL DEFAULT 'trial',
    subscription_expires    DATETIME NULL,
    last_active_router_id   INT NULL,                -- router ya mwisho aliyoitumia (mikrotik_configs.router_id)
    alert_email             VARCHAR(150) NULL,        -- email ya kupokea alerts za station offline
    notify_station_offline  TINYINT(1)   NOT NULL DEFAULT 1,
    balance                 DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parent_admin (parent_admin_id),
    KEY fk_last_router (last_active_router_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ROUTER(S) ZA MIKROTIK ZA KILA RESELLER (reseller mmoja anaweza kuwa na routers kadhaa) ──
CREATE TABLE mikrotik_configs (
    router_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    router_label VARCHAR(100) NOT NULL DEFAULT 'Router Yangu',
    mikrotik_ip  VARCHAR(50)  NOT NULL,
    api_user     VARCHAR(50)  NOT NULL,
    api_pass     VARCHAR(100) NOT NULL,
    api_port     INT          NOT NULL DEFAULT 8728,
    allowed_ips  TEXT         NULL,                  -- whitelist (save_whitelist.php)
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VIFURUSHI VYA SUBSCRIPTION (mipango ya malipo ya mfumo, siyo tariffs za hotspot) ──
CREATE TABLE subscription_plans (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    plan_name   VARCHAR(50)   NOT NULL,
    max_routers INT           NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SUBSCRIPTION ZA KILA RESELLER (trial, active, grace, expired) ──
CREATE TABLE subscriptions (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    user_id                INT NOT NULL,
    plan_id                INT NULL,
    status                 ENUM('trial','pending_payment','active','grace','expired') NOT NULL DEFAULT 'trial',
    starts_at              DATETIME NOT NULL,
    expires_at             DATETIME NOT NULL,
    grace_until            DATETIME NULL,
    amount_paid            DECIMAL(10,2) NULL,
    payment_transaction_id VARCHAR(64) NULL,        -- SUB-... (external_id kwa gateway)
    gateway_uuid           VARCHAR(64) NULL,        -- uuid ya Dalipay
    gateway_reference      VARCHAR(64) NULL,        -- col_...
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_status (status),
    KEY idx_plan_id (plan_id),
    KEY idx_gateway_uuid (gateway_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VIFURUSHI/BEI ZA KILA ROUTER (kila reseller-router ana bei zake) ──
CREATE TABLE tariffs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    router_id     INT NOT NULL,
    package_type  VARCHAR(50)   NOT NULL,             -- 'daily' | 'weekly' | 'monthly'
    price         DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration_days INT           NOT NULL DEFAULT 1,
    speed         VARCHAR(100)  NULL,                 -- mfano '2M/2M' au '6 Mbps'
    profile_name  VARCHAR(50)   NOT NULL,              -- LAZIMA ifanane na hotspot user profile ya MikroTik
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_router_package (router_id, package_type),
    KEY idx_user_id (user_id),
    KEY idx_router_id (router_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VOCHA ──
CREATE TABLE vouchers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,                    -- reseller mwenye vocha hii
    router_id        INT NOT NULL,                    -- router iliyotoa vocha hii
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
    KEY idx_router_id (router_id)
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
    KEY idx_user_id (user_id)
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
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MALIPO (M-Pesa/Vodacom n.k.) ──
CREATE TABLE payment_transactions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    router_id      INT NULL,
    phone          VARCHAR(20)  NOT NULL,
    package_type   VARCHAR(100) NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    transaction_id VARCHAR(64)  NOT NULL UNIQUE,   -- rejea YETU (external_id kwa gateway)
    gateway_uuid      VARCHAR(64) NULL,             -- uuid ya Dalipay (kuuliza hali ya malipo)
    gateway_reference VARCHAR(64) NULL,             -- col_... (kunukuu kwa Dalipay support)
    status         ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    fail_reason    VARCHAR(255) NULL,               -- sababu halisi ya kushindikana
    claimed_at     DATETIME     NULL,               -- ulinzi: webhook vs poll wasitengeneze vocha mbili
    voucher_code   VARCHAR(20)  NULL,
    client_mac     VARCHAR(20)  NULL,
    client_ip      VARCHAR(45)  NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL,
    KEY idx_user (user_id),
    KEY idx_txn (transaction_id),
    KEY idx_router_id (router_id),
    KEY idx_gateway_uuid (gateway_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MAOMBI YA CASH OUT (payout) ZA RESELLER ── [NDIO ZIADA: haikuwepo schema.sql ya zamani]
CREATE TABLE payout_requests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    external_id       VARCHAR(30) NULL,             -- PO-... (external_id kwa gateway)
    gateway_uuid      VARCHAR(64) NULL,
    gateway_reference VARCHAR(64) NULL,             -- dsb_... (hali huulizwa kwa HII)
    -- 'approved' ni ya zamani (malipo ya mkono). Mtiririko mpya:
    -- pending -> awaiting_approval -> success | failed | rejected
    status       ENUM('pending','approved','awaiting_approval','success','failed','rejected')
                 NOT NULL DEFAULT 'pending',
    fail_reason  VARCHAR(255) NULL,
    approved_by  INT      NULL,                     -- admin aliyeidhinisha
    approved_at  DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NULL,
    UNIQUE KEY uq_external_id (external_id),        -- kinga dhidi ya malipo mara mbili
    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_gateway_reference (gateway_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── KUZUIA MAJARIBIO YA VOCHA MAKOSA MAKOSA (rate limiting) ──
CREATE TABLE voucher_attempts (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    client_key        VARCHAR(128) NOT NULL UNIQUE,
    attempts          INT NOT NULL DEFAULT 1,
    first_attempt_at  DATETIME NOT NULL,
    blocked_until     DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── LOG YA MAKOSA YA MFUMO (error_logger.php) ── [NDIO ZIADA: haikuwepo schema.sql ya zamani]
CREATE TABLE error_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    source     VARCHAR(60) NOT NULL,
    user_id    INT NULL,
    router_id  INT NULL,
    message    TEXT NOT NULL,
    context    TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_source (source),
    KEY idx_user (user_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FOREIGN KEYS
-- Zimewekwa hapa (baada ya majedwali yote kuundwa) badala ya inline,
-- kwa sababu users <-> mikrotik_configs zina utegemezi wa pande zote mbili
-- (users.last_active_router_id -> mikrotik_configs.router_id, na
-- mikrotik_configs.user_id -> users.id).
--
-- Zilizo na "NDIO ZIADA" hazipo kwenye live DB ya sasa (haikuwa na FK
-- constraint kwenye jedwali hilo) - zimeongezwa hapa kwa uadilifu wa data.
-- Kama unapendelea kufanana 100% na live DB ya sasa, ziondoe kabla ya kuweka.
-- ============================================================

ALTER TABLE users
    ADD CONSTRAINT fk_users_parent_admin FOREIGN KEY (parent_admin_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_last_router  FOREIGN KEY (last_active_router_id) REFERENCES mikrotik_configs(router_id) ON DELETE SET NULL;

ALTER TABLE mikrotik_configs
    ADD CONSTRAINT fk_mikrotik_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE subscriptions
    ADD CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL;

ALTER TABLE tariffs
    ADD CONSTRAINT fk_tariffs_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_tariffs_router FOREIGN KEY (router_id) REFERENCES mikrotik_configs(router_id) ON DELETE CASCADE;

ALTER TABLE vouchers
    ADD CONSTRAINT fk_vouchers_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_vouchers_router FOREIGN KEY (router_id) REFERENCES mikrotik_configs(router_id) ON DELETE CASCADE;

ALTER TABLE access_points
    ADD CONSTRAINT fk_access_points_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE active_users
    ADD CONSTRAINT fk_active_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE payment_transactions
    ADD CONSTRAINT fk_payment_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_payment_router FOREIGN KEY (router_id) REFERENCES mikrotik_configs(router_id) ON DELETE SET NULL; -- NDIO ZIADA

ALTER TABLE payout_requests
    ADD CONSTRAINT fk_payout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE; -- NDIO ZIADA
