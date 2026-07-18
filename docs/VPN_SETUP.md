# VPN Setup Runbook — WireGuard (single VPS hub, Case A)

**Goal:** let your **online server** reach every reseller's MikroTik router securely, even though the
routers sit behind ISP NAT/CGNAT with no public IP. Each router opens a **WireGuard** tunnel *out* to
your VPS and gets a **fixed tunnel IP**. The web app (same VPS) then talks to the router at that IP
(e.g. `10.10.0.11:8728`) — the RouterOS API is **never** exposed to the public internet.

> **Muhtasari (kwa kifupi):** Kila router itaunganisha WireGuard kwenda seva yako na kupewa namba ya
> ndani isiyobadilika (mfano `10.10.0.11`). Namba hii ndiyo utakayoweka kwenye Admin Dashboard badala
> ya `192.168.10.1`. Hakuna CHR inayohitajika — WireGuard inaendeshwa moja kwa moja kwenye VPS.

- **Case A (single box):** WireGuard runs **directly on your existing Linux VPS**. No CHR, no second
  server, no inter-box routing. The VPS is the hub *and* the web/DB server.
- **The app reaches routers directly:** PHP on the VPS connects to `10.10.0.11:8728` over the `wg0`
  interface. Nothing in `save_mikrotik.php` / `getMikrotikConnection()` changes — you just enter the
  tunnel IP instead of `192.168.10.1`.

---

## ⚠️ Requirement: every router must be RouterOS v7

WireGuard on MikroTik exists **only on RouterOS v7+**. Your fleet is mixed, so **upgrade any v6 router
to v7 before onboarding it**:

```rsc
/system package update check-for-updates
/system package update install   ;# router reboots into v7
/system routerboard upgrade      ;# then reboot once more to update the bootloader (firmware)
```

Most MikroTik hardware from ~2015 onward runs v7 fine. If you have an old device that genuinely can't
run v7 (very low flash / pre-mipsbe), keep **that unit** on L2TP/IPsec — see
`git show` history / ask me to add an L2TP appendix for stragglers. Everything else uses WireGuard.

---

## 0. Topology & addressing

```
                        INTERNET
                            │
            ┌───────────────┴────────────────┐
            │   VPS (public static IP)        │
            │  ┌───────────────────────────┐  │
            │  │ Web app (PHP) + MySQL      │  │
            │  │ https://«yourdomain»       │  │
            │  ├───────────────────────────┤  │
            │  │ WireGuard hub  wg0         │  │  ← this VPS IS the hub
            │  │        10.10.0.1  :51820   │  │
            │  └───────────────────────────┘  │
            └──────┬──────────────────┬────────┘
          WireGuard (dial-out)   WireGuard
                   │                  │
        ┌──────────┴─────┐   ┌────────┴────────┐
        │ Reseller A     │   │ Reseller B      │
        │ MikroTik v7    │   │ MikroTik v7     │
        │ tunnel 10.10.0.11  │ tunnel 10.10.0.12│
        │ shop LAN 192.168.x │ shop LAN 192.168.x│
        └────────────────┘   └─────────────────┘
```

**Master list — keep this, one row per reseller:**

| Item                | Tunnel IP     | WireGuard public key            | Notes                          |
|---------------------|---------------|---------------------------------|--------------------------------|
| VPS hub             | `10.10.0.1`   | `«SERVER_PUBLIC_KEY»`           | UDP `51820` open on VPS        |
| Reseller A router   | `10.10.0.11`  | `«ROUTER_A_PUBLIC_KEY»`         | = `mikrotik_ip` in dashboard   |
| Reseller B router   | `10.10.0.12`  | `«ROUTER_B_PUBLIC_KEY»`         | fixed, never reuse a number    |

**Placeholders** — replace every `«...»`:
- `«VPS_PUBLIC_IP»` — public static IP of the VPS.
- `«SERVER_PRIVATE_KEY»` / `«SERVER_PUBLIC_KEY»` — generated in §1.
- `«ROUTER_A_PUBLIC_KEY»` — generated **on the router** in §3 (private key never leaves the router).

---

## 1. VPS — install WireGuard & generate server keys

On Debian/Ubuntu:

```bash
sudo apt update && sudo apt install -y wireguard
wg genkey | sudo tee /etc/wireguard/server_private.key | wg pubkey | sudo tee /etc/wireguard/server_public.key
sudo chmod 600 /etc/wireguard/server_private.key
```

Note both values — `server_private.key` → `«SERVER_PRIVATE_KEY»`, `server_public.key` →
`«SERVER_PUBLIC_KEY»` (goes to every router in §3).

---

## 2. VPS — create the hub interface

Create `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address    = 10.10.0.1/24
ListenPort = 51820
PrivateKey = «SERVER_PRIVATE_KEY»

# --- one [Peer] block per reseller, added in §4 ---
```

Open the WireGuard port and bring the interface up:

```bash
sudo ufw allow 51820/udp            # or your cloud provider's firewall
sudo systemctl enable --now wg-quick@wg0
sudo wg show                        # should list wg0, no peers yet
```

> No IP forwarding / masquerade needed: the PHP app is on this same box and talks to the routers
> directly over `wg0`. We deliberately do **not** route the shops' internet through the hub.

---

## 3. Reseller router (v7) — create the tunnel

Run on **each reseller's MikroTik**. The router generates its **own** private key; you only copy out
its public key.

