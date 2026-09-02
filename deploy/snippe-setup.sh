#!/usr/bin/env bash
# ==================================================================
# deploy/snippe-setup.sh — kusanidi Snippe kwenye VPS
# ------------------------------------------------------------------
# Endesha KWENYE VPS kama root:
#
#   cd /var/www/tech5g
#   git pull origin rekebisha-cash-out-ada-3.8
#   bash deploy/snippe-setup.sh
#
# HAINA SIRI NDANI YAKE. Inaziuliza, au inazisoma kutoka
# /root/.tech5g-credentials kama tayari zipo.
#
# NI SALAMA KUIENDESHA MARA NYINGI: kila hatua inakagua kwanza,
# na pool config inanakiliwa kabla ya kuguswa.
# ==================================================================
set -euo pipefail

POOL=/etc/php-fpm-tech5g/pool.d/tech5g.conf
CREDS=/root/.tech5g-credentials
APP=/var/www/tech5g
PHP=/usr/local/apps/php82/bin/php
MYSQL=/usr/local/apps/mariadb1011/bin/mysql

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
ylw()  { printf '\033[33m%s\033[0m\n' "$*"; }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { red "Endesha kama root."; exit 1; }
[ -f "$POOL" ] || { red "Pool haipo: $POOL"; exit 1; }

# ── 1. SIRI ────────────────────────────────────────────────────────
step "1/6  Siri za Snippe"

[ -f "$CREDS" ] && . "$CREDS" 2>/dev/null || true

if [ -z "${SNIPPE_API_KEY:-}" ]; then
    read -rsp "  SNIPPE_API_KEY (snp_...): " SNIPPE_API_KEY; echo
fi
if [ -z "${SNIPPE_WEBHOOK_SECRET:-}" ]; then
    read -rsp "  SNIPPE_WEBHOOK_SECRET (whsec_...): " SNIPPE_WEBHOOK_SECRET; echo
fi

case "$SNIPPE_API_KEY" in snp_*) ;; *) red "  API key lazima ianze na 'snp_'."; exit 1;; esac
case "$SNIPPE_WEBHOOK_SECRET" in whsec_*) ;; *) red "  Webhook secret lazima ianze na 'whsec_'."; exit 1;; esac
grn "  ✓ muundo wa siri ni sahihi"

# ── 2. THIBITISHA NA SNIPPE KABLA YA KUGUSA CHOCHOTE ──────────────
step "2/6  Thibitisha key na Snippe (read-only)"

HTTP=$(curl -sS -o /tmp/snippe_bal.json -w '%{http_code}' -m 25 \
       https://api.snippe.sh/v1/payments/balance \
       -H "Authorization: Bearer $SNIPPE_API_KEY" || echo 000)

case "$HTTP" in
  200) grn "  ✓ key inakubalika · salio: $(sed -n 's/.*"available":{[^}]*"value":\([0-9]*\).*/\1/p' /tmp/snippe_bal.json) TZS" ;;
  401) red "  ✗ 401 - key si sahihi."; exit 1 ;;
  403) red "  ✗ 403 - key haina scope. Inahitaji collection:read/create + disbursement:read/create."; exit 1 ;;
  *)   red "  ✗ HTTP $HTTP - siendelei."; exit 1 ;;
esac
rm -f /tmp/snippe_bal.json

# ── 3. HIFADHI SIRI ────────────────────────────────────────────────
step "3/6  Hifadhi siri ($CREDS + FPM pool)"

touch "$CREDS"; chmod 600 "$CREDS"
for kv in "SNIPPE_API_KEY=$SNIPPE_API_KEY" "SNIPPE_WEBHOOK_SECRET=$SNIPPE_WEBHOOK_SECRET"; do
    k="${kv%%=*}"
    if grep -q "^export $k=" "$CREDS" 2>/dev/null; then
        sed -i "s|^export $k=.*|export $kv|" "$CREDS"
    else
        echo "export $kv" >> "$CREDS"
    fi
done
grn "  ✓ $CREDS imesasishwa (chmod 600) — cron inaisoma"

BAK="$POOL.bak.$(date +%Y%m%d-%H%M%S)"
cp -a "$POOL" "$BAK"
grn "  ✓ nakala ya pool: $BAK"

