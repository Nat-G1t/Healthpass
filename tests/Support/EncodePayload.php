<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The Save & Close / Preview & Print payload (FR-NRS-04/05).
 *
 * Since D-65 the encode form posts the seven vitals the clinic confirms
 * alongside the result, and all seven are required — so every test that saves
 * or previews needs them. One helper keeps that list in a single place.
 */
final class EncodePayload
{
    /**
     * The seven confirmed vitals. The defaults match the kiosk reading the
     * nurse tests capture, so a save with no overrides corrects nothing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function vitals(array $overrides = []): array
    {
        return [
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'temperature_c' => 36.5,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'heart_rate_bpm' => 75,
            'respiratory_rate' => 16,
            ...$overrides,
        ];
    }

    /**
     * A complete, valid payload: Fit plus the vitals.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(array $overrides = []): array
    {
        return ['result' => 'Fit', ...self::vitals(), ...$overrides];
    }
}
