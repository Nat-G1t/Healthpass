# Deploying HealthPass on a Raspberry Pi (kiosk terminal)

This guide sets up the **self-service vitals kiosk** on a Raspberry Pi 4 running
Raspberry Pi OS. The Pi runs the *whole* unified Laravel app locally and opens
Chromium full-screen on the kiosk page. Staff (nurses, admins) on the same
campus LAN can reach the same app through the Pi's IP address.

> **Scope:** deployment/ops only — no application code changes. Reference
> numbers, FR-IDs, and architecture come from `docs/HealthPass_PRD.md` and
> `CLAUDE.md`.

> ## ⚠️ Read this first — Decision D-34 changed the primary shape
>
> **The defense now runs against the hosted internet deployment**, not against
> the app running on the Pi. The Pi is a **browser/terminal**: Chromium opens
> `https://<domain>/kiosk`, and the server lives elsewhere — see
> **`docs/deployment-hosted.md`**.
>
> This document stays authoritative for everything about the **terminal itself**
> — Chromium kiosk autostart (§4), display rotation (§4), Web Serial and the
> ESP32 (§7), device enrollment (§9) — and for running the **whole app on the Pi**,
> which is now the **documented offline fallback** (§10) rather than the plan of
> record. Sections 1–3 (installing PHP/MariaDB/nginx on the Pi) apply only to
> that fallback.
>
> HTTPS is a secure context, so Web Serial works at `https://<domain>/kiosk`
> exactly as it did at `http://localhost`. **But serial grants are per-origin** —
> Baldo must re-authorize the ESP32 once on the new domain.

---

## 0. Why the Pi runs the app locally (important)

> **D-34 update:** this section explains the *original* reason the app ran on the
> Pi — the secure-context rule. That rule is unchanged and still the reason
> `http://<LAN-IP>` can never work. What changed is *how* we satisfy it: the
> hosted shape satisfies it with **TLS** instead of the `localhost` exemption.

Web Serial (`navigator.serial`, FR-KSK-07 / FR-HW-05 sensor path) only works in
a **secure context**. Browsers count these as secure:

- `https://…`
- `http://localhost`, `http://127.0.0.1`

They do **not** count `http://<LAN-IP>` (e.g. `http://192.168.100.97:8080`) as
secure. So:

- The **kiosk browser on the Pi must load `http://localhost/kiosk`** — then the
  sensors work.
- **Staff browsers on the LAN reach `http://<pi-ip>/`** — dashboards work, but
  Web Serial does not. That's fine: staff pages don't use sensors.

> **Note on yesterday's LAN test.** You ran the app on your laptop
> (`php artisan serve --host=0.0.0.0`) and pointed the Pi's Chromium at
> `http://192.168.100.97:8080/kiosk`. That correctly proved LAN reachability,
> the firewall rule, and that the kiosk UI renders full-screen on the Pi — but
> because it was `http://<LAN-IP>`, **the Web Serial sensor path would have been
> blocked** in that setup. It's a great smoke test for everything *except*
> sensors. For the real kiosk, the app must run **on the Pi** and Chromium must
> open **`http://localhost/kiosk`**, which is what this guide does. (This
> matches the `CLAUDE.md` "kiosk architecture" note.)

---

## 1. Install dependencies

Fresh Raspberry Pi OS (Bookworm, 64-bit recommended). One command:

```bash
sudo bash scripts/pi/install-deps.sh
```

That installs **PHP 8.2+** (+ the extensions Laravel 12 needs: mbstring, xml,
curl, zip, bcmath, intl, mysql, sqlite3, gd), **Composer**, **MariaDB**,
**Node.js 20 LTS**, **nginx**, and **Chromium**.

> Pi OS Bookworm ships PHP 8.2, which satisfies `"php": "^8.2"`. On an older Pi
> OS, upgrade the OS (or add the `ondrej/php` repo) rather than pinning an old
> PHP — Laravel 12 requires 8.2+.

### Create the database

```bash
sudo mysql_secure_installation      # set a root password, accept defaults
sudo mysql
```

```sql
CREATE DATABASE healthpass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'healthpass'@'127.0.0.1' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON healthpass.* TO 'healthpass'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Use the same DB name / user / password in the `.env` (next step).

---

## 2. Clone and configure the app

```bash
bash scripts/pi/setup-app.sh            # clones into /var/www/healthpass
# or:  bash scripts/pi/setup-app.sh /home/pi/healthpass
```

The script:

1. Clones `https://github.com/Nat-G1t/Healthpass.git`.
2. `composer install` — **with dev packages**: the seeders' model factories
   need `fakerphp/faker`, which is a `require-dev` package. Dev packages are
   pruned again in step 7.
