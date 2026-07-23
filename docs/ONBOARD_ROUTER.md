# Kuunganisha Router Mpya — Tech5G (Runbook Halisi)

Hii ndiyo hatua HALISI tulizotumia kuunganisha router ya kwanza (**bin waqas**, Router ID 1),
zikiwa tayari kwa kurudia kwa kila router mpya. Kila `«...»` badilisha na thamani yako.

> **Muhimu:** Mfumo huu una WireGuard **wg1** ya Tech5G pekee — TOFAUTI na `wg0` ya jassnet
> iliyopo kwenye VPS hiyo hiyo. Usiguse `wg0`.

---

## 0. Mipangilio ya kudumu (reference)

| Kipengele | Thamani |
|---|---|
| VPS public IP | `107.161.168.192` |
| WireGuard interface (Tech5G) | `wg1` |
| WireGuard port | `51821` (UDP) |
| Tunnel subnet | `10.60.0.0/24` |
| VPS hub tunnel IP | `10.60.0.1` |
| VPS server public key | `hAnwIpw8hy8BWwZC5Pp+evtLUhaANc3ZZCIUcR9GNik=` |
| Domain | `https://tech5g.co.tz` |

**Tunnel IP zilizotumika:** `10.60.0.1` = hub · `10.60.0.2` = bin waqas (Router 1).
**Router ijayo:** anza na `10.60.0.3`, kisha `.4`, `.5` … (kamwe usirudie namba).

Siri zote (DB, keys, admin) ziko kwenye VPS: `/root/.tech5g-credentials`.

---

## 1. VPS — ongeza router mpya kwenye WireGuard (njia rahisi)

Kuna script tayari kwenye VPS inayofanya kila kitu cha upande wa VPS na kukupa commands za MikroTik:

```bash
ssh root@107.161.168.192
/root/add-tech5g-router.sh «jina» «octet»
# mfano kwa reseller 'juma' kwenye 10.60.0.3:
/root/add-tech5g-router.sh juma 3
```

Script hii:
1. Inatengeneza key-pair ya router (`/etc/wireguard/tech5g_«jina».key/.pub`).
2. Inaongeza peer kwenye `/etc/wireguard/wg1.conf` (AllowedIPs = `10.60.0.«octet»/32`).
3. Inatekeleza LIVE kwa `wg syncconf` — **bila kuvunja** router zilizopo.
4. Inachapisha commands tayari za MikroTik (§2) zikiwa na keys sahihi.

### (Hiari) Njia ya mkono badala ya script

```bash
cd /etc/wireguard
umask 077
wg genkey | tee tech5g_«jina».key | wg pubkey > tech5g_«jina».pub
ROUTER_PUB=$(cat tech5g_«jina».pub)

cat >> /etc/wireguard/wg1.conf <<EOF

# ---- Reseller: «jina» (tunnel IP 10.60.0.«octet») ----
[Peer]
PublicKey = $ROUTER_PUB
AllowedIPs = 10.60.0.«octet»/32
EOF

wg syncconf wg1 <(wg-quick strip wg1)   # tekeleza bila kuvunja peers zilizopo
```

---

## 2. MikroTik — WireGuard (RouterOS v7, Terminal)

> Script ya §1 inakupa block hii tayari na keys. Vinginevyo, weka thamani mwenyewe.
> `«ROUTER_PRIVATE_KEY»` = ya router hii (kutoka `tech5g_«jina».key`).

```rsc
/interface wireguard
add name=wg-tech5g listen-port=51821 private-key="«ROUTER_PRIVATE_KEY»"

/ip address
add address=10.60.0.«octet»/24 interface=wg-tech5g

/interface wireguard peers
add interface=wg-tech5g public-key="hAnwIpw8hy8BWwZC5Pp+evtLUhaANc3ZZCIUcR9GNik=" \
    endpoint-address=107.161.168.192 endpoint-port=51821 \
    allowed-address=10.60.0.0/24 persistent-keepalive=25s

/ip firewall filter
add chain=input in-interface=wg-tech5g action=accept comment="Tech5G VPN trusted" place-before=0
```

**Thibitisha tunnel (kwenye router):** `/interface wireguard peers print detail` — angalia
`last-handshake` ya sekunde chache. Au `/ping 10.60.0.1`.

**Thibitisha kutoka VPS:** `ping 10.60.0.«octet»` na `wg show wg1`.

---

## 3. MikroTik — API user (ndiyo dashboard inayotumia)

```rsc
/ip service enable api

/user group add name=api-only \
  policy=api,read,write,test,!local,!telnet,!ssh,!ftp,!reboot,!password,!policy,!winbox,!web,!sniff,!sensitive,!romon

/user add name=tech5g_api password="«PASSWORD_IMARA»" group=api-only
```

