# ============================================================
# TECH 5G WI-FI - DYNAMIC DUAL WAN SETUP (ETHERNET + SFP)
# ============================================================
# Jinsi ya kutumia:
#   1. Badilisha maadili kwenye sehemu ya "MAADILI YANAYOWEZA KUBADILISHWA" hapa chini
#      kulingana na mahitaji yako.
#   2. Hifadhi faili hili, kisha ulipakie (upload) WinBox > Files (drag-and-drop).
#   3. Kwenye New Terminal andika:  /import file-name=tech5g-setup.rsc
# ============================================================


# ------------------------------------------------------------
# MAADILI YANAYOWEZA KUBADILISHWA (badilisha hapa tu, sio chini)
# ------------------------------------------------------------

:local routerName        "Tech5G-Hotspot"
:local timeZone          "Africa/Dar_es_Salaam"

:local bridgeName        "Tech5G Bridge"
:local hotspotPoolName   "Tech5G Hotspot Pool"
:local hotspotNetwork    "10.50.0.0/24"
:local hotspotGateway    "10.50.0.1"
:local hotspotPoolRange  "10.50.0.2-10.50.0.254"
:local dhcpLeaseTime     "1h"

# Muda wa trial na kuzuia kurudia
:local trialTime         "5m"
:local trialResetPeriod  "365d"
:local trialRateLimit    "4M/4M"

# Profiles za vocha (jina, muda wa session, rate-limit)
:local dailyName   "daily_profile"
:local dailyTime   "1d"
:local dailyRate   "6M/6M"

:local weeklyName  "weekly_profile"
:local weeklyTime  "7d"
:local weeklyRate  "8M/8M"

:local monthlyName "monthly_profile"
:local monthlyTime "30d"
:local monthlyRate "10M/10M"

# Walled Garden (majina ya tovuti zinazoruhusiwa bila kulipia)
:local walledGardenHosts {"*.azampay.co.tz";"checkout.azampay.co.tz";"app.dalipay.co.tz";"*.dalipay.co.tz";"tech5g.co.tz";"*.tech5g.co.tz"}
:local tech5gServerIP     "143.246.136.110"

# WireGuard VPN
:local wgListenPort       51821
:local wgPrivateKey       "qGU+J4g8cz6bBX1igA7Jm7qAGWQdy1bKhNeeCPbi9Xs="
:local wgLocalAddress     "10.60.0.5/24"
:local wgPeerPublicKey    "/+4EytOmXXXXZook7/+hGbqjMFSbSrMgrYgCuxyyKXQ="
:local wgEndpointAddress  "143.246.136.110"
:local wgEndpointPort     51821
:local wgAllowedAddress   "10.60.0.0/24"

# API (huduma ya nje kuunganisha na mfumo wa vocha)
:local apiPort            8728
:local apiAllowedSubnet   "10.60.0.0/24"
:local apiUsername        "tech5g_api"
:local apiPassword        "tech@2026"

# ------------------------------------------------------------
# SCRIPT HALISI (usibadilishe chini ya hapa isipokuwa unajua)
# ------------------------------------------------------------

# ---- 1. System Identity & Clock ----
/system identity set name=$routerName
/system clock set time-zone-autodetect=no time-zone-name=$timeZone

# ---- 2. Auto-Detect Internet Interface (SFP or Ether1) ----
:local wanIf ""

:if ([:len [/interface find name="sfp1"]] > 0) do={
    :set wanIf "sfp1"
} else={
    :if ([:len [/interface find name="sfp-sfpplus1"]] > 0) do={
        :set wanIf "sfp-sfpplus1"
    } else={
        :set wanIf "ether1"
    }
}

/interface set [ find name=$wanIf ] name="Internet Source"

# ---- 3. Bridge & Local Ports Configuration ----
/interface bridge add name=$bridgeName

:foreach i in=[/interface ethernet find name!="Internet Source"] do={
    :local portName [/interface ethernet get $i name]
    /interface bridge port add bridge=$bridgeName interface=$portName
}

# Auto-add Wireless/WiFi if available (jina halisi la interface)
# Baadhi ya vifaa (mfano hAP lite) havina menyu ya /interface/wifi kabisa,
# hivyo tunatumia :do{}on-error{} kuepuka kuvunja script nzima.
:local wifiIfName ""
:do {
    :local wifiList [/interface/wifi find]
    :if ([:len $wifiList] > 0) do={
        :set wifiIfName [/interface/wifi get [:pick $wifiList 0] name]
    }
} on-error={}

:if ($wifiIfName = "") do={
    :do {
        :local wlanList [/interface/wireless find]
        :if ([:len $wlanList] > 0) do={
            :set wifiIfName [/interface/wireless get [:pick $wlanList 0] name]
        }
    } on-error={}
}
:if ($wifiIfName != "") do={ /interface bridge port add bridge=$bridgeName interface=$wifiIfName }

