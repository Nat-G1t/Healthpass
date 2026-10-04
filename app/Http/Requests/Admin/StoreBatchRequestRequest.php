<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BatchRequest;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a New Batch Request submission (FR-ADM-02/03, BR-06, BR-07).
 *
 * D-108: the college sends the form, the purpose and the students — no clinic
 * date and no start hour, which the Director now picks at approval.
 *
 * The college scope (FR-ADM-06) is enforced HERE too, not just in the UI:
 * every submitted student id must belong to the admin's managed college,
 * so a crafted request cannot smuggle another college's students into a
 * batch. The college id comes from the authenticated user — never from
 * the request body.
 */
class StoreBatchRequestRequest extends FormRequest
{
    /**
     * BR-07 (D-105): a batch needs at least this many students. The New Batch
     * page reads it too (BatchRequestController::create), so the browser and
     * the server never disagree on the number.
     */
    public const MIN_STUDENTS = 2;

    public function authorize(): bool
    {
        // The /admin route group already enforces auth + role + college
        // scope (`college.scope` middleware); no extra gate needed here.
        return true;
    }

    /**
     * BR-06: reason_detail only means anything when the reason is "others".
     * Drop stray detail text otherwise (e.g. the admin typed one, then
     * switched back to a listed reason) — server-side, so a crafted request
     * can't smuggle it past the UI.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('reason') !== BatchRequest::REASON_OTHERS) {
            $this->merge(['reason_detail' => null]);
        }
    }

    public function rules(): array
    {
        // Non-null is guaranteed by the `college.scope` middleware.
        $collegeId = $this->user()->managedCollege->id;

        // is_string: a crafted form_type[]=… must fail validation, not crash
        // the array lookup below.
        $formType = $this->input('form_type');
        $reasonsForForm = is_string($formType) ? (BatchRequest::REASONS_BY_FORM[$formType] ?? []) : [];

        return [
            // D-62: the clinic form comes first — it decides which reasons are
            // valid, so a clearance reason posted with an assessment form (or
            // any reason with no form) is refused.
            'form_type' => ['required', Rule::in(array_keys(BatchRequest::FORM_TYPES))],
            'reason' => ['required', Rule::in(array_keys($reasonsForForm))],
            'reason_detail' => [
                'required_if:reason,'.BatchRequest::REASON_OTHERS,
                'nullable',
                'string',
                // Prints on the form's "Others, Specify:" line (D-62).
                'max:120',
            ],
            // D-108: no clinic date and no start hour — the Director picks
            // both at approval. A posted requested_date / requested_time is
            // never read.
            // BR-07 (D-105): at least MIN_STUDENTS students per batch.
            'students' => ['required', 'array', 'min:'.self::MIN_STUDENTS],
            'students.*' => [
                'integer',
                'distinct',
                Rule::exists('student_profiles', 'id')
                    ->where('college_id', $collegeId)
                    // A deactivated account (e.g. a graduate) is off the roster.
                    ->whereIn('user_id', User::where('status', 'active')->select('id')),
            ],
        ];
    }

    public function messages(): array
    {
        $tooFewStudents = 'Select at least '.self::MIN_STUDENTS.' students for this batch.';

        // D-105: people see the batch's reason as its "Purpose".
        return [
            'form_type.required' => 'Please choose the form the clinic will use.',
            'form_type.in' => 'Invalid form.',
            'reason.required' => 'Please choose a purpose for this batch request.',
            'reason.in' => 'That purpose is not on the chosen form.',
            'reason_detail.max' => 'Please keep the specified purpose to 120 characters — it prints on one line of the form.',
            'reason_detail.required_if' => 'Please specify the purpose when choosing "Others".',
            'students.required' => $tooFewStudents,
            'students.min' => $tooFewStudents,
            'students.*.distinct' => 'A student was selected more than once.',
            'students.*.exists' => 'One of the selected students is not an active student in your college.',
            'students.*.integer' => 'One of the selected students is invalid.',
        ];
    }

    /**
     * The one schedule rule left at submission: a cohort bigger than a whole
     * clinic day can never be scheduled, whatever date or hour the Director
     * picks (ClinicScheduleService::maxBatchSize()). Runs only once the basic
     * rules pass, so the student list is trustworthy by this point.
     *
     * D-108 moved everything else to approval: the capacity of each hour, the
     * elapsed-hour rule (BR-23) and the one-schedule-per-day clash (BR-25) all
     * need a date, and the college no longer picks one.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $schedule = app(ClinicScheduleService::class);
            $studentCount = count((array) $this->input('students', []));

            if ($studentCount > $schedule->maxBatchSize()) {
                $validator->errors()->add('students', sprintf(
                    'A batch of %d students cannot fit in one clinic day — the clinic takes at most %d (%d per hour × %d hours). Please split it into two batches.',
                    $studentCount,
                    $schedule->maxBatchSize(),
                    $schedule->hourlyCapacity(),
                    count($schedule->slots()),
                ));
            }
        });
    }
}
