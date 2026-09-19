<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D-37 — appointments created BEFORE the hourly-slot change have
 * `scheduled_time` NULL and were deliberately not backfilled.
 *
 * They belong to no slot, so they must: render as an em dash rather than
 * blowing up on a null, stay invisible to the per-slot counters, still be
 * counted by the outer daily cap, and pass through the nurse queue untouched.
 * (D-61 removed the student booking page these were first checked through.)
 */
class LegacyAppointmentTimeTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    /** A pre-D-37 appointment: a date, no time. */
    private function legacyAppointment(User $student, string $date): Appointment
    {
        return Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => $date,
            'scheduled_time' => null,
            'status' => 'scheduled',
        ]);
    }

    // ── Display ──────────────────────────────────────────────────────────────

    public function test_a_legacy_appointment_renders_an_em_dash_for_its_time(): void
    {
        $appointment = $this->legacyAppointment($this->student(), now()->addDay()->toDateString());

        $this->assertNull($appointment->scheduled_time);
        $this->assertSame('—', $appointment->timeLabel());
    }

    public function test_a_slotted_appointment_renders_its_hour(): void
    {
        $appointment = Appointment::factory()->inSlot('13:00:00')->create();

        $this->assertSame('1:00 PM', $appointment->timeLabel());
    }

    public function test_the_student_dashboard_renders_with_a_legacy_appointment(): void
    {
        $student = $this->student();
        $this->legacyAppointment($student, now()->addDays(3)->toDateString());

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertViewHas('nextAppointment');
    }

    // ── Counting ─────────────────────────────────────────────────────────────
    // Since D-61 the counters are read by the College Admin's batch form and
    // the Director's approval — the student booking page they used to feed
    // is gone — so the service is asserted directly.

    private function schedule(): ClinicScheduleService
    {
        return app(ClinicScheduleService::class);
    }

    public function test_legacy_appointments_do_not_occupy_or_fill_any_slot(): void
    {
        $date = now()->addDays(4)->toDateString();

        // Twelve legacy rows on the date — if they leaked into a slot counter
        // the 7 AM hour would read as full.
        Appointment::factory()->count(12)->create([
            'scheduled_date' => $date,
            'scheduled_time' => null,
            'status' => 'scheduled',
        ]);

        foreach ($this->schedule()->slots() as $slot) {
            $this->assertSame(0, $this->schedule()->bookedInSlot($date, $slot), "{$slot} should be empty");
        }

        $this->assertSame([], $this->schedule()->fullSlotsIn($date, $this->schedule()->slots()));
    }

    public function test_legacy_appointments_still_count_toward_the_daily_cap(): void
    {
        // The daily cap is the only counter that can see them, which is why
        // D-37 kept it alongside the per-hour cap.
        config(['healthpass.daily_capacity' => 3]);

        $date = Carbon::parse(now()->addDays(4)->toDateString());

        Appointment::factory()->count(3)->create([
            'scheduled_date' => $date->toDateString(),
            'scheduled_time' => null,
            'status' => 'scheduled',
        ]);

        $this->assertContains($date->day, $this->schedule()->fullDaysForMonth($date->year, $date->month));
    }

    // ── Nurse queue (FR-NRS-01) ──────────────────────────────────────────────

    public function test_the_nurse_queue_renders_a_visit_from_a_legacy_appointment(): void
    {
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $nurse = User::factory()->create(['role' => 'nurse']);
        $student = $this->student();
        StudentProfile::factory()->forCollege($college)->create(['user_id' => $student->id]);

        $appointment = $this->legacyAppointment($student, today()->toDateString());

        ClinicVisit::create([
            'reference_no' => 'HP-'.now()->year.'-0001',
            'student_id' => $student->id,
            'appointment_id' => $appointment->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
            'status' => 'captured',
        ]);

        $this->actingAs($nurse)
            ->get(route('nurse.queue'))
            ->assertOk();
    }
}
