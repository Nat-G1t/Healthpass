<?php

declare(strict_types=1);

namespace App\Actions\Kiosk;

use App\Models\ClinicVisit;
use App\Models\VitalSigns;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The kiosk re-check use case (FR-KSK-11a, D-72).
 *
 * The student rested, came back, scanned again and re-took ONLY the readings
 * that were flagged. This action folds those new numbers into the `resting`
 * visit that is already on disk and releases it into the clinic queue.
 *
 * Everything except the re-taken numbers comes from the SAVED rows — height,
 * weight, consent, the twelve Physical Signs answers, the social history. The
 * request body carries the re-taken fields and nothing else; a payload naming
 * any other value changes nothing (CLAUDE.md's kiosk trust rule).
 *
 * What the server decides for itself, as always:
 *   • ALL the flag booleans are recomputed from the merged reading — including
 *     the ones nobody re-took, so the stored flags always describe the stored
 *     numbers.
 *   • The BMI is left alone: resting changes no height or weight.
 *   • entry_method rolls the first pass's provenance together with the
 *     re-check's (`mixed` once the two differ) — the clinic can then see that
 *     part of this reading was typed by hand.
 *   • `checked_in_at` moves to NOW, so the student joins the BACK of the FCFS
 *     Live Queue: their wait starts when they actually finished, not when they
 *     first walked up.
 */
final class RecheckKioskVisit
{
    /**
     * @param  ClinicVisit  $visit  The SESSION-bound resting visit — never an id
     *                              from the request body.
     * @param  array  $data  The validated re-taken fields from
     *                       KioskRecheckRequest: `temperature` and/or
     *                       `systolic`/`diastolic`/`heartRate`, plus
     *                       `vitalMethods`.
     * @param  array|null  $bpReading  The Bluetooth reading claimed in THIS
     *                                 session (D-58), or null.
     */
    public function handle(ClinicVisit $visit, array $data, ?array $bpReading = null): ClinicVisit
    {
        return DB::transaction(function () use ($visit, $data, $bpReading): ClinicVisit {
            // lockForUpdate() holds the row for the rest of this transaction, so
            // two taps of Submit cannot both read `resting` and both release the
            // visit. The controller's status re-check runs inside this lock.
            $visit = ClinicVisit::query()->lockForUpdate()->findOrFail($visit->id);
            $vitals = $visit->vitalSigns()->lockForUpdate()->firstOrFail();

            $steps = $vitals->recheckSteps();

            // The pre-rest numbers, kept for the clinic to see at encode. Read
            // from the STORED row, so it is exactly what the first pass wrote.
            $firstReading = [
                'temperature_c' => (float) $vitals->temperature_c,
                'bp_systolic' => (int) $vitals->bp_systolic,
                'bp_diastolic' => (int) $vitals->bp_diastolic,
                'heart_rate_bpm' => (int) $vitals->heart_rate_bpm,
                'is_temp_flagged' => (bool) $vitals->is_temp_flagged,
                'is_bp_flagged' => (bool) $vitals->is_bp_flagged,
                'is_hr_flagged' => (bool) $vitals->is_hr_flagged,
                'taken_at' => $visit->checked_in_at?->toIso8601String(),
            ];

            $merged = $this->mergedVitals($vitals, $data, $steps);

            $vitals->fill([
                ...$merged,
                'first_reading' => $firstReading,
                'entry_method' => $this->mergedEntryMethod($vitals->entry_method, $data['vitalMethods'] ?? []),
                // D-58: the device record must describe the BP that is now
                // stored. A re-taken BP that did not come from the monitor
                // clears it; one that did replaces it.
                'bp_device_reading' => in_array('bp', $steps, true)
                    ? $this->bpDeviceReading($bpReading, $merged)
                    : $vitals->bp_device_reading,
                // Every flag, not just the re-taken ones (BR-14). BMI is
                // recomputed from the untouched height/weight and so cannot move.
                ...SubmitKioskVisit::flagsFor(
                    [
                        'temperature' => $merged['temperature_c'],
                        'systolic' => $merged['bp_systolic'],
                        'diastolic' => $merged['bp_diastolic'],
                        'heartRate' => $merged['heart_rate_bpm'],
                    ],
                    config('healthpass.thresholds'),
                    (float) $vitals->bmi,
                ),
            ])->save();

            $visit->fill([
                'status' => 'captured', // BR-11: resting → captured → encoded
                'checked_in_at' => now(), // back of the FCFS queue (FR-NRS-01)
                'resting_until' => null, // the rest is over and cannot repeat
            ])->save();

            return $visit;
        });
    }

    /**
     * The stored reading with the re-taken fields written over it.
     *
     * A step the student was NOT asked to re-take keeps its saved value even if
     * the body sent one — that is the trust rule, not an optimisation.
     *
     * @param  list<string>  $steps  The re-check steps this visit actually owed.
     * @return array{temperature_c: float, bp_systolic: int, bp_diastolic: int, heart_rate_bpm: int}
     */
    private function mergedVitals(VitalSigns $vitals, array $data, array $steps): array
    {
        $merged = [
            'temperature_c' => (float) $vitals->temperature_c,
            'bp_systolic' => (int) $vitals->bp_systolic,
            'bp_diastolic' => (int) $vitals->bp_diastolic,
            'heart_rate_bpm' => (int) $vitals->heart_rate_bpm,
        ];

        if (in_array('temp', $steps, true)) {
            $merged['temperature_c'] = (float) $data['temperature'];
        }

        if (in_array('bp', $steps, true)) {
            $merged['bp_systolic'] = (int) $data['systolic'];
            $merged['bp_diastolic'] = (int) $data['diastolic'];
            $merged['heart_rate_bpm'] = (int) $data['heartRate'];
        }

        return $merged;
    }

    /**
     * One provenance value for the whole reading (FR-KSK-06): the first pass's
     * stored entry_method rolled together with the re-check's per-step methods.
     * Any disagreement — including a first pass that was already `mixed` —
     * makes the result `mixed`.
     *
     * @param  list<string>  $methods  'sensor' | 'manual', one per re-taken step.
     */
    private function mergedEntryMethod(?string $stored, array $methods): string
    {
        if ($stored === 'mixed') {
            return 'mixed';
        }

        $unique = array_values(array_unique(array_filter([$stored, ...$methods])));

        return count($unique) === 1 ? $unique[0] : 'mixed';
    }

    /**
     * The Bluetooth monitor's record of the RE-TAKEN blood pressure (D-58), or
     * null. Same rule as the first pass (SubmitKioskVisit::bpDeviceReading):
     * the claimed reading is kept only while the stored numbers ARE its
     * numbers, so a BP re-typed by hand after the claim stores nothing
     * device-related.
     */
    private function bpDeviceReading(?array $claimed, array $merged): ?array
    {
        if ($claimed === null) {
            return null;
        }

        $sameNumbers = $merged['bp_systolic'] === $claimed['systolic']
            && $merged['bp_diastolic'] === $claimed['diastolic']
            && $merged['heart_rate_bpm'] === $claimed['pulse'];

        if (! $sameNumbers) {
            return null;
        }

        return Arr::only($claimed, ['device_model', 'raw', 'taken_at', 'mean_arterial', 'flags', 'suspect', 'received_at']);
    }
}
