<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use App\Models\ClinicVisit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Server-side validation of a RE-CHECK submit (FR-KSK-11a, D-72).
 *
 * Its own Form Request rather than a mode inside KioskSubmitRequest, because
 * the two payloads have almost nothing in common: a re-check carries only the
 * one or two readings that were flagged — no consent, no questionnaire, no
 * social history, no height or weight, because the resting visit already holds
 * all of those. Keeping them apart means each class's rules() reads as exactly
 * what that endpoint accepts.
 *
 * WHICH fields are required is decided by the SERVER: the session-bound
 * resting visit's stored flags say which steps the student owed
 * (VitalSigns::recheckSteps), and only those are validated. Anything else in
 * the body is simply never read — not here, and not in RecheckKioskVisit.
 *
 * Ranges come from config/healthpass.php (FR-KSK-08), the same source the
 * first pass and the front-end use.
 */
final class KioskRecheckRequest extends FormRequest
{
    /** The kiosk is public; identity was established earlier in the flow. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bounds = config('healthpass.validation');
        $steps = $this->recheckSteps();

        $rules = [
            // Provenance of the re-taken step(s); rolled into the stored
            // entry_method server-side (FR-KSK-06).
            'vitalMethods' => ['required', 'array', 'min:1'],
            'vitalMethods.*' => [Rule::in(['sensor', 'manual'])],
        ];

        if (in_array('temp', $steps, true)) {
            $rules['temperature'] = ['required', 'numeric', "min:{$bounds['temperature_c']['min']}", "max:{$bounds['temperature_c']['max']}"];
        }

        if (in_array('bp', $steps, true)) {
            $rules['systolic'] = ['required', 'integer', "min:{$bounds['bp_systolic']['min']}", "max:{$bounds['bp_systolic']['max']}"];
            $rules['diastolic'] = ['required', 'integer', "min:{$bounds['bp_diastolic']['min']}", "max:{$bounds['bp_diastolic']['max']}"];
            $rules['heartRate'] = ['required', 'integer', "min:{$bounds['heart_rate']['min']}", "max:{$bounds['heart_rate']['max']}"];
        }

        return $rules;
    }

    /**
     * The steps the SESSION-bound resting visit owes, read from its stored
     * flags. An empty list (no bound visit, or it is no longer resting) leaves
     * only the vitalMethods rule; the controller refuses that request on its
     * own before the action ever runs.
     *
     * @return list<string>
     */
    private function recheckSteps(): array
    {
        $visitId = $this->session()->get('kiosk.resting_visit_id');

        if ($visitId === null) {
            return [];
        }

        $visit = ClinicVisit::with('vitalSigns')->find($visitId);

        return $visit?->vitalSigns?->recheckSteps() ?? [];
    }
}
