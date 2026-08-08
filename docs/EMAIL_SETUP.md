# Barua pepe ya biashara — support@tech5g.co.tz (Zoho Mail)

Mwongozo wa kuweka barua pepe rasmi ya Tech 5G, na kuunganisha mfumo ili
utume OTP ya usajili.

---

## 0. Kwa nini Zoho, siyo seva yetu wenyewe?

VPS yetu **haiwezi** kuendesha mail server:

| Kipimo | Hali |
|---|---|
| Port 25 kutoka nje (kutuma) | **IMEZUIWA** na mtoa huduma |
| Port 25 kuingia (kupokea) | **IMEZUIWA** |
| Port 143 / 993 / 465 / 587 kuingia | **ZIMEFUNGWA** |
| Port 587 kutoka nje (relay) | ✅ Wazi |

Pia `tech5g.co.tz` haikuwa na **MX**, **SPF** wala **DMARC** kabisa. Kutuma OTP
kutoka IP mpya isiyo na sifa (reputation) kunamaanisha barua nyingi zingeenda
**Spam** — jambo baya sana kwa code ya kuthibitisha akaunti.

Zoho Mail (tier ya bure, domain moja) inatatua yote: mailbox halisi ya IMAP,
deliverability nzuri, na SMTP relay kupitia port 587 ambayo tayari iko wazi.

---

## 1. Fungua akaunti Zoho

1. Nenda <https://www.zoho.com/mail/> → **Sign Up** → chagua **Free Plan**
   (Forever Free — watumiaji 5, domain moja).
2. Chagua **"Sign up with a domain I already own"** na weka `tech5g.co.tz`.
3. Tengeneza mtumiaji wa kwanza: **support@tech5g.co.tz**.

---

## 2. Rekodi za DNS

DNS ya `tech5g.co.tz` iko **rodlinehost.com** (`ns1.rodlinehost.com`,
`ns2.rodlinehost.com`). Ingia kwenye paneli yao → DNS Zone Editor → ongeza:

### (a) Kuthibitisha umiliki wa domain
Zoho itakupa rekodi ya kipekee (TXT au CNAME) — **nakili ile inayoonekana
kwenye skrini yako**, mfano:

```
Aina : TXT
Jina : @        (au tech5g.co.tz)
Thamani: zoho-verification=zbXXXXXXXX.zmverify.zoho.com
```

### (b) MX — kupokea barua pepe
Futa MX yoyote ya zamani (hakuna kwa sasa), kisha ongeza tatu hizi:

| Aina | Jina | Kipaumbele | Thamani |
|---|---|---|---|
| MX | `@` | 10 | `mx.zoho.com` |
| MX | `@` | 20 | `mx2.zoho.com` |
| MX | `@` | 50 | `mx3.zoho.com` |

> Ukichagua data centre ya Ulaya wakati wa kujisajili, tumia `mx.zoho.eu`,
> `mx2.zoho.eu`, `mx3.zoho.eu`. Angalia ukurasa wa Zoho unaokuonyesha.

### (c) SPF — kuruhusu Zoho kutuma kwa niaba yetu
```
Aina : TXT
Jina : @
Thamani: v=spf1 include:zoho.com ~all
```
⚠️ Kuwe na **rekodi MOJA tu** ya SPF kwenye domain. Kama utaongeza mtoa huduma
mwingine baadaye, unganisha kwenye mstari mmoja, mfano:
`v=spf1 include:zoho.com include:nyingine.com ~all`

### (d) DKIM — saini ya barua pepe
Zoho → **Mail Admin Console** → *Domains* → `tech5g.co.tz` → *Email
Configuration* → **DKIM** → *Add selector* (tumia `zoho`) → nakili public key
utakayopewa:
```
Aina : TXT
Jina : zoho._domainkey
Thamani: v=DKIM1; k=rsa; p=<key ndefu kutoka Zoho>
```
Kisha bonyeza **Verify** kwenye Zoho.

### (e) DMARC — inashauriwa
```
Aina : TXT
Jina : _dmarc
Thamani: v=DMARC1; p=none; rua=mailto:support@tech5g.co.tz; pct=100; adkim=s; aspf=s
```
Anza na `p=none` (kuchunguza tu). Baada ya wiki 2 ukiona ripoti ni safi,
badilisha kuwa `p=quarantine` kisha `p=reject`.

### Kuthibitisha rekodi zimeenea
```bash
dig +short MX  tech5g.co.tz
dig +short TXT tech5g.co.tz
dig +short TXT zoho._domainkey.tech5g.co.tz
dig +short TXT _dmarc.tech5g.co.tz
```

---

## 3. Unganisha mfumo (OTP na alerts)

Mfumo hutuma barua pepe kupitia SMTP. Tumia **App Password** ya Zoho, siyo
password yako ya kuingia:

