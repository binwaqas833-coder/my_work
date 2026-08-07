-- ============================================================
-- 2026-08-07_dalipay_disbursements.sql
-- Kuunganisha KUTOA PESA (cash-out ya reseller) na Dalipay Disbursements API.
--
-- Kuitumia kwenye VPS:
--   set -a; . /root/.tech5g-credentials; set +a
--   mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-08-07_dalipay_disbursements.sql
--
-- Salama kuiendesha zaidi ya mara moja. HAIGUSI data iliyopo.
-- ============================================================

-- ── HALI MPYA ZA OMBI LA CASH-OUT ──
-- Awali: pending -> approved | rejected, ambapo "approved" ilimaanisha tu
-- "admin amekubali", siyo "pesa imefika". Sasa tunatofautisha:
--   pending            = reseller ameomba, salio limeshikwa
--   awaiting_approval  = tumeituma Dalipay; inasubiri operator wa Dalipay
--   success            = pesa imefika kwa mpokeaji
--   failed             = imeshindikana (salio hurudishwa)
--   rejected           = admin/Dalipay wamekataa (salio hurudishwa)
--   approved           = (ya zamani) inabaki kwa rekodi zilizolipwa kwa mkono
ALTER TABLE payout_requests
    MODIFY COLUMN status ENUM('pending','approved','awaiting_approval','success','failed','rejected')
        NOT NULL DEFAULT 'pending';

ALTER TABLE payout_requests
    -- rejea YETU inayotumwa kama external_id (PO-XXXXXXXXXXXX)
    ADD COLUMN IF NOT EXISTS external_id       VARCHAR(30)  NULL AFTER amount,
    -- vitambulisho vya gateway. MUHIMU: hali ya disbursement huulizwa kwa
    -- REFERENCE (dsb_...), siyo uuid - tofauti na collections.
    ADD COLUMN IF NOT EXISTS gateway_uuid      VARCHAR(64)  NULL AFTER external_id,
    ADD COLUMN IF NOT EXISTS gateway_reference VARCHAR(64)  NULL AFTER gateway_uuid,
    ADD COLUMN IF NOT EXISTS fail_reason       VARCHAR(255) NULL AFTER status,
    -- ukaguzi: nani aliidhinisha na lini
    ADD COLUMN IF NOT EXISTS approved_by       INT          NULL AFTER fail_reason,
    ADD COLUMN IF NOT EXISTS approved_at       DATETIME     NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS updated_at        DATETIME     NULL AFTER created_at;

-- external_id lazima iwe ya kipekee: ndiyo inayozuia malipo kutumwa MARA MBILI
-- kwa ombi lilelile endapo mtu atabofya "Thibitisha" mara mbili.
ALTER TABLE payout_requests
    ADD UNIQUE KEY IF NOT EXISTS uq_external_id (external_id);

ALTER TABLE payout_requests
    ADD KEY IF NOT EXISTS idx_status (status),
    ADD KEY IF NOT EXISTS idx_gateway_reference (gateway_reference);
