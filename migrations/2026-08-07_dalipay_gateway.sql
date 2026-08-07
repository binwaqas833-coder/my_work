-- ============================================================
-- 2026-08-07_dalipay_gateway.sql
-- Kuunganisha ISP Gateway ya Dalipay (malipo halisi ya mobile money)
--
-- Kuitumia kwenye VPS (MariaDB 10.11 - Webuzo):
--   set -a; . /root/.tech5g-credentials; set +a
--   /usr/local/apps/mariadb1011/bin/mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-08-07_dalipay_gateway.sql
--
-- Ni salama kuiendesha zaidi ya mara moja (IF NOT EXISTS ni ya MariaDB).
-- HAIGUSI data iliyopo - inaongeza column mpya tu.
-- ============================================================

-- ── MALIPO YA VOCHA (mteja wa hotspot) ──
ALTER TABLE payment_transactions
    -- uuid ya gateway: ndiyo tunayotumia kuuliza hali ya malipo
    ADD COLUMN IF NOT EXISTS gateway_uuid      VARCHAR(64)  NULL AFTER transaction_id,
    -- reference ya gateway (col_...): namba ya kunukuu kwa Dalipay support
    ADD COLUMN IF NOT EXISTS gateway_reference VARCHAR(64)  NULL AFTER gateway_uuid,
    -- sababu halisi ya kushindikana (awali "failed" haikuwa ikieleza kwa nini)
    ADD COLUMN IF NOT EXISTS fail_reason       VARCHAR(255) NULL AFTER status,
    -- ULINZI WA VOCHA MBILI: webhook na poll zinaweza kufika kwa pamoja.
    -- Anayeweka alama hii kwanza ndiye pekee anayetengeneza vocha.
    ADD COLUMN IF NOT EXISTS claimed_at        DATETIME     NULL AFTER fail_reason;

ALTER TABLE payment_transactions
    ADD KEY IF NOT EXISTS idx_gateway_uuid (gateway_uuid);

-- ── MALIPO YA SUBSCRIPTION (reseller analipia plan yake) ──
ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS gateway_uuid      VARCHAR(64) NULL AFTER payment_transaction_id,
    ADD COLUMN IF NOT EXISTS gateway_reference VARCHAR(64) NULL AFTER gateway_uuid;

ALTER TABLE subscriptions
    ADD KEY IF NOT EXISTS idx_gateway_uuid (gateway_uuid);
