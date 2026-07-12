<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FR-STU-04 — booking submission + confirmation screen.
 * FR-STU-05 / BR-04 — duplicate non-cancelled appointment rejected as validation error.
 * FR-STU-06 — student can cancel own scheduled future appointment.
 * BR-01 — past dates rejected.
 * BR-02 — full day rejected at write time.
 * §5.6 / BR-19 — reference number format APT-YYYY-####.
 */
class BookAppointmentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    /** A future date that won't conflict with basic validation. */
    private function futureDate(int $daysAhead = 7): string
    {
        return now()->addDays($daysAhead)->toDateString();
    }

    /**
     * A valid medical-clearance booking payload. D-28 made purpose required for
     * medical bookings, so every medical POST must carry one — override any key.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function medicalBooking(array $overrides = []): array
    {
        return array_merge([
            'service' => 'medical',
            'date' => $this->futureDate(),
            'purpose' => 'Sports Activities',
        ], $overrides);
    }

    // ── 1. Guard: unauthenticated / wrong role ────────────────────────────────

    public function test_guest_is_redirected_from_booking_page(): void
    {
        $this->get(route('student.appointments'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_submit_booking(): void
    {
        $this->post(route('student.appointments.store'), [
            'service' => 'medical',
            'date' => $this->futureDate(),
        ])->assertRedirect(route('login'));
    }

    // ── 2. Past date rejection (BR-01) ────────────────────────────────────────

    public function test_past_date_is_rejected(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_today_is_bookable(): void
    {
        // Pin to the morning so this stays green regardless of the wall clock —
        // same-day booking is only blocked from the closing cutoff onward (BR-20).
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00', 'Asia/Manila'));

        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('appointments', 1);
    }

    // ── 2b. Same-day closing cutoff (BR-20) ───────────────────────────────────

    public function test_today_is_bookable_one_minute_before_cutoff(): void
    {
        // 16:59 — clinic still open, same-day booking allowed.
        Carbon::setTestNow(Carbon::parse('2026-07-08 16:59', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_today_is_rejected_at_closing_cutoff(): void
    {
        // 17:00 exactly — clinic closed for today; same-day booking rejected.
        Carbon::setTestNow(Carbon::parse('2026-07-08 17:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
            ]))
            ->assertSessionHasErrors([
                'date' => 'The clinic is closed for today. Please book for the next day onwards.',
            ]);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_tomorrow_is_bookable_after_cutoff(): void
    {
        // 18:00 — past today's cutoff, but booking for the NEXT day is fine.
        Carbon::setTestNow(Carbon::parse('2026-07-08 18:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->addDay()->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_availability_excludes_today_after_cutoff(): void
    {
        // After the cutoff, today's day-of-month is reported as unavailable so the
        // calendar greys it out — computed server-side, never from the browser clock.
        Carbon::setTestNow(Carbon::parse('2026-07-08 18:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => 2026,
                'month' => 7,
            ]))
            ->assertOk()
            ->assertJsonPath('cutoff_days', fn ($days) => in_array(8, $days, true));
    }

    public function test_availability_includes_today_before_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => 2026,
                'month' => 7,
            ]))
            ->assertOk()
            ->assertJsonPath('cutoff_days', fn ($days) => ! in_array(8, $days, true));
    }

    // ── 3. Full-day rejection at write time (BR-02) ───────────────────────────

    public function test_full_day_is_rejected_at_write_time(): void
    {
        config(['healthpass.daily_capacity' => 2]);

        $date = $this->futureDate();
        $student = $this->student();

        // Fill the day to capacity using two different students
        Appointment::factory()->count(2)->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking(['date' => $date]))
            ->assertSessionHasErrors('date');

        // Confirm the student's appointment was NOT created
        $this->assertDatabaseMissing('appointments', [
            'student_id' => $student->id,
            'scheduled_date' => $date,
        ]);
    }

    public function test_cancelled_appointments_do_not_count_toward_capacity(): void
    {
        config(['healthpass.daily_capacity' => 1]);

        $date = $this->futureDate();
        $student = $this->student();

        // A cancelled appointment on that day — must not block booking
        Appointment::factory()->cancelled()->create(['scheduled_date' => $date]);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking(['date' => $date]))
            ->assertRedirect(); // Not a validation error

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'status' => 'scheduled',
        ]);
    }

    /**
     * FR-STU-03 / BR-02 — a date filled to capacity is reported by the availability
     * endpoint. This is the first test to exercise fullDaysForMonth(); it guards the
     * portable (non-MySQL) query so the SQLite suite can't regress to "no such
     * function: DAY".
     */
    public function test_full_day_appears_in_availability_json(): void
    {
        config(['healthpass.daily_capacity' => 2]);

        $date = $this->futureDate(5);
        $carbon = \Illuminate\Support\Carbon::parse($date);

        Appointment::factory()->count(2)->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => $carbon->year,
                'month' => $carbon->month,
            ]))
            ->assertOk()
            ->assertJsonPath('full_days', fn ($days) => in_array($carbon->day, $days, true));
    }

    // ── 4. Duplicate rejection (FR-STU-05 / BR-04) ───────────────────────────

    public function test_duplicate_active_appointment_for_same_service_and_date_is_rejected(): void
    {
        $date = $this->futureDate();
        $student = $this->student();

        // Existing scheduled appointment
        Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking(['date' => $date]))
            ->assertSessionHasErrors('date');

        // Still only one appointment
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_different_service_on_same_date_is_allowed(): void
    {
        $date = $this->futureDate();
        $student = $this->student();

        Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), [
                'service' => 'dental',
                'date' => $date,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_cancelled_appointment_does_not_block_rebooking_same_service_and_date(): void
    {
        $date = $this->futureDate();
        $student = $this->student();

        // A previously cancelled appointment — should NOT block rebooking
        Appointment::factory()->cancelled()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
        ]);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking(['date' => $date]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'service_type' => 'medical',
            'status' => 'scheduled',
        ]);
    }

    // ── 5. Successful booking (FR-STU-04, BR-19) ─────────────────────────────

    public function test_successful_booking_creates_appointment_with_apt_reference(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking());

        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();

        $this->assertMatchesRegularExpression(
            '/^APT-\d{4}-\d{4}$/',
            $appointment->reference_no,
            'Reference number must match APT-YYYY-#### format (BR-19)'
        );
        $this->assertSame('scheduled', $appointment->status);
        $this->assertSame('self', $appointment->source);
        $this->assertSame('medical', $appointment->service_type);
    }

    public function test_successful_booking_redirects_to_confirmation_screen(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking());

        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();

        $response->assertRedirect(route('student.appointments.confirmed', $appointment));
    }

    public function test_confirmation_screen_shows_service_date_clinic_hours_and_reference(): void
    {
        $student = $this->student();
        $date = $this->futureDate(10);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'status' => 'scheduled',
            'source' => 'self',
        ]);

        $this->actingAs($student)
            ->get(route('student.appointments.confirmed', $appointment))
            ->assertOk()
            ->assertSee($appointment->reference_no)
            ->assertSee('Medical Clearance')
            ->assertSee('7:00 AM')     // clinic_hours.open from config
            ->assertSee('5:00 PM');    // clinic_hours.close from config
    }

    public function test_student_cannot_view_another_students_confirmation(): void
    {
        $owner = $this->student();
        $other = $this->student();

        $appointment = Appointment::factory()->create(['student_id' => $owner->id]);

        $this->actingAs($other)
            ->get(route('student.appointments.confirmed', $appointment))
            ->assertForbidden();
    }

    // ── 6. Cancellation (FR-STU-06) ───────────────────────────────────────────

    public function test_student_can_cancel_own_scheduled_future_appointment(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->futureDate(3),
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertRedirect(route('student.dashboard'));

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_student_cannot_cancel_another_students_appointment(): void
    {
        $owner = $this->student();
        $other = $this->student();

        $appointment = Appointment::factory()->create([
            'student_id' => $owner->id,
            'scheduled_date' => $this->futureDate(3),
            'status' => 'scheduled',
        ]);

        $this->actingAs($other)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_student_cannot_cancel_appointment_on_its_scheduled_date(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_student_cannot_cancel_past_appointment(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->past()->create([
            'student_id' => $student->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertForbidden();
    }

    public function test_student_cannot_cancel_already_cancelled_appointment(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->cancelled()->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->futureDate(3),
        ]);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertForbidden();
    }

    // ── 7. JSON / fetch API path ──────────────────────────────────────────────

    /**
     * When the booking page submits via fetch (Accept: application/json), a duplicate
     * triggers 422 JSON — not a DB exception and not a redirect.
     */
    public function test_duplicate_booking_with_json_accept_returns_422_json(): void
    {
        $date = $this->futureDate();
        $student = $this->student();

        Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking(['date' => $date]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('date');

        // No second appointment was created
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_successful_json_booking_returns_redirect_url(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking());

        $response->assertOk();
        $response->assertJsonStructure(['redirect']);

        // The redirect URL points to the confirmed screen
        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();
        $this->assertStringContainsString(
            (string) $appointment->id,
            $response->json('redirect')
        );
    }

    // ── 8. Dashboard Next Appointment after cancellation ─────────────────────

    public function test_cancelling_nearest_appointment_shows_next_one_on_dashboard(): void
    {
        $student = $this->student();
        $appointmentA = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->futureDate(3),
            'status' => 'scheduled',
        ]);
        $appointmentB = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->futureDate(10),
            'status' => 'scheduled',
        ]);

        // Dashboard initially shows A (the nearest)
        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertViewHas('nextAppointment', fn ($apt) => $apt->id === $appointmentA->id);

        // Cancel A
        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointmentA))
            ->assertRedirect(route('student.dashboard'));

        // Dashboard now shows B
        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertViewHas('nextAppointment', fn ($apt) => $apt->id === $appointmentB->id);
    }

    public function test_cancelling_only_appointment_shows_empty_state_on_dashboard(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->futureDate(3),
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment));

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertViewHas('nextAppointment', null);
    }

    // ── 9. Purpose of Medical Clearance (D-28) ────────────────────────────────

    public function test_booking_page_renders_the_purpose_card(): void
    {
        // Renders book.blade incl. the shared <x-hp.purpose-fieldset> component —
        // guards against a Blade compile error the POST tests wouldn't catch.
        $this->actingAs($this->student())
            ->get(route('student.appointments'))
            ->assertOk()
            ->assertSee('Purpose of Medical Clearance')
            ->assertSee('Off Campus Procedure')
            ->assertSee('Others, Specify');
    }

    public function test_medical_booking_requires_a_purpose(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $this->futureDate(),
                // no purpose
            ])
            ->assertSessionHasErrors('purpose');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_medical_booking_missing_purpose_returns_422_json(): void
    {
        $this->actingAs($this->student())
            ->postJson(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $this->futureDate(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_medical_booking_persists_the_chosen_purpose(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'purpose' => 'Field Trip/Educational Tour',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'service_type' => 'medical',
            'purpose' => 'Field Trip/Educational Tour',
            'purpose_other' => null,
        ]);
    }

    public function test_medical_booking_rejects_a_purpose_outside_the_locked_list(): void
    {
        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'purpose' => 'Vacation',
            ]))
            ->assertSessionHasErrors('purpose');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_others_purpose_requires_specify_text(): void
    {
        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'purpose' => ClearanceRecord::PURPOSE_OTHERS,
                // no purpose_other
            ]))
            ->assertSessionHasErrors('purpose_other');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_others_purpose_persists_with_its_specify_text(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'purpose' => ClearanceRecord::PURPOSE_OTHERS,
                'purpose_other' => 'Regional quiz bee at PSU Lubao',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'purpose' => 'Others',
            'purpose_other' => 'Regional quiz bee at PSU Lubao',
        ]);
    }

    public function test_stray_specify_text_is_dropped_for_a_listed_purpose(): void
    {
        // The student typed a specify text, then switched back to a listed
        // purpose — prepareForValidation clears the leftover server-side.
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'purpose' => 'Sports Activities',
                'purpose_other' => 'leftover text',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'purpose' => 'Sports Activities',
            'purpose_other' => null,
        ]);
    }

    public function test_purpose_other_over_120_chars_is_rejected(): void
    {
        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'purpose' => ClearanceRecord::PURPOSE_OTHERS,
                'purpose_other' => str_repeat('a', 121),
            ]))
            ->assertSessionHasErrors('purpose_other');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_dental_booking_does_not_require_a_purpose(): void
    {
        // Dental is scheduling-only — no clearance form — so purpose is exempt.
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), [
                'service' => 'dental',
                'date' => $this->futureDate(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'service_type' => 'dental',
            'purpose' => null,
            'purpose_other' => null,
        ]);
    }

    public function test_dental_booking_ignores_any_supplied_purpose(): void
    {
        // A crafted request can't smuggle a purpose onto a dental booking —
        // prepareForValidation nulls it server-side.
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), [
                'service' => 'dental',
                'date' => $this->futureDate(),
                'purpose' => 'Sports Activities',
                'purpose_other' => 'sneaky',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'service_type' => 'dental',
            'purpose' => null,
            'purpose_other' => null,
        ]);
    }
}
