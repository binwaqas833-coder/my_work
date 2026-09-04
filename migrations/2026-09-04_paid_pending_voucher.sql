-- ============================================================
-- 2026-09-04_paid_pending_voucher.sql
--
-- TATIZO LILILOSABABISHA MIGRATION HII (2026-09-04):
-- Mteja 0716433435 alilipa TZS 1,000 (TXN-859BC2EDB715). Snippe
-- walipokea pesa na wakatuma webhook ya "success". Lakini router
-- ya reseller (10.60.0.10) haikufikika kwenye tunnel ya WireGuard,
-- hivyo vocha haikutengenezwa - na mfumo ukauweka muamala kama
-- 'failed'.
--
-- HAPO NDIPO PESA ILIPOPOTEA: 'failed' ina maana "mteja hakulipa".
-- completeVoucherPayment() inarudi mapema kwa rekodi ya 'failed'
-- (haijaribu tena KAMWE), hivyo hata router iliporudi, hakuna
-- kilichoendelea. Pesa ipo kwa Snippe, mteja hana vocha, na ripoti
-- inasoma kana kwamba malipo yalishindikana.
--
-- SULUHISHO: hali MPYA 'paid_pending_voucher' = "PESA IMEPOKELEWA,
-- vocha bado haijatolewa". Ni hali ya MUDA inayojaribiwa tena na
-- retry_pending_vouchers.php mpaka vocha itoke.
--
--   pending              -> hatujui kama mteja amelipa (bado tunasubiri)
--   paid_pending_voucher -> AMELIPA. Deni letu. Lazima lifike mwisho.
--   completed            -> amelipa NA amepata vocha
--   failed               -> HAKULIPA (amekataa, salio halitoshi, muda umeisha)
--
-- 'failed' sasa ina maana MOJA tu: hakuna pesa iliyopokelewa. Hakuna
-- tena njia ya muamala uliolipiwa kuishia hapo.
--
-- Kuitumia kwenye VPS:
--   set -a; . /root/.tech5g-credentials; set +a
--   mysql -h127.0.0.1 -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-09-04_paid_pending_voucher.sql
--
-- Ni salama kuiendesha zaidi ya mara moja.
-- ============================================================

-- ── 1. HALI MPYA KWENYE ENUM ──
-- Inaongezwa KABLA ya 'completed' ili mpangilio uonyeshe safari halisi
-- ya muamala. Hakuna rekodi inayobadilika: thamani zilizopo zinabaki.
ALTER TABLE payment_transactions
    MODIFY COLUMN status ENUM('pending','paid_pending_voucher','completed','failed')
    NOT NULL DEFAULT 'pending';

-- ── 2. KUFUATILIA MAJARIBIO YA KUTOA VOCHA ──
-- delivery_attempts : mara ngapi tumejaribu kutengeneza vocha ya malipo haya
-- next_attempt_at   : cron isijaribu tena kabla ya muda huu (exponential backoff);
--                     bila hii, router iliyokufa ingepigwa kila dakika milele
-- alerted_at        : admin/reseller ameshaarifiwa lini (tusimtumie email
--                     ile ile kila dakika 2)
ALTER TABLE payment_transactions
    ADD COLUMN IF NOT EXISTS delivery_attempts INT      NOT NULL DEFAULT 0 AFTER fail_reason,
    ADD COLUMN IF NOT EXISTS next_attempt_at   DATETIME NULL              AFTER delivery_attempts,
    ADD COLUMN IF NOT EXISTS alerted_at        DATETIME NULL              AFTER next_attempt_at;

-- Cron huchagua kwa (status, next_attempt_at) - bila index hii ingekuwa
-- full table scan kila dakika 2.
ALTER TABLE payment_transactions
    ADD KEY IF NOT EXISTS idx_status_next_attempt (status, next_attempt_at);

-- ── 3. AFYA YA KILA ROUTER (router_health_check.php) ──
-- KWA NINI: routers 1, 2 na 3 zilikuwa hazifikiki TANGU 2026-08-16 na
-- hakuna aliyejua - tuligundua tu tulipofuatilia malipo yaliyopotea
-- wiki tatu baadaye. Jedwali hili linageuza ukimya huo kuwa alert.
--
-- Rekodi ni MOJA kwa kila router (router_id ni PRIMARY KEY): hii ni
-- hali ya SASA, siyo historia. Historia ipo error_logs.
CREATE TABLE IF NOT EXISTS router_health (
    router_id            INT NOT NULL PRIMARY KEY,
    status               ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    consecutive_failures INT      NOT NULL DEFAULT 0,
    last_check_at        DATETIME NULL,
    last_ok_at           DATETIME NULL,   -- mara ya mwisho API ilijibu (kupima muda wa kuzima)
    last_error           VARCHAR(255) NULL,
    alerted_at           DATETIME NULL,   -- email ya "imezima" ilitumwa lini
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. OKOA MIAMALA ILIYOLIPIWA ILIYOWEKWA 'failed' KIMAKOSA ──
-- Hii inagusa rekodi ambazo sababu yake ni ya KIUFUNDI (router/MikroTik/
-- tariff) - siyo mteja kukataa kulipa. Kwa hizo, pesa ILIINGIA.
--
-- next_attempt_at = NOW() : cron ijaribu mara moja inayofuata.
-- Hakuna hatari ya vocha mbili: completeVoucherPayment() ina ulinzi wa
-- claimed_at, na rekodi yoyote iliyokwisha kuwa 'completed' haiguswi hapa.
UPDATE payment_transactions
   SET status          = 'paid_pending_voucher',
       next_attempt_at = NOW(),
       updated_at      = NOW()
 WHERE status = 'failed'
   AND voucher_code IS NULL
   AND gateway_reference IS NOT NULL
   AND (
        fail_reason LIKE '%Router ya mtoa huduma haipatikani%'
     OR fail_reason LIKE '%Imeshindikana kupandisha MikroTik%'
     OR fail_reason LIKE '%Kifurushi hakipatikani tena%'
     OR fail_reason LIKE '%Router haijulikani%'
   );

-- ── 5. WEKA ROUTERS ZILIZOPO KWENYE JEDWALI LA AFYA ──
-- 'unknown' mpaka check ya kwanza ipite - hatutaki alert za uongo
-- za "imerudi online" kwa router ambayo hatujawahi kuipima.
INSERT IGNORE INTO router_health (router_id, status)
SELECT router_id, 'unknown' FROM mikrotik_configs;
