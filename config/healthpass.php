<?php

return [

    // BR-02, D-4: One global daily slot cap; counted against non-cancelled appointments.
    // Clinic staff update this value without touching any controller or view.
    'daily_capacity' => env('HEALTHPASS_DAILY_CAPACITY', 40),

    // BR-01 (updated): Clinic open daily, 7 AM–5 PM.
    'clinic_hours' => [
        'open' => '07:00',
        'close' => '17:00',
        'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ],

    // BR-01: Weekdays (Carbon / JS day-of-week integers, 0 = Sunday … 6 = Saturday) on which
    // self-booking is allowed. Remove an integer to block that weekday clinic-wide.
    'booking_days' => [0, 1, 2, 3, 4, 5, 6],

    // BR-20 (pending adviser sign-off): same-day booking cutoff. Once the local clock
    // (Asia/Manila) reaches this hour, TODAY can no longer be self-booked — the clinic is
    // closing. Integer hour, 24h, matches clinic_hours.close ('17:00') above; keep the two
    // in sync. Consumed server-side by StoreAppointmentRequest and the availability endpoint.
    'closing_hour' => 17,

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
        // the Pi's own loopback (127.0.0.1/::1) or an authenticated active nurse may
        // reach it (keeps FR-NRS-06 "Enable Kiosk Mode" working from staff machines).
        // Set false (env HEALTHPASS_KIOSK_RESTRICT=false) to allow LAN access for
        // local dev/testing. Secure by default. See App\Http\Middleware\KioskAccess.
        'restrict_access' => env('HEALTHPASS_KIOSK_RESTRICT', true),

        'complete_reset_seconds' => 12,  // FR-KSK-13: auto-reset after successful submission
        'idle_timeout_seconds' => 90,  // FR-KSK-15: abandon reset on no interaction mid-flow
        // FR-KSK-07 / FR-HW-05, §11.2: Web Serial sensor link. `serial_baud` must
        // match the MCU firmware; `serial_timeout_ms` is how long a connected but
        // silent sensor waits before the kiosk nudges toward manual entry.
        'serial_baud' => 9600,
        'serial_timeout_ms' => 10000,
    ],

];