**Zoho → My Account → Security → App Passwords → Generate New Password**
(jina: `tech5g-app`). Nakili herufi utakazopewa.

Kisha kwenye VPS, hariri pool ya FPM:

```bash
nano /etc/php-fpm-tech5g/pool.d/tech5g.conf
```

Jaza thamani ya `SMTP_PASS` (mistari mingine tayari ipo):

```ini
env[SMTP_HOST]      = "smtp.zoho.com"
env[SMTP_PORT]      = "587"
env[SMTP_SECURE]    = "tls"
env[SMTP_USER]      = "support@tech5g.co.tz"
env[SMTP_PASS]      = "APP-PASSWORD-YAKO-HAPA"
env[MAIL_FROM]      = "support@tech5g.co.tz"
env[MAIL_FROM_NAME] = "Tech 5G Wi-Fi"
```

Weka pia kwenye `/root/.tech5g-credentials` (kwa cron/CLI), kisha:

```bash
systemctl restart php-fpm-tech5g
```

### Kupima
```bash
cd /var/www/tech5g
set -a; . /root/.tech5g-credentials; set +a
/usr/local/apps/php82/bin/php -r '
require "mailer.php";
var_dump(tech5gSendMail("email-yako@gmail.com","Jaribio","Jaribio la Tech 5G","<p>Inafanya kazi!</p>"));'
```
`bool(true)` = imefanikiwa. Ikirudisha `false`, angalia
`/var/log/php-fpm-tech5g.log`.

---

## 4. Mipangilio ya simu (mobile)

### Njia rahisi: app rasmi
Sakinisha **Zoho Mail** (Android / iOS), ingia na `support@tech5g.co.tz`.
Hakuna mipangilio ya mkono inayohitajika.

### Njia ya mkono (Gmail app, Outlook, Apple Mail)
Chagua **IMAP** (siyo POP) ili barua zisomeke kwenye vifaa vyote.

**Kupokea — IMAP**

| Kipengele | Thamani |
|---|---|
| Server | `imap.zoho.com` |
| Port | `993` |
| Usalama | **SSL/TLS** |
| Username | `support@tech5g.co.tz` |
| Password | App Password ya Zoho |

**Kutuma — SMTP**

| Kipengele | Thamani |
|---|---|
| Server | `smtp.zoho.com` |
| Port | `465` (SSL) — au `587` na STARTTLS |
| Usalama | **SSL/TLS** (au STARTTLS kwa 587) |
| Uthibitisho | **Ndiyo** — jina/password sawa na IMAP |
| Username | `support@tech5g.co.tz` |
| Password | App Password ya Zoho |

> Ukiwasha 2FA (inashauriwa), **lazima** utumie App Password kwenye simu —
> password ya kawaida itakataliwa.
>
> Kama ulichagua data centre ya Ulaya: `imap.zoho.eu` / `smtp.zoho.eu`.

### iPhone
Settings → Mail → Accounts → Add Account → **Other** → Add Mail Account →
jaza jina, email, password → **Next** → chagua **IMAP** → jaza server za juu.

### Android (Gmail app)
Gmail → Settings → Add account → **Other** → weka email → **Manual setup** →
**Personal (IMAP)** → jaza server za juu.

---

## 5. Kinachotokea kwenye mfumo baada ya haya

1. Mtumiaji anajisajili (`index.php`).
2. `process_engine.php` inathibitisha email, inatengeneza akaunti yenye
   `email_verified = 0`, na kutuma OTP ya tarakimu 6.
3. Anapelekwa `verify_email.php` kuweka code.
4. Code ikikubalika → `email_verified = 1`. Bado anasubiri **Admin
   a-approve** kama ilivyokuwa awali.
5. Akijaribu kuingia kabla ya kuthibitisha, anarudishwa kwenye ukurasa wa OTP
   na kupewa code mpya.

**Ulinzi uliopo:** OTP inahifadhiwa kama *hash* (siyo wazi), inaisha baada ya
dakika 10, majaribio 5 tu, kutuma tena mara moja kwa sekunde 60 na si zaidi
ya 5 kwa saa. Angalia `otp_helper.php`.

---

## 6. Usalama — muhimu

Password ya zamani ya Gmail (`mail_config.php`) **ilikuwa imepakiwa GitHub**.
Faili hiyo imeondolewa kwenye git na kwenye seva, na sasa iko kwenye
`.gitignore`. **Hakikisha umei-revoke** kwenye
<https://myaccount.google.com/apppasswords> — kuiondoa kwenye code
hakuizimi.

Siri mpya za SMTP **hazitakiwi kamwe** kuandikwa ndani ya code — ziko
`env[...]` kwenye pool ya FPM pekee.
