-- ============================================================
-- 2026-08-08_seed_subscription_plans.sql
-- Kuweka subscription plans za msingi.
--
-- KWA NINI: jedwali la subscription_plans lilikuwa TUPU kwenye production.
-- Reseller mpya anapoidhinishwa hupewa trial yenye plan_id = NULL, na
-- subscription_helper.php humpa max_routers = 1 kwa trial isiyo na plan.
-- Kwa kuwa hakukuwa na plan YOYOTE ya ku-upgrade, kila merchant alikwama
-- na router MOJA - hakuweza kuongeza ya pili wala ya tatu, na ujumbe wa
-- "Fanya upgrade ya plan" ulimpeleka kwenye ukurasa usio na chaguo lolote.
--
-- Kuitumia kwenye VPS:
--   set -a; . /root/.tech5g-credentials; set +a
--   mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-08-08_seed_subscription_plans.sql
--
-- Salama kuiendesha zaidi ya mara moja: plan_name ni UNIQUE sasa, na
-- tunatumia INSERT ... ON DUPLICATE KEY UPDATE ambayo HAIBADILISHI bei
-- ulizoweka mwenyewe baadaye (inagusa tu rows ambazo hazipo).
-- ============================================================

-- ── (1) Zuia plans zenye majina yanayojirudia ──
-- admin.php sasa ina "Plan Mpya"; bila UNIQUE, admin angeweza kutengeneza
-- "Kati" mbili na resellers wasijue ipi ni ipi.
SET @dup := (
    SELECT COUNT(*) FROM (
        SELECT plan_name FROM subscription_plans GROUP BY plan_name HAVING COUNT(*) > 1
    ) x
);
SET @sql := IF(@dup > 0,
    'SELECT ''ONYO: kuna plans zenye majina yanayojirudia - zisafishe kwanza, UNIQUE haijawekwa.'' AS onyo',
    'SELECT ''Hakuna majina yanayojirudia.'' AS hali');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'subscription_plans'
       AND INDEX_NAME   = 'uniq_plan_name'
);
SET @sql := IF(@idx = 0 AND @dup = 0,
    'ALTER TABLE subscription_plans ADD UNIQUE KEY uniq_plan_name (plan_name)',
    'SELECT ''uniq_plan_name imerukwa.'' AS hali');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── (2) Plans za msingi ──
-- Bei ni za mwaka (ndivyo admin.php inavyoonyesha: "/mwaka").
-- Badilisha bei/idadi wakati wowote kupitia Admin -> Bei za Subscription Plans.
INSERT INTO subscription_plans (plan_name, max_routers, price, is_active) VALUES
    ('Mwanzo',  1,   50000.00, 1),
    ('Kati',    3,  120000.00, 1),
    ('Biashara',10, 300000.00, 1)
ON DUPLICATE KEY UPDATE plan_name = VALUES(plan_name);

SELECT id, plan_name, max_routers, price, is_active FROM subscription_plans ORDER BY price ASC;
