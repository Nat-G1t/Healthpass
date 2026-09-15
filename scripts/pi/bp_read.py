#!/usr/bin/env python3
"""
bp_read.py - HealthPass BLE bridge for the A&D UA-651BLE blood pressure monitor.

Runs on the Raspberry Pi 4. Waits for the monitor to advertise, connects,
syncs its clock, subscribes to the standard Blood Pressure Measurement
indication, decodes it, and hands the reading to HealthPass.

Lives in the repo at scripts/pi/bp_read.py and starts at boot as the
healthpass-bp systemd service (D-59) - see docs/deployment-pi.md section 4a.

The UA-651BLE is Continua-certified, so it speaks the plain Bluetooth SIG
Blood Pressure Profile. Nothing here is reverse-engineered:

    Service 0x1810  Blood Pressure
      Char  0x2A35  Blood Pressure Measurement   (INDICATE only - not readable)
      Char  0x2A49  Blood Pressure Feature       (read)
    Service 0x1805  Current Time
      Char  0x2A2B  Current Time                 (write - sets the device clock)

Usage:
    # one-shot: wait for a reading, print JSON, exit
    python3 bp_read.py --address AA:BB:CC:DD:EE:FF --once

    # first run after pairing: swallow the 30 stored readings without posting
    python3 bp_read.py --address AA:BB:CC:DD:EE:FF --flush

    # daemon: keep listening forever, POST each new reading to HealthPass.
    # The key comes from the HEALTHPASS_KIOSK_KEY environment variable (or
    # --device-key). Without --post-url a reading is only printed, never sent.
    HEALTHPASS_KIOSK_KEY=... python3 bp_read.py --address AA:BB:CC:DD:EE:FF \
        --post-url http://127.0.0.1/api/kiosk/bp-reading

Requires: python3-bleak (pip install bleak), and requests if you use --post-url.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import os
import struct
import sys
from datetime import datetime, timedelta
from pathlib import Path

from bleak import BleakClient, BleakScanner

BP_SERVICE = "00001810-0000-1000-8000-00805f9b34fb"
BP_MEASUREMENT = "00002a35-0000-1000-8000-00805f9b34fb"
CURRENT_TIME = "00002a2b-0000-1000-8000-00805f9b34fb"

NAME_PREFIX = "A&D_UA-651BLE"
SEEN_FILE = Path.home() / ".healthpass_bp_seen.json"

# How long to keep listening after the last indication before assuming the
# monitor is done sending. Stored readings arrive back to back, so a short
# silence already means it has finished. Was 4 s; every second here is a
# second the student stands waiting at the kiosk (D-59).
QUIET_SECONDS = 1.5

log = logging.getLogger("bp")


# --------------------------------------------------------------------------
# IEEE-11073 16-bit SFLOAT, which is how the BLE spec encodes the pressures
# --------------------------------------------------------------------------

_SFLOAT_SPECIALS = {0x07FF, 0x0800, 0x07FE, 0x0802}  # NaN, NRes, +INF, -INF


def parse_sfloat(raw: int) -> float | None:
    mantissa = raw & 0x0FFF
    exponent = (raw >> 12) & 0x0F

    if mantissa in _SFLOAT_SPECIALS:
        return None
    if exponent >= 0x08:
        exponent -= 0x10
    if mantissa >= 0x0800:
        mantissa -= 0x1000

    return mantissa * (10.0**exponent)


def _u16(data: bytes, i: int) -> int:
    return int.from_bytes(data[i : i + 2], "little")


# --------------------------------------------------------------------------
# Blood Pressure Measurement characteristic (0x2A35)
# --------------------------------------------------------------------------


def parse_measurement(data: bytes) -> dict:
    """Decode one 0x2A35 indication into a plain dict."""
    if len(data) < 7:
        raise ValueError(f"measurement too short: {data.hex()}")

    flags = data[0]
    i = 1

    unit = "kPa" if flags & 0x01 else "mmHg"
    systolic = parse_sfloat(_u16(data, i)); i += 2
    diastolic = parse_sfloat(_u16(data, i)); i += 2
    mean_arterial = parse_sfloat(_u16(data, i)); i += 2

    taken_at = None
    if flags & 0x02:  # timestamp present
        year = _u16(data, i)
        month, day, hour, minute, second = data[i + 2 : i + 7]
        i += 7
        if year and month and day:
            try:
                taken_at = datetime(year, month, day, hour, minute, second)
            except ValueError:
                taken_at = None

    pulse = None
    if flags & 0x04:
        pulse = parse_sfloat(_u16(data, i)); i += 2

    user_id = None
    if flags & 0x08:
        user_id = data[i]; i += 1

    status = None
    if flags & 0x10:
        status = _u16(data, i); i += 2

    reading = {
        "systolic": round(systolic) if systolic is not None else None,
        "diastolic": round(diastolic) if diastolic is not None else None,
        "mean_arterial": round(mean_arterial) if mean_arterial is not None else None,
        "pulse": round(pulse) if pulse is not None else None,
        "unit": unit,
        "taken_at": taken_at.isoformat() if taken_at else None,
        "user_id": user_id,
        "raw": data.hex(),
        "entry_method": "device_ble",
        "device_model": "A&D UA-651BLE",
    }

    # Measurement Status bits - these are why a reading might be junk.
    if status is not None:
        reading["flags"] = {
            "body_movement": bool(status & 0x0001),
            "cuff_too_loose": bool(status & 0x0002),
            "irregular_pulse": bool(status & 0x0004),  # the IHB/AFib icon
            "pulse_out_of_range": bool(status & 0x0018),
            "improper_position": bool(status & 0x0020),
        }
        reading["suspect"] = any(
            reading["flags"][k]
            for k in ("body_movement", "cuff_too_loose", "improper_position")
        )
    else:
        reading["flags"] = {}
        reading["suspect"] = False

    return reading


def is_plausible(reading: dict) -> bool:
    """Sanity check against the UA-651BLE's own published measurement range."""
    sys_, dia, pul = reading["systolic"], reading["diastolic"], reading["pulse"]
    if sys_ is None or dia is None:
        return False
    if not (60 <= sys_ <= 279 and 40 <= dia <= 200):
        return False
    if sys_ <= dia:
        return False
    if pul is not None and not (40 <= pul <= 180):
        return False
    return True


