# Kuunganisha Malipo — Snippe

Mfumo unatumia **Snippe** (`https://api.snippe.sh`) kwa malipo halisi ya
mobile money — kuingiza (vocha + subscription) na kutoa (cash-out).

Gateway zilizotangulia (**Dalipay**, kisha **AzamPay**) zimeondolewa kabisa.

> **SIRI HAZIINGII KWENYE GIT KAMWE.** Zinapokelewa kama `env[...]` ndani ya
> FPM pool. Kila `«...»` hapa chini badilisha na thamani halisi.

---

## 0. Kwa nini Snippe ni rahisi kuliko zilizotangulia

| | Dalipay | AzamPay | **Snippe** |
|---|---|---|---|
| Uthibitisho | key mbili | token inayoisha muda | **API key moja** |
| Kuuliza hali ya malipo | ✅ | ❌ **hakuna kabisa** | ✅ |
| Poll kama kinga ya webhook | ✅ | ❌ | ✅ |
| Cash-out: Vodacom | ✅ | ❌ | ✅ |
| Cash-out: Halotel | ✅ | ❌ | ✅ |
| Mtandao unatambuliwaje | ramani yetu | ramani yetu | **Snippe wenyewe** |
| Kinga ya malipo mara mbili | rejea yetu | rejea yetu | **Idempotency-Key** |

Mambo mawili yaliyorudi na Snippe, ambayo AzamPay ilikuwa imeyaondoa:

1. **Poll ni kinga tena.** `GET /v1/payments/{reference}` ipo. Webhook
   ikipotea, mteja aliyesimama pale bado anaunganishwa — hakuna tena
   hali ya "kila mteja anakwama mpaka admin aingilie".

2. **Vodacom inaweza kutolewa pesa.** Snippe hutambua mtandao wenyewe
   kutoka namba, hivyo hatuna ramani ya kudumisha na mitandao yote minne
   inafanya kazi pande zote mbili.

---

## 1. VPS — weka siri kwenye FPM pool

```bash
ssh root@143.246.136.110
nano /etc/php-fpm-tech5g/pool.d/tech5g.conf
```

> **Njia hii SIYO ya kubahatisha.** Webuzo hu-regenerate
> `/usr/local/apps/php82/etc/php-fpm.conf` kila usiku na kufuta mstari wa
> `include=` — jambo lililozima tovuti kwa saa 5+ tarehe 2026-08-08. App ina
> service yake binafsi (`php-fpm-tech5g.service`). Usirudishe pool kwenye `php-fpm.d/`.

Ongeza ndani ya block ya pool (pamoja na `env[...]` zilizopo za DB na `MIKROTIK_ENC_KEY`):

```ini
env[SNIPPE_API_KEY]        = "«snp_...»"
env[SNIPPE_WEBHOOK_SECRET] = "«whsec_...»"
```

Kisha:

```bash
systemctl restart php-fpm-tech5g
```

Hifadhi nakala kwenye `/root/.tech5g-credentials` (chmod 600) — cron ya
`poll_payouts.php` inasoma hapo.

**API key inapatikana wapi:** Dashboard → **Settings → API Keys** →
*Create API Key*. Scopes zinazohitajika (zote nne):

| Scope | Inatumika wapi |
|---|---|
| `collection:create` | `lipia.php`, `start_subscription_payment.php` |
| `collection:read` | `check_payment_status.php`, `check_subscription_status.php` |
| `disbursement:create` | `payout_helper.php` (admin athibitishapo cash-out) |
| `disbursement:read` | `poll_payouts.php` |

> Key inaonyeshwa **mara moja tu**. Ikipotea, tengeneza mpya.

**Webhook secret inapatikana wapi:** Dashboard → **Settings → Webhook Secret**.

### Kwa PC yako (development)

CLI na Apache ya PC yako hazina `env[...]` za FPM pool. Weka siri kwenye
`secrets.local.php` (imezuiwa na `.gitignore: *.local.php`):

```php
<?php
return [
    'SNIPPE_API_KEY'        => 'snp_...',
    'SNIPPE_WEBHOOK_SECRET' => 'whsec_...',
];
```

