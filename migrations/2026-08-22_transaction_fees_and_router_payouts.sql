-- ============================================================
-- 2026-08-22_transaction_fees_and_router_payouts.sql
-- ------------------------------------------------------------
-- ADA YA 3.8% NA SALIO LA KILA ROUTER
--
-- TATIZO LILILOKUWEPO:
--   • Hakuna sehemu yoyote iliyokuwa inakokotoa ada ya 3.8%.
--   • users.balance HAIKUWAHI kuongezwa malipo yanapokamilika - iliongezwa
--     TU na refundPayout(). Hivyo kila reseller alikuwa na salio 0.00 na
--     cash_out.php haikuweza kuonyesha kitu chochote cha kutoa.
--   • payout_requests haikujua ombi ni la router ipi, hivyo pesa za router
--     moja zingeweza kuonekana kama salio la router nyingine.
--
-- SULUHISHO (chanzo KIMOJA cha ukweli):
--   • Ada na kiasi halisi (net) vinahifadhiwa kwenye payment_transactions
--     yenyewe, mara MOJA, wakati muamala unakamilika (payment_helper.php).
--   • Salio la kutoa HALIHIFADHIWI popote - linakokotolewa:
--         SUM(net_amount ya completed za router hii)
--       - SUM(amount ya payout_requests za router hii zisizo failed/rejected)
--     Hivyo 3.8% HAIKATWI MARA YA PILI wakati wa cash-out, na ombi lilelile
--     haliwezi kutolewa mara mbili.
--   • users.balance haitumiki tena (imeachwa hapa kwa historia tu).
--
-- Kuiweka:
--   mysql -h127.0.0.1 -u<user> -p login_signup < migrations/2026-08-22_transaction_fees_and_router_payouts.sql
-- ============================================================

USE login_signup;

-- ── 1. Ada na kiasi halisi kwa kila muamala ──
ALTER TABLE payment_transactions
    ADD COLUMN fee_percent DECIMAL(6,3)  NOT NULL DEFAULT 3.800 AFTER amount,
    ADD COLUMN fee_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00  AFTER fee_percent,
    ADD COLUMN net_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00  AFTER fee_amount;

-- Index ya swali la salio: (mmiliki, router, hali)
ALTER TABLE payment_transactions
    ADD KEY idx_owner_router_status (user_id, router_id, status);

-- Backfill: muamala ulioshakamilika kabla ya migration hii bado hauna ada.
-- Kanuni ni ile ile inayotumika kwenye PHP: ROUND(gross * 3.8 / 100, 2).
UPDATE payment_transactions
   SET fee_percent = 3.800,
       fee_amount  = ROUND(amount * 3.800 / 100, 2),
       net_amount  = amount - ROUND(amount * 3.800 / 100, 2)
 WHERE status = 'completed'
   AND net_amount = 0.00;

-- ── 2. Ombi la cash-out sasa ni LA ROUTER MOJA ──
-- NULL = ombi la zamani (kabla ya migration hii) lisilojulikana router yake.
-- balance_helper.php inayapunguza kwenye jumla ya mmiliki, siyo kwenye
-- router yoyote mahsusi.
ALTER TABLE payout_requests
    ADD COLUMN router_id INT NULL AFTER user_id,
    ADD KEY idx_owner_router_status (user_id, router_id, status);

ALTER TABLE payout_requests
    ADD CONSTRAINT fk_payout_router FOREIGN KEY (router_id)
        REFERENCES mikrotik_configs(router_id) ON DELETE SET NULL;