3. `npm ci && npm run build` — **builds assets once. The Pi never runs
   `npm run dev` (Vite dev server).** Production serves the compiled files in
   `public/build`.
4. Copies `scripts/pi/pi.env.example` → `.env`, then `php artisan key:generate`.
5. `php artisan migrate --force`.
6. `php artisan db:seed --force` — **required**: creates the colleges plus all
   demo staff/student accounts (see `docs/dev-notes.md` for the login list;
   every password is `password`). Skipping this leaves an empty database where
   every web and kiosk login fails; running it under `--no-dev` crashes with
   `Call to a member function randomElement() on null` (no Faker).
7. `composer install --no-dev --optimize-autoloader` — prunes dev packages
   now that seeding is done.
8. `php artisan config:cache && route:cache && view:cache`.
9. Fixes `storage/` + `bootstrap/cache/` ownership to `www-data`.

**Edit `.env` before/after** to set the real `DB_PASSWORD`. Template highlights
(full file in `scripts/pi/pi.env.example`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost          # secure context for Web Serial (see §0)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1                 # never "localhost" for the DB socket
DB_PORT=3306
DB_DATABASE=healthpass
DB_USERNAME=healthpass
DB_PASSWORD=change-me
```

> After editing `.env`, re-run `php artisan config:cache` so the cached config
> picks up the change (a cached config ignores later `.env` edits).

---

## 3. Serve on port 80 — pick one

The kiosk browser will open `http://localhost/kiosk`, so the app must answer on
port **80**. Two options:

### Option A — nginx + php-fpm (recommended)

Best for an always-on, unattended terminal: starts on boot, restarts on
crash, serves static assets efficiently, and doesn't tie the app to one shell.

Create `/etc/nginx/sites-available/healthpass`:

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /var/www/healthpass/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # Match your installed PHP version's fpm socket:
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Enable it and reload:

```bash
sudo ln -sf /etc/nginx/sites-available/healthpass /etc/nginx/sites-enabled/healthpass
sudo rm -f /etc/nginx/sites-enabled/default        # drop the placeholder site
sudo nginx -t                                      # test config
sudo systemctl restart nginx
sudo systemctl enable nginx php8.2-fpm             # start on boot
```

Check the fpm socket name with `ls /run/php/`; adjust `fastcgi_pass` if your PHP
minor version differs.

### Option B — `php artisan serve` (simple, for quick bring-up / testing)

Single-threaded and not really meant for production, but zero-config. Binding to
port 80 needs root (or a capability), so use a systemd service:

Create `/etc/systemd/system/healthpass-serve.service`:

```ini
[Unit]
Description=HealthPass (php artisan serve)
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/healthpass
# Port 80 is privileged; grant the ability to bind it without full root.
AmbientCapabilities=CAP_NET_BIND_SERVICE
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=80
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now healthpass-serve
```

> `artisan serve` handles one request at a time. For a single kiosk it's usually
> fine, but if staff also browse via the LAN, prefer **Option A**.

Verify either option locally on the Pi:

```bash
curl -I http://localhost/kiosk        # expect HTTP/1.1 200 OK
```

---

## 4. Chromium kiosk autostart

### Display rotation — 15.6″ 1080×1920 portrait (D-26)

The kiosk display is a **15.6″ 1080p panel used in portrait** (1080×1920,
PRD D-26 — replaces the old 7″ 800×480 landscape screen). The panel is
physically landscape-native, so the Pi must rotate the output 90° at the
OS level; the app itself just fills whatever viewport Chromium reports.

> **PLACEHOLDER — for Baldo to pin down on the actual Pi.** On Bookworm's
> default Wayland (labwc) session the tool is `wlr-randr`, e.g.:
>
> ```bash
> wlr-randr --output HDMI-A-1 --transform 90     # or 270, depending on mount
> ```
>
> Things to confirm and document here:
>
> - [ ] The real output name (`wlr-randr` with no args lists outputs).
> - [ ] `--transform 90` vs `270` for the physical mounting direction.
> - [ ] Where to persist it so it applies before Chromium launches —
>       `~/.config/labwc/autostart` (before the kiosk-service start line)
>       or a `kanshi` profile.
> - [ ] **Touch input rotates with it?** If touches land 90° off, map the
>       touchscreen to the output (labwc `rc.xml` input config or a udev
>       calibration matrix) and document the exact snippet.
> - [ ] Chromium reports 1080×1920 (portrait) at `http://localhost/kiosk`
>       — check `window.innerWidth/innerHeight` in DevTools.

Launcher script: `scripts/pi/kiosk-chromium.sh` — opens Chromium full-screen on
`http://localhost/kiosk`, disables screen blanking (X11 only; see below), and
suppresses the crash-restore / info bars. Copy it somewhere stable and make it
executable:

```bash
sudo cp /var/www/healthpass/scripts/pi/kiosk-chromium.sh /usr/local/bin/healthpass-kiosk
sudo chmod +x /usr/local/bin/healthpass-kiosk
```

> **Keyring prompt (why `--password-store=basic`).** With desktop autologin the
> GNOME keyring is left **locked**, so Chromium pops an "unlock keyring" dialog
> on launch — fatal for an unattended kiosk. The launcher passes
> `--password-store=basic` to make Chromium use a plaintext store instead of the
> keyring, so no dialog appears. (The kiosk stores no real secrets; the only
> per-origin state we care about is the Web Serial grant, §7.)

**Enable desktop autologin first.** On newer Bookworm builds `sudo raspi-config`
→ *System Options* → *Boot / Auto Login* shows only **B1** (console) and **B2**
(desktop) — there is no separately labelled "Desktop Autologin" item; **B2 is
the desktop-autologin choice.** You can also verify or set it directly in
`/etc/lightdm/lightdm.conf` under `[Seat:*]`:

```ini
[Seat:*]
autologin-user=pi            # replace with your login user
```

Then choose **one** autostart mechanism. On **Bookworm/Wayland (labwc)** — the
current default Pi OS desktop — use **Option A**. Option B is **legacy / X11
only** (classic LXDE desktop).

### Option A — systemd user service + labwc autostart (Bookworm / Wayland)

This is the **reboot-verified** configuration for Pi OS Bookworm's default
Wayland (labwc) session.

> **Why not `WantedBy=graphical-session.target`?** On labwc that target is
> **never activated**, so a service wired to it just stays loaded/inactive after
> boot and Chromium never launches. Instead the service is left **disabled** and
> started explicitly from the labwc autostart file (below), after importing the
> Wayland environment into the user systemd manager.

**1. Create `~/.config/systemd/user/healthpass-kiosk.service`** — note there is
**no `[Install]` section** (the service is intentionally *not* enabled; it is
started on demand):

```ini
[Unit]
Description=HealthPass Chromium kiosk
After=graphical-session.target
PartOf=graphical-session.target

[Service]
# labwc runs a Wayland compositor on wayland-0; Chromium needs to find it.
Environment=WAYLAND_DISPLAY=wayland-0
ExecStartPre=/bin/sh -c 'until curl -sf http://localhost/kiosk >/dev/null; do sleep 2; done'
ExecStart=/usr/local/bin/healthpass-kiosk
Restart=always
RestartSec=3
```

```bash
systemctl --user daemon-reload      # no 'enable' — Option B's file starts it
```

The `ExecStartPre` loop waits until the app answers before launching Chromium,
so the kiosk never opens on a connection-refused page during boot. (Expect a
short delay after boot while this loop waits for nginx/php-fpm/MariaDB.)

**2. Create `~/.config/labwc/autostart`** — this is a **file, not a directory**
(create it if missing). It hands the Wayland environment to the user systemd
manager, then starts the service:

```sh
systemctl --user import-environment WAYLAND_DISPLAY XDG_RUNTIME_DIR
systemctl --user start healthpass-kiosk &
```

> The `import-environment` line is **mandatory** — without it the user systemd
> manager has no `WAYLAND_DISPLAY`/`XDG_RUNTIME_DIR`, and Chromium cannot find
> the Wayland compositor (it exits immediately, and `Restart=always` then loops).

**3. Screen blanking** on Wayland is **not** handled by `xset` (X11 only — the
`xset` block in the launcher is a harmless no-op under Wayland). Disable blanking
via `sudo raspi-config` → *Display Options* → *Screen Blanking* → *No*.

### Option B — LXDE autostart (**legacy / X11 only**)

> **Applies only to the classic X11 LXDE desktop, not Bookworm/Wayland (labwc).**
> The `xset` lines below are X11-only and do nothing under Wayland; on Wayland
> use raspi-config → Display Options → Screen Blanking (see Option A step 3).

Add to `~/.config/lxsession/LXDE-pi/autostart` (create the file if missing):

```
@xset s off
@xset -dpms
@xset s noblank
@/usr/local/bin/healthpass-kiosk
```

The `@` prefix tells LXDE to relaunch the command if it crashes.

> Manual test any time (no autostart needed):
> `chromium-browser --kiosk http://localhost/kiosk`
> During development you can also press F11 for `--fullscreen`, as in yesterday's
> test — but `--kiosk` is what you want unattended (no exit chrome).

### Dev mode — desktop shortcut instead of autostart

While the Pi doubles as a dev machine, boot-to-kiosk gets in the way (you land
in Chromium every boot and the service relaunches it on close). Switch to an
on-demand desktop launcher:

**1. Disable autostart.** Comment out the start line in
`~/.config/labwc/autostart` — keep the `import-environment` line, it's harmless
and needed again when you re-enable for the defense:

```sh
systemctl --user import-environment WAYLAND_DISPLAY XDG_RUNTIME_DIR
# systemctl --user start healthpass-kiosk &
```

That's the only switch: the service has no `[Install]` section and was never
enabled, so this line is the sole thing that starts it at boot.

**2. Install the desktop shortcut** (`scripts/pi/healthpass-kiosk.desktop`):

```bash
cp /var/www/healthpass/scripts/pi/healthpass-kiosk.desktop ~/Desktop/
chmod +x ~/Desktop/healthpass-kiosk.desktop
```

On first double-click PCManFM asks *Execute / Open / Cancel* — choose
**Execute**. To stop the prompt: File Manager → *Edit* → *Preferences* →
*General* → tick *"Don't ask options on launch executable file"*.

The shortcut runs the launcher script directly (not the systemd service), so
there is no `Restart=always`: **Alt+F4 closes the kiosk** and returns to the
desktop — no restart loop during development.

**Re-enable for the defense:** uncomment the line from step 1. The desktop
shortcut can stay; it's inert unless clicked.

---

## 4a. Bluetooth BP monitor — start the bridge at boot (D-58, D-59)

The A&D UA-651BLE cuff talks Bluetooth, so a small Python bridge,
`scripts/pi/bp_read.py`, listens for it and POSTs each reading to HealthPass
(PRD §11.4). It runs as a **system service that starts when the Pi boots** —
before anyone logs in — so the monitor is already being listened for when a
nurse opens the kiosk, and systemd restarts it if it ever stops. Nobody starts
it by hand.

> **Pair and flush once first.** The monitor must already be paired with the
> Pi, and `python3 scripts/pi/bp_read.py --address <MAC> --flush` run once, so
> the readings stored inside the monitor are marked as seen instead of posted.

**1. Check Python has what the bridge needs** (as your login user):

```bash
/usr/bin/python3 -c "import bleak, requests; print('ok')"
```

`ok` → carry on. An `ImportError` → `sudo apt install python3-bleak python3-requests`
(or, if bleak lives in a virtualenv, put that `python3` path in the service's
`ExecStart`).

**2. The settings file.** It holds the kiosk key, so only root can read it:

```bash
cd /var/www/healthpass
sudo install -D -m 600 scripts/pi/bp-daemon.env.example /etc/healthpass/bp-daemon.env
sudo nano /etc/healthpass/bp-daemon.env
```

Set `BP_ADDRESS` (the monitor), `BP_POST_URL` — `http://127.0.0.1/api/kiosk/bp-reading`
on the Pi-local fallback, `https://<domain>/api/kiosk/bp-reading` on the hosted
deploy — and `HEALTHPASS_KIOSK_KEY`, the **same value** as in that server's
`.env`. On the Pi-local shape you can copy the key straight from the app:

```bash
KEY=$(sudo grep '^HEALTHPASS_KIOSK_KEY=' /var/www/healthpass/.env | cut -d= -f2- | tr -d '"\r')
sudo sed -i "s|^HEALTHPASS_KIOSK_KEY=.*|HEALTHPASS_KIOSK_KEY=$KEY|" /etc/healthpass/bp-daemon.env
```

**3. Install and start the service.** It runs as the login user that paired
the monitor — `User=baldo` in the unit file; change that line first if yours
differs. **Stop any copy you started by hand in a terminal (Ctrl+C) first** —
two bridges would fight over the one monitor.

```bash
sudo cp scripts/pi/healthpass-bp.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now healthpass-bp    # enable = every boot, --now = start now
```

**4. Check it.**

```bash
systemctl status healthpass-bp       # expect "active (running)"
journalctl -u healthpass-bp -f       # live log; Ctrl+C stops watching, not the service
```

Take a measurement: the log shows `found …`, `connected …`, the reading's JSON
and **`posted to HealthPass: 201`**. `403` means the key doesn't match the
server's; `POST failed` means `BP_POST_URL` is wrong. Reboot once and check
`systemctl status healthpass-bp` again without touching anything.

**On the kiosk** the student taps **▶ Start** on the Blood Pressure step, then
presses START on the monitor. The kiosk shows a waiting animation until the
reading arrives, for up to 2 minutes (`healthpass.kiosk.bp_wait_seconds`).

**After a `git pull`** the service is still running the old script — restart it
(§6 includes the line).

---

## 5. Staff access over the campus LAN

The same Pi serves staff dashboards. No extra service — nginx/artisan already
listens on the Pi's network interface.

1. **Find the Pi's IP** on the campus network:

   ```bash
   hostname -I        # e.g. 192.168.100.50
   ```

2. **Reserve it.** Ask IT to set a DHCP reservation (or configure a static IP)
   so the address doesn't change — staff bookmarks and any signage depend on it.

3. **Staff browse to** `http://<pi-ip>/` (e.g. `http://192.168.100.50/`) and log
   in normally. They land on their role dashboard; they never touch the kiosk
   flow.

4. **Firewall:** Pi OS has no inbound firewall enabled by default, so LAN
   clients can reach port 80 out of the box. If you enable `ufw`, allow it:

   ```bash
   sudo ufw allow 80/tcp
   ```

> The Windows firewall rule you added yesterday
> (`New-NetFirewallRule ... -LocalPort 8080`) was for the **laptop** dev server.
> On the Pi it isn't needed unless you turn on `ufw`.

**Reachability check from another machine on the same Wi-Fi** (this is the Pi
equivalent of yesterday's `curl -I http://192.168.100.97:8080`):

```bash
curl -I http://<pi-ip>/            # expect HTTP/1.1 200 OK
```

If that works from a phone/laptop on the campus Wi-Fi, staff access is good.

---

## 6. Updating the deployment

```bash
cd /var/www/healthpass
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build
# Run artisan as www-data — storage/ and bootstrap/cache/ are owned by www-data,
# so running as your login user would write root/pi-owned cache/log files that
# php-fpm (www-data) then can't read. See docs/dev-notes.md → Pi deployment notes.
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo systemctl restart nginx php8.2-fpm     # or: healthpass-serve
sudo systemctl restart healthpass-bp        # the BP bridge (§4a) runs from this checkout
```

---

## 7. Web Serial permission persistence in unattended kiosk mode (FR-HW-05)

> **PARTIALLY RESOLVED — remaining items for Baldo after hardware testing.**

The problem: Chromium normally shows a **per-visit permission prompt** the first
time a page calls `navigator.serial.requestPort()`, and remembered grants are
tied to the browser profile / origin. On an unattended kiosk that reboots, we
need the ESP32 sensor bridge to connect **without a human tapping "Allow"**
every boot.

**Resolved: `--incognito` is gone from `kiosk-chromium.sh`.** Incognito wiped the
profile on every launch, which threw away *both* the Web Serial grant and the
D-27 device-token cookie — guaranteeing a manual tap at every boot. The launcher
now relies on the persistent `--user-data-dir` alone; the crash-restore bubble it
used to suppress is handled by `--disable-session-crashed-bubble` /
`--noerrdialogs` instead.

**Resolved by D-34: the origin is now `https://<domain>`, not `http://localhost`.**
Grants are per-origin, so the ESP32 must be re-authorized once on the new domain,
and any Chromium policy below must name the **https origin**.

Things for Baldo to test and document here once we have the hardware:

- [ ] Confirm the grant actually survives a reboot now that the profile persists
      (expected, but verify on the real Pi — this is the whole point).
- [ ] Chromium enterprise policy `SerialAllowAllPortsForUrls` /
      `SerialAllowUsbDevicesForUrls` — can we pre-authorize
      `https://<domain>` to the ESP32's USB VID/PID so there's **no prompt at
      all**, even on a fresh profile? (Policy file under
      `/etc/chromium/policies/managed/`.) Document the exact VID/PID and JSON
      here.
- [ ] **Serial port permissions:** confirm the kiosk user is in the `dialout`
      group (`sudo usermod -aG dialout $USER`, then log out/in) so Chromium can
      open `/dev/ttyUSB*` / `/dev/ttyACM*`. Without it the kiosk reports a
      generic serial `error` status with no obvious cause.
- [ ] Confirm the app still auto-reconnects to a previously granted port on load
      (the Web Serial module's reconnect path, FR-KSK-07) without user gesture,
      or note what gesture is unavoidable.
- [ ] Final decision + exact flags/policy, so the kiosk build is reproducible.

Fill this section with the tested, working configuration.

---

## 8. Post-deployment verification checklist (verified 2026-07-07)

> These checks confirm the Pi behaves the same behind nginx/php-fpm as it does
> under `php artisan serve`/SQLite tests. Run on the actual Pi deployment
> **2026-07-07** — all software-side checks passed. The only open items are the
> hardware-dependent ones (real sensor + Web Serial grant), deferred to the
> next joint day with Baldo.

- [x] **App answers on port 80** — `curl -I http://localhost/kiosk` → 200;
      nginx/php-fpm/MariaDB all active after boot.
- [x] **Compiled assets served** (no Vite dev) — kiosk renders fully styled
      from `public/build`.
- [x] **Database seeded** — demo staff login works on the web app.
- [x] **KioskAccess middleware behind nginx/php-fpm.** The gate now admits
      **device-enrolled OR active nurse OR config-allowed loopback** (D-27, §9);
      everyone else gets the branded restricted page. Re-run on the Pi (not just
      the test suite) — nginx passes the real `REMOTE_ADDR` to PHP via fastcgi:
  - [x] loopback (`http://localhost/kiosk`, `127.0.0.1`) → **200**
  - [x] LAN anonymous (`http://<pi-ip>/kiosk`) → **403** (branded page)
  - [x] nurse-authenticated over LAN → **200**
  - [x] `HEALTHPASS_KIOSK_RESTRICT=false` allows LAN → **200**
        (reverted to restricted + config re-cached; LAN anonymous is 403 again)
- [x] **Secure context / Web Serial.** `navigator.serial` is **defined** in
      Chromium DevTools at `http://localhost/kiosk`
      (secure-context check, FR-KSK-07 / FR-HW-05).
- [x] **Staff LAN access** — `http://<pi-ip>/` reachable and logs in from
      another device on the campus Wi-Fi.
- [x] **Full kiosk session, manual path** — QR/email login → 4 vital steps →
      questionnaire → submit; visit appears in the nurse queue and
      `vital_signs.entry_method = manual`.
- [x] **Reboot resilience** — after `sudo reboot`, services come back and
      `curl -I http://localhost/kiosk` → 200 with no manual intervention.
- [x] **Screen blanking off + kiosk idle reset** — screen stays on
      (raspi-config, Wayland) and the kiosk returns to Welcome after the 90s
      idle timeout.

### Deferred to next joint day with Baldo (hardware not on hand 2026-07-07)

- [ ] **Sensor end-to-end on the Pi** — one kiosk session with at least one
      vital filled by the real sensor; confirm `entry_method` records
      `sensor`/`mixed` accordingly. (Day 32 exit goal; Day 33 hardening runs
      simulator-driven in the meantime, per the Day 34 fallback.)
- [ ] **Web Serial permission persistence (§7)** — Baldo to test and document
      after hardware testing; see §7.
- [ ] **Re-confirm Day 33 adversarial cases on real hardware** — especially
      physical USB removal mid-BP-reading (real disconnect timing can differ
      from a simulated disconnect).

---

## 9. Kiosk device authorization & the hosted shape (D-27)

`KioskAccess` gates `/kiosk`. A request is admitted when **one** holds:

1. it carries a valid, un-revoked **device token** (a nurse enrolled this
   browser via *Enable Kiosk Mode → Kiosk Devices*),
2. it is an authenticated **active nurse**, or
3. it comes from **loopback** *and* `HEALTHPASS_KIOSK_ALLOW_LOOPBACK=true`.

Everyone else (a logged-in student/admin/director, or a guest) gets a friendly
branded 403 page — not a bare stub or a login redirect.

### Two ways to provision a device token

The token is a long random string; the server stores only its **SHA-256 hash**.
The browser presents the plaintext on every `/kiosk` request. Two paths:

- **Persistent-profile cookie (now the default path).** On the terminal, sign in
  as a nurse, open **Kiosk Devices**, name the device, and click **Enable Kiosk
  Mode on this device**. That drops a long-lived HttpOnly cookie on the browser.
  This survives reboots because the launcher keeps its profile — `--incognito`
  was **removed** from `kiosk-chromium.sh` (see §7), so the cookie and the Web
  Serial grant both persist.

- **`KIOSK_URL` query token (re-provisioning / wiped profile).** Bake the
  one-time provisioning URL shown after enrollment into the launcher's
  `KIOSK_URL`:

  ```bash
  KIOSK_URL="https://<domain>/kiosk?device_token=XXXXXXXX"
  ```

  On launch, `KioskAccess` validates the token, sets the cookie, and redirects
  to the clean `/kiosk` URL (the token never lingers in the address bar).

> On the **Pi-local fallback shape** (§10) `allow_loopback` is **true**, so
> `http://localhost/kiosk` needs no device token at all — enrollment is required
> on the hosted shape, where loopback trust is off.

### Hosted internet shape — turn loopback off + set trusted proxies

**This is the primary shape as of D-34** — full runbook in
`docs/deployment-hosted.md`. Chromium points at `https://<domain>/kiosk`
(HTTPS is a secure context, so Web Serial works; serial grants are per-origin).
The essentials:

- Set **`HEALTHPASS_KIOSK_ALLOW_LOOPBACK=false`**. Reason: behind a
  **misconfigured** reverse proxy, `$request->ip()` can report `127.0.0.1` for
  **every** internet visitor — which would otherwise open the kiosk to the whole
  world. With loopback off, the kiosk is reachable only by an enrolled device
  token or an authenticated nurse. **Never key this on `APP_ENV`** — the Pi is
  `APP_ENV=production` over plain `http://localhost` by design.

- Configure Laravel's **trusted proxies to the actual proxy IPs only — never
  `*`**. Set `TRUSTED_PROXIES` in `.env` (comma-separated); it is applied in
  `App\Providers\AppServiceProvider::boot()` via `App\Support\TrustedProxies`.
  A wildcard trusts any client's `X-Forwarded-For`, letting a visitor spoof their
  IP (e.g. claim `127.0.0.1`) — the app **refuses to boot** on `*`. Pin the real
  load-balancer/proxy addresses so client IPs resolve correctly.

  > Not configured in `bootstrap/app.php`: that closure runs before the config
  > files load, so `config()` is unavailable there and `env()` reads empty once
  > `config:cache` has run — the proxy list would silently be empty in production.

---

## 10. Offline fallback — running the whole app on the Pi

Before D-34 this was the plan of record; it is now the **backup** for defense day
if the venue's internet fails (`docs/deployment-hosted.md` §7).

Keep a working local install by following §§1–4 of this document. To switch the
Pi from hosted to local:

1. `KIOSK_URL="http://localhost/kiosk"` in the launcher (or its env), and point
   the Bluetooth BP bridge at `http://127.0.0.1/api/kiosk/bp-reading` (D-58):
   set `BP_POST_URL` in `/etc/healthpass/bp-daemon.env`, then
   `sudo systemctl restart healthpass-bp` (§4a).
2. In the Pi's `.env`: `HEALTHPASS_KIOSK_ALLOW_LOOPBACK=true`,
   `APP_URL=http://localhost`, `TRUSTED_PROXIES=` (empty), and the same
   `HEALTHPASS_KIOSK_KEY` the BP daemon sends.
3. `sudo -u www-data php artisan config:cache`
4. Restart the kiosk service / relaunch Chromium.

Two things to know before you rely on it:

- **The serial grant is per-origin**, so the ESP32 must be granted separately on
  `http://localhost` — do it once *before* defense day, not during the failure.
- **The local database is separate.** Restore a fresh dump from the hosted server
  or the fallback demos stale data.
- **No internet means no email, and that is fine — it is not lost (D-39).**
  Appointment emails (FR-STU-12) and registration OTPs are **queued jobs**, so
  the app writes them to the local `jobs` table and returns immediately.
  Bookings, batch approvals and the whole kiosk flow work normally with the
  venue's internet down; only the *delivery* stalls. What happens next depends
  on whether a worker is running on the Pi:
  - **No `queue:work` on the Pi (the usual fallback setup):** jobs simply pile
    up in `jobs` and nothing is sent. They are still there afterwards.
  - **A worker running with no route to the SMTP host:** each job fails its 3
    attempts (1 min, then 5 min apart) and lands in `failed_jobs`. Recover with
    `php artisan queue:retry all` once connectivity is back — do **not** re-approve
    the batch, which would create duplicate appointments.

  Either way the queue **drains on reconnect**, so students booked during an
  offline demo get their email late rather than never. Registration OTPs are the
  real casualty: a student cannot complete sign-up while mail is stalled, so use
  pre-seeded accounts for an offline demo rather than registering live.

**Rehearse this switch end to end at least once.** An untested fallback is not a
fallback.

---

## 11. Moving the kiosk between networks (classroom → clinic)

**Short version: the Pi only needs Wi-Fi.** On the hosted shape (D-34) the Pi is
a browser pointed at a public URL, so moving it to a different room or a
different Wi-Fi changes nothing about the app. Everything the kiosk depends on is
bound to the **origin** (`https://<domain>`), not to the network:

| Thing | Survives a network change? | Why |
|---|---|---|
| Kiosk device token (D-27) | ✅ | Cookie on the browser profile, keyed to the origin |
| Web Serial grant for the ESP32 | ✅ | Granted per origin, stored in the profile |
| Display rotation / touch mapping | ✅ | OS-level, per device |
| Database, accounts, queue | ✅ | They live on the server, not the Pi |
| **Internet access** | ❌ | The one thing you must re-establish |

Nothing on the student/staff side needs configuration at all — anyone can reach
`https://<domain>` from any device on any network.

### Before the defense (do this in the lab, not on the day)

1. **Pre-add every Wi-Fi network you might use.** NetworkManager stores multiple
   networks and joins whichever is in range, so add the classroom *and* the
   clinic SSIDs ahead of time:

   ```bash
   nmcli device wifi connect "<SSID>" password "<password>"
   nmcli connection show           # confirm both are saved
   ```

   Also add your **phone hotspot** as a third saved network — that is the
   fastest recovery if venue Wi-Fi fails.

2. **Ask campus IT about the two things that actually break kiosks:**
   - **Captive portal** (a "click here to accept" page). This is the single
     biggest risk: Chromium in `--kiosk` mode has no address bar or tabs, so
     nobody can accept the terms, and the kiosk just shows a redirect page.
     Ask IT to **whitelist the Pi's MAC address** so it bypasses the portal.
   - **MAC registration / device approval**, common on campus networks —
     register the Pi in advance on both the classroom and clinic networks.

   Get the Pi's MAC with `ip link show wlan0`.

3. **Enroll the device and grant the sensor once** (§9 and §7). Both persist
   across reboots and network changes, so this is genuinely a one-time step.

4. **Rehearse the offline fallback** (§10) at least once.

### On the day — classroom

1. Power on. Confirm the Pi joined the Wi-Fi: `nmcli connection show --active`.
2. Confirm it can actually reach the app — not just the network:
   ```bash
   curl -I https://<domain>/kiosk        # expect 200, not a portal redirect
   ```
   A `200` from a captive portal is still a portal page; if in doubt open the URL
   in a normal browser window before starting the kiosk.
3. Launch the kiosk (desktop shortcut or `systemctl --user start
   healthpass-kiosk`).
4. Run one full session with a sensor reading. No permission prompt should
   appear — if one does, the profile was wiped and you need to re-grant (§7) and
   re-enroll (§9).

### Moving to the clinic

1. Power off, move the Pi, power on.
2. It auto-joins the clinic Wi-Fi if you saved it in step 1 above.
3. Repeat the `curl` check and launch.

**That's it — no re-enrollment, no re-granting, no config edits**, because the
domain did not change.

> **The one thing that would force a redo:** changing the domain itself. A new
> origin invalidates both the device token cookie and the Web Serial grant. Pick
> the domain before you enroll anything, and don't change it after.

---

## Quick reference

| What | Command / path |
|------|----------------|
| Install deps | `sudo bash scripts/pi/install-deps.sh` |
| Clone + configure | `bash scripts/pi/setup-app.sh` |
| Pi env template | `scripts/pi/pi.env.example` |
| Kiosk launcher | `scripts/pi/kiosk-chromium.sh` |
| Dev-mode desktop shortcut | `scripts/pi/healthpass-kiosk.desktop` (§4 dev mode) |
| **Kiosk URL (primary, D-34)** | `https://<domain>/kiosk` — see `docs/deployment-hosted.md` |
| Kiosk URL (offline fallback) | `http://localhost/kiosk` (§10) |
| Display rotation (portrait) | `wlr-randr --output <name> --transform 90` (§4, Baldo to finalize) |
| Serial port access | `sudo usermod -aG dialout $USER`, then log out/in (§7) |
| Staff URL (LAN, fallback only) | `http://<pi-ip>/` |
| Enroll a kiosk device | Nurse nav → **Enable Kiosk Mode** → *Kiosk Devices* (§9) |
| Local health check | `curl -I http://localhost/kiosk` |