# ---- 4. IP Network & Hotspot IP Pool ----
:local hotspotNetworkAddr [:pick $hotspotNetwork 0 [:find $hotspotNetwork "/"]]

/ip pool add name=$hotspotPoolName ranges=$hotspotPoolRange
/ip address add address=("$hotspotGateway" . "/24") comment="Tech5G Hotspot IP" interface=$bridgeName network=$hotspotNetworkAddr
/ip dhcp-server add address-pool=$hotspotPoolName disabled=no interface=$bridgeName lease-time=$dhcpLeaseTime name="Tech5G Dhcp Server"
/ip dhcp-server network add address=$hotspotNetwork comment="Tech5G Hotspot DHCP Network" gateway=$hotspotGateway

/ip dhcp-client add disabled=no interface="Internet Source" use-peer-dns=yes use-peer-ntp=yes

# ---- 5. User Profiles (Vouchers & Trial) ----
/ip hotspot user profile add name=$dailyName session-timeout=$dailyTime rate-limit=$dailyRate shared-users=1
/ip hotspot user profile add name=$weeklyName session-timeout=$weeklyTime rate-limit=$weeklyRate shared-users=1
/ip hotspot user profile add name=$monthlyName session-timeout=$monthlyTime rate-limit=$monthlyRate shared-users=1
/ip hotspot user profile add name="trial" rate-limit=$trialRateLimit session-timeout=$trialTime shared-users=1

# ---- 6. Hotspot Profile & Server Setup ----
/ip hotspot profile add hotspot-address=$hotspotGateway login-by=http-chap,http-pap,mac-cookie,trial name="Tech5G Hotspot Profile" trial-uptime-limit=$trialTime trial-uptime-reset=$trialResetPeriod trial-user-profile="trial" use-radius=no
/ip hotspot add address-pool=$hotspotPoolName addresses-per-mac=1 disabled=no interface=$bridgeName name="Tech5G Hotspot" profile="Tech5G Hotspot Profile"

# ---- 7. Firewall & NAT ----
/ip firewall filter add action=accept chain=input connection-state=established,related comment="Allow established/related"
/ip firewall filter add action=drop chain=input connection-state=invalid comment="Drop invalid"
/ip firewall filter add action=drop chain=input in-interface="Internet Source" comment="Block WAN access to router"

/ip firewall filter add action=drop chain=input comment="ICMP Block Tech5G Bridge" in-interface=$bridgeName protocol=icmp
/ip firewall filter add action=drop chain=forward comment="Client Isolation" dst-address=$hotspotNetwork in-interface=$bridgeName src-address=$hotspotNetwork
/ip firewall mangle add action=change-ttl chain=postrouting comment="Anti-sharing" new-ttl=set:1 out-interface=$bridgeName passthrough=yes
/ip firewall mangle add action=change-ttl chain=prerouting comment="Hide Router IP" new-ttl=increment:2 passthrough=yes
/ip firewall nat add action=masquerade chain=srcnat comment="Masquerade Hotspot" src-address=$hotspotNetwork
/ip firewall nat add action=masquerade chain=srcnat comment="Masquerade WAN" out-interface="Internet Source"

# ---- 8. Walled Garden ----
:foreach host in=$walledGardenHosts do={
    /ip hotspot walled-garden add dst-host=$host
}
/ip hotspot walled-garden ip add dst-address=$tech5gServerIP comment="Tech5G Server Access"

# ---- 9. WireGuard VPN ----
/interface wireguard add listen-port=$wgListenPort name=wg-tech5g private-key=$wgPrivateKey
/ip address add address=$wgLocalAddress comment="Tech5G WireGuard IP" interface=wg-tech5g
/interface wireguard peers add allowed-address=$wgAllowedAddress endpoint-address=$wgEndpointAddress endpoint-port=$wgEndpointPort interface=wg-tech5g persistent-keepalive=25s public-key=$wgPeerPublicKey
/ip firewall filter add action=accept chain=input comment="Tech5G VPN trusted" in-interface=wg-tech5g place-before=0

# ---- 10. API Service & Users ----
/ip service enable api
/ip service set api port=$apiPort address=$apiAllowedSubnet
:if ([:len [/user/group find where name="api-only"]] = 0) do={ /user group add name=api-only policy=api,read,write,test,ftp,!local,!telnet,!ssh,!reboot,!password,!policy,!winbox,!web,!sniff,!sensitive,!romon }
:if ([:len [/user find where name=$apiUsername]] = 0) do={ /user add group=api-only name=$apiUsername password=$apiPassword } else={ /user set [find name=$apiUsername] password=$apiPassword group=api-only }

:put "===================================================="
:put "SCRIPT HII INAFANYA KAZI KWA ETHERNET AU SFP AUTOMATICALLY!"
:put "===================================================="
