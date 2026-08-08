# Barua pepe ya biashara — support@tech5g.co.tz (kwenye VPS yetu wenyewe)

Seva ya barua pepe imesimikwa **kwenye VPS yetu** (siyo Zoho wala mtoa huduma
mwingine): **Postfix** (SMTP) + **Dovecot** (IMAP) + **OpenDKIM** (saini).

---

## 1. Hali ya sasa

| Kipengele | Hali |
|---|---|
| Kupokea barua (port 25 kuingia) | ✅ Inafanya kazi |
| IMAP kwa simu (port 993) | ✅ Inafanya kazi |
| Kutuma kwa watumiaji walio-thibitishwa (587 / 465) | ✅ Inafanya kazi |
| Saini ya DKIM | ✅ Inafanya kazi |
| Kuzuia open relay | ✅ Imezuiwa (`454 Relay access denied`) |
| **Kutuma NJE (kwenda Gmail n.k.)** | ❌ **Imezuiwa na mtoa huduma** |

### Kuhusu kutuma nje — soma hii

Mtoa huduma wa VPS (**microsafi / ms1.microsafi.com**) amezuia **port 25
kutoka nje**. Uthibitisho halisi kutoka kwenye log ya seva:

```
connect to gmail-smtp-in.l.google.com[142.251.127.27]:25: Connection timed out
connect to alt1.gmail-smtp-in.l.google.com[142.250.147.26]:25: Connection timed out
connect to alt2.gmail-smtp-in.l.google.com[142.251.127.27]:25: Connection timed out
connect to alt3.gmail-smtp-in.l.google.com[172.253.148.27]:25: Connection timed out
```

Siyo tatizo la usanidi wetu — hakuna firewall kwenye seva (`iptables` policy ni
`ACCEPT`), na port 587 na 443 zinafanya kazi vizuri kutoka seva hiyo hiyo.
Ni kizuizi cha mtandao wa mtoa huduma (jambo la kawaida kuzuia spam).

**Barua zinazokwenda nje zitakwama kwenye foleni mpaka mojawapo ya haya:**

**(a) Mtoa huduma afungue port 25 — NJIA INAYOPENDEKEZWA**
Wasiliana na microsafi/rodlinehost na uombe:
> "Please unblock outbound TCP port 25 for VPS 143.246.136.110. We run a
> legitimate mail server for tech5g.co.tz. Also please set reverse DNS (PTR)
> for 143.246.136.110 to tech5g.co.tz."

**PTR ni muhimu pia** — kwa sasa IP yetu **haina** rekodi ya PTR
(`NXDOMAIN`). Bila PTR, Gmail na Outlook hukataa au hupeleka Spam hata port
25 ikifunguliwa.

**(b) Pitisha barua kwenye relay ya port 587** (port 587 iko wazi)
Hii inahitaji akaunti ya nje (Gmail, Brevo, SendGrid n.k.). Ni mstari mmoja:

```bash
postconf -e "relayhost = [smtp.gmail.com]:587"
postconf -e "smtp_sasl_auth_enable = yes"
echo "[smtp.gmail.com]:587 akaunti@gmail.com:APP-PASSWORD" > /etc/postfix/sasl_passwd
chmod 600 /etc/postfix/sasl_passwd && postmap /etc/postfix/sasl_passwd
systemctl reload postfix
```
(`smtp_sasl_password_maps` na `smtp_sasl_security_options` tayari zimewekwa.)

> Mpaka mojawapo ifanyike, **OTP ya usajili haitawafikia watumiaji**. Kupokea
> barua na kusoma kwa simu kunafanya kazi tayari.

---

## 2. Rekodi za DNS — ZINAHITAJIKA

DNS iko **rodlinehost.com** (`ns1.rodlinehost.com`, `ns2.rodlinehost.com`).
Ingia → DNS Zone Editor → ongeza:

### (a) MX — kupokea barua
| Aina | Jina | Kipaumbele | Thamani |
|---|---|---|---|
| MX | `@` | 10 | `tech5g.co.tz` |

> Tunatumia domain yenyewe kama seva ya barua (ina rekodi A tayari:
> `143.246.136.110`) — hivyo hakuna haja ya `mail.tech5g.co.tz` wala cheti
> kingine cha TLS.

### (b) SPF — nani anaruhusiwa kutuma
```
Aina : TXT
Jina : @
Thamani: v=spf1 a mx ip4:143.246.136.110 ~all
```
⚠️ Kuwe na rekodi **MOJA tu** ya SPF kwenye domain.
Ukitumia relay ya nje (njia b hapo juu), ongeza yake pia, mfano:
`v=spf1 a mx ip4:143.246.136.110 include:_spf.google.com ~all`

### (c) DKIM — saini (funguo tayari imetengenezwa kwenye seva)
```
Aina : TXT
Jina : mail._domainkey
Thamani:
v=DKIM1; h=sha256; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwSK+SqElbcyXQV9KOE7WRW58sGnznVUp1auDQ/j88hS9AbL7b8mOp3rQuwFx/Lr0Z4Y8ASatZmgbjnYkQ+4aUdP2J64mh6MqvygQ7WuCfw5hVCWv2E5X3KdLH23sjvoCnK2+Tr/dzIvEEgiU2JdF6JA/TvH1u0/zp+2Mot3hB6Oh21ZRVorSVFh8X9SwJqMz34c6RINd0ovMV548rC+2CIg+w24ciDqzP1ZIvGMgWDiBj3Gs//0/yjvH4HUigtCoi//RGTKX7bwIIOvs8AfS1dk3Ece6iX3zElCP7uqAa660JGBPrb4JOjvXvAdxT81fstqF5LMdDbKZHE8U5Tp5mwIDAQAB
```
> Baadhi ya paneli hukataa thamani ndefu. Ikiwa hivyo, igawe kwenye vipande
> vya `"..."` viwili kama ilivyo kwenye
> `/etc/opendkim/keys/tech5g.co.tz/mail.txt`.

