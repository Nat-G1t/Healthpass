<?php

return [

    // BR-02, D-4/D-37: clinic load caps, counted against non-cancelled appointments.
    // Clinic staff update these values without touching any controller or view.
    //
    // D-37 made the clinic day TEN one-hour booking slots (clinic_hours below,
    // 7 AM–5 PM, lunch included). A kiosk session takes at most 5 minutes, so a
    // slot holds 12 students — 12 × 10 = 120 a day. Medical and dental share ONE
    // counter: what is being capped is clinic congestion, not kiosk throughput.
    'hourly_capacity' => env('HEALTHPASS_HOURLY_CAPACITY', 12),

    // The outer daily cap. It equals hourly_capacity × the number of slots, and
    // is still enforced separately because it is the only cap that sees legacy
    // pre-D-37 appointments (scheduled_time NULL), which belong to no slot.
    'daily_capacity' => env('HEALTHPASS_DAILY_CAPACITY', 120),

    // D-34, hosted internet deploy: the IP address(es) of the reverse proxy /
    // load balancer sitting in front of the app, comma-separated. Laravel only
    // honours X-Forwarded-For / X-Forwarded-Proto from these addresses, which is
    // what makes per-IP throttles count the real visitor and https:// links come
    // out as https://. Leave EMPTY on the Pi-local shape (no proxy in front).
    // NEVER '*' — App\Support\TrustedProxies rejects it at boot; see
    // docs/deployment-hosted.md.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // D-35, StaffSeeder: how seeded staff accounts (director, nurse, 12 college
    // admins — FR-AUTH-05, there is no staff registration) get their credentials.
    'seed_staff' => [
        // TRUE on the hosted deploy: each account gets a distinct random one-time
        // password, printed once by the seeder and never stored, and is flagged
        // must_change_password so the owner must replace it at first login.
        // FALSE (default) keeps the local/dev shared 'password' — see dev-notes.
        'one_time_passwords' => env('HEALTHPASS_SEED_STAFF_ONE_TIME', false),

        // Email domain for the generated staff addresses. Override on the hosted
        // deploy with the real institutional domain, then confirm each individual
        // address with the clinic and the colleges before go-live.
        'email_domain' => env('HEALTHPASS_STAFF_EMAIL_DOMAIN', 'healthpass.test'),
    ],

    // BR-01 (updated): Clinic open daily, 7 AM–5 PM. Since D-37 this is also the
    // source of the bookable slot list — App\Services\ClinicScheduleService derives
    // one one-hour slot per open hour (07:00 … 16:00), so the slot grid is never
    // hardcoded in a view. Whole hours only.
    'clinic_hours' => [
        'open' => '07:00',
        'close' => '17:00',
        'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ],

    // BR-01: Weekdays (Carbon / JS day-of-week integers, 0 = Sunday … 6 = Saturday) on which
    // self-booking is allowed. Remove an integer to block that weekday clinic-wide.
    'booking_days' => [0, 1, 2, 3, 4, 5, 6],

    // FR-STU-12 (D-39): where the student is told to go, and what to bring, in the
    // appointment email. Config rather than a string in the Blade because the
    // clinic can move (the kiosk already moved once — docs/kiosk-network-move.md)
    // and a room change must not need a code edit. Confirm the real room/building
    // with the clinic before go-live; the default is deliberately generic.
    'clinic_location' => env('HEALTHPASS_CLINIC_LOCATION', 'University Clinic, Pampanga State University'),

    // BR-20 (pending adviser sign-off): same-day booking cutoff. Once the local clock
    // (Asia/Manila) reaches this hour, TODAY can no longer be self-booked — the clinic is
    // closing. Integer hour, 24h, matches clinic_hours.close ('17:00') above; keep the two
    // in sync. Consumed server-side by StoreAppointmentRequest and the availability endpoint.
    'closing_hour' => 17,

    // FR-ADM-12 (D-55): when a batch student who never reached the kiosk stops
    // reading "Not yet attended" and becomes "Absent" on the Batch Results popup.
    // 24h 'H:i' on the CLINIC DATE, server clock (Asia/Manila) — from 8:00 PM, so
    // the clinic day and the evening after it have passed. Any later day is
    // absent too. Decided on the server, never in the browser.
    'absent_cutoff' => env('HEALTHPASS_ABSENT_CUTOFF', '20:00'),

    // §7.4, D-10: Rule-based flag thresholds — screening signals, not diagnoses.
    // All flag logic (kiosk badges, nurse queue, Director anomalies) reads ONLY from here. (NFR-7, BR-13)
    'thresholds' => [
        'temperature_max' => 37.2,   // > 37.2 °C  → is_temp_flagged ("Fever")
        'bp_systolic' => 140,    // systolic ≥ 140  → is_bp_flagged ("High Blood Pressure"); D-10 canonical
        'bp_diastolic' => 90,     // OR diastolic ≥ 90 → is_bp_flagged
        'bmi_obese' => 30.0,   // ≥ 30.0 → is_bmi_flagged ("Abnormal BMI / Obese")
    ],

    // FR-KSK-08: Server-side plausibility bounds; out-of-range input triggers re-entry prompt.
    'validation' => [
        'height_cm' => ['min' => 50,   'max' => 250],
        'weight_kg' => ['min' => 10,   'max' => 300],
        'temperature_c' => ['min' => 30.0, 'max' => 45.0],
        'bp_systolic' => ['min' => 60,   'max' => 260],
        'bp_diastolic' => ['min' => 30,   'max' => 160],
        'heart_rate' => ['min' => 30,   'max' => 220],
    ],

    // FR-KSK-13, FR-KSK-15: Kiosk session lifecycle timings.
    'kiosk' => [
        // Security (audit fix): restrict who can reach the /kiosk route group by
        // NETWORK. The page stays auth-less for the person AT the terminal, but only
        // an enrolled kiosk DEVICE (D-27), an authenticated active nurse, or (when
        // allow_loopback below is on) the Pi's own loopback may reach it. Everyone
        // else gets a friendly branded 403. Set false (env HEALTHPASS_KIOSK_RESTRICT
        // =false) to drop the gate entirely for LAN dev/testing. Secure by default.
        // See App\Http\Middleware\KioskAccess.
        'restrict_access' => env('HEALTHPASS_KIOSK_RESTRICT', true),

        // Whether a loopback (127.0.0.1/::1) request counts as authorized. The
        // Pi-local defense shape NEEDS this on (Chromium hits http://localhost/kiosk)
        // and enables it explicitly in scripts/pi/pi.env.example; the hosted internet
        // deploy leaves it OFF. NEVER key this on APP_ENV — the Pi is
        // APP_ENV=production over plain http://localhost by design.
        //
        // DEFAULT IS FALSE (fail-safe): behind a same-host reverse proxy (nginx →
        // php-fpm/app on 127.0.0.1 is the common single-box setup) $request->ip()
        // reports 127.0.0.1 for EVERY internet visitor unless trusted proxies are
        // set exactly right — a true default would silently open /kiosk/scan to the
        // whole world as a PII oracle. So loopback trust must be opted INTO by the
        // deployment that actually runs on loopback (the Pi), never assumed. (Also
        // configure trusted proxies to the real proxy IPs — never '*' — see docs.)
        'allow_loopback' => env('HEALTHPASS_KIOSK_ALLOW_LOOPBACK', false),

        'complete_reset_seconds' => 12,  // FR-KSK-13: auto-reset after successful submission
        'idle_timeout_seconds' => 90,  // FR-KSK-15: abandon reset on no interaction mid-flow
        // FR-KSK-07 / FR-HW-05, §11.2: Web Serial sensor link. `serial_baud` must
        // match the MCU firmware; `serial_timeout_ms` is how long a connected but
        // silent sensor waits before the kiosk nudges toward manual entry.
        'serial_baud' => 9600,
        'serial_timeout_ms' => 10000,
    ],

];
