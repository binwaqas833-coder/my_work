# ============================================================
# TECH 5G WI-FI - ROUTER SETUP TEMPLATE
# Tumia hii kwa kila router MPYA unayoongeza kwenye mfumo.
#
# KABLA HUJAENDESHA: badilisha maeneo yenye alama <<< HAPA >>>
#
# ⚠️ MUHIMU: Script hii ina HATUA TATU TOFAUTI (0, 0B, kisha 1+).
# Hatua 0 na 0B ZOTE MBILI zinasababisha router ku-REBOOT papo hapo.
# HUWEZI kuziunganisha na configuration kuu kwenye executaion moja -
# script itasimama pale reboot inapotokea. Fuata utaratibu huu:
#
#   HATUA 0  -> Endesha PEKE YAKE -> Subiri router i-reboot -> Ingia tena
#   HATUA 0B -> Endesha PEKE YAKE -> Subiri update+reboot -> Ingia tena
#   HATUA 1+ -> Ndipo uendeshe configuration kuu (sehemu ya chini)
# ============================================================


# ============================================================
# HATUA 0: FUTA CONFIGURATION ZOTE ZA ZAMANI (RESET)
# ------------------------------------------------------------
# Endesha SEHEMU HII PEKE YAKE kwenye New Terminal, kisha SUBIRI
# router i-reboot yenyewe (dakika 1-2). Router itapoteza IP ya
# zamani na kurudi kwenye mipangilio ya kiwanda (au tupu kabisa).
# Baada ya reboot utahitaji kuingia tena kwa MAC (Neighbors tab)
# kwani IP itakuwa imebadilika/kufutika.
#
# no-defaults=yes  -> HAITAWEKA hata configuration ya "default"
#                     ya kiwanda (bridge otomatiki, DHCP client
#                     kwenye ether1, n.k.) - inabaki TUPU KABISA,
#                     tayari kwa configuration yetu safi.
# skip-backup=yes  -> haitunzi nakala ya zamani (badilisha kuwa
#                     "no" kama unataka backup ihifadhiwe kwanza)
# ============================================================
# /system reset-configuration no-defaults=yes skip-backup=yes


# ============================================================
# HATUA 0B: HAKIKISHA ROUTEROS IKO KWENYE VERSION MPYA (UPDATE)
# ------------------------------------------------------------
# Endesha SEHEMU HII PEKE YAKE, BAADA ya Hatua 0 kukamilika na
# umeshaingia tena kwenye router. Hii itaangalia kama kuna update,
# kuipakua, na kuisakinisha (na ku-reboot kama update ipo).
#
# ⚠️ Hakikisha router ina muunganiko mzuri wa intaneti kabla ya hii,
# na kwamba haiko mbali sana (remote) endapo update itashindwa.
#
# :put [/system package update check-for-updates as-value]
# /system package update install
#
# Baada ya reboot, thibitisha version mpya kwa amri:
# /system resource print
# (angalia mstari wa "version:")
# ============================================================


# ============================================================
# HATUA 1+: CONFIGURATION KUU YA TECH 5G WI-FI
# Endesha SEHEMU HII BAADA ya Hatua 0 na 0B kukamilika kikamilifu.
# ============================================================

# ---- 1. Jina la Router (litaonekana kwenye login.html kama $(identity)) ----
/system identity set name="Tech5G-<<< JINA LA RESELLER/ENEO >>>"

# ---- 2. Bridge kuu ----
/interface bridge
add name="Tech5G Bridge"

# Rename ether1 kama chanzo cha intaneti (badilisha jina la interface
# kulingana na router yako - baadhi ni "ether1", nyingine "ether1-gateway")
/interface ethernet
set [ find default-name=ether1 ] name="Internet Source"

# Ongeza ports nyingine kwenye bridge (badilisha idadi kulingana na router)
/interface bridge port
add bridge="Tech5G Bridge" interface=ether2
add bridge="Tech5G Bridge" interface=ether3

