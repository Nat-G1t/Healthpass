<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_today_is_bookable(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => today()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('appointments', 1);
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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $date,
            ])
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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $date,
            ])
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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $date,
            ])
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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $date,
            ])
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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $this->futureDate(),
            ]);

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
            ->post(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $this->futureDate(),
            ]);

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
            ->postJson(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $date,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('date');

        // No second appointment was created
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_successful_json_booking_returns_redirect_url(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)
            ->postJson(route('student.appointments.store'), [
                'service' => 'medical',
                'date' => $this->futureDate(),
            ]);

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
}
