-- ============================================================
-- 2026-09-02_snippe_gateway.sql
-- Kuhamia Snippe (https://snippe.sh) kutoka gateway ya Dalipay.
--
-- Kuitumia kwenye VPS (MariaDB 10.11 - Webuzo):
--   set -a; . /root/.tech5g-credentials; set +a
--   /usr/local/apps/mariadb1011/bin/mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-09-02_snippe_gateway.sql
--
-- Ni salama kuiendesha zaidi ya mara moja. HAIGUSI data iliyopo.
--
-- ── KWA NINI NI NDOGO HIVI? ──
-- Column za gateway (gateway_uuid, gateway_reference, external_id,
-- fail_reason, claimed_at) hazina jina la gateway ndani yake, hivyo
-- zinatumika tena kama zilivyo. Kinachobadilika ni MAANA yake:
--
--   *.gateway_reference = reference ya Snippe (UUID). HII ndiyo
--                         inayotumika kuuliza hali:
--                           GET /v1/payments/{reference}
--                           GET /v1/payouts/{reference}
--   *.gateway_uuid      = nakala ya ile ile (imebaki ili maswali/
--                         ripoti za zamani zisivunjike)
--
-- Rekodi za zamani (za Dalipay) zinabaki kama kumbukumbu; zote
-- zilishafika mwisho wake kabla ya kuhama.
-- ============================================================

-- ── 1. INDEX ZA WEBHOOK (hii ndiyo sehemu MUHIMU ya migration hii) ──
-- snippe_webhook.php hutafuta muamala kwa gateway_reference pale ambapo
-- metadata haipatikani. Bila index hizi, kila webhook ingefanya full
-- table scan - polepole, na Snippe wanataka jibu ndani ya sekunde 30.
ALTER TABLE payment_transactions
    ADD KEY IF NOT EXISTS idx_gateway_reference (gateway_reference);

ALTER TABLE subscriptions
    ADD KEY IF NOT EXISTS idx_gateway_reference (gateway_reference);

-- payout_requests tayari ina idx_gateway_reference (migration ya 2026-08-07).

-- ── 2. ADA YA CASH-OUT (TSh 1,500 FLAT) ──
-- Snippe hutoza ada FLAT kwa kila cash-out. Tech5G haitozi chochote,
-- hivyo ada hiyo inapitishwa kwa mmiliki wa router: anaomba TSh 5,000,
-- anapokea TSh 5,000 KAMILI, na salio lake linapungua TSh 6,500.
--
-- Ada inahifadhiwa kwenye ROW YENYEWE (siyo kukokotolewa wakati wa
-- kusoma) ili:
--   * salio la zamani libaki sahihi endapo ada ya Snippe itabadilika;
--   * ombi likishindikana, kiasi NA ada vinarudi vyenyewe - hali
--     inabadilika tu, hakuna "refund" ya kuongeza column (ndiyo ulinzi
--     uleule unaozuia salio kuongezwa mara mbili).
--
-- Rekodi za zamani zinabaki 0.00: zilitengenezwa kabla ya ada kupitishwa,
-- na balance_helper.php inatumia COALESCE(fee_amount,0) kwa hivyo.
ALTER TABLE payout_requests
    ADD COLUMN IF NOT EXISTS fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Ada ya Snippe (flat) - inashikiliwa pamoja na amount'
        AFTER amount;

-- ── 3. Comments zisidanganye anayesoma schema baadaye ──
ALTER TABLE payment_transactions
    MODIFY COLUMN gateway_uuid      VARCHAR(64) NULL
        COMMENT 'Snippe payment reference (nakala ya gateway_reference)',
    MODIFY COLUMN gateway_reference VARCHAR(64) NULL
        COMMENT 'Snippe payment reference - hali huulizwa kwa hii';

ALTER TABLE subscriptions
    MODIFY COLUMN gateway_uuid      VARCHAR(64) NULL
        COMMENT 'Snippe payment reference (nakala)',
    MODIFY COLUMN gateway_reference VARCHAR(64) NULL
        COMMENT 'Snippe payment reference - hali huulizwa kwa hii';

ALTER TABLE payout_requests
    MODIFY COLUMN gateway_uuid      VARCHAR(64) NULL
        COMMENT 'Snippe payout reference (nakala)',
    MODIFY COLUMN gateway_reference VARCHAR(64) NULL
        COMMENT 'Snippe payout reference - hali huulizwa kwa hii';

-- ============================================================
-- ── 4. UKAGUZI KABLA YA KUWASHA: tariff zilizo chini ya TSh 500 ──
--
-- Snippe HAIKUBALI malipo chini ya TZS 500. Tariff yoyote iliyo chini
-- ya hapo HAIWEZI KULIPIWA - mteja atapata ujumbe wa hitilafu badala
-- ya vocha. (lipia.php inamzuia mapema, lakini ni bora ujue kwanza.)
--
-- Query hii HAIBADILISHI kitu. Endesha, kisha panga bei upya kwa
-- zilizoonekana:
--
--   SELECT t.router_id, t.package_type, t.price, m.router_name
--     FROM tariffs t
--     LEFT JOIN mikrotik_configs m ON m.router_id = t.router_id
--    WHERE t.price < 500
--    ORDER BY t.router_id, t.price;
--
-- ── 5. UKAGUZI: maombi ya cash-out yaliyokwama ──
-- Yaliyo 'awaiting_approval' yalitumwa kwa gateway ya ZAMANI. Snippe
-- hawaijui rejea zao, hivyo poll_payouts.php haitawahi kuyakamilisha.
-- Yashughulikie kwa mkono kabla ya kuwasha Snippe:
--
--   SELECT id, user_id, phone_number, amount, gateway_reference, created_at
--     FROM payout_requests
--    WHERE status = 'awaiting_approval'
--    ORDER BY id;
-- ============================================================
