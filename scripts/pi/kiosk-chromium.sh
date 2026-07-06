#!/usr/bin/env bash
# HealthPass — launch Chromium full-screen on the kiosk URL.
# Used by the systemd service or LXDE autostart. See docs/deployment-pi.md.
#
# Uses http://localhost so Web Serial (navigator.serial) has a secure context.
set -euo pipefail

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
exec "$CHROME_BIN" \
  --kiosk \
  --incognito \
  --noerrdialogs \
  --disable-infobars \
  --disable-session-crashed-bubble \
  --disable-features=TranslateUI \
  --check-for-update-interval=31536000 \
  --user-data-dir="$HOME/.config/healthpass-kiosk" \
  "$KIOSK_URL"
