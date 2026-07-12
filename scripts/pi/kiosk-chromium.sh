#!/usr/bin/env bash
# HealthPass — launch Chromium full-screen on the kiosk URL.
# Used by the systemd service or LXDE autostart. See docs/deployment-pi.md.
#
# Uses http://localhost so Web Serial (navigator.serial) has a secure context.
set -euo pipefail

# If the kiosk is NETWORK-restricted (HEALTHPASS_KIOSK_ALLOW_LOOPBACK=false, the
# hosted shape) this Pi must present a DEVICE TOKEN. Because --incognito wipes
# cookies each launch, bake a one-time provisioning token into the URL — enroll
# the device on the nurse "Kiosk Devices" page and copy its URL here, e.g.
#   KIOSK_URL="http://localhost/kiosk?device_token=XXXXXXXX"
# KioskAccess validates it, drops the cookie, and redirects to the clean URL.
# On the Pi-local defense shape (allow_loopback=true) the plain URL is enough.
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
# --incognito + fresh profile dir: no restore-tabs / "didn't shut down" bubble.
# --disable-* flags: suppress the crash-restore bar and info bars on the Pi.
# --password-store=basic: desktop autologin leaves the GNOME keyring locked, which
#   makes Chromium pop an "unlock keyring" dialog on launch (fatal on an unattended
#   kiosk). Force a plaintext store so no dialog appears. See docs/deployment-pi.md §4.
exec "$CHROME_BIN" \
  --kiosk \
  --incognito \
  --noerrdialogs \
  --disable-infobars \
  --disable-session-crashed-bubble \
  --disable-features=TranslateUI \
  --password-store=basic \
  --check-for-update-interval=31536000 \
  --user-data-dir="$HOME/.config/healthpass-kiosk" \
  "$KIOSK_URL"
