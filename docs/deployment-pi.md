# Deploying HealthPass on a Raspberry Pi (kiosk terminal)

This guide sets up the **self-service vitals kiosk** on a Raspberry Pi 4 running
Raspberry Pi OS. The Pi runs the *whole* unified Laravel app locally and opens
Chromium full-screen on the kiosk page. Staff (nurses, admins) on the same
campus LAN can reach the same app through the Pi's IP address.

> **Scope:** deployment/ops only — no application code changes. Reference
> numbers, FR-IDs, and architecture come from `docs/HealthPass_PRD.md` and
> `CLAUDE.md`.

---

## 0. Why the Pi runs the app locally (important)

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
```

---

## 7. Web Serial permission persistence in unattended kiosk mode (FR-HW-05)

> **PLACEHOLDER — for Baldo to complete after hardware testing.**

The problem: Chromium normally shows a **per-visit permission prompt** the first
time a page calls `navigator.serial.requestPort()`, and remembered grants are
tied to the browser profile / origin. On an unattended kiosk that reboots, we
need the serial device (BP monitor / sensor bridge) to connect **without a human
tapping "Allow"** every boot.

Things for Baldo to test and document here once we have the hardware:

- [ ] Does the grant survive a reboot with a **persistent profile**
      (`--user-data-dir`, as set in `kiosk-chromium.sh`)? Or does `--incognito`
      wipe it — do we need to drop `--incognito` for the sensor build?
- [ ] Chromium enterprise policy `SerialAllowAllPortsForUrls` /
      `SerialAllowUsbDevicesForUrls` — can we pre-authorize `http://localhost`
      to a specific USB VID/PID so there's **no prompt at all**? (Policy file
      under `/etc/chromium/policies/managed/`.) Document the exact VID/PID and
      JSON here.
- [ ] Confirm the app still auto-reconnects to a previously granted port on load
      (the Web Serial module's reconnect path, FR-KSK-07) without user gesture,
      or note what gesture is unavoidable.
- [ ] Final decision + exact flags/policy, so the kiosk build is reproducible.

Fill this section with the tested, working configuration.

---

## 8. Post-deployment verification checklist (PENDING — not yet done)

> These checks confirm the Pi behaves the same behind nginx/php-fpm as it does
> under `php artisan serve`/SQLite tests. **They have NOT been run yet** — do not
> assume they pass. Tick them off against the actual Pi deployment.

- [ ] **KioskAccess middleware behind nginx/php-fpm.** Re-run all four cases on
      the Pi (not just the test suite) — this depends on nginx passing the real
      `REMOTE_ADDR` to PHP via fastcgi:
  - [ ] loopback (`http://localhost/kiosk`, `127.0.0.1`) → **200**
  - [ ] LAN anonymous (`http://<pi-ip>/kiosk`) → **403**
  - [ ] nurse-authenticated over LAN → **200**
  - [ ] `HEALTHPASS_KIOSK_RESTRICT=false` allows LAN → **200**
- [ ] **Secure context / Web Serial.** In Chromium DevTools console at
      `http://localhost/kiosk`, confirm `navigator.serial` is **defined**
      (secure-context check, FR-KSK-07 / FR-HW-05).
- [ ] **Web Serial permission persistence (§7).** Placeholder for Baldo after
      hardware testing (Days 31–32) — see §7.

---

## Quick reference

| What | Command / path |
|------|----------------|
| Install deps | `sudo bash scripts/pi/install-deps.sh` |
| Clone + configure | `bash scripts/pi/setup-app.sh` |
| Pi env template | `scripts/pi/pi.env.example` |
| Kiosk launcher | `scripts/pi/kiosk-chromium.sh` |
| Dev-mode desktop shortcut | `scripts/pi/healthpass-kiosk.desktop` (§4 dev mode) |
| Kiosk URL (on Pi) | `http://localhost/kiosk` |
| Staff URL (LAN) | `http://<pi-ip>/` |
| Local health check | `curl -I http://localhost/kiosk` |
