# Deploying HealthPass on the internet (hosted shape)

This is the **primary deployment** as of Decision **D-34**: one Laravel app on a
public server over HTTPS, serving the web app *and* the kiosk. The Raspberry Pi
stops being a server and becomes a **browser** — Chromium on the Pi points at
`https://<domain>/kiosk`.

> **Scope:** deployment/ops only — no application behaviour changes. Requirements,
> FR-IDs, and decisions come from `docs/HealthPass_PRD.md`.
>
> For the Pi terminal itself (display rotation, Chromium autostart, sensors) see
> `docs/deployment-pi.md`. The two documents are companions: **this** one is the
> server, **that** one is the terminal.

---

## 0. Why hosted, and what changes

The defense is a *demo of a real deployment*, not a demo of a laptop (D-34). One
public app means students, college admins, the nurse, and the Director all use
the same live system from their own devices, and the kiosk is a client of it like
everything else.

Two things make this safe for the kiosk, both non-obvious:

1. **HTTPS is a secure context**, so `navigator.serial` (Web Serial, FR-KSK-07 /
   FR-HW-05) works at `https://<domain>/kiosk` exactly as it did at
   `http://localhost`. Plain `http://<LAN-IP>` never worked and still doesn't.
2. **Serial permission grants are per-origin.** Moving from `http://localhost` to
   `https://<domain>` throws away every existing grant — Baldo must re-authorize
   the ESP32 once on the new origin. This is a one-time step, not a regression.

| | Pi-local (old primary, now fallback) | Hosted (D-34, primary) |
|---|---|---|
| App runs on | the Pi | public server |
| Kiosk URL | `http://localhost/kiosk` | `https://<domain>/kiosk` |
| Secure context via | `localhost` exemption | TLS |
| Kiosk gate | loopback trust | **enrolled device token** |
| `HEALTHPASS_KIOSK_ALLOW_LOOPBACK` | `true` | **`false`** |
| `TRUSTED_PROXIES` | empty | **the proxy's IP** |
| Needs venue internet | no | **yes** — see §7 |

---

## 1. Server requirements

- PHP **8.2+** with the Laravel 12 extensions (mbstring, xml, curl, zip, bcmath,
  intl, pdo_mysql, gd), Composer
- MySQL/MariaDB
- nginx (or Apache) as the TLS terminator / reverse proxy
- Node.js 20 LTS **for the build only** — you can build assets elsewhere and
  upload `public/build` instead of installing Node on the server