# Ondoa env za gateway za zamani (Dalipay/AzamPay) kama zipo
sed -i '/^env\[DALIPAY_/d; /^env\[AZAMPAY_/d' "$POOL"

# Weka/sasisha env za Snippe
for kv in "SNIPPE_API_KEY=$SNIPPE_API_KEY" "SNIPPE_WEBHOOK_SECRET=$SNIPPE_WEBHOOK_SECRET"; do
    k="${kv%%=*}"; v="${kv#*=}"
    if grep -q "^env\[$k\]" "$POOL"; then
        sed -i "s|^env\[$k\].*|env[$k] = \"$v\"|" "$POOL"
    else
        echo "env[$k] = \"$v\"" >> "$POOL"
    fi
done
grn "  ✓ pool imesasishwa"

# ── 4. DATABASE ────────────────────────────────────────────────────
step "4/6  Migration"

MIG="$APP/migrations/2026-09-02_snippe_gateway.sql"
[ -f "$MIG" ] || { red "  Migration haipo: $MIG — je umefanya 'git pull'?"; exit 1; }

"$MYSQL" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIG"
grn "  ✓ migration imepita"

echo "  Ukaguzi:"
"$MYSQL" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "
  SELECT CONCAT('    tariff chini ya TSh 500: ', COUNT(*)) FROM tariffs WHERE price < 500;
  SELECT CONCAT('    cash-out zilizokwama (gateway ya zamani): ', COUNT(*))
    FROM payout_requests WHERE status='awaiting_approval';
  SELECT CONCAT('    cash-out pending zisizo na ada: ', COUNT(*))
    FROM payout_requests WHERE status='pending' AND fee_amount = 0;"

# ── 5. WASHA UPYA ──────────────────────────────────────────────────
step "5/6  Washa upya PHP-FPM"
systemctl restart php-fpm-tech5g
sleep 2
systemctl is-active --quiet php-fpm-tech5g \
  && grn "  ✓ php-fpm-tech5g inafanya kazi" \
  || { red "  ✗ imeshindwa kuanza. Rudisha: cp $BAK $POOL && systemctl restart php-fpm-tech5g"; exit 1; }

# ── 6. THIBITISHA ──────────────────────────────────────────────────
step "6/6  Thibitisho la mwisho"

set -a; . "$CREDS"; set +a
cd "$APP"
"$PHP" -r 'require "config.php";
  printf("    SNIPPE_ENABLED   : %s\n", var_export(SNIPPE_ENABLED,true));
  printf("    PAYMENT_MOCK_MODE: %s\n", var_export(PAYMENT_MOCK_MODE,true));
  printf("    Webhook URL      : %s/snippe_webhook.php\n", APP_BASE_URL);
  printf("    Ada: %s%% kuingiza, TSh %s kutoa\n", GATEWAY_FEE_PERCENT ?? "?", "1,500");'

WH=$(curl -sS -o /dev/null -w '%{http_code}' -m 20 -X POST \
     https://tech5g.co.tz/snippe_webhook.php \
     -H 'Content-Type: application/json' -d '{"type":"payment.completed"}' || echo 000)

echo
case "$WH" in
  401) grn "  ✓ webhook inafikika na inakataa payload isiyo na saini (401 = SAHIHI)" ;;
  200) red "  ✗ webhook imekubali payload isiyo na saini! Kagua SNIPPE_WEBHOOK_SECRET."; exit 1 ;;
  *)   ylw "  ⚠ webhook: HTTP $WH — kagua nginx/Apache na 'ufw status'" ;;
esac

step "IMEKAMILIKA"
cat <<'DONE'
  Kilichobaki kwako:
    1. Nunua kifurushi kimoja cha bei ndogo (≥ TSh 500) kwa namba yako
       kwenye router halisi — thibitisha vocha inatoka.
    2. Cash-out ndogo — thibitisha inafika.
    3. Cron ya poll_payouts.php (DEPLOY_SNIPPE.md §5) kama haipo bado.
    4. BADILISHA funguo zote mbili kwenye dashboard ya Snippe — zilipita
       kwenye chat, hivyo zinapaswa kuchukuliwa kama zimevuja.
DONE
