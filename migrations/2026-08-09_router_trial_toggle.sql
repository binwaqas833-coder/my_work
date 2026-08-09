-- ============================================================
-- 2026-08-09_router_trial_toggle.sql
-- Ruhusu merchant kuwasha/kuzima "Jaribu Dakika 5 Bure" kwa kila router.
--
-- KWA NINI: awali trial ilikuwa IMEWASHWA KWA NGUVU kwa kila router
-- (script ya setup inaweka login-by=...,trial na trial-uptime-limit=5m),
-- na kitufe cha "Jaribu Dakika 5 Bure" kilionekana kwa kila mteja bila
-- merchant kuwa na uwezo wa kukizima. Baadhi ya maeneo (mfano hoteli au
-- maeneo yenye msongamano) hawataki kutoa dakika za bure hata kidogo.
--
-- Kuitumia kwenye VPS:
--   set -a; . /root/.tech5g-credentials; set +a
--   mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
--       < /var/www/tech5g/migrations/2026-08-09_router_trial_toggle.sql
--
-- Salama kuiendesha zaidi ya mara moja.
-- Default ni 1 (imewashwa) ili tabia ya sasa isibadilike ghafla kwa
-- routers zilizopo.
-- ============================================================

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mikrotik_configs'
       AND COLUMN_NAME  = 'trial_enabled'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE mikrotik_configs
        ADD COLUMN trial_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER allowed_ips',
    'SELECT ''trial_enabled tayari ipo.'' AS hali');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT router_id, router_label, mikrotik_ip, trial_enabled
  FROM mikrotik_configs ORDER BY router_id;