- A domain name pointed at the server, and a TLS certificate (Let's Encrypt)

---

## 2. First deploy

```bash
sudo mkdir -p /var/www/healthpass && cd /var/www/healthpass
git clone https://github.com/Nat-G1t/Healthpass.git .

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp scripts/hosted.env.example .env      # NOT .env.example — see the warning in it
php artisan key:generate
```

Create the database and user, then fill in `.env` (§3). Then:

```bash
php artisan migrate --force
php artisan db:seed --force             # colleges + staff accounts (see §5)
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> `db:seed` needs `fakerphp/faker`, a dev package. If you installed with
> `--no-dev`, run `composer install` (with dev) → seed → `composer install
> --no-dev --optimize-autoloader` again, same as the Pi guide does.

---

## 3. Environment — the settings that actually matter

Start from `scripts/hosted.env.example`. **Never copy the root `.env.example`
onto a public box** — it ships `APP_DEBUG=true`.

| Key | Value | Why it bites if wrong |
|---|---|---|
| `APP_DEBUG` | `false` | A stack trace on any 500 leaks env values, queries, and paths to the visitor |
| `APP_URL` | `https://<domain>` | Verification and OTP links are generated from it |
| `SESSION_SECURE_COOKIE` | `true` | Otherwise the session cookie can travel in clear and be stolen |
| `HEALTHPASS_KIOSK_ALLOW_LOOPBACK` | `false` | A same-host proxy makes every visitor look like `127.0.0.1`; `true` here would open `/kiosk/scan` to the internet as a PII oracle |
| `TRUSTED_PROXIES` | the proxy IP(s) | See below — the single easiest thing to get wrong |
| `LOG_LEVEL` | `warning` | `debug` logs can capture request payloads (PII) |

### Trusted proxies (D-34)

Behind nginx, PHP sees every request coming from the **proxy**, not the visitor.
The real IP and the original `https` scheme arrive in `X-Forwarded-For` /
`X-Forwarded-Proto`, and Laravel ignores those headers unless it knows which
proxies to trust.

Get it wrong and two things break quietly:

- **Every per-IP throttle collapses into one bucket** keyed to the proxy — one
  student hitting the OTP limit locks out the whole campus.
- **Laravel builds `http://` links on an `https://` site** — email verification
  and password-reset OTP links break or redirect-loop.

```dotenv
TRUSTED_PROXIES=127.0.0.1        # single-box nginx → php-fpm
```

**Never `*`.** A wildcard trusts any client's `X-Forwarded-For`, letting a
visitor claim to be `127.0.0.1`. `App\Support\TrustedProxies` **refuses to boot**
on `*` rather than let a spoofable app go live. Covered by
`tests/Feature/Deployment/TrustedProxiesTest.php`.

> After any `.env` edit, re-run `php artisan config:cache` — a cached config
> ignores later `.env` changes.

### SMTP + the queue worker (D-39) — read this or the emails never send

HealthPass sends real email in two places: registration / password OTPs (D-8)
and the appointment scheduling notice (FR-STU-12). Both need **two** things
configured, and the second one has no error message when you forget it.

**1. Real SMTP credentials.** `MAIL_MAILER=log` is the dev default and writes
messages to the log instead of sending them. On the hosted box:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=<provider smtp host>
MAIL_PORT=587
MAIL_USERNAME=<provider username>
MAIL_PASSWORD=<provider password>       # never commit this
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS="noreply@<domain>"    # must be an address the provider may send as
MAIL_FROM_NAME="HealthPass"

HEALTHPASS_CLINIC_LOCATION="University Clinic, <building/room>"
```

`MAIL_FROM_ADDRESS` has to be an address the provider is authorised to send as
(SPF/DKIM on the real domain). Get it wrong and mail is accepted, then silently
spam-filed — which looks identical to working, until a student says they never
got anything.

**2. A SUPERVISED `queue:work` PROCESS. THIS IS THE ONE THAT BITES.**

`QUEUE_CONNECTION=database`, and every appointment email is a **queued job**
(D-39). Dispatching a job only writes a row to the `jobs` table. **If no worker
is running, that row sits there forever. The Director sees "batch approved — 60
appointment(s) created", every appointment is real and correct, and not one of
the 60 students is ever emailed. Nothing errors. Nothing is logged. The only
symptom is a `jobs` table that grows and never drains.**

So the worker is not optional infrastructure — it is part of the feature.

```ini
# /etc/supervisor/conf.d/healthpass-worker.conf
[program:healthpass-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/healthpass/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/healthpass/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start healthpass-worker:*
sudo supervisorctl status                      # must show RUNNING
```

Notes:

- **Keep `numprocs=1`.** One worker consumes jobs serially, which is also the
  app's send-rate ceiling — that is deliberately the only throttle in the system
  (D-39: no rate-limiting package was added). If the mail provider imposes a
  per-minute cap, one worker is what keeps you under it. Raising `numprocs`
  raises the send rate and can trip the provider's limit.
- **Restart the worker on every deploy.** A worker holds the old code in memory;
  `php artisan queue:restart` in the deploy script (§8) tells it to exit so
  supervisor starts a fresh one.
- A job that fails all 3 attempts lands in `failed_jobs` and logs an
  `Appointment email failed after all retries` line carrying the appointment
  reference and student id. Inspect with `php artisan queue:failed`, retry with
  `php artisan queue:retry all`.

```bash
php artisan queue:failed          # what did not go out
php artisan queue:monitor default # depth — a number that only grows means no worker
```

---

## 4. nginx + TLS

```nginx
server {
    listen 443 ssl http2;
    server_name healthpass.example.edu.ph;
    root /var/www/healthpass/public;

    ssl_certificate     /etc/letsencrypt/live/<domain>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<domain>/privkey.pem;

    index index.php;
    charset utf-8;
    client_max_body_size 8M;          # QR photo uploads at registration Step 4

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        # These are what TRUSTED_PROXIES lets Laravel read. Without them the app
        # cannot see the real client IP or know the request arrived over TLS.
        fastcgi_param HTTP_X_FORWARDED_FOR   $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name healthpass.example.edu.ph;
    return 301 https://$host$request_uri;    # force HTTPS at the edge
}
```

Certificates: `sudo certbot --nginx -d healthpass.example.edu.ph`.

> HTTPS is forced **here, at the proxy** — not in application code. Never key
> HTTPS-forcing on `APP_ENV`: the Pi fallback (§7) is `APP_ENV=production` over
> plain `http://localhost` by design.

---

## 5. Staff accounts

Only students self-register (FR-AUTH-05). `nurse`, `college_admin`, and
`director` accounts come from `database/seeders/StaffSeeder.php` — 1 director,
1 nurse, and 1 admin per college (12).

On this shape the seeder must issue **one-time passwords** (D-35). Set both keys
in `.env` **before** seeding:

```dotenv
HEALTHPASS_SEED_STAFF_ONE_TIME=true
HEALTHPASS_STAFF_EMAIL_DOMAIN=dhvsu.edu.ph
```

Then `php artisan db:seed --force` prints a table of 14 accounts with a distinct
random password each:

```
 Name               Email                        One-time password
 Clinic Director    director@dhvsu.edu.ph        k7Qv2mXp9RtLb4Ns
 ...
```

**Copy that table immediately — the passwords are stored nowhere in plaintext**
and cannot be recovered. Hand each person their own; on first login
`RequirePasswordChange` sends them to the OTP-confirmed change-password screen
and lets nothing else through until they set their own.

> The generated addresses follow the seeder's pattern (`director@`, `nurse@`,
> `admin.<code>@`). **Confirm the real addresses** with the clinic and each
> college and edit `StaffSeeder` before seeding production — the OTP mail has to
> reach a mailbox the person actually reads.

Leave `HEALTHPASS_SEED_STAFF_ONE_TIME` unset locally: dev keeps the familiar
shared `password` accounts from `docs/dev-notes.md`.

### 5.1 After go-live — adding staff needs no server access (D-47)

The seeder above is the **bootstrap**, run once. From then on the **Director**
manages the staff roster from inside the app at **`/director/staff`** ("Staff
Accounts" in their sidebar), so **adding a College Admin no longer requires SSH
or a database client** (FR-AUTH-10):

| Action | What it does |
|---|---|
| Create account | `college_admin` (with a college) or `nurse` (without one). Issues a one-time password on the same D-35 terms as the seeder. |
| Deactivate / Reactivate | An inactive account cannot log in **and is signed out of any session it already has, on its very next request** (FR-AUTH-07). **This is the only removal there is** — accounts are never deleted, so a departed nurse's encoded clearances still render. |
| Change college | Moves a College Admin to another unit without creating a second account. |

The generated password is displayed **once**, in a panel on that page. It is not
emailed and is stored nowhere — same rule as the seeder's table. Hand it over
through official channels.

**There is no "reset password" action, deliberately (D-47a).** The Director sets
an account's password exactly once, when creating it. A Director who could reset
an existing account's password could sign in as that person and read their
records, which is the impersonation D-47 rules out. A staff member who forgets
their password uses **Forgot password** on the login page (FR-AUTH-09) — an OTP
to their own mailbox, which nobody else can complete. That makes each staff
address a real dependency: confirm them at handover, not at go-live.

Two things the Director **cannot** do there, both enforced server-side and both
covered by tests in `tests/Feature/Director/StaffAccountTest.php`:

- **Create another Director, or a student.** Posting `role=director` with
  devtools is a validation failure, not an account. A second Director account
  therefore still means running the seeder (or a one-off tinker) on the server —
  deliberately, so the role cannot self-propagate.
- **Deactivate or re-credential their own account.** The only super-admin cannot
  lock themselves out of the deployment.

**Operational consequence:** the Director's own password is the one credential
that still has no in-app recovery path beyond the ordinary forgot-password OTP
(D-20), which needs their mailbox to be reachable. Confirm that address works
**before** the defense.

> **Staff emails follow `APP_URL` (D-50).** Creating an account emails the new
> staff member a sign-in link, and moving a College Admin emails them the
> change. Both links are generated from `route('login')`, so setting `APP_URL`
> to the real domain in §3 is the only thing needed — there is no separate URL
> to edit. Both are queued, so they need the §3 worker running; and both need
> real SMTP, or the notice is written to a log nobody reads. The welcome email
> deliberately carries **no password**.
>
> **Revoking access is immediate.** `App\Http\Middleware\EnsureAccountIsActive`
> re-reads `users.status` on every web request, so Deactivate cuts an open
> session on its next request rather than waiting for it to expire. If someone
> leaves or a laptop goes missing, Deactivate is the whole procedure — there is
> no session table to clear by hand.

---

## 6. Kiosk enrollment for the Pi

With loopback trust off, the Pi's Chromium needs a **device token** (D-27):

1. On the Pi, browse to `https://<domain>` and sign in as the nurse.
2. Nurse nav → **Enable Kiosk Mode** → *Kiosk Devices* → name the device
   ("Clinic Kiosk Pi") → **Enable Kiosk Mode on this device**.
3. That sets a long-lived HttpOnly cookie on that browser profile. The launcher
   (`scripts/pi/kiosk-chromium.sh`) uses a **persistent** `--user-data-dir` and
   deliberately does **not** pass `--incognito`, so the cookie — and the Web
   Serial grant — survive reboots.
4. Set the launcher URL: `KIOSK_URL="https://<domain>/kiosk"`.
5. If the profile is ever wiped, re-provision with the one-time URL shown at
   enrollment: `https://<domain>/kiosk?device_token=…`.

Then have Baldo re-grant the ESP32's serial port once on the new origin (§0).

---

## 7. Defense-day risk: the venue's internet

The hosted shape puts the demo on the venue's network. Mitigations, in order:

1. **Phone hotspot as the Pi's backup uplink**, tested beforehand.
2. **Keep the Pi-local install working** as the documented fallback: the Pi can
   still run the whole app locally (`docs/deployment-pi.md`). Switching back is
   two changes — `KIOSK_URL="http://localhost/kiosk"` and
   `HEALTHPASS_KIOSK_ALLOW_LOOPBACK=true` (then `config:cache`). Rehearse it.
3. **Manual-entry drill** (D-7) still covers sensor failure independently.

Sync the fallback's database from a fresh dump before defense day, or it will
demo stale data.

---

## 8. Updating a live deployment

```bash
cd /var/www/healthpass
php artisan down                      # maintenance page
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo systemctl reload php8.2-fpm
sudo -u www-data php artisan queue:restart   # workers hold old code in memory (D-39)
php artisan up
```

`queue:restart` asks the running worker to exit cleanly after its current job;
supervisor then starts a fresh one on the new code. Skip it and appointment
emails keep rendering from the previous deploy's views until the next reboot.

Back up the database before any deploy that carries a migration:

```bash
mysqldump -u healthpass -p healthpass > ~/healthpass-$(date +%F).sql
```

---

## 9. Go-live checklist

- [ ] `TRUSTED_PROXIES` set to the real proxy IP; `curl` from another network and
      confirm the access log shows **your** IP, not `127.0.0.1`
- [ ] `APP_DEBUG=false` — force a 404 and confirm no stack trace
- [ ] HTTPS forced; `http://` redirects to `https://`
- [ ] `SESSION_SECURE_COOKIE=true` — cookie shows `Secure` in DevTools
- [ ] `HEALTHPASS_KIOSK_ALLOW_LOOPBACK=false` — `/kiosk` from a plain browser
      returns the branded 403
- [ ] Kiosk reachable from the enrolled Pi only
- [ ] `navigator.serial` defined at `https://<domain>/kiosk` on the Pi
- [ ] ESP32 grant re-issued on the new origin; one full sensor-fed kiosk session
      lands in the nurse queue with `entry_method` = `sensor`/`mixed`
- [ ] Real staff credentials in place (§5, D-35) — **no `@healthpass.test`
      accounts reachable**, and logging in as a seeded account lands on the
      change-password screen and refuses to go anywhere else
- [ ] Registration OTP email actually arrives via real SMTP
- [ ] **`supervisorctl status` shows `healthpass-worker` RUNNING** — without it
      every appointment email queues silently and never sends (§3, D-39)
- [ ] Book one test appointment and approve one small test batch; confirm the
      emails actually **arrive** (not just that `jobs` emptied), and that the
      body shows the right date, hour slot and reference number
- [ ] `HEALTHPASS_CLINIC_LOCATION` set to the real room/building — it is printed
      in every appointment email
- [ ] `php artisan queue:failed` is empty
- [ ] Database backup taken and restore tested

---

## Quick reference

| What | Command / path |
|---|---|
| Env template | `scripts/hosted.env.example` |
| Queue worker status | `sudo supervisorctl status healthpass-worker:*` |
| Undelivered mail | `php artisan queue:failed` |
| Queue depth (grows = no worker) | `php artisan queue:monitor default` |
| Trusted-proxy parser | `app/Support/TrustedProxies.php` |
| Kiosk URL | `https://<domain>/kiosk` |
| Enroll the Pi | Nurse nav → **Enable Kiosk Mode** → *Kiosk Devices* |
| Pi terminal setup | `docs/deployment-pi.md` |
| Offline fallback | `docs/deployment-pi.md` + `KIOSK_URL=http://localhost/kiosk` |
