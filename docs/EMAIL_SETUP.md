# Barua pepe ya mfumo — hali halisi (imesasishwa 2026-09-13)

> **Toleo la zamani la faili hii lilikuwa linaelezea seva ILIYOKUFA.**
> Lilikuwa na IP `143.246.136.110` kila mahali, na maelekezo ya Postfix +
> Dovecot + OpenDKIM zilizokuwa zikiendeshwa kwenye VPS ya zamani. VPS
> hiyo ilikufa 2026-09-12. **Hakuna seva ya barua kwenye box mpya** —
> port 25, 465, 587 na 993 zote zimefungwa kwenye `66.29.143.116`
> (imethibitishwa 2026-09-13). Usifuate maelekezo ya toleo la zamani.

---

## 1. Mfumo unatumaje barua pepe sasa

Hakuna Postfix. `mailer.php` inatuma **moja kwa moja kupitia SMTP ya nje**
kwa kutumia PHPMailer iliyo-vendor-iwa (`PHPMailer/src/`).

Njia MOJA tu inatumika kwa mfumo mzima — `tech5gSendMail()`. Wanaoitumia:

| Inakotumika | Kwa nini |
|---|---|
| `otp_helper.php` | code ya kuthibitisha barua pepe wakati wa usajili |
| `email_helper.php` → `admin.php`, `payout_helper.php` | hali ya payout |
| `mailer_helper.php` → `check_stations.php` | station imezima |
| `router_health_check.php` | router haifikiki |
| `retry_pending_vouchers.php` | vocha imekwama baada ya malipo |

### Mipangilio (env[...] kwenye pool ya php-fpm)

```ini
SMTP_HOST      = timonsansibar.com
SMTP_PORT      = 465
SMTP_SECURE    = ssl
SMTP_USER      = info@timonsansibar.com
SMTP_PASS      = <siri>
MAIL_FROM      = info@timonsansibar.com
MAIL_FROM_NAME = Tech5G
```

> ⚠️ **`MAIL_FROM_NAME` haipaswi kuwa na nafasi.** `secrets.env` husomwa
> na cron kwa `set -a; . secrets.env`, na nafasi isiyo kwenye nukuu
> hufanya shell ijaribu kuendesha neno la pili — `5G: command not found`
> — na thamani hukatika kuwa "Tech". Jina kamili la biashara lipo ndani
> ya barua pepe yenyewe (`tech5gEmailTemplate`).

Ziko `/var/www/tech5g/private/secrets.env` (640 root:tech5g). Baada ya
kuhariri **lazima** uendeshe `/root/fix-secrets.sh` — inajenga upya
`/etc/php/8.3/fpm/pool.d/tech5g.conf` na ku-reload php-fpm.

> **php-fpm HUKATAA `env[]` yenye thamani tupu** na master mzima
> hushindwa kuanza. Key isiyo na thamani LAZIMA iondolewe kabisa.

---

## 2. Kwa nini Gmail haikufanya kazi

`SMTP_PASS` iliyokuwepo ilikuwa na **herufi 14** — hiyo ni password ya
akaunti. **Gmail App Password ni herufi 16 DAIMA**, na Gmail HAIKUBALI
password ya akaunti kwenye SMTP. Jibu lake: `Password command failed`.

Ukitaka kurudi Gmail: tengeneza App Password kwenye
<https://myaccount.google.com/apppasswords>. Outbound 25/465/587 zote
ZIKO WAZI kwenye box hii (tofauti na VPS ya zamani), hivyo hakuna haja
ya relay — lakini soma §3 kuhusu `From` kabla ya kubadilisha.

---

## 3. Kwa nini `MAIL_FROM` SIYO `support@tech5g.co.tz`

Sababu mbili, zote mbili ni za lazima:

1. **Namecheap hukataa From ya kigeni.** Tunatuma kupitia akaunti yao
   (`timonsansibar.com`), na wanajibu:
   ```
   550 Your domain tech5g.co.tz is not allowed in header From
   ```
2. **`tech5g.co.tz` HAINA rekodi ya MX.** Majibu ya mteja yangedondoka.

