# =====================================================================
# tech5g_migrate_to_new_vps.rsc
# ---------------------------------------------------------------------
# Inahamisha router kutoka VPS ya zamani (143.246.136.110, IMEKUFA)
# kwenda VPS mpya (66.29.143.116).
#
# Funguo ya ROUTER haibadiliki - ya SERVER pekee ndiyo mpya. Kwa hiyo
# hapa tunabadilisha vitu VITATU tu:
#   1. endpoint-address ya peer  -> IP mpya
#   2. public-key ya peer        -> funguo mpya ya server
#   3. walled-garden dst-address -> IP mpya (bila hii mteja hawezi
#      kufungua ukurasa wa malipo kabla ya kulipa)
#
# Endesha kwenye Winbox > New Terminal, au File > upload kisha:
#   /import file-name=tech5g_migrate_to_new_vps.rsc
# Salama kuiendesha zaidi ya mara moja.
# =====================================================================

:local oldIP    "143.246.136.110"
:local newIP    "66.29.143.116"
:local newSrvPK "YuuyXrjIGLTumhfn2FutSV5ZtFDGmlr3R7beBip1wXY="

:put "== Tech5G: kuhamia VPS mpya =="

# ── 1 + 2. Peer ya WireGuard ──
:local peers [/interface/wireguard/peers find where endpoint-address=$oldIP]
:if ([:len $peers] = 0) do={
    :put "  (i) hakuna peer yenye endpoint ya zamani - labda tayari imehamishwa"
    :set peers [/interface/wireguard/peers find where endpoint-address=$newIP]
    :if ([:len $peers] > 0) do={ :put "  OK: peer tayari inaelekea $newIP" }
} else={
    :foreach p in=$peers do={
        /interface/wireguard/peers set $p endpoint-address=$newIP \
            endpoint-port=51821 public-key=$newSrvPK \
            persistent-keepalive=25s
        :put "  OK: peer imehamishiwa $newIP na funguo mpya"
    }
}

# ── 3. Walled garden: ruhusu backend mpya, ondoa ya zamani ──
:local wgOld [/ip/hotspot/walled-garden/ip find where dst-address=$oldIP]
:foreach w in=$wgOld do={
    /ip/hotspot/walled-garden/ip remove $w
    :put "  OK: walled-garden ya IP ya zamani imeondolewa"
}
:if ([:len [/ip/hotspot/walled-garden/ip find where dst-address=$newIP]] = 0) do={
    /ip/hotspot/walled-garden/ip add dst-address=$newIP action=accept \
        comment="Tech5G PHP backend access"
    :put "  OK: walled-garden imeongezwa kwa $newIP"
} else={
    :put "  (i) walled-garden ya $newIP tayari ipo"
}

:put "== Imekamilika. Subiri sekunde 30 kisha angalia: =="
:put "   /interface/wireguard/peers print   (angalia last-handshake)"
:put "   /ping 10.60.0.1 count=4            (lazima ijibu)"