```rsc
# 1) create the WireGuard interface (auto-generates a keypair)
/interface/wireguard add name=wg-hub listen-port=13231

# 2) read its PUBLIC key — copy this to the server master list («ROUTER_A_PUBLIC_KEY»)
/interface/wireguard print

# 3) point it at the VPS hub. allowed-address = 10.10.0.0/24 means ONLY management traffic
#    goes through the tunnel — the shop's internet is untouched (this is the WireGuard
#    equivalent of "add-default-route=no").
/interface/wireguard/peers add interface=wg-hub \
    public-key="«SERVER_PUBLIC_KEY»" \
    endpoint-address=«VPS_PUBLIC_IP» endpoint-port=51820 \
    allowed-address=10.10.0.0/24 \
    persistent-keepalive=25s

# 4) give this router its fixed tunnel IP
/ip/address add address=10.10.0.11/24 interface=wg-hub
```

`persistent-keepalive=25s` is what punches through CGNAT and keeps the NAT mapping alive — do not omit
it. The shop's firewall needs **no** inbound port open; the router dials out.

---

## 4. VPS — add the router as a peer

Append a block to `/etc/wireguard/wg0.conf` for this reseller (use the key from §3 step 2):

```ini
[Peer]
# Reseller A
PublicKey  = «ROUTER_A_PUBLIC_KEY»
AllowedIPs = 10.10.0.11/32
```

Apply without dropping other tunnels:

```bash
sudo wg syncconf wg0 <(wg-quick strip wg0)
sudo wg show wg0     # after the router connects you'll see a 'latest handshake'
```

---

## 5. Router — lock the API to the tunnel (security)

Once the tunnel is up, make the RouterOS API reachable **only** over WireGuard:

```rsc
/ip service set api address=10.10.0.0/24 disabled=no
/ip firewall filter add chain=input action=accept protocol=tcp dst-port=8728 \
    src-address=10.10.0.0/24 in-interface=wg-hub comment="API over VPN only" \
    place-before=0
```

Make sure the API user (the one you type in the dashboard) exists with an `api,read,write` policy —
see project README step 1.

---

## 6. Verify end-to-end from the server

From the **VPS** — this is exactly what `save_mikrotik.php` does before it saves:

```bash
ping 10.10.0.11
php /path/to/my_work/test_router.php 10.10.0.11 «api_user» «api_pass»
```

Expect `✅ IMEFANIKIWA! Router imejibu.` plus identity, model, RouterOS version, hotspot count, and
profiles. If you see that, tunnel + API are working.

---

## 7. Register in the dashboard + pilot

1. Admin Dashboard → the reseller's card → **`mikrotik_ip = 10.10.0.11`**, API user, API pass → Save.
   Success gives the **Router ID** toast.
2. Put that Router ID + your public domain into that router's `login.html`
   (`var routerID` and `var serverPHPUrl = "https://«yourdomain»/index_backup.php"`), upload to the
   router's `hotspot/` folder.
3. Update the router's **walled-garden** to allow `«yourdomain»` (replacing the old `192.168.10.254`).
4. Test a real voucher purchase + auto-login on a phone at the shop.

---

## 8. Per-reseller quick recipe (repeat for each shop)

1. Router on v7 (⚠️ upgrade if needed).
2. Pick the next free tunnel IP (`10.10.0.13`, `.14`, …) — add a row to the master list.
3. On the router: §3 (create `wg-hub`, copy public key, set peer + tunnel IP) then §5 (lock API).
4. On the VPS: §4 (add `[Peer]` with the router's public key + its `/32`, `wg syncconf`).
5. Verify (§6) → register + login.html + walled-garden (§7).

---

## 9. Security checklist

- [ ] Each router's **private key never left the router** (generated on-device in §3).
- [ ] Router API restricted to `10.10.0.0/24` (§5) — unreachable from shop LAN or internet.
- [ ] Peer `AllowedIPs` on the VPS is the router's **/32** (not `/24` or `0.0.0.0/0`).
- [ ] Router peer `allowed-address` is `10.10.0.0/24` (mgmt only) — shop internet not tunnelled.
- [ ] UDP `51820` is the only new port open on the VPS.
- [ ] VPS `server_private.key` is `chmod 600`.

---

## 10. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| No handshake on `wg show` | wrong keys (server↔router swapped), or UDP 51820 blocked on the VPS firewall. |
| Handshake OK, no ping to `10.10.0.1` | wrong `allowed-address` on router peer, or tunnel IP not assigned (§3 step 4). |
| Ping works, API times out | router API not bound to `10.10.0.0/24` (§5), or firewall accept below a drop rule. |
| Tunnel drops after idle | missing `persistent-keepalive=25s` on the router peer (needed for CGNAT). |
| API errors / weird hangs | MTU. On the router: `/interface/wireguard set wg-hub mtu=1412`, or add a TCP MSS clamp. |
| Works, then breaks after reboot | someone changed the router's tunnel IP — it must stay fixed per the master list. |

---

### What the code side needs (Track 2, separate from this runbook)
- `login.html` → `serverPHPUrl` uses `https://«yourdomain»` (per router).
- A single `config.php` for server URL + DB creds (remove scattered `192.168.x`).
- Security pass: mask/encrypt `api_pass`, disable `display_errors`, enforce HTTPS.
