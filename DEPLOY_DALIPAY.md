# Kuunganisha Malipo Halisi — ISP Gateway ya Dalipay

Mfumo sasa unatumia **Dalipay** (`https://app.dalipay.co.tz/api/v1`) kwa malipo halisi ya
mobile money, badala ya MOCK iliyokuwa ikitoa vocha bila pesa kutoka.

> **SIRI (keys) HAZIINGII KWENYE GIT KAMWE.** Zinapokelewa kama `env[...]` ndani ya FPM pool.
> Kila `«...»` hapa chini badilisha na thamani halisi kutoka dashboard ya Dalipay → **API keys**.

---

## 0. Jinsi inavyofanya kazi

```
Mteja abonyeza "Lipa"
        │
        ▼
   lipia.php ──────────────► POST /collections  (Dalipay)
   (rekodi 'pending')                │
        │                            ▼
        │                   USSD prompt kwenye simu ya mteja
        │                            │
        │            ┌───────────────┴───────────────┐
        ▼            ▼                               ▼
  check_payment_status.php              dalipay_webhook.php
  (poll kila sek 3 — KINGA)             (gateway inatuambia — HARAKA)
        │                               │
        └──────────────┬────────────────┘
                       ▼
        payment_helper.php::completeVoucherPayment()
        → tengeneza vocha → panda MikroTik → auto-login
```

**Kwa nini njia MBILI?** Dalipay **hairudii** webhook ikishindwa kufika mara ya kwanza
(hakuna retry). Poll ndiyo kinga: hata webhook ikipotea, mteja aliyesimama pale
bado anaunganishwa. Zikifika kwa pamoja, `claimed_at` inahakikisha vocha
inatengenezwa **mara moja tu** (siyo mbili kwa malipo mamoja).

---

## 1. VPS — weka siri kwenye FPM pool

```bash
ssh root@143.246.136.110
nano /etc/php-fpm-tech5g/pool.d/tech5g.conf
```

> **Njia hii SIYO ya kubahatisha.** Pool haiko tena ndani ya
> `/usr/local/apps/php82/etc/php-fpm.d/`. Webuzo hu-regenerate
> `/usr/local/apps/php82/etc/php-fpm.conf` kila usiku saa 00:00 na kufuta mstari wa
> `include=`, jambo lililozima tovuti kwa saa 5+ tarehe 2026-08-08. Sasa app ina
> service yake binafsi (`php-fpm-tech5g.service`) yenye config nje ya himaya ya
> Webuzo. Usirudishe pool kwenye `php-fpm.d/`.

Ongeza mistari hii ndani ya block ya pool (pamoja na `env[...]` zilizopo za DB na
`MIKROTIK_ENC_KEY`):

```ini
env[DALIPAY_BASE_URL]        = "https://app.dalipay.co.tz/api/v1"
env[DALIPAY_PUBLIC_KEY]      = "«gw_pk_production_...»"
env[DALIPAY_SECRET_KEY]      = "«gw_sk_production_...»"
env[DALIPAY_CALLBACK_SECRET] = "«callback secret kutoka Settings»"
```

Kisha:

```bash
systemctl restart php-fpm-tech5g
```

Hifadhi nakala ya siri hizi kwenye `/root/.tech5g-credentials` (chmod 600), sehemu
moja na siri nyingine zote.

> **Bila keys hizi mfumo unarudi kwenye MOCK** (`PAYMENT_MOCK_MODE`) — unaotoa vocha
> bila malipo. Ni sawa kwenye PC yako ya development, lakini **KAMWE** production.
> Thibitisha §5 baada ya kuweka.

---

## 2. Database — ongeza column mpya

```bash
set -a; . /root/.tech5g-credentials; set +a
/usr/local/apps/mariadb1011/bin/mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    < /var/www/tech5g/migrations/2026-08-07_dalipay_gateway.sql
```

Inaongeza: `gateway_uuid`, `gateway_reference`, `fail_reason`, `claimed_at` kwenye
`payment_transactions`, na `gateway_uuid`/`gateway_reference` kwenye `subscriptions`.
Ni salama kuiendesha zaidi ya mara moja; haiguzi data iliyopo.

---

## 3. Dalipay — weka webhook URL

Dashboard ya Dalipay → **Settings** → Webhook URL:

```
https://tech5g.co.tz/dalipay_webhook.php
```