Jina la biashara (**Tech 5G Wi-Fi**) linabaki kwenye `From`; anwani
pekee ndiyo ya Namecheap. `Reply-To` inafuata `MAIL_FROM`, na anwani
hiyo INAPOKEA barua kweli.

> Ukihamia Gmail: Gmail nayo huandika upya `From` kuwa akaunti
> iliyothibitishwa, isipokuwa anwani hiyo imesajiliwa kama "Send mail
> as" alias — na kuthibitisha alias kunahitaji anwani IPOKEE barua ya
> uthibitisho. Bila MX, haiwezekani. Ndiyo mzunguko uleule.

---

## 4. ⚠️ DNS — HAKUNA rekodi yoyote ya barua pepe

Imethibitishwa 2026-09-13 moja kwa moja kwa nameserver:

```bash
dig @ns1.rodlinehost.com MX  tech5g.co.tz   # → hakuna (SOA pekee)
dig @ns1.rodlinehost.com TXT tech5g.co.tz   # → hakuna
dig -x 66.29.143.116                        # → hakuna PTR
```

Uhamisho wa 2026-09-12 ulihamisha rekodi ya **A** pekee. Rekodi zote za
barua pepe ziliachwa kwenye seva iliyokufa. Athari:

- `support@tech5g.co.tz` **HAIWEZI KUPOKEA** chochote.
- Hakuna SPF, DKIM wala DMARC — barua yoyote ya `From: @tech5g.co.tz`
  itakwenda Spam au kukataliwa.

### Zinazohitajika (DNS iko rodlinehost.com → DNS Zone Editor)

Kwa hali ya sasa (tunatuma kupitia Namecheap), **SPF pekee ndiyo ya
haraka** — inaruhusu Namecheap kutuma kwa niaba yetu:

| Aina | Jina | Thamani |
|---|---|---|
| TXT | `@` | `v=spf1 include:spf.web-hosting.com ~all` |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:info@timonsansibar.com` |

Ukitaka `support@tech5g.co.tz` IPOKEE barua, unahitaji seva ya barua na:

| Aina | Jina | Kipaumbele | Thamani |
|---|---|---|---|
| MX | `@` | 10 | `tech5g.co.tz` |

> ⚠️ Kuwe na rekodi **MOJA tu** ya SPF kwenye domain.
> Funguo ya DKIM ya zamani (`mail._domainkey`) **ilikufa na seva** —
> private key ilikuwa `/etc/opendkim/keys/` kwenye box ya zamani.
> Ikihitajika tena, lazima itengenezwe UPYA.

---

## 5. Kupima

```bash
# Je, credentials zinafanya kazi? (AUTH pekee, haitumi barua)
U=$(printf 'info@timonsansibar.com' | base64)
P=$(printf 'PASSWORD' | base64)
{ printf 'EHLO tech5g.co.tz\r\nAUTH LOGIN\r\n%s\r\n%s\r\nQUIT\r\n' "$U" "$P"; sleep 5; } \
  | openssl s_client -quiet -crlf -connect timonsansibar.com:465 2>/dev/null \
  | grep -E '^(235|535)'
# 235 = imefanikiwa, 535 = password si sahihi

# Kutuma kweli kupitia mfumo
cd /var/www/tech5g/app
set -a; . /var/www/tech5g/private/secrets.env; set +a
php -r 'require "mailer.php";
  var_dump(tech5gSendMail("wewe@gmail.com","Jina","Jaribio",
  tech5gEmailTemplate("Jina","<p>Hujambo</p>")));'
```

Ikirudisha `false`, sababu iko `/var/log/php8.3-fpm-tech5g.log`
(`mailer.php` huandika `$mail->ErrorInfo` hapo).

---

## 6. Vikwazo vya kujua

- Namecheap ni **shared hosting** yenye kikomo (`MAILMAX=1000` kwa
  muunganisho). Usajili mwingi kwa wakati mmoja unaweza kufikia kikomo.
- **Usajili UNATEGEMEA barua pepe.** `otpGenerateAndSend()` ikishindwa,
  `process_engine.php` haimalizi usajili — barua pepe ikifa, hakuna
  mtu anayeweza kujisajili.
- `mailer.php` ina `Timeout = 15` na inatuma **ndani ya request**.
  Seva ya SMTP isiyojibu inasimamisha ukurasa sekunde 15.