# WiFi - inajitambua yenyewe kati ya "wlan1" (routers za zamani, package
# ya wireless-cm2) na "wifi1" (routers mpya, package ya wifi RouterOS 7).
# Huna haja ya kujua mapema router ina ipi - script inachagua yenyewe.
:local wifiIfName ""
:if ([:len [/interface find where name="wifi1"]] > 0) do={
    :set wifiIfName "wifi1"
} else={
    :if ([:len [/interface find where name="wlan1"]] > 0) do={
        :set wifiIfName "wlan1"
    }
}
:if ($wifiIfName != "") do={
    /interface bridge port add bridge="Tech5G Bridge" interface=$wifiIfName
    :put ("WiFi interface iliyopatikana na kuongezwa kwenye bridge: " . $wifiIfName)
} else={
    :put "ONYO: Hakuna 'wifi1' wala 'wlan1' iliyopatikana - ongeza WiFi interface kwa mkono!"
}

# ---- 3. Hotspot Profile ----
# hotspot-address: hii ndiyo IP ya "getaway" ya hotspot - epuka
# kutumia range inayogongana na router nyingine za mfumo wako
# login-by inajumuisha "trial" ili kuwezesha "Jaribu bure dakika 5"
/ip hotspot profile
add hotspot-address=10.<<< NAMBA >>>.0.1 login-by=http-chap,http-pap,mac-cookie,trial \
    name="Tech5G Hotspot Profile" use-radius=no trial-uptime-limit=5m \
    trial-user-profile=trial

# ---- 4. Pool na DHCP kwa Hotspot ----
/ip pool
add name="Tech5G Hotspot Pool" ranges=10.<<< NAMBA >>>.0.2-10.<<< NAMBA >>>.0.254

/ip dhcp-server
add address-pool="Tech5G Hotspot Pool" disabled=no interface="Tech5G Bridge" \
    lease-time=1h name="Tech5G Dhcp Server"

/ip dhcp-server network
add address=10.<<< NAMBA >>>.0.0/24 comment="Tech5G hotspot network" \
    gateway=10.<<< NAMBA >>>.0.1

# ---- 5. Hotspot Server ----
/ip hotspot
add address-pool="Tech5G Hotspot Pool" addresses-per-mac=1 disabled=no \
    interface="Tech5G Bridge" name="Tech5G Hotspot" profile="Tech5G Hotspot Profile"

/ip address
add address=10.<<< NAMBA >>>.0.1/24 comment="Tech5G hotspot network" \
    interface="Tech5G Bridge" network=10.<<< NAMBA >>>.0.0

# ---- 6. Firewall - Client Isolation + ICMP block ----
/ip firewall filter
add action=drop chain=input comment="ICMP Block Tech5G Bridge" \
    in-interface="Tech5G Bridge" protocol=icmp
add action=drop chain=forward comment="Client Isolation Tech5G Hotspot" \
    dst-address=10.<<< NAMBA >>>.0.0/24 in-interface="Tech5G Bridge" \
    src-address=10.<<< NAMBA >>>.0.0/24

# ---- 7. Anti-sharing (TTL) ----
/ip firewall mangle
add action=change-ttl chain=postrouting comment="Tech5G Anti-sharing" \
    new-ttl=set:1 out-interface="Tech5G Bridge" passthrough=yes
add action=change-ttl chain=prerouting comment="Hide Router IP" \
    new-ttl=increment:2 passthrough=yes

# ---- 8. NAT ----
/ip firewall nat
add action=masquerade chain=srcnat comment="Tech5G masquerade hotspot" \
    src-address=10.<<< NAMBA >>>.0.0/24
add action=masquerade chain=srcnat comment="Masquerade WAN" \
    out-interface="Internet Source"

# ---- 9. Muda wa Zanzibar/EAT ----
/system clock
set time-zone-autodetect=no time-zone-name=Africa/Dar_es_Salaam

