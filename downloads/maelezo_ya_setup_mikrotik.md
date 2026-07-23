# Tech 5G Wi-Fi — Maelezo Kamili ya Router Setup Script (Toleo la 2)

Hati hii inaeleza **kila kitu script `tech5g_router_setup.rsc` inafanya**, **wapi hasa ndani ya script IP/api_user/api_pass zinatengenezwa**, na **hatua zilizobaki kwa mkono**.

---

## SEHEMU YA 0: Kabla ya kuendesha script kuu

Script kuu (Hatua 1-16) ni sehemu ya tatu ya utaratibu. Kuna hatua mbili za HIARI kabla:

| Hatua | Inafanya nini | Inareboot? |
|---|---|---|
| **0A** — Sasisha RouterOS | `/system package update install` — inahakikisha router iko kwenye version mpya kabla ya kuweka config | Ndiyo |
| **0B** — Futa config za zamani | `/system reset-configuration no-defaults=yes` — inafuta KILA KITU cha zamani (bridges, users, hotspot) | Ndiyo |

⚠️ Hizi mbili hazitokei ndani ya "run" moja kwa sababu MikroTik inareboot baada ya kila moja — script haiwezi kuendelea kiotomatiki baada ya reboot bila wewe kuingia tena. Fuata mpangilio: **0A → subiri reboot → 0B → subiri reboot → ndipo uendeshe Hatua 1-16.**

Kama unatumia `run-after-reset=tech5g_router_setup.rsc` pamoja na Hatua 0B, script kuu (1-16) itaendeshwa **kiotomatiki** mara moja baada ya reboot ya 0B — huna haja ya kuiendesha wewe mwenyewe tena.

---

## SEHEMU YA 1: Script kuu inafanya nini (Hatua 1-16)

| # | Kinachofanyika | IP/User/Pass zinatokea hapa? |
|---|---|---|
| 1 | Weka jina la router (Identity) | — |
| 2 | Tengeneza Bridge + unganisha ports (ethernet + WiFi, inagundua `wifi1`/`wlan1` yenyewe) | — |
| 3 | Hotspot Profile (login-by, trial settings) | — |
| 4 | IP Pool + DHCP Server kwa wateja | — |
| 5 | Hotspot Server (huduma kuu ya captive portal) | — |
| 6 | `/ip address add address=10.X.0.1/24` | ✅ **HAPA `mikrotik_ip` INATENGENEZWA** |
| 7 | Firewall — Client Isolation, ICMP block | — |
| 8 | Anti-sharing (TTL manipulation) | — |
| 9 | NAT (Masquerade) | — |
| 10 | Muda (Timezone — Dar es Salaam) | — |
| 11 | Walled Garden — malipo (AzamPay n.k.) | — |
| 12 | Walled Garden IP — ruhusu server yako ya PHP | — |
| 13 | Hotspot User Profiles: `daily`, `weekly`, `monthly`, `trial` (dakika 5, 4M/4M) | — |
| 14 | API service inawashwa (port 8728) | — |
| 14 (2) | `/user add name=... password=... group=api-only` | ✅ **HAPA `api_user` na `api_pass` VINATENGENEZWA** |
| 14b | Password ya admin default inabadilishwa (usalama) | — |
| 15 | Chapisha taarifa zote (IP, api_user, api_pass, api_port) kwenye terminal | — |
| 16 | Router inajituma yenyewe kwenda `register_router.php` kuandika kwenye `mikrotik_configs` | ✅ Hapa ndipo vinaandikwa DATABASE-NI (kiotomatiki) |

---

## SEHEMU YA 2: Trial (Dakika 5 za Bure)

- **Router**: profile `trial` (session-timeout=5m, rate-limit=4M/4M) — Hatua 13
- **PHP**: `trial_handler.php` inamsoma mtumiaji kutoka SESSION na kumpeleka kwenye router kwa `username=T-<mac>`
- **Ujumbe wa muda kuisha**: faili tofauti `status.html` (kinaonyeshwa wakati "Open Status Page: always" — Server Profile) — kinahesabu countdown na kikiisha kinamrudisha mtumiaji kwenye `index_backup.php?trial_expired=1`, ambapo toast inaonekana ikimwambia anunue kifurushi

