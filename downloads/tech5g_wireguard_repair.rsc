# ============================================================
# TECH 5G — KUREKEBISHA TUNNEL YA WIREGUARD (API HAIFIKIKI)
# ============================================================
#
# ITUMIE LINI:
#   Dashboard inasema "Router haipatikani", au wateja wanalipa lakini
#   hawapati vocha, HALI YA KUWA router yenyewe ina intaneti na wateja
#   wanaweza kuvinjari mtandao kawaida.
#
#   Hiyo ni dalili ya tatizo MOJA: handshake ya WireGuard inafanikiwa,
#   lakini data haipiti. Seva inatuma packet kwenda kwenye router,
#   router HAIJIBU KABISA - siyo ping, siyo API, hakuna.
#
# TATIZO HALISI (lilivyotokea 2026-09-04):
#   Seva (10.60.0.1) haikuweza kufikia routers ZOTE tano. `wg show`
#   ilionyesha handshake ya sekunde 5 zilizopita - tunnel "ipo" - lakini
#   baada ya kutuma bytes 148 za ping, bytes ZILIZOPOKELEWA zilikuwa 0.
#
#   Sababu tatu zinazowezekana, na script hii inazifunga ZOTE:
#     1. allowed-address ya peer haijumuishi 10.60.0.1 - WireGuard
#        yenyewe inatupa packet kimya kimya baada ya ku-decrypt.
#     2. Interface ya WireGuard haina /ip address - router haina
#        anwani ya kujibia.
#     3. Firewall chain=input inazuia in-interface ya WireGuard.
#
# JINSI YA KUTUMIA:
#   1. Badilisha $wgAddress hapa chini kwa tunnel IP YA ROUTER HII
#      (angalia jedwali). HII NDIYO SEHEMU PEKEE YA KUBADILISHA.
#   2. WinBox > Files > drag-and-drop faili hili.
#   3. WinBox > New Terminal:
#        /import file-name=tech5g_wireguard_repair.rsc
#   4. Soma majibu yanayochapishwa. Yakiisha, jaribu:
#        /ping 10.60.0.1 count=4
#      Ukipata majibu, imeisha - dashboard itaonyesha router online
#      ndani ya dakika 5, na vocha za wateja waliolipa zitatoka zenyewe.
#
# JEDWALI LA TUNNEL IP (kila router ina YAKE - USICHANGANYE):
#   Bin Waqas - Router 1  ->  10.60.0.2/24
#   Bin Waqas - Router 2  ->  10.60.0.5/24
#   Bin Waqas - Router 3  ->  10.60.0.8/24
#   Bin Waqas - Router 4  ->  10.60.0.10/24
#   TAVETA                ->  10.60.0.11/24
#
# NI SALAMA: haiguswi hotspot, wateja walioingia, wala vocha zilizopo.
# Ni salama pia kuiendesha zaidi ya mara moja.
# ============================================================


# ------------------------------------------------------------
# BADILISHA HAPA TU
# ------------------------------------------------------------
:local wgAddress   "10.60.0.10/24"
:local apiPassword "tech@2026"

# Hizi ni za mfumo - usizibadilishe.
:local serverIP    "66.29.143.116"
:local serverTunIP "10.60.0.1"
:local tunnelNet   "10.60.0.0/24"
:local apiPort     8728
:local apiUsername "tech5g_api"


# ------------------------------------------------------------
# SCRIPT (usibadilishe chini ya hapa)
# ------------------------------------------------------------

:put "=========================================="
:put "TECH 5G - KUREKEBISHA WIREGUARD"
:put "=========================================="

# ---- 1. Tafuta peer inayoelekea seva yetu ----
# Tunaitafuta kwa endpoint-address badala ya jina la interface, kwa
# sababu routers zilizosanidiwa kwa mkono zina majina tofauti
# (wireguard1, wg-tech5g, vpn, ...).
:local peerId [/interface/wireguard/peers find where endpoint-address=$serverIP]

:if ([:len $peerId] = 0) do={
    :put "KOSA: Hakuna WireGuard peer inayoelekea $serverIP."
    :put "      Router hii haijawahi kusanidiwa VPN ya Tech5G, au peer imefutwa."
    :put "      Tumia tech5g_router_setup.rsc (setup kamili) badala ya faili hii."
    :error "Hakuna peer ya kurekebisha."
}

:local wgIface [/interface/wireguard/peers get [:pick $peerId 0] interface]
:put "[1/5] Peer imepatikana. Interface: $wgIface"


# ---- 2. allowed-address LAZIMA ijumuishe seva ----
# HII NDIYO SABABU YA KAWAIDA KULIKO ZOTE. WireGuard ina "cryptokey
# routing": packet iliyo-decrypt-iwa yenye source IP isiyo ndani ya
# allowed-address INATUPWA KIMYA KIMYA. Handshake inaendelea kufanikiwa
# (inatambulika kwa ufunguo, siyo IP), hivyo `wg show` inaonekana nzuri
# wakati hakuna data inayopita - ndicho tulichokiona.
:local awGuard [/interface/wireguard/peers get [:pick $peerId 0] allowed-address]
:put "      allowed-address ya sasa: $awGuard"