### (d) DMARC
```
Aina : TXT
Jina : _dmarc
Thamani: v=DMARC1; p=none; rua=mailto:support@tech5g.co.tz
```
Anza na `p=none`. Baada ya wiki mbili ukiona ripoti ni safi, badilisha kuwa
`p=quarantine` kisha `p=reject`.

### (e) PTR (reverse DNS)
**Haiwezi kuwekwa na sisi** — ni ya mtoa huduma wa VPS. Waombe iwe
`tech5g.co.tz` (angalia ujumbe wa §1a).

### Kuthibitisha
```bash
dig +short MX  tech5g.co.tz
dig +short TXT tech5g.co.tz
dig +short TXT mail._domainkey.tech5g.co.tz
dig +short TXT _dmarc.tech5g.co.tz
dig +short -x 143.246.136.110
```

---

## 3. Mipangilio ya simu (mobile)

Tumia **IMAP** (siyo POP) ili barua zionekane kwenye vifaa vyote.

**Kupokea — IMAP**

| Kipengele | Thamani |
|---|---|
| Server | `tech5g.co.tz` |
| Port | `993` |
| Usalama | **SSL/TLS** |
| Username | `support@tech5g.co.tz` (anwani KAMILI) |
| Password | ya sanduku (iko `/root/.tech5g-credentials`) |

**Kutuma — SMTP**

| Kipengele | Thamani |
|---|---|
| Server | `tech5g.co.tz` |
| Port | `465` (SSL/TLS) — au `587` na STARTTLS |
| Usalama | **SSL/TLS** (au STARTTLS kwa 587) |
| Uthibitisho | **Ndiyo** — sawa na IMAP |
| Username | `support@tech5g.co.tz` |
| Password | ile ile |

### iPhone
Settings → Mail → Accounts → Add Account → **Other** → Add Mail Account →
jaza jina/email/password → **Next** → chagua **IMAP** → jaza server za juu →
Save.

### Android (Gmail app)
Gmail → Settings → Add account → **Other** → weka anwani → **Manual setup** →
**Personal (IMAP)** → jaza server za juu.

> Cheti ni cha Let's Encrypt kwa `tech5g.co.tz`, hivyo simu haitalalamika —
> mradi utumie **`tech5g.co.tz`** kama jina la server (siyo IP).

---

## 4. Kuongeza sanduku jipya la barua

```bash
/root/tech5g-add-mailbox.sh mauzo@tech5g.co.tz
```
Script inatengeneza password, inaiongeza Dovecot na Postfix, na kupakia upya
huduma. Lakabu (aliases) ziko `/etc/postfix/virtual` — `postmaster@`,
`abuse@`, `info@` na `noreply@` tayari zinaelekezwa `support@`.

---

## 5. Mfumo (OTP) unavyotumia seva hii

`mailer.php` inatuma kupitia SMTP:

```ini
env[SMTP_HOST]      = "tech5g.co.tz"
env[SMTP_PORT]      = "587"
env[SMTP_SECURE]    = "tls"
env[SMTP_USER]      = "support@tech5g.co.tz"
env[SMTP_PASS]      = "..."
env[MAIL_FROM]      = "support@tech5g.co.tz"
env[MAIL_FROM_NAME] = "Tech 5G Wi-Fi"
```
(ziko `/etc/php-fpm-tech5g/pool.d/tech5g.conf` na `/root/.tech5g-credentials`)

Kupima:
```bash
cd /var/www/tech5g
set -a; . /root/.tech5g-credentials; set +a
/usr/local/apps/php82/bin/php -r '
require "mailer.php";
var_dump(tech5gSendMail("support@tech5g.co.tz","S","Jaribio","<p>Hujambo</p>"));'
```

---

## 6. Uendeshaji wa kila siku

```bash
systemctl status postfix dovecot opendkim   # hali
postqueue -p                                # foleni
postqueue -f                                # lazimisha kutuma
postsuper -d ALL                            # futa foleni yote
journalctl -u postfix -u dovecot -f         # log moja kwa moja
doveadm mailbox status -u support@tech5g.co.tz messages INBOX
```

### Cheti cha TLS
Postfix na Dovecot wanatumia cheti kilekile cha Apache
(`/etc/letsencrypt/live/tech5g.co.tz/`). Certbot ikisasisha, **lazima**
wapakie upya. Deploy hook iko:
`/etc/letsencrypt/renewal-hooks/deploy/reload-mail.sh`

### sendmail ya zamani
Sendmail ya Webuzo ime-**mask**-iwa (`systemctl mask sendmail`) kwa sababu
ilikuwa imeshika port 25 na 587. Usiiwashe tena — itagongana na Postfix.

---

## 7. Usalama

- Open relay **imezuiwa**: kutuma kunahitaji uthibitisho (`permit_sasl_authenticated, reject`).
- TLS ni **lazima** kwenye 587/465; Dovecot ina `ssl = required`.
- Password za masanduku zimehifadhiwa kama **SHA512-CRYPT** ndani ya
  `/etc/dovecot/users` (chmod 640, root:dovecot).
- Password ya zamani ya Gmail iliyokuwa `mail_config.php` **ilipakiwa GitHub**.
  Faili imeondolewa, lakini **lazima i-revoke-iwe** kwenye
  <https://myaccount.google.com/apppasswords>.
