#!/usr/bin/env bash
# HealthPass — launch Chromium full-screen on the kiosk URL.
# Used by the systemd service or LXDE autostart. See docs/deployment-pi.md.
#
# Uses http://localhost so Web Serial (navigator.serial) has a secure context.
set -euo pipefail

# D-34: the defense runs against the HOSTED site, so this points at the public
# HTTPS origin, e.g. KIOSK_URL="https://healthpass.example.edu.ph/kiosk".
# The Pi-local URL below stays the documented offline fallback (see §10).
#
# The hosted shape sets HEALTHPASS_KIOSK_ALLOW_LOOPBACK=false, so this Pi must
# present a DEVICE TOKEN. Enroll it once on the nurse "Kiosk Devices" page: the
# persistent profile below keeps the resulting cookie across reboots, so the
# plain URL is enough after enrollment. If the profile is ever wiped, re-provision
# by pasting the one-time URL here:
#   KIOSK_URL="https://healthpass.example.edu.ph/kiosk?device_token=XXXXXXXX"
# KioskAccess validates it, drops the cookie, and redirects to the clean URL.
KIOSK_URL="${KIOSK_URL:-http://localhost/kiosk}"

# Chromium is 'chromium-browser' on Pi OS, 'chromium' on some builds.
CHROME_BIN="$(command -v chromium-browser || command -v chromium)"

# Keep the screen awake (no blanking / DPMS) for an always-on terminal.
if command -v xset >/dev/null 2>&1; then
  xset s off || true
  xset -dpms || true
  xset s noblank || true
fi

# --kiosk: borderless full screen, no exit chrome.
# NO --incognito (deliberate): incognito wipes the profile every launch, which
#   would throw away BOTH the Web Serial port grant (FR-HW-05 — the ESP32 would
#   need a manual "Allow" tap at every boot) and the kiosk device-token cookie
#   (D-27/D-34). The persistent --user-data-dir below is what makes unattended
#   restart work. The crash-restore bubble is suppressed by the flags below
#   instead.
# --disable-* flags: suppress the crash-restore bar and info bars on the Pi.
# --password-store=basic: desktop autologin leaves the GNOME keyring locked, which
#   makes Chromium pop an "unlock keyring" dialog on launch (fatal on an unattended
#   kiosk). Force a plaintext store so no dialog appears. See docs/deployment-pi.md §4.
exec "$CHROME_BIN" \
  --kiosk \
  --noerrdialogs \
  --disable-infobars \
  --disable-session-crashed-bubble \
  --disable-features=TranslateUI \
  --password-store=basic \
  --check-for-update-interval=31536000 \
  --user-data-dir="$HOME/.config/healthpass-kiosk" \
  "$KIOSK_URL"
