# my_work

Enable API service with the server's subnet in address
Create the API user (klikcell-api) in a group with api,read,write policy
Set up the hotspot + the three profiles (daily_profile, weekly_profile, monthly_profile)
Walled-garden rule so unpaid customers can reach 192.168.10.254
Firewall input rule allowing the LAN to reach the router

Step 2 — Admin adds it in the dashboard (this is save_mikrotik.php, the file you have open):

Admin opens the dashboard, finds the reseller's card, fills 3 fields: router IP, API user, API password.
On submit, the system live-tests the connection first (save_mikrotik.php:35) — if the router doesn't answer, nothing is saved and the admin sees the failure toast.
If it connects: config saved, and a Router ID is auto-assigned (save_mikrotik.php:46-51) — the piece we fixed. The toast tells the admin the ID: "Router ID: 3 — weka namba hii kwenye login.html."
From that moment the system can fully control the router — sell vouchers, auto-login customers, renew packages. That part is automatic.

Step 3 — The one remaining manual step: upload login.html and status.html to the router's hotspot/ folder. This is how the captive portal tells your server which router a customer is standing next to.
Download both from **MikroTik Setup** (mikrotik_setup.php) while logged in as that reseller — the Router ID is injected into every occurrence at download time, so there is nothing to edit by hand. The download is locked until the router is registered (step 2), which prevents shipping another reseller's Router ID.

Full per-router runbook: docs/ONBOARD_ROUTER.md

192.168.10.1 8728

                 THE INTERNET
                      │
        ┌─────────────┴──────────────┐
        │   YOUR VPS (static IP)      │
        │   • Web app (PHP) + HTTPS   │
        │   • MySQL                   │
        │   • VPN server  10.10.0.1   │
        └──────┬───────────────┬──────┘
      VPN tunnel (dial-in)  VPN tunnel
               │               │
      ┌────────┴──────┐  ┌─────┴─────────┐
      │ Reseller A    │  │ Reseller B    │
      │ MikroTik      │  │ MikroTik      │
      │ tunnel 10.10.0.11│ 10.10.0.12    │
      │ LAN 192.168.x │  │ LAN 192.168.x │
      └───────────────┘  └───────────────┘