---

## SEHEMU YA 3: Alama za `<<< >>>` zinazopaswa kujazwa

| Alama | Maelezo |
|---|---|
| `<<< JINA LA RESELLER/ENEO >>>` | Jina la eneo (litaonekana kwenye login.html) |
| `<<< NAMBA >>>` (zote, sawa kila mahali) | Namba ya subnet — LAZIMA tofauti kwa kila router |
| `<<< IP YA SERVER YAKO YA PHP >>>` | IP ya kompyuta ya XAMPP inayofikika na router |
| `<<< JINA_LA_API_USER >>>` | Jina la mtumiaji mpya wa API (mfano `tech5g_api`) |
| `<<< PASSWORD_IMARA >>>` | Password ya huyo API user |
| `<<< PASSWORD_MPYA_YA_ADMIN >>>` | Password mpya ya admin ya default (usalama) |
| `<<< SIRI_YA_USAJILI >>>` | Neno la siri linalolingana na `REGISTER_SECRET` ndani ya `register_router.php` |
| `<<< RESELLER_USER_ID >>>` | `id` ya reseller kwenye jedwali `users` (SIYO router_id — hiyo inajitengeneza yenyewe) |

---

## SEHEMU YA 4: Nini kimebaki kwa MKONO (haiwezekani/hapaswi kufanywa na script)

1. **Kupandisha `login.html` na `status.html`** kwenye router (Files) — hizi ni faili za kompyuta yako, router haiwezi kuzitafuta yenyewe.
   - **Usibadilishe `routerID` kwa mkono.** Pakua faili hizi mbili kutoka ukurasa wa **MikroTik Setup** kwenye dashboard yako (`mikrotik_setup.php`) baada ya router yako kusajiliwa — zinakuja tayari zikiwa na `router_id` yako sahihi kila mahali.
   - Kama ukurasa unasema "Imefungwa", maana yake router yako bado haijasajiliwa (Hatua 16 / admin bado hajahifadhi IP na API).

2. **Kuweka bei za `tariffs`** kwa `user_id` ya reseller husika — hii ni uamuzi wa kibiashara, siyo kitu cha kiufundi kinachoweza kuamuliwa na script.

3. **Kujaribu kwa `test.php`** kuthibitisha kila kitu kinafanya kazi kabla ya kumkabidhi router mteja/reseller.

---

## MFANO HALISI: Router ya sasa (router_id=2)

Kwenye database yako ya sasa, router_id=2 ina `mikrotik_ip = 192.168.88.1` (IP ya default ya kiwandani ya MikroTik) na `api_user = bin_waqas`. Hii inaonyesha router hii **haikuwahi kuendeshewa script hii** — bado ina configuration yake ya awali, na taarifa zake ziliingizwa kwa mkono kupitia phpMyAdmin. Hii ni sawa kabisa na inafanya kazi — script hii mpya ni kwa ajili ya **routers mpya** unazoongeza kwenye mfumo kutoka sasa, siyo lazima kubadilisha zile zilizopo tayari zinazofanya kazi vizuri.

---

## Ukumbusho wa haraka (checklist ya router mpya)

- [ ] (Hiari) Sasisha RouterOS — Hatua 0A
- [ ] (Hiari) Futa config za zamani — Hatua 0B, na `run-after-reset`
- [ ] Jaza `<<< >>>` ZOTE kwenye script kuu, hakikisha NAMBA ya subnet ni ya kipekee
- [ ] Endesha script (au iiache ijiendeshe kiotomatiki baada ya 0B)
- [ ] Soma matokeo ya terminal — nakili `router_id` mpya
- [ ] Pakua `login.html` na `status.html` kutoka **MikroTik Setup** ya dashboard (zinakuja na `router_id` yako tayari)
- [ ] Pandisha `login.html` na `status.html` kwenye Files (hotspot folder)
- [ ] Weka `tariffs` kwenye database kwa `user_id` husika
- [ ] Jaribu na `test.php`
- [ ] Jaribu kuunganisha kwa simu halisi (Android na iPhone) — vocha na trial vyote viwili