# --------------------------------------------------------------------------
# Dedupe - the monitor replays up to 30 stored readings on every connect
# --------------------------------------------------------------------------


def load_seen() -> set[str]:
    try:
        return set(json.loads(SEEN_FILE.read_text()))
    except (OSError, ValueError):
        return set()


def save_seen(seen: set[str]) -> None:
    try:
        # keep the file from growing forever
        SEEN_FILE.write_text(json.dumps(sorted(seen)[-200:]))
    except OSError as exc:
        log.warning("could not write %s: %s", SEEN_FILE, exc)


def fingerprint(reading: dict) -> str:
    return "|".join(
        str(reading.get(k))
        for k in ("taken_at", "systolic", "diastolic", "pulse")
    )


# --------------------------------------------------------------------------
# Talking to the device
# --------------------------------------------------------------------------


async def sync_clock(client: BleakClient) -> None:
    """Write Current Time (0x2A2B) so the monitor timestamps readings correctly.

    The UA-651BLE has a clock but no way to set it from the buttons - it
    expects the receiver to set it. Without this, every timestamp is garbage.
    """
    now = datetime.now()
    payload = struct.pack(
        "<HBBBBBBBB",
        now.year,
        now.month,
        now.day,
        now.hour,
        now.minute,
        now.second,
        now.isoweekday(),  # 1 = Monday .. 7 = Sunday
        0,  # fractions256
        0,  # adjust reason
    )
    try:
        await client.write_gatt_char(CURRENT_TIME, payload, response=True)
        log.info("clock synced to %s", now.isoformat(timespec="seconds"))
    except Exception as exc:  # noqa: BLE001 - never let this kill a reading
        log.warning("clock sync failed (continuing anyway): %s", exc)


async def collect_from_device(device, on_readings) -> list[dict]:
    """Connect once, drain whatever the monitor wants to send, disconnect.

    `device` is the BLEDevice the scanner just found, NOT its address string:
    handed a string, bleak scans for the monitor all over again before it
    connects, which cost several seconds per reading (D-59).

    `on_readings` gets the readings as soon as the monitor goes quiet, BEFORE
    the disconnect - tearing the link down takes another couple of seconds,
    and the student is waiting at the kiosk.
    """
    readings: list[dict] = []
    last_packet = asyncio.get_running_loop().time()
    disconnected = asyncio.Event()

    def on_disconnect(_client: BleakClient) -> None:
        log.info("monitor closed the connection")
        disconnected.set()

    def on_indication(_char, data: bytearray) -> None:
        nonlocal last_packet
        last_packet = asyncio.get_running_loop().time()
        log.debug("indication: %s", bytes(data).hex())
        try:
            readings.append(parse_measurement(bytes(data)))
        except Exception as exc:  # noqa: BLE001
            log.error("could not decode %s: %s", bytes(data).hex(), exc)

    async with BleakClient(device, disconnected_callback=on_disconnect) as client:
        log.info("connected to %s", device.address)
        await sync_clock(client)
        await client.start_notify(BP_MEASUREMENT, on_indication)
        log.info("subscribed to 0x2A35, waiting for data")

        loop = asyncio.get_running_loop()
        while not disconnected.is_set():
            await asyncio.sleep(0.25)
            if readings and loop.time() - last_packet > QUIET_SECONDS:
                log.info("no more data for %.1fs, done", QUIET_SECONDS)
                break
            if not readings and loop.time() - last_packet > 30:
                log.warning("connected but nothing sent in 30s, giving up")
                break

        if readings:
            on_readings(readings)

        # No stop_notify(): disconnecting ends the subscription anyway, and
        # skipping it saves one more round trip to the monitor.

    return readings


