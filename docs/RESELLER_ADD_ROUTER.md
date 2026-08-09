# Jinsi ya Kuunganisha Router Yako — Mwongozo wa Reseller

Mwongozo huu ni **wa reseller mwenyewe**. Hauhitaji msaada wa admin wala SSH.
Utachukua takribani **dakika 15**.

> **Unahitaji nini kabla ya kuanza**
> - Akaunti yako ya Tech 5G imeidhinishwa na admin (umeshathibitisha barua pepe)
> - MikroTik yenye **RouterOS v7** na intaneti inayofanya kazi
> - Uwezo wa kufungua **WinBox** au **Terminal** ya router yako

---

## Kwa nini kuna "tunnel"?

Router yako iko nyuma ya NAT ya mtoa huduma wako wa intaneti — haina anwani ya
umma. Kwa hiyo mfumo wetu hauwezi kuipigia simu moja kwa moja.

Suluhisho: router **yako ndiyo inayopiga simu kwetu** kupitia njia salama
(WireGuard), kisha inapewa namba isiyobadilika kama `10.60.0.5`. Mfumo
unaitumia namba hiyo kuzungumza na router yako. API ya router **haiwekwi wazi
kwenye intaneti hata kidogo**.

---

## HATUA 1 — Jiombee tunnel (dakika 1)

1. Ingia kwenye <https://tech5g.co.tz>
2. Nenda **My Mikrotiks**
3. Bonyeza **⚡ Niandalie Tunnel**

Utaonyeshwa:
- **Tunnel IP** yako (mfano `10.60.0.5`)
- Maagizo tayari ya MikroTik yenye funguo zako

> ### ⚠️ NAKILI SASA HIVI
> **Funguo ya siri (private key) inaonyeshwa MARA MOJA TU.** Haihifadhiwi
> popote — wala sisi hatuwezi kuiona tena. Ukiondoka kwenye ukurasa kabla ya
> kunakili, itabidi uombe tunnel mpya (ya zamani itafutwa).
>
> Bonyeza **Nakili**, kisha bandika mahali salama kwanza (mfano Notepad).

---

## HATUA 2 — Bandika kwenye MikroTik (dakika 2)

Fungua **WinBox → New Terminal** (au SSH), kisha bandika block yote uliyopewa.

Inafanya haya:

| Sehemu | Inafanya nini |
|---|---|
| `/interface wireguard` | Inatengeneza tunnel kwa funguo yako |
| `/ip address` | Inaweka namba yako (mfano `10.60.0.5`) |
| `/interface wireguard peers` | Inaielekeza router kwenye seva yetu |
| `/ip firewall filter` | Inaruhusu mfumo wetu kufika kwenye router |
| `/ip service enable api` | Inawasha API (ndiyo mfumo unavyodhibiti router) |
| `/user add name=tech5g_api` | Inatengeneza mtumiaji wa mfumo |

### Badilisha password kabla ya kubandika

Kwenye mstari wa mwisho utaona:

```
/user add name=tech5g_api password="WEKA-PASSWORD-YAKO-IMARA" group=api-only
```

**Badilisha `WEKA-PASSWORD-YAKO-IMARA`** na password yako imara (herufi 12+,
changanya herufi kubwa, ndogo na namba). **Usitumie `123456`.**

> Mtumiaji huyu anaweza kusoma na kubadilisha mipangilio ya router yako.
> Ukiiweka password dhaifu, mtu mwenye njia ya kufika kwenye tunnel angeweza
> kuidhania kwa sekunde chache.

**Iandike mahali salama** — utaihitaji kwenye Hatua 3.

### Thibitisha tunnel imeunganika

Kwenye terminal ya router:

```rsc
/ping 10.60.0.1 count=4
```

Ukiona majibu, tunnel iko sawa. Kama hakuna, angalia jedwali la matatizo mwishoni.

---

## HATUA 3 — Ongeza router kwenye dashboard (dakika 2)

Rudi **My Mikrotiks** → sehemu ya **Ongeza Router Mpya**:

| Uwanja | Weka nini |
|---|---|
| Jina la Router | Jina lolote unalolielewa, mfano `Duka Kariakoo` |
| Tunnel IP | Namba uliyopewa Hatua 1 (mfano `10.60.0.5`) — **siyo** `192.168.x.x` |
| API User | `tech5g_api` |
| API Password | Ile uliyoiweka Hatua 2 |
| API Port | Acha wazi (8728) |

Bonyeza **Thibitisha na Ongeza**.

> Mfumo **utajaribu kuunganishwa papo hapo**. Ikishindwa, hautahifadhi — hiyo
> ni kinga, siyo hitilafu. Maana yake tunnel au password bado haiko sawa.