`config.php` huisoma kama ipo. **Env halisi ya seva daima inashinda**, hivyo
faili iliyosahaulika haiwezi kuteka production.

> ⚠️ Funguo za production zikiwa kwenye faili hiyo, **MOCK inazimika na
> kila jaribio la PC yako linatumia pesa HALISI.** Kwa majaribio ya
> kawaida, toa `SNIPPE_API_KEY` kwenye faili hiyo.

> ⚠️ Bila `SNIPPE_WEBHOOK_SECRET` **kila webhook inakataliwa (401)**. Ni
> sahihi kufanya hivyo: bila kuthibitisha saini, yeyote anayejua URL
> angeweza kutuma `payment.completed` ya uongo na kujipa vocha bure.
> Lakini maana yake ni kwamba **ukisahau kuiweka, hakuna vocha itakayotoka
> kiotomatiki** (poll bado itaokoa wateja walio mtandaoni).

> **Bila `SNIPPE_API_KEY` mfumo unarudi MOCK** (`PAYMENT_MOCK_MODE`) —
> unaotoa vocha bila malipo. Sawa kwenye PC yako, **KAMWE** production.
> Thibitisha §4(b).

---

## 2. Database

```bash
set -a; . /root/.tech5g-credentials; set +a
/usr/local/apps/mariadb1011/bin/mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    < /var/www/tech5g/migrations/2026-09-02_snippe_gateway.sql
```

Inafanya matatu:

1. **Index kwenye `gateway_reference`** — webhook hutafuta kwa hiyo.
2. **Column `payout_requests.fee_amount`** — ada ya Snippe kwa kila
   cash-out (§6). Rekodi za zamani zinabaki `0.00`.
3. Comments za schema.

Haifuti data yoyote.

### ⚠️ Kagua vitu viwili KABLA ya kuwasha

**(a) Tariff zilizo chini ya TSh 500** — Snippe haikubali chini ya hapo:

```sql
SELECT t.router_id, t.package_type, t.price, m.router_name
  FROM tariffs t
  LEFT JOIN mikrotik_configs m ON m.router_id = t.router_id
 WHERE t.price < 500
 ORDER BY t.router_id, t.price;
```

Zilizoonekana **haziwezi kulipiwa**. `lipia.php` humzuia mteja mapema kwa
ujumbe wazi, lakini reseller anapaswa kupanga bei upya.

**(b) Cash-out zilizokwama kwenye gateway ya zamani:**

```sql
SELECT id, user_id, phone_number, amount, gateway_reference, created_at
  FROM payout_requests WHERE status = 'awaiting_approval' ORDER BY id;
```

Hizi zilitumwa kwa gateway ya ZAMANI. Snippe hawaijui rejea zao, hivyo
`poll_payouts.php` **haitawahi** kuzikamilisha. Zishughulikie kwa mkono:
thibitisha kwenye dashboard ya gateway ya zamani, kisha weka `success`
(zililipwa) au tumia "Kataa" (hazikulipwa — salio linarudi kwa reseller).

---

## 3. Webhook

**Hakuna cha kusanidi kwenye dashboard.** Mfumo hutuma `webhook_url` ndani
ya kila ombi, ikielekeza:

```
https://tech5g.co.tz/snippe_webhook.php
```

Endpoint moja hushughulikia matukio yote — `payment.completed`,
`payment.failed`, `payment.voided`, `payment.expired`, `payout.completed`,
`payout.failed`, `payout.reversed`.

**Usalama:** kila payload imesainiwa —
`X-Webhook-Signature = hex(HMAC-SHA256(secret, "{timestamp}.{body ghafi}"))`.
Tunathibitisha kwa `hash_equals()` (dhidi ya timing attack) na kukataa
payload iliyo na umri zaidi ya **dakika 5** (dhidi ya replay).

**Kurudiwa:** Snippe hujaribu mara 5 (3, 6, 12, 24 dakika). Tukio lilelile
linaweza kufika mara kadhaa — `claimed_at` (vocha) na ukaguzi wa hali
(cash-out) huhakikisha kinatendeka **mara moja tu**.

---

