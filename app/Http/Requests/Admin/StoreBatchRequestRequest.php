<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BatchRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use App\Services\ScheduleClashService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a New Batch Request submission (FR-ADM-02/03, BR-06, BR-07).
 *
 * The college scope (FR-ADM-06) is enforced HERE too, not just in the UI:
 * every submitted student id must belong to the admin's managed college,
 * so a crafted request cannot smuggle another college's students into a
 * batch. The college id comes from the authenticated user — never from
 * the request body.
 */
class StoreBatchRequestRequest extends FormRequest
{
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
            // D-29: the admin proposes the clinic date (they know the
            // cohort's event); the Director confirms it at approval (D-36).
            'requested_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            // D-37: and the START hour of the batch's span. The span itself is
            // never posted — the server computes it from the student count, so
            // a client cannot claim fewer hours than the cohort needs.
            'requested_time' => ['required', Rule::in($this->schedule()->slots())],
            // BR-07: at least one student per batch.
            'students' => ['required', 'array', 'min:1'],
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
        return [
            'form_type.required' => 'Please choose the form the clinic will use.',
            'form_type.in' => 'Invalid form.',
            'reason.required' => 'Please choose a reason for this batch request.',
            'reason.in' => 'That reason is not on the chosen form.',
            'reason_detail.max' => 'Please keep the specified reason to 120 characters — it prints on one line of the form.',
            'reason_detail.required_if' => 'Please specify the reason when choosing "Others".',
            'requested_date.required' => 'Please pick the date your students should visit the clinic.',
            'requested_date.date_format' => 'Invalid requested date.',
            'requested_date.after_or_equal' => 'The requested date cannot be in the past.',
            'requested_time.required' => 'Please pick the hour the batch should start.',
            'requested_time.in' => 'That start time is not part of clinic hours.',
            'students.required' => 'Select at least one student for this batch.',
            'students.min' => 'Select at least one student for this batch.',
            'students.*.distinct' => 'A student was selected more than once.',
            'students.*.exists' => 'One of the selected students is not an active student in your college.',
            'students.*.integer' => 'One of the selected students is invalid.',
        ];
    }

    /** The slot grid + capacity rules (D-37), shared with every other caller. */
    private function schedule(): ClinicScheduleService
    {
        return app(ClinicScheduleService::class);
    }

    /**
     * D-37 span rules. Runs only once the basic rules pass, so the student
     * count and the start hour are both trustworthy by this point.
     *
     * The capacity check (4) and the D-54 clash check (5) are re-run under a
     * row lock at write time (BatchRequestController::store) — this read is
     * unlocked and races with another batch taking the last seats in one of
     * the batch's hours, or holding one of its students in them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $schedule = $this->schedule();
            $startSlot = $this->input('requested_time');
            $date = $this->input('requested_date');
            $studentCount = count((array) $this->input('students', []));

            // (1) A cohort bigger than the whole clinic day can never fit.
            if ($studentCount > $schedule->maxBatchSize()) {
                $validator->errors()->add('students', sprintf(
                    'A batch of %d students cannot fit in one clinic day — the clinic takes at most %d (%d per hour × %d hours). Please split it across two dates.',
                    $studentCount,
                    $schedule->maxBatchSize(),
                    $schedule->hourlyCapacity(),
                    count($schedule->slots()),
                ));

                return;
            }

            // (2) BR-23: the start hour must not have gone by already. Only the
            // START needs checking — the span runs forwards, so if its first
            // hour is still open none of the later ones can have elapsed.
            if ($schedule->isSlotElapsed($date, $startSlot)) {
                $validator->errors()->add('requested_time', $schedule->slotElapsedMessage($startSlot));

                return;
            }

            // (3) The span must end by closing time.
            $span = $schedule->spanForStudents($startSlot, $studentCount);

            if ($span === []) {
                $validator->errors()->add('requested_time', sprintf(
                    '%d students need %d clinic hour(s). Starting at %s would run past %s — please choose an earlier start time.',
                    $studentCount,
                    $schedule->blocksFor($studentCount),
                    $schedule->startLabel($startSlot),
                    $schedule->startLabel(sprintf('%02d:00:00', (int) substr((string) config('healthpass.clinic_hours.close'), 0, 2))),
                ));

                return;
            }

            // (4) Every hour in the span must have room. Name the offending
            // hour — "full" alone doesn't tell the admin what to move away from.
            $fullSlots = $schedule->fullSlotsIn($date, $span);

            if ($fullSlots !== []) {
                $validator->errors()->add('requested_time', sprintf(
                    'This batch would run %s, but the %s slot is already fully booked. Please choose a different start time or date.',
                    $schedule->spanLabel($span),
                    implode(' and the ', array_map(
                        fn (string $slot): string => $schedule->label($slot),
                        $fullSlots,
                    )),
                ));

                return;
            }

            // (5) D-54 / D-77 / BR-25: no student may already have a clinic
            // schedule that DAY on another pending/approved batch, whatever its
            // hours or form (D-61 removed the self-booking half). First come
            // wins, so THIS batch is refused. One
            // error per clashing student, keyed
            // `clashes.<student_profile_id>`: the New Batch page collects those
            // keys into its popup and its "Remove these students" button.
            $profileIdsByUserId = StudentProfile::whereIn('id', (array) $this->input('students'))
                ->pluck('id', 'user_id')
                ->all();

            $clashes = app(ScheduleClashService::class)
                ->clashesForBatch(array_keys($profileIdsByUserId), $date);

            foreach (self::clashErrors($clashes, $profileIdsByUserId) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
    }

    /**
     * D-54: one validation message per clashing student, keyed
     * `clashes.<student_profile_id>`.
     *
     * The form knows students by PROFILE id, while ScheduleClashService speaks
     * USER ids (what the pivot stores), hence the lookup. Shared with the
     * locked re-check in BatchRequestController::store() so both gates report
     * a clash identically.
     *
     * @param  array<int, list<string>>  $clashes  user id => what they clash with
     * @param  array<int, int>  $profileIdsByUserId
     * @return array<string, string>
     */
    public static function clashErrors(array $clashes, array $profileIdsByUserId): array
    {
        $errors = [];

        foreach ($clashes as $userId => $reasons) {
            $errors['clashes.'.$profileIdsByUserId[$userId]] = implode('; ', $reasons);
        }

        return $errors;
    }
}
