<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a self-booking submission (FR-STU-04, FR-STU-05, BR-02, BR-04).
 *
 * Basic rules run first (service enum, date format, not in the past, and the
 * D-28 purpose of a medical clearance). The two DB-side checks run in the
 * after() callback only when basic validation already passes — both are
 * surfaced as validation errors, not DB exceptions.
 */
class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    /**
     * prepareForValidation runs before the rules — a Form Request hook for
     * normalizing input BEFORE it is trusted. Two D-28 clean-ups, both
     * server-side so a crafted request can't smuggle values past the UI:
     *   1. Dental is scheduling-only (no clearance form), so any purpose sent
     *      with a dental booking is meaningless — drop it to NULL.
     *   2. purpose_other only means anything when the purpose is Others; drop
     *      stray specify text otherwise (e.g. the student typed one, then
     *      switched back to a listed purpose).
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('service') !== 'medical') {
            $this->merge(['purpose' => null, 'purpose_other' => null]);
        } elseif ($this->input('purpose') !== ClearanceRecord::PURPOSE_OTHERS) {
            $this->merge(['purpose_other' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'service' => ['required', 'in:medical,dental'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            // D-28: purpose is required for a medical clearance (something WILL
            // be printed) and forbidden-by-normalization for dental. The value
            // must come from the locked list plus the "Others" line; the server
            // is the sole gate (SQLite never enforced the column as an enum).
            'purpose' => [
                'required_if:service,medical',
                'nullable',
                Rule::in([...ClearanceRecord::PURPOSES, ClearanceRecord::PURPOSE_OTHERS]),
            ],
            'purpose_other' => [
                'required_if:purpose,'.ClearanceRecord::PURPOSE_OTHERS,
                'nullable',
                'string',
                'max:120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'service.required' => 'Please select a service.',
            'service.in' => 'Invalid service type.',
            'date.required' => 'Please pick a date.',
            'date.date_format' => 'Invalid date format.',
            'date.after_or_equal' => 'The booking date cannot be in the past.',
            'purpose.required_if' => 'Please choose the purpose of your medical clearance.',
            'purpose.in' => 'Invalid purpose.',
            'purpose_other.required_if' => 'Please specify the event for an "Others" purpose.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $date = $this->input('date');
            $service = $this->input('service');

            // BR-20: same-day closing cutoff. Once the local clock reaches closing_hour,
            // TODAY is no longer bookable (the clinic is closing). now() and today() both
            // resolve in the app timezone (Asia/Manila), so this compares apples to apples.
            $closingHour = (int) config('healthpass.closing_hour');
            if ($date === today()->toDateString() && now()->hour >= $closingHour) {
                $validator->errors()->add(
                    'date',
                    'The clinic is closed for today. Please book for the next day onwards.'
                );

                return;
            }

            // BR-02: capacity re-check at write time (prevents race-condition over-bookings).
            $capacity = (int) config('healthpass.daily_capacity');
            $booked = Appointment::whereDate('scheduled_date', $date)
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($booked >= $capacity) {
                $validator->errors()->add(
                    'date',
                    'This day is fully booked. Please select a different date.'
                );

                return;
            }

            // BR-04: one active (non-cancelled) appointment per student per service per date.
            $duplicate = Appointment::where('student_id', $this->user()->id)
                ->where('service_type', $service)
                ->whereDate('scheduled_date', $date)
                ->where('status', '!=', 'cancelled')
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'date',
                    'You already have an active '.ucfirst($service).' appointment on this date.'
                );
            }
        });
    }
}