## 4. Thibitisha

**(a) API key inakubalika** (hairudishi pesa — inasoma salio tu):

```bash
curl -sS https://api.snippe.sh/v1/payments/balance \
  -H "Authorization: Bearer «snp_...»"
```

| Jibu | Maana |
|------|-------|
| `200` + `data.available` | ✅ Sawa |
| `401 unauthorized` | ❌ Key si sahihi / haijawekwa |
| `403 insufficient_scope` | ❌ Key haina scope inayohitajika (§1) |

**(b) Mfumo hauko kwenye MOCK** (endesha kwenye VPS):

```bash
cd /var/www/tech5g
set -a; . /root/.tech5g-credentials; set +a
/usr/local/emps/bin/php -r 'require "config.php"; var_dump(SNIPPE_ENABLED, PAYMENT_MOCK_MODE);'
```

Lazima iwe `bool(true)` na `bool(false)`.

> ⚠️ CLI hairithi `env[...]` za FPM pool. Bila `set -a; . /root/...`
> utaona MOCK hata kama pool imesanidiwa sawa.

**(c) Webhook inafikika kutoka nje:**

```bash
curl -sS -o /dev/null -w "%{http_code}\n" -X POST \
  https://tech5g.co.tz/snippe_webhook.php \
  -H "Content-Type: application/json" -d '{"type":"payment.completed"}'
```

| Jibu | Maana |
|------|-------|
| `401` | ✅ **HII NDIYO SAHIHI** — inafikika na imekataa payload isiyo na saini |
| `404` / `502` | ❌ Haifikiki — kagua nginx/Apache na firewall (`ufw status`) |
| `200` | ❌ **HATARI** — ukaguzi wa saini haufanyi kazi. Usiendelee. |

> Tofauti na AzamPay, webhook ikishindwa **siyo janga**: poll ya
> `check_payment_status.php` bado inamuunganisha mteja. Lakini irekebishe —
> bila webhook, cash-out hutegemea cron pekee (mpaka dakika 5 kuchelewa).

**(d) Malipo halisi ya mwisho:** nunua kifurushi cha bei ndogo (≥ TSh 500)
kwa namba yako mwenyewe kwenye router halisi:

1. USSD prompt inafika simuni;
2. baada ya PIN, ukurasa unabadilika kuwa "Umeunganishwa Kikamilifu";
3. `malipo_status.php` inaonyesha **COMPLETED** yenye voucher code;
4. muamala mmoja tu, vocha moja tu (siyo mbili).

**(e) Cash-out ya majaribio:** kiasi kidogo kwa namba yako. Fuatilia:
`pending` → admin athibitishe → `awaiting_approval` → (webhook au cron) →
`success`.

---

## 5. Cron ya cash-out

Webhook huharakisha matokeo, lakini Snippe wanaacha baada ya majaribio 5.
Cron ndiyo kinga:

```
*/5 * * * * root set -a; . /root/.tech5g-credentials; set +a; \
  /usr/local/apps/php82/bin/php /var/www/tech5g/poll_payouts.php >> /var/log/tech5g-payouts.log 2>&1
```

---

## 6. ADA — Tech5G HAITOZI CHOCHOTE

Ada zote ni za **Snippe**, zinazopitishwa kwa mmiliki wa router kama
zilivyo. Tech5G haiongezi senti wala haichukui sehemu — mizania ni **sifuri**.

| | Ada ya Snippe | Nani analipa |
|---|---|---|
| Malipo yanayoingia | **2.50%** ya kiasi | mmiliki wa router (inakatwa kwenye net) |
| Cash-out | **TSh 1,500 FLAT** | mmiliki wa router (inashikiliwa pamoja na kiasi) |

**Malipo yanayoingia.** Mteja akilipa TSh 1,000: Snippe wanachukua 25,
salio letu linapokea 975, mmiliki anaandikiwa 975. Tech5G: **0**.

**Cash-out.** Ada ni **flat**, hivyo haiwezi kutoka kwenye asilimia:

