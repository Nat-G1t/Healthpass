<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a self-booking submission (FR-STU-04, FR-STU-05, BR-02, BR-04).
 *
 * Basic rules run first (service enum, date format, not in the past).
 * The two DB-side checks run in the after() callback only when basic validation
 * already passes — both are surfaced as validation errors, not DB exceptions.
 */
class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'service' => ['required', 'in:medical,dental'],
            'date'    => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'service.required' => 'Please select a service.',
            'service.in'       => 'Invalid service type.',
            'date.required'    => 'Please pick a date.',
            'date.date_format' => 'Invalid date format.',
            'date.after_or_equal' => 'The booking date cannot be in the past.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $date    = $this->input('date');
            $service = $this->input('service');

            // BR-02: capacity re-check at write time (prevents race-condition over-bookings).
            $capacity = (int) config('healthpass.daily_capacity');
            $booked   = Appointment::whereDate('scheduled_date', $date)
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
