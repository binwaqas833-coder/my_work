-- ============================================================
-- 2026-08-09_wg_peers.sql
-- Kufuatilia tunnel za WireGuard zilizotolewa kwa resellers.
--
-- KWA NINI: kuongeza router ilikuwa inahitaji admin mwenye SSH ya root
-- kuendesha /root/add-tech5g-router.sh. Sasa reseller anaweza kujiombea
-- mwenyewe kupitia dashboard, na jedwali hili ndilo linalozuia:
--   - mtu mmoja kuchukua tunnel nyingi
--   - anwani ile ile kutolewa mara mbili
--
-- MUHIMU: private key HAIHIFADHIWI hapa (wala popote). Inaonyeshwa MARA
-- MOJA tu kwa reseller wakati wa kuomba. Tunahifadhi public key pekee,
-- ambayo ndiyo iliyo kwenye wg1.conf.
--
-- Kuitumia:
--   mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < .../2026-08-09_wg_peers.sql
-- Salama kuiendesha zaidi ya mara moja.
-- ============================================================

CREATE TABLE IF NOT EXISTS wg_peers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    tunnel_ip  VARCHAR(45)  NOT NULL,
    public_key VARCHAR(64)  NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME     NULL,
    UNIQUE KEY uq_tunnel_ip (tunnel_ip),
    KEY idx_user (user_id),
    KEY idx_active (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rekodi peer iliyotolewa kwa mkono kwa bin waqas (10.60.0.2) ili
-- self-service isijaribu kuitoa tena kwa mtu mwingine.
INSERT INTO wg_peers (user_id, tunnel_ip, public_key)
SELECT 5, '10.60.0.2', 'QnGVLxD1jgfxRCACYDRcULBBq6ccSKKUARVi3yf+yUI='
 WHERE NOT EXISTS (SELECT 1 FROM wg_peers WHERE tunnel_ip = '10.60.0.2');

SELECT p.id, p.user_id, u.username, p.tunnel_ip, p.created_at, p.revoked_at
  FROM wg_peers p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id;