async def find_device(address: str | None, timeout: float):
    """Wait for the monitor to advertise. Returns a BLEDevice or None."""
    if address:
        return await BleakScanner.find_device_by_address(address, timeout=timeout)

    def matcher(device, adv) -> bool:
        name = adv.local_name or device.name or ""
        return name.startswith(NAME_PREFIX)

    return await BleakScanner.find_device_by_filter(matcher, timeout=timeout)


# --------------------------------------------------------------------------
# Handing the reading to HealthPass
# --------------------------------------------------------------------------


def post_reading(url: str, device_key: str | None, reading: dict) -> bool:
    import requests  # imported lazily so --once works without it

    headers = {"Accept": "application/json"}
    if device_key:
        headers["X-Kiosk-Key"] = device_key
    try:
        response = requests.post(url, json=reading, headers=headers, timeout=10)
        response.raise_for_status()
        log.info("posted to HealthPass: %s", response.status_code)
        return True
    except Exception as exc:  # noqa: BLE001
        log.error("POST failed: %s", exc)
        return False


def handle(reading: dict, args, seen: set[str]) -> None:
    fp = fingerprint(reading)

    if fp in seen:
        log.info("already seen, skipping: %s", fp)
        return
    seen.add(fp)

    if not is_plausible(reading):
        log.warning("implausible reading, dropping: %s", reading)
        return

    # Old stored readings replayed from memory - not this patient, right now.
    if args.max_age_minutes and reading["taken_at"]:
        taken = datetime.fromisoformat(reading["taken_at"])
        age = datetime.now() - taken
        if age > timedelta(minutes=args.max_age_minutes):
            log.info("stored reading from %s is too old, skipping", reading["taken_at"])
            return

    if args.flush:
        log.info("flush mode, marking as seen without posting: %s", fp)
        return

    print(json.dumps(reading, indent=2), flush=True)

    if args.post_url:
        post_reading(args.post_url, args.device_key, reading)


# --------------------------------------------------------------------------


async def run(args) -> int:
    seen = load_seen()

    def on_readings(readings: list[dict]) -> None:
        for reading in readings:
            handle(reading, args, seen)
        save_seen(seen)

    while True:
        log.info("scanning for the monitor ...")
        device = await find_device(args.address, args.scan_timeout)

        if device is None:
            if args.once:
                log.error("monitor not found within %.0fs", args.scan_timeout)
                return 1
            continue

        log.info("found %s (%s)", device.name or NAME_PREFIX, device.address)

        try:
            readings = await collect_from_device(device, on_readings)
        except Exception as exc:  # noqa: BLE001
            log.error("connection failed: %s", exc)
            await asyncio.sleep(2)
            continue

        if args.once:
            return 0 if readings else 1

        # Let the monitor power down before we start scanning again.
        await asyncio.sleep(3)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--address", help="MAC of the monitor (from bp_scan.py)")
    parser.add_argument("--once", action="store_true", help="exit after one reading")
    parser.add_argument(
        "--flush",
        action="store_true",
        help="mark everything received as seen without posting (run once after pairing)",
    )
    parser.add_argument("--post-url", help="HealthPass endpoint to POST readings to")
    parser.add_argument(
        "--device-key",
        # From the environment by default, so the systemd service can pass the
        # key without it showing up in the process list.
        default=os.environ.get("HEALTHPASS_KIOSK_KEY"),
        help="sent as X-Kiosk-Key header (default: $HEALTHPASS_KIOSK_KEY)",
    )
    parser.add_argument("--scan-timeout", type=float, default=30.0)
    parser.add_argument(
        "--max-age-minutes",
        type=int,
        default=10,
        help="ignore stored readings older than this (0 to accept all)",
    )
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)-7s %(message)s",
        datefmt="%H:%M:%S",
    )

    if args.post_url:
        # Fail at start-up, not after the first measurement, when requests is missing.
        import requests  # noqa: F401

    try:
        return asyncio.run(run(args))
    except KeyboardInterrupt:
        return 0


if __name__ == "__main__":
    sys.exit(main())
