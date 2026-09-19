<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * medical bookings and D-37 made a one-hour time slot required for every
     * booking, so both are always present — override any key.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function medicalBooking(array $overrides = []): array
    {
        return array_merge([
            'service' => 'medical',
            'date' => $this->futureDate(),
            'time' => '07:00:00',
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
        // The slot must be one that hasn't ended yet at 09:00 (BR-23).
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00', 'Asia/Manila'));

        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
                'time' => '10:00:00',
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('appointments', 1);
    }

    // ── 2b. Same-day closing cutoff (BR-20) ───────────────────────────────────

    public function test_today_is_bookable_one_minute_before_cutoff(): void
    {
        // 16:59 — clinic still open, same-day booking allowed. The only hour
        // that hasn't ended yet is the last one, 4–5 PM (BR-23).
        Carbon::setTestNow(Carbon::parse('2026-07-08 16:59', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
                'time' => '16:00:00',
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
        $carbon = Carbon::parse($date);

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

    // ── 3b. Per-hour slot capacity (D-37, FR-STU-03/04) ───────────────────────

    /** Fill $count seats of one hour on one date. */
    private function fillSlot(string $date, string $slot, int $count): void
    {
        Appointment::factory()->count($count)->inSlot($slot)->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);
    }

    public function test_a_booking_persists_its_chosen_slot(): void
    {
        $student = $this->student();
        $date = $this->futureDate();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '13:00:00',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'scheduled_time' => '13:00:00',
        ]);
        $this->assertSame($date, Appointment::firstOrFail()->scheduled_date->toDateString());
    }

    public function test_a_booking_without_a_time_is_rejected(): void
    {
        $payload = $this->medicalBooking();
        unset($payload['time']);

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $payload)
            ->assertSessionHasErrors('time');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_time_outside_the_slot_grid_is_rejected(): void
    {
        // 6 AM is before opening and 07:30 is not on the hour — neither is a slot.
        foreach (['06:00:00', '07:30:00', '17:00:00'] as $notASlot) {
            $this->actingAs($this->student())
                ->post(route('student.appointments.store'), $this->medicalBooking(['time' => $notASlot]))
                ->assertSessionHasErrors('time');
        }

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_the_twelfth_booking_in_a_slot_succeeds(): void
    {
        $date = $this->futureDate();
        $student = $this->student();

        $this->fillSlot($date, '09:00:00', 11);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '09:00:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'scheduled_time' => '09:00:00',
        ]);
    }

    public function test_the_thirteenth_booking_in_a_slot_is_rejected_naming_the_hour(): void
    {
        $date = $this->futureDate();

        $this->fillSlot($date, '09:00:00', 12);

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '09:00:00',
            ]))
            ->assertSessionHasErrors([
                'time' => 'The 9:00 AM – 10:00 AM slot is fully booked. Please pick another time.',
            ]);

        $this->assertSame(12, Appointment::where('scheduled_time', '09:00:00')->count());
    }

    public function test_a_full_slot_does_not_block_the_next_hour(): void
    {
        $date = $this->futureDate();
        $student = $this->student();

        $this->fillSlot($date, '09:00:00', 12);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '10:00:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_cancelled_appointments_free_their_slot_seat(): void
    {
        $date = $this->futureDate();

        Appointment::factory()->count(12)->cancelled()->inSlot('09:00:00')->create([
            'scheduled_date' => $date,
        ]);

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '09:00:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * The race the Form Request's unlocked read cannot see (FR-STU-04, D-37).
     *
     * DB::listen fires the moment the Form Request's per-slot COUNT returns —
     * it saw 11 of 12 seats and let the booking through. A competing student
     * then takes the last seat. Only the locked re-check inside the controller's
     * transaction can catch that, so this test fails if per-slot enforcement
     * ever moves into the Form Request alone.
     */
    public function test_only_one_booking_wins_the_last_seat_in_a_slot(): void
    {
        $date = $this->futureDate();
        $slot = '09:00:00';

        $this->fillSlot($date, $slot, 11);

        $competitorBooked = false;

        DB::listen(function ($query) use (&$competitorBooked, $date, $slot): void {
            if ($competitorBooked) {
                return;
            }
            if (! str_contains($query->sql, 'scheduled_time') || ! str_contains(strtolower($query->sql), 'count(*)')) {
                return;
            }

            $competitorBooked = true; // set FIRST — the insert below re-enters this listener
            $this->fillSlot($date, $slot, 1);
        });

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => $slot,
            ]))
            ->assertSessionHasErrors('time');

        $this->assertTrue($competitorBooked, 'The competing booking was never injected — the test no longer models the race.');
        $this->assertSame(12, Appointment::where('scheduled_time', $slot)->where('status', '!=', 'cancelled')->count());
    }

    // ── 3b-ii. Elapsed hours on today (BR-23) ─────────────────────────────────

    /** The exact case Nat reported: it is 12:00 and 11–12 is still offered. */
    public function test_an_hour_that_already_passed_today_cannot_be_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
                'time' => '11:00:00',
            ]))
            ->assertSessionHasErrors([
                'time' => 'The 11:00 AM – 12:00 PM slot has already passed. Please pick a later time today.',
            ]);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_the_current_hour_is_still_bookable_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
                'time' => '12:00:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', ['scheduled_time' => '12:00:00']);
    }

    public function test_an_hour_survives_until_it_actually_ends(): void
    {
        // 11:59 — still inside the 11 AM hour, so it remains bookable.
        Carbon::setTestNow(Carbon::parse('2026-07-27 11:59', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->toDateString(),
                'time' => '11:00:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_a_passed_hour_is_still_bookable_on_a_later_date(): void
    {
        // The hour only dies on TODAY — tomorrow's 7 AM is untouched.
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => today()->addDay()->toDateString(),
                'time' => '07:00:00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_availability_marks_passed_hours_as_elapsed_not_full(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $response = $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => 2026, 'month' => 7, 'date' => today()->toDateString(),
            ]))
            ->assertOk();

        $slots = collect($response->json('slots'))->keyBy('value');

        // 11 AM: gone, but not because it filled up.
        $this->assertTrue($slots['11:00:00']['elapsed']);
        $this->assertFalse($slots['11:00:00']['full']);
        $this->assertFalse($slots['11:00:00']['available']);

        // Noon onwards: still open.
        $this->assertFalse($slots['12:00:00']['elapsed']);
        $this->assertTrue($slots['12:00:00']['available']);
        $this->assertTrue($slots['16:00:00']['available']);
    }

    public function test_today_is_greyed_out_once_no_hour_is_left(): void
    {
        // 16:30 — only the 4–5 PM hour remains, and it is full.
        Carbon::setTestNow(Carbon::parse('2026-07-27 16:30', 'Asia/Manila'));

        $this->fillSlot(today()->toDateString(), '16:00:00', 12);

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', ['year' => 2026, 'month' => 7]))
            ->assertOk()
            ->assertJsonPath('full_days', fn ($days) => in_array(27, $days, true));
    }

    public function test_today_stays_open_while_one_hour_remains(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 16:30', 'Asia/Manila'));

        // 4–5 PM has one seat left, so today is still bookable.
        $this->fillSlot(today()->toDateString(), '16:00:00', 11);

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', ['year' => 2026, 'month' => 7]))
            ->assertOk()
            ->assertJsonPath('full_days', fn ($days) => ! in_array(27, $days, true));
    }

    // ── 3c. Slot availability in the JSON endpoint (FR-STU-03) ────────────────

    public function test_availability_reports_remaining_seats_per_slot(): void
    {
        $date = $this->futureDate(5);
        $carbon = Carbon::parse($date);

        $this->fillSlot($date, '09:00:00', 12);
        $this->fillSlot($date, '10:00:00', 5);

        $response = $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => $carbon->year,
                'month' => $carbon->month,
                'date' => $date,
            ]))
            ->assertOk();

        $slots = collect($response->json('slots'))->keyBy('value');

        $this->assertCount(10, $slots, 'The clinic day is ten one-hour slots.');
        $this->assertSame(12, $slots['09:00:00']['booked']);
        $this->assertSame(0, $slots['09:00:00']['remaining']);
        $this->assertTrue($slots['09:00:00']['full']);
        $this->assertSame(7, $slots['10:00:00']['remaining']);
        $this->assertFalse($slots['10:00:00']['full']);
        $this->assertSame(12, $slots['07:00:00']['remaining']);
    }

    public function test_availability_omits_slots_when_no_date_is_given(): void
    {
        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', ['year' => 2026, 'month' => 8]))
            ->assertOk()
            ->assertJsonMissingPath('slots');
    }

    public function test_a_day_with_every_slot_full_is_reported_as_a_full_day(): void
    {
        $date = $this->futureDate(5);
        $carbon = Carbon::parse($date);

        // 10 slots × 12 = 120 appointments — the day has no free hour left.
        foreach (app(ClinicScheduleService::class)->slots() as $slot) {
            $this->fillSlot($date, $slot, 12);
        }

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => $carbon->year,
                'month' => $carbon->month,
            ]))
            ->assertOk()
            ->assertJsonPath('full_days', fn ($days) => in_array($carbon->day, $days, true));
    }

    public function test_a_day_with_one_free_hour_is_not_a_full_day(): void
    {
        $date = $this->futureDate(5);
        $carbon = Carbon::parse($date);

        $slots = app(ClinicScheduleService::class)->slots();

        // Every slot but the last one is at capacity — 108 of 120 booked.
        foreach (array_slice($slots, 0, -1) as $slot) {
            $this->fillSlot($date, $slot, 12);
        }

        $this->actingAs($this->student())
            ->getJson(route('student.appointments.availability', [
                'year' => $carbon->year,
                'month' => $carbon->month,
            ]))
            ->assertOk()
            ->assertJsonPath('full_days', fn ($days) => ! in_array($carbon->day, $days, true));
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

    public function test_duplicate_check_stays_per_date_not_per_slot(): void
    {
        // D-37 left BR-04 per DATE, not per slot, and D-54 kept that for
        // SELF-bookings: a student who self-booked 7 AM medical still cannot
        // self-book a second medical one at 2 PM the same day. (A batch-made
        // appointment no longer counts toward BR-04 — see section 4b.)
        $date = $this->futureDate();
        $student = $this->student();

        Appointment::factory()->inSlot('07:00:00')->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '14:00:00',
            ]))
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('appointments', 1);
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

    // ── 4b. Already scheduled by the college (D-54, BR-25) ──────────────────

    /**
     * A batch holding $student for $blocks hours from $start on $date. An
     * approved one also carries the student's generated appointment in the
     * FIRST hour of the span, the way the Director's fan-out assigns it.
     */
    private function batchFor(User $student, string $status, string $date, string $start, int $blocks = 2): BatchRequest
    {
        static $seq = 500;

        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $college->id]);

        $batch = BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => $date,
            'scheduled_date' => $status === 'approved' ? $date : null,
            'requested_time' => $start,
            'requested_blocks' => $blocks,
            'status' => $status,
        ]);

        $row = BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]);

        if ($status === 'approved') {
            $appointment = Appointment::factory()->medical()->inSlot($start)->create([
                'student_id' => $student->id,
                'scheduled_date' => $date,
                'source' => 'batch',
                'batch_request_id' => $batch->id,
            ]);
            $row->update(['appointment_id' => $appointment->id]);
        }

        return $batch;
    }

    /** Nat's reported case: the College Admin already has this student at 9 AM on Thu, Sep 10. */
    public function test_self_booking_inside_a_pending_batch_span_is_refused_with_the_college_admin_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $student = $this->student();
        $batch = $this->batchFor($student, 'pending', '2026-09-10', '09:00:00', 2);

        $response = $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking([
                'date' => '2026-09-10',
                'time' => '09:00:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.time.0', 'Thu, Sep 10, 9:00 AM – 10:00 AM has already been scheduled for you by your college admin.');

        // The student is never shown the batch reference (or who else is on it).
        $this->assertStringNotContainsString($batch->reference_no, $response->getContent());
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_self_booking_outside_the_batch_span_on_the_same_date_is_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $student = $this->student();
        $this->batchFor($student, 'pending', '2026-09-10', '09:00:00', 2);

        $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking([
                'date' => '2026-09-10',
                'time' => '14:00:00',
            ]))
            ->assertOk();

        $this->assertDatabaseHas('appointments', ['student_id' => $student->id, 'scheduled_time' => '14:00:00']);
    }

    /**
     * D-54 decision 4: BR-04 counts SELF-bookings only. An approved 9 AM batch
     * appointment must not forbid a 2 PM self-booking of the same service —
     * the hours don't overlap, so decision 1 allows it.
     */
    public function test_an_approved_batch_appointment_does_not_stop_a_later_self_booking_of_the_same_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $student = $this->student();
        $this->batchFor($student, 'approved', '2026-09-10', '09:00:00', 1);

        $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking([
                'date' => '2026-09-10',
                'time' => '14:00:00',
            ]))
            ->assertOk();

        $this->assertSame(2, Appointment::where('student_id', $student->id)->where('service_type', 'medical')->count());
    }

    public function test_an_approved_batch_still_blocks_the_rest_of_its_span(): void
    {
        // The student was assigned 9 AM, but the batch holds 9–11 for everyone on it.
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $student = $this->student();
        $this->batchFor($student, 'approved', '2026-09-10', '09:00:00', 2);

        $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking([
                'date' => '2026-09-10',
                'time' => '10:00:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('time');

        $this->assertSame(1, Appointment::where('student_id', $student->id)->count());
    }

    public function test_a_student_withdrawn_from_a_batch_can_self_book_inside_its_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $student = $this->student();
        $batch = $this->batchFor($student, 'approved', '2026-09-10', '09:00:00', 2);
        Appointment::where('batch_request_id', $batch->id)->update(['status' => 'cancelled']);

        $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking([
                'date' => '2026-09-10',
                'time' => '09:00:00',
            ]))
            ->assertOk();

        $this->assertDatabaseHas('appointments', ['student_id' => $student->id, 'source' => 'self', 'status' => 'scheduled']);
    }

    /**
     * The race the Form Request's unlocked read cannot see: the College Admin
     * submits a batch holding this student the moment the Form Request's clash
     * query returns. Only the locked re-check inside store() can catch it.
     */
    public function test_a_batch_submitted_mid_booking_is_caught_by_the_locked_recheck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $student = $this->student();
        $batchInjected = false;

        DB::listen(function ($query) use (&$batchInjected, $student): void {
            if ($batchInjected || ! str_contains($query->sql, 'batch_request_students')) {
                return;
            }

            $batchInjected = true; // set FIRST — the inserts below re-enter this listener
            $this->batchFor($student, 'pending', '2026-09-10', '09:00:00', 1);
        });

        $this->actingAs($student)
            ->postJson(route('student.appointments.store'), $this->medicalBooking([
                'date' => '2026-09-10',
                'time' => '09:00:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('time');

        $this->assertTrue($batchInjected, 'The competing batch was never injected — the test no longer models the race.');
        $this->assertDatabaseCount('appointments', 0);
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

    /**
     * D-39: a batch appointment belongs to the cohort the College Admin booked,
     * so only that admin may withdraw it — otherwise a student could silently
     * drop out of a graduation batch and leave the college's roster wrong. This
     * is also the statement the FR-STU-12 email makes to batch students, so it
     * has to be true on the server, not just hidden in the UI.
     */
    public function test_student_cannot_cancel_a_batch_booked_appointment(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->futureDate(3),
            'status' => 'scheduled',
            'source' => 'batch',
        ]);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'scheduled',
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

    public function test_booking_page_renders_the_slot_picker_from_config(): void
    {
        // The picker's options are DERIVED from clinic_hours — shrink the day
        // and the page must follow, which a hardcoded list would not.
        config(['healthpass.clinic_hours.open' => '09:00']);
        config(['healthpass.clinic_hours.close' => '12:00']);

        $this->actingAs($this->student())
            ->get(route('student.appointments'))
            ->assertOk()
            ->assertSee('Step 3 — Pick a Time')
            // The labels reach the page as JSON inside the Alpine component
            // (en dashes are \u-escaped there), so assert on the view data.
            ->assertViewHas('slots', fn (array $slots): bool => $slots === [
                ['value' => '09:00:00', 'label' => '9:00 AM – 10:00 AM'],
                ['value' => '10:00:00', 'label' => '10:00 AM – 11:00 AM'],
                ['value' => '11:00:00', 'label' => '11:00 AM – 12:00 PM'],
            ])
            ->assertViewHas('slotCapacity', 12);
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

    public function test_a_dental_booking_is_rejected(): void
    {
        // D-60: medical clearance is the clinic's only service, so a crafted
        // request naming any other one never reaches the database.
        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking(['service' => 'dental']))
            ->assertSessionHasErrors('service');

        $this->assertDatabaseCount('appointments', 0);
    }
}