Endpoint hii inakataa ombi lolote lisilo na saini sahihi ya `X-Signature`
(HMAC-SHA256 ya body ghafi kwa callback secret). Bila ukaguzi huo, mtu yeyote
anayejua URL angeweza kutuma "collection.success" ya uongo na kujipa vocha bure.

---

## 4. IP whitelist (hiari lakini inashauriwa)

Dalipay → **API keys** → IP whitelisting: ongeza `143.246.136.110` (IP ya VPS).
Ukifanya hivi, keys zako hazitafanya kazi zikiibiwa na kutumika mahali pengine.

> Ukiweka whitelist, majaribio kutoka PC yako yataanza kukataliwa (403). Ongeza IP
> ya PC yako kwanza kama unataka kujaribu kutoka hapo.

---

## 5. Thibitisha

**(a) Keys zinakubalika** (haitoi pesa — inauliza muamala usiokuwepo):

```bash
curl -sS -o /dev/null -w "%{http_code}\n" \
  "https://app.dalipay.co.tz/api/v1/collections/00000000-0000-0000-0000-000000000000/status" \
  -H "X-Public-Key: «gw_pk_production_...»" \
  -H "X-Secret-Key: «gw_sk_production_...»"
```

| Jibu  | Maana                                                    |
| ----- | -------------------------------------------------------- |
| `404` | ✅ Sawa — keys zinakubalika, muamala huo tu haupo         |
| `401` | ❌ Keys si sahihi / hazijawekwa vizuri                    |
| `403` | ❌ IP ya VPS haipo kwenye whitelist (§4)                  |

**(b) Mfumo hauko kwenye MOCK** (endesha kwenye VPS):

```bash
cd /var/www/tech5g && /usr/local/emps/bin/php -r \
  'require "config.php"; var_dump(DALIPAY_ENABLED, PAYMENT_MOCK_MODE);'
```

Lazima iwe `bool(true)` na `bool(false)`.

> ⚠️ CLI hairithi `env[...]` za FPM pool. Kwa ukaguzi huu tumia
> `set -a; . /root/.tech5g-credentials; set +a` kwanza, la sivyo utaona MOCK
> hata kama pool imesanidiwa sawa.

**(c) Malipo halisi ya mwisho:** nunua kifurushi cha bei ndogo kabisa kwa namba yako
mwenyewe kwenye router halisi. Angalia:

1. USSD prompt inafika simuni;
2. baada ya PIN, ukurasa unabadilika kuwa "Umeunganishwa Kikamilifu";
3. `malipo_status.php` inaonyesha **COMPLETED** yenye voucher code;
4. muamala mmoja tu, vocha moja tu (siyo mbili).

---

## Utatuzi wa haraka

| Dalili                                        | Sababu / Suluhisho                                                                                     |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Vocha zinatoka bila malipo                    | Mfumo uko MOCK — keys hazijafika PHP. Angalia §5(b) na `systemctl restart php-fpm-tech5g`.             |
| "Imeshindikana kuanzisha malipo"              | Angalia `admin_error_logs.php` — sababu halisi kutoka gateway imeandikwa hapo.                          |
| Malipo yamekamilika lakini hakuna vocha       | Router ilikuwa chini. `malipo_status.php` → button **Kukamilisha** (haitumii pesa mpya).                |
| Muamala umekwama 'pending' milele             | Webhook haikufika NA poll ilikatika. **Kukamilisha** inafuta `claimed_at` na kujaribu tena.             |
| Mteja wa TTCL (073) hawezi kulipa             | Sahihi — Dalipay haiungi mkono TTCL. Mteja anapata ujumbe wazi kabla ya kuanzisha muamala.              |
| Webhook zote zinakataliwa (401)               | `DALIPAY_CALLBACK_SECRET` haifanani na ile ya Settings za Dalipay.                                      |

---

## Kilichobaki (HAKIJAFANYWA)

**Disbursements (kutoa pesa) bado ni ya mkono.** `cash_out.php` inaandika ombi kwenye
`payout_requests` na admin analipa mwenyewe nje ya mfumo — API ya
`POST /disbursements` **haijaunganishwa**. Kuiunganisha kunahitaji maamuzi yako
kwanza: nani athibitishe, kikomo cha kiasi kwa siku, na KYC ya akaunti ya Dalipay
lazima iwe `verified` (bila hivyo settlement za production zinazuiwa). Malipo
yanayoingia (vocha + subscription) yanafanya kazi kikamilifu bila hilo.
