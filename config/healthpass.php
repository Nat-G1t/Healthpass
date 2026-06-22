<?php

return [

    // BR-02, D-4: One global daily slot cap; counted against non-cancelled appointments.
    // Clinic staff update this value without touching any controller or view.
    'daily_capacity' => env('HEALTHPASS_DAILY_CAPACITY', 40),

    // BR-01: Monday–Friday, 7 AM–5 PM. Used to block off-hours self-bookings.
    'clinic_hours' => [
        'open' => '07:00',
        'close' => '17:00',
        'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
    ],

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
        'complete_reset_seconds' => 12,  // FR-KSK-13: auto-reset after successful submission
        'idle_timeout_seconds' => 90,  // FR-KSK-15: abandon reset on no interaction mid-flow
    ],

];