/interface/wireguard/peers set [:pick $peerId 0] allowed-address=$tunnelNet persistent-keepalive=25s
:put "[2/5] allowed-address imewekwa $tunnelNet, keepalive 25s."


# ---- 3. Interface ya WireGuard lazima iwe na IP ya kujibia ----
:local ipCount [:len [/ip/address find where interface=$wgIface]]
:if ($ipCount = 0) do={
    /ip/address add address=$wgAddress interface=$wgIface comment="Tech5G WireGuard IP"
    :put "[3/5] IP $wgAddress imeongezwa kwenye $wgIface (haikuwepo kabisa)."
} else={
    :put "[3/5] IP tayari ipo kwenye $wgIface: $[/ip/address get [:pick [/ip/address find where interface=$wgIface] 0] address]"
    :put "      (Kama siyo ile ya jedwali hapo juu, irekebishe kwa mkono.)"
}

# Interface isiwe imezimwa
/interface/wireguard set [find name=$wgIface] disabled=no


# ---- 4. Firewall: ruhusu chain=input kutoka kwenye tunnel ----
# Bila rule hii, router yenye "drop input" ya kawaida inakubali
# handshake (UDP kwenye WAN) lakini inazuia API iliyo ndani ya tunnel.
:if ([:len [/ip/firewall/filter find where comment="Tech5G VPN trusted"]] = 0) do={
    :if ([:len [/ip/firewall/filter find]] > 0) do={
        /ip/firewall/filter add action=accept chain=input in-interface=$wgIface \
            comment="Tech5G VPN trusted" place-before=0
    } else={
        /ip/firewall/filter add action=accept chain=input in-interface=$wgIface \
            comment="Tech5G VPN trusted"
    }
    :put "[4/5] Rule ya firewall 'Tech5G VPN trusted' imeongezwa (juu kabisa)."
} else={
    # Ipo lakini inaweza kuwa imezimwa au iko chini ya rule ya drop.
    /ip/firewall/filter set [find where comment="Tech5G VPN trusted"] \
        disabled=no in-interface=$wgIface action=accept chain=input
    /ip/firewall/filter move [find where comment="Tech5G VPN trusted"] destination=0
    :put "[4/5] Rule ya firewall ilikuwepo - imewashwa na kupelekwa juu kabisa."
}


# ---- 5. Huduma ya API na mtumiaji wake ----
/ip/service set [find name=api] disabled=no port=$apiPort address=$tunnelNet
:put "[5/5] API imewashwa kwenye port $apiPort, inaruhusu $tunnelNet pekee."

:if ([:len [/user/group find where name="api-only"]] = 0) do={
    /user/group add name=api-only \
        policy=api,read,write,test,ftp,!local,!telnet,!ssh,!reboot,!password,!policy,!winbox,!web,!sniff,!sensitive,!romon
    :put "      Group 'api-only' imetengenezwa."
}

:if ([:len [/user find where name=$apiUsername]] = 0) do={
    /user add name=$apiUsername password=$apiPassword group=api-only
    :put "      Mtumiaji '$apiUsername' ameongezwa."
} else={
    /user set [find name=$apiUsername] password=$apiPassword group=api-only disabled=no
    :put "      Mtumiaji '$apiUsername' amethibitishwa (password imewekwa upya)."
}


# ---- 6. Uthibitisho ----
:put ""
:put "=========================================="
:put "UTHIBITISHO"
:put "=========================================="
:delay 2s
:local matokeo [/ping $serverTunIP count=4]
:put "Ping kwenda seva ($serverTunIP): majibu $matokeo kati ya 4"

:if ($matokeo > 0) do={
    :put ""
    :put "IMEFANIKIWA. Tunnel inafanya kazi pande zote mbili."
    :put "Dashboard itaonyesha router hii ONLINE ndani ya dakika 5,"
    :put "na vocha za wateja waliolipa zitatoka zenyewe."
} else={
    :put ""
    :put "BADO HAIJAFANYA KAZI. Angalia kwa mpangilio huu:"
    :put "  a) /interface/wireguard/peers print  -> last-handshake ni ya hivi karibuni?"
    :put "     Hapana -> tatizo ni WAN/NAT/port 51821 ya seva, siyo config hii."
    :put "  b) /ip/route print  -> je kuna route inayopeleka $tunnelNet mahali pengine?"
    :put "  c) /ip/firewall/filter print  -> rule ya 'Tech5G VPN trusted' iko namba 0?"
    :put "  d) /ip/firewall/raw print  -> kuna rule ya raw inayotupa packet kabla ya filter?"
    :put "  Tuma matokeo ya (a) hadi (d) kwa msimamizi wa Tech5G."
}
:put "=========================================="
