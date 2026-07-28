<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
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

    /** A valid dental booking payload (D-37 slot included, D-28 purpose exempt). */
    private function dentalBooking(array $overrides = []): array
    {
        return array_merge([
            'service' => 'dental',
            'date' => $this->futureDate(),
            'time' => '07:00:00',
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

    public function test_medical_and_dental_share_one_slot_counter(): void
    {
        // D-37: the constraint being modelled is clinic congestion, so a dental
        // appointment consumes an hour-seat exactly like a medical one.
        $date = $this->futureDate();

        Appointment::factory()->count(12)->dental()->inSlot('09:00:00')->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->student())
            ->post(route('student.appointments.store'), $this->medicalBooking([
                'date' => $date,
                'time' => '09:00:00',
            ]))
            ->assertSessionHasErrors('time');
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
        // D-37 explicitly left BR-04 alone: a student with a 7 AM medical
        // appointment cannot book a second medical one at 2 PM the same day.
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
            ->post(route('student.appointments.store'), $this->dentalBooking(['date' => $date]))
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

    public function test_dental_booking_does_not_require_a_purpose(): void
    {
        // Dental is scheduling-only — no clearance form — so purpose is exempt.
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('student.appointments.store'), $this->dentalBooking())
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
            ->post(route('student.appointments.store'), $this->dentalBooking([
                'purpose' => 'Sports Activities',
                'purpose_other' => 'sneaky',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'student_id' => $student->id,
            'service_type' => 'dental',
            'purpose' => null,
            'purpose_other' => null,
        ]);
    }
}