Ikifanikiwa, utapelekwa moja kwa moja kuweka bei zako.

---

## HATUA 4 — Weka bei zako (dakika 2)

Weka bei za **Siku / Wiki / Mwezi**. Hizi ndizo mteja atakazoziona.

Mfumo utaandika majina ya profile kiotomatiki:
`daily_profile`, `weekly_profile`, `monthly_profile`.

> **MUHIMU:** majina haya LAZIMA yawepo kwenye router yako na yafanane
> **herufi kwa herufi** (kwa underscore `_`, siyo hyphen `-`). Kama router yako
> ni mpya na umeendesha script yetu ya usanidi, tayari yapo. Vinginevyo
> tengeneza hivi:
>
> ```rsc
> /ip hotspot user profile
> add name=daily_profile   session-timeout=1d  rate-limit=6M/6M
> add name=weekly_profile  session-timeout=7d  rate-limit=8M/8M
> add name=monthly_profile session-timeout=30d rate-limit=10M/10M
> ```
>
> Yasipofanana, **mteja atalipa lakini hataingia** — ndilo kosa linalojirudia zaidi.

---

## HATUA 5 — Pandisha ukurasa wa kuingia (dakika 3)

1. Nenda **MikroTik Setup** kwenye dashboard
2. Pakua **`login.html`** na **`status.html`**
   (zinakuja tayari zikiwa na namba ya router YAKO)
3. Pandisha zote mbili kwenye folda ya **`hotspot/`** ya router:
   **WinBox → Files → hotspot/** → buruta faili hapo

### Njia nyingine (haraka zaidi)

Kwenye terminal ya router:

```rsc
/tool fetch url="https://tech5g.co.tz/login.html" dst-path=hotspot/login.html check-certificate=no
/tool fetch url="https://tech5g.co.tz/status.html" dst-path=hotspot/status.html check-certificate=no
```

> **Tahadhari:** Usijaribu kupandisha faili hizi kwa amri ya `/file set contents=`.
> RouterOS hukata maudhui kwenye mstari wa kwanza na faili huharibika (huwa na
> `<!DOCTYPE html>` pekee). Tumia WinBox au `/tool fetch` **pekee**.

---

## HATUA 6 — Jaribu

1. Unganisha simu kwenye Wi-Fi ya router yako
2. Ukurasa wa Tech 5G unapaswa kujitokeza wenyewe
3. Angalia kama bei zako zinaonekana sawa
4. Jaribu **"Jaribu Dakika 5 Bure"** (kama umeiwasha)

Ukimaliza, router yako iko hewani na inaweza kuuza vocha.

---

## Jaribio la bure la dakika 5 — kuwasha au kuzima

Kwenye **My Mikrotiks**, kila router ina kitufe:

> 🎁 Jaribio la dakika 5 bure: **IMEWASHWA** [Zima]

- **Imewashwa** — mteja mpya anapata dakika 5 bure (4M/4M), **mara moja tu kwa
  kila kifaa**. Muda ukiisha, router yenyewe hukata.
- **Imezimwa** — kitufe hakionekani kwa mteja, **na** router inaacha kutoa
  trial kabisa.

Tunabadilisha pande zote mbili kwa makusudi. Kuficha kitufe pekee
hakutoshi — mtu anayejua mbinu angeweza kuomba trial moja kwa moja kwenye router.

---

## Matatizo yanayojirudia

| Dalili | Sababu / Suluhisho |
|---|---|
| "Mawasiliano na MikroTik yamefeli" wakati wa kuongeza router | Tunnel haijaunganika, au password si sahihi. Jaribu `/ping 10.60.0.1` kwenye router. |
| `/ping 10.60.0.1` haifanyi kazi | Mtoa huduma wako anazuia UDP 51821, au ulibandika funguo isiyo sahihi. Angalia `/interface wireguard peers print detail` — `last-handshake` inapaswa kuwa ya sekunde chache. |
| Ukurasa wa kuingia ni mtupu | `login.html` haikupandishwa vizuri, au walled-garden haina `tech5g.co.tz`. |
| Mteja amelipa lakini hajaingia | Jina la profile halifanani (`daily-profile` badala ya `daily_profile`) — angalia Hatua 4. |
| Nimepoteza private key | Omba tunnel mpya kwenye **My Mikrotiks**. Ya zamani itafutwa; itabidi ubandike maagizo mapya kwenye router. |
| Nimesahau API password | Ibadilishe kwenye router: `/user set tech5g_api password="mpya"`, kisha isasishe kwenye dashboard. |

Msaada: **support@tech5g.co.tz**