| Anaomba | Anapokea | Salio linapungua | Ada ni % ngapi |
|---|---|---|---|
| 5,000 | **5,000** | 6,500 | 30.0% |
| 20,000 | **20,000** | 21,500 | 7.5% |
| 100,000 | **100,000** | 101,500 | 1.5% |

Mpokeaji **daima anapata kiasi kamili** alichoomba. Ada inakatwa kwenye
salio lake juu ya kiasi hicho.

> **`MIN_PAYOUT` ilipandishwa 1,000 → 5,000.** Kwa ada ya TSh 1,500 flat,
> ombi la TSh 1,000 lingemgharimu mmiliki 1,500 kutoa 1,000 — zaidi ya
> anachopokea. Hata kwa 5,000 ada ni 30%; washauri wamiliki wakusanye
> kabla ya kutoa. **Usishushe `MIN_PAYOUT` karibu na 1,500.**

**Ada inahifadhiwaje:** kwenye `payout_requests.fee_amount` (row yenyewe),
siyo kukokotolewa wakati wa kusoma. Sababu mbili:

1. Snippe wakibadilisha ada, maombi ya zamani yanabaki na ada yao halisi.
2. Ombi likishindikana, **kiasi NA ada vinarudi vyenyewe** — hali
   inabadilika tu. Hakuna njia ya "refund" inayoongeza salio, hivyo
   hakuna njia ya kuliongeza mara mbili kimakosa.

**Ada zikibadilika upande wa Snippe,** badilisha `GATEWAY_FEE_PERCENT` na
`GATEWAY_PAYOUT_FEE` ndani ya `balance_helper.php` — ndipo pekee
zinapofafanuliwa.

> ⚠️ Salio letu la Snippe lazima liwe na pesa za kutosha kabla ya
> kuthibitisha cash-out. Kagua:
> `curl -sS https://api.snippe.sh/v1/payments/balance -H "Authorization: Bearer «snp_...»"`

---

## Utatuzi wa haraka

| Dalili | Sababu / Suluhisho |
|--------|--------------------|
| Vocha zinatoka bila malipo | Mfumo uko MOCK. §4(b), kisha `systemctl restart php-fpm-tech5g`. |
| "Imeshindikana kuanzisha malipo" | `admin_error_logs.php` ina sababu halisi kutoka Snippe. |
| Webhook zote 401 | `SNIPPE_WEBHOOK_SECRET` haifanani na ya Dashboard, **au** saa ya seva imepotoka zaidi ya dakika 5 (`timedatectl`). |
| Muamala unakwama 'pending' | Webhook haifiki NA poll haipati hali. Kagua §4(a) na §4(c). |
| "Kifurushi kiko chini ya kiwango" | Tariff chini ya TSh 500 — §2(a). |
| `insufficient_scope` (403) | Key haina scope. Tengeneza mpya yenye zote nne (§1). |
| Cash-out: "insufficient balance" | **Salio LETU la Snippe**, siyo la reseller. Ongeza float. Salio la reseller limerudishwa kiotomatiki (kiasi + ada). |
| Reseller: "Salio halitoshi" akiwa na pesa | Anasahau ada ya TSh 1,500. Kutoa 5,000 kunahitaji 6,500 (§6). |
| Cash-out imekwama 'awaiting_approval' | `fail_reason` ikisema "HALI HAIJULIKANI", kagua dashboard ya Snippe **kabla** ya kujaribu tena — salio halijarudishwa kwa makusudi. |
| Malipo yamekamilika lakini hakuna vocha | Router ilikuwa chini. `malipo_status.php` → **Kukamilisha** (haitumii pesa mpya). |

---

## Kilichobaki (HAKIJAFANYWA)

**Bank transfer payouts hazijaunganishwa.** Snippe wanaunga mkono benki 40+
(`channel: "bank"`), lakini cash-out yetu yote ni ya mobile money. Kuiongeza
kungehitaji: uwanja wa bank code + namba ya akaunti kwenye profile ya
reseller, na ukaguzi wa jina la mwenye akaunti.

**Ada ya Snippe haionyeshwi kwa admin kabla ya kuthibitisha.**
`snippePayoutFee()` ipo tayari na inafanya kazi; haijaunganishwa kwenye
UI ya `admin.php`.