# ---- 10. Walled Garden - Mitandao ya Malipo (AzamPay + mobile money) ----
# Ongeza domains halisi za AzamPay/watoa huduma wako baada ya kuzithibitisha
# kwenye documentation yao - hizi ni mfano tu, badilisha kulingana na wewe:
/ip hotspot walled-garden
add dst-host=*.azampay.co.tz
add dst-host=checkout.azampay.co.tz

# ---- 11. Walled Garden IP - Server yako ya PHP (MUHIMU!) ----
# Hii ndiyo IP ya kompyuta/server inayoendesha XAMPP/PHP yako.
# Bila hii, wateja hawawezi kufikia index_backup.php kabla ya login.
/ip hotspot walled-garden ip
add dst-address=<<< IP YA SERVER YAKO YA PHP >>> comment="Tech5G PHP backend access"

# ---- 12. Hotspot User Profiles (lazima yalingane na ENUM package_type) ----
/ip hotspot user profile
add name="daily" session-timeout=1d shared-users=1
add name="weekly" session-timeout=7d shared-users=1
add name="monthly" session-timeout=30d shared-users=1

# ---- 12b. Trial Profile - Jaribu Bure Dakika 5 ----
# Hii ni user wa muda anayetengenezwa na MikroTik yenyewe (siyo kwenye
# database yako ya MySQL). Kila MAC address inaruhusiwa mara moja tu
# mpaka router i-reboot au host ifutwe kwenye IP > Hotspot > Hosts.
# Ujumbe "Muda wako wa bure umekwisha" + redirect ya automatiki
# unashughulikiwa na faili la "status.html" (angalia maelezo tofauti),
# siyo hapa kwenye router - hivyo hakuna haja ya scheduler ngumu.
add name="trial" session-timeout=5m shared-users=1 rate-limit=4M/4M

# ============================================================
# HAKUNA sehemu ya "auto-generate users" hapa - KWA MAKUSUDI.
# Wateja wako hawatengenezwi na script hii; wanatengenezwa na
# generate_vouchers.php yako na ku-sync kupitia MikroTik API
# (routeros_api.class.php). Hii inaepuka matatizo ya kuwa na
# vyanzo viwili tofauti vya ukweli (source of truth).
# ============================================================

# ---- 13. API kwa ajili ya Dashboard yako (SIYO www ya wazi kwa dunia!) ----
# Tofauti na NOVANET script - HATUFUNGUI www kwa 0.0.0.0/0.
/ip service
set api port=8728 disabled=no
# Hiari (inapendekezwa): zuia API ifikiwe tu na server yako ya PHP
# set api address=<<< IP YA SERVER YAKO YA PHP >>>/32

# ---- 14. Tengeneza System User MAALUM kwa API (siyo "admin") ----
# Hii inaunda mtumiaji mpya wa API moja kwa moja - ANDIKA password
# yako imara hapa KABLA ya kuendesha script (badilisha <<< >>>).
# Baada ya kuendesha, hii NDIYO api_user na api_pass utakayoingiza
# kwenye mikrotik_configs - hakuna haja ya kwenda System > Users kwa mkono.
/user group add name=api-only policy=api,read,write,test,!local,!telnet,!ssh,!ftp,!reboot,!password,!policy,!winbox,!web,!sniff,!sensitive,!romon
/user add name=<<< JINA_LA_API_USER mfano tech5g_api >>> password=<<< PASSWORD_IMARA >>> group=api-only

# ---- 14b. Sasisha password ya "admin" ya default (USALAMA) ----
# Admin default HAIFUTWI (kufuta admin pekee kunaweza kukufungia
# nje ya router endapo kitu kikienda vibaya) - badala yake password
# yake INABADILISHWA kiotomatiki kuwa imara. Tumia password HII
# (siyo ile ya default) endapo utahitaji kuingia WinBox kwa dharura.
/user set [find name=admin] password=<<< PASSWORD_MPYA_YA_ADMIN >>>