> Kama group/user tayari ipo, RouterOS itasema "already exists" — sawa. Kubadili password:
> `/user set tech5g_api password="«PASSWORD_IMARA»"`.

---

## 4. MikroTik — Hotspot + profiles (kama router ni MPYA kabisa)

Kama router bado haina hotspot, endesha **`downloads/tech5g_router_setup.rsc`** (jaza `«...»` zote).
Ndani yake mna: bridge, hotspot server, DHCP, pool, walled-garden, na profiles.

**SHERIA MUHIMU ya majina ya profile** — jina la profile kwenye router LAZIMA lifanane herufi kwa
herufi na `tariffs.profile_name` kwenye database ya reseller huyo. Vinginevyo malipo yatafanikiwa
lakini mteja hataingia (profile haipo).

- Reseller anayejaza bei mwenyewe kupitia fomu (`setup_tariffs.php`) → database huweka
  `daily_profile`, `weekly_profile`, `monthly_profile` (na **underscore**). Hivyo tengeneza profile
  za router kwa majina hayo:
  ```rsc
  /ip hotspot user profile
  add name=daily_profile   rate-limit=6M/6M
  add name=weekly_profile  rate-limit=8M/8M
  add name=monthly_profile rate-limit=10M/10M
  ```
- (bin waqas ni ubaguzi: router yake ina `daily-profile` kwa **hyphen**, hivyo tuli-update rows
  zake za `tariffs` kwenye DB zilingane. Kwa router mpya, tumia underscore ili ilingane na fomu.)

**Walled-garden** (lazima iruhusu portal kabla ya login) — tayari imo kwenye `.rsc`:
```rsc
/ip hotspot walled-garden
add dst-host=tech5g.co.tz
add dst-host=*.tech5g.co.tz
/ip hotspot walled-garden ip
add dst-address=107.161.168.192 comment="Tech5G backend"
```

---

## 5. Dashboard — sajili reseller na router yake

1. Reseller ajisajili kwenye `https://tech5g.co.tz` (au admin amtengeneze), kisha **admin a-approve**.
2. Admin afungue kadi ya reseller huyo → jaza:
   - **Router IP** = tunnel IP yake (mfano `10.60.0.3`)
   - **API User** = `tech5g_api`
   - **API Password** = «uliyoweka §3»
3. Bofya **Save**. Mfumo utajaribu muunganisho; ukifanikiwa utaonyesha **Router ID** (mfano `2`).
4. Reseller (kama hajaweka bei) atapelekwa `setup_tariffs.php` kuweka Siku/Wiki/Mwezi.

---

## 6. login.html — captive portal ya router

1. Chukua `https://tech5g.co.tz/login.html`.
2. Badilisha **Router ID** sehemu ZOTE tatu ilingane na namba ya router hii:
   - `<meta http-equiv="refresh" ... router_id=«ID»>`
   - `<a class="manual-link" ... router_id=«ID»>`
   - `var routerID = "«ID»";`
3. Pakia kama `login.html` kwenye folda ya **hotspot** ya router (WinBox → Files → hotspot/).

> Router 1 (bin waqas) tayari ina router_id=1 kwenye `login.html` iliyopo.

---

## 7. Uthibitisho wa mwisho (kutoka VPS)

```bash
# tunnel + API kwa pamoja (badilisha IP/password):
php -r 'require "/var/www/tech5g/routeros_api.class.php";
$a=new RouterosAPI(); $a->connect_timeout=6;
var_dump($a->connect("10.60.0.«octet»","tech5g_api","«PASSWORD»"));'
```
`bool(true)` = tayari. Kisha fungua `https://tech5g.co.tz/index_backup.php?router_id=«ID»&mac=x&ip=x`
— inapaswa kuonyesha vifurushi vya reseller, siyo "Router halijasajiliwa".

---

## Utatuzi wa haraka

| Dalili | Sababu / Suluhisho |
|---|---|
| Hakuna `last-handshake` | Provider anazuia UDP 51821, au endpoint/port/keys si sahihi. Angalia `wg show wg1`. |
| `invalid user name or password` | User `tech5g_api` haipo / password tofauti kwenye router. `/user set tech5g_api password=...` |
| "Mawasiliano yamefeli" kwenye Save | Tunnel down, au IP si sahihi (tumia tunnel IP `10.60.0.x`, siyo LAN). |
| Mteja analipa lakini haingii | Jina la profile la router halilingani na `tariffs.profile_name` (§4). |
| Portal blank kabla ya login | Walled-garden haina `tech5g.co.tz` + IP ya VPS (§4). |