# ---- 15. Chapisha taarifa zote unazohitaji kwa mikrotik_configs ----
:put "===================================================="
:put ("ROUTER IDENTITY : " . [/system identity get name])
:put ("MIKROTIK IP     : 10.<<< NAMBA >>>.0.1  (au IP ya management uliyoweka)")
:put ("API USER        : <<< JINA_LA_API_USER >>>")
:put ("API PASS        : <<< PASSWORD_IMARA >>>")
:put ("API PORT        : 8728")
:put "Nakili taarifa hizi kwenda kwenye mikrotik_configs (phpMyAdmin)"
:put "===================================================="

# ---- 16. JISAJILI KIOTOMATIKI KWENYE mikrotik_configs (HIARI) ----
# Badala ya kunakili taarifa za hapo juu kwa mkono kwenda phpMyAdmin,
# router inaweza KUJITUMA taarifa hizo moja kwa moja kwa PHP endpoint
# maalum (register_router.php) - endpoint hiyo ndiyo itakayoandika
# kwenye database. SHARTI: router iweze kufikia server yako ya PHP
# (IP ile ile uliyoiweka kwenye Walled Garden IP hapo juu).
#
# <<< RESELLER_USER_ID >>> = user_id ya reseller huyu kwenye jedwali
#                             `users` (login_signup) - MUHIMU ijulikane
#                             KABLA - siyo router_id (hiyo inatengenezwa
#                             na database yenyewe, auto-increment).
# <<< SIRI_YA_USAJILI >>>   = neno la siri linalolingana na lile
#                             lililowekwa ndani ya register_router.php
#                             (kuzuia mtu yeyote asijisajili bila ruhusa)
:local regResult [/tool fetch \
    url=("http://<<< IP YA SERVER YAKO YA PHP >>>/my_work/register_router.php") \
    http-method=post \
    http-data=("secret=<<< SIRI_YA_USAJILI >>>" . \
        "&user_id=<<< RESELLER_USER_ID >>>" . \
        "&identity=" . [/system identity get name] . \
        "&ip=10.<<< NAMBA >>>.0.1" . \
        "&api_user=<<< JINA_LA_API_USER >>>" . \
        "&api_pass=<<< PASSWORD_IMARA >>>" . \
        "&api_port=8728") \
    output=user as-value]
:put "---- MATOKEO YA USAJILI KIOTOMATIKI ----"
:put ($regResult->"data")
:put "-----------------------------------------"
# Ukiona "OK" au "router_id: X" hapo juu, usajili umefanikiwa - hakuna
# haja ya kwenda phpMyAdmin tena kwa mkono. Ukiona error/timeout,
# fuata utaratibu wa zamani (nakili taarifa za HATUA 15 kwa mkono).

# ============================================================
# BAADA YA KUENDESHA SCRIPT HII, ILIYOBAKI KWA MKONO NI HII TU:
#
# 1. Pandisha login.html yako kwenye router (Files -> hotspot folder).
#    HII PEKEE HAIWEZI KUFANYWA NA ROUTER - ni faili la kompyuta yako.
#    Hakikisha routerID ndani ya login.html inalingana na router_id
#    iliyotengenezwa na Hatua 16 (angalia matokeo ya "MATOKEO YA
#    USAJILI KIOTOMATIKI" kwenye terminal).
#
# 2. Pandisha status.html vilevile (kwa ajili ya trial countdown).
#
# 3. Weka bei za `tariffs` kwenye database kwa user_id ya reseller huyu
#    (bei ni maamuzi ya kibiashara - hayawezi kuamuliwa na script).
#
# 4. Jaribu API connection kwa test.php kuthibitisha kila kitu kiko sawa.
#
# (Hatua za zamani za "ongeza system user" na "ongeza row kwenye
# mikrotik_configs" tayari zimefanywa NA SCRIPT hii - Hatua 14b na 16.)
# ============================================================
