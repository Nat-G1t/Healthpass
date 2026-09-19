<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Appointment;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FR-KSK-03a (D-61) — the schedule check after Identity Confirm.
 *
 * The kiosk shows "No Clinic Schedule Today" between Identity Confirm and
 * Privacy Consent when the student holds NO `scheduled` appointment dated
 * today, and that screen only leads back to Welcome — there are no walk-ins.
 * The decision is made SERVER-SIDE: the identity payload carries a
 * `hasAppointmentToday` boolean that the front-end uses purely to pick which
 * screen to show. These tests assert that boolean for the scan endpoint (login
 * shares the exact same payload builder).
 *
 * The submit endpoint refuses such a student on its own (KioskSubmitTest), so
 * this screen is a courtesy, not the gate.
 */
class KioskScheduleCheckTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A student user + profile with a known qr_token. */
    private function student(string $token = 'KIOSK-SCHEDULE-TOKEN'): StudentProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        return StudentProfile::factory()->forCollege($this->college())->create([
            'user_id' => $user->id,
            'qr_token' => $token,
        ]);
    }

    /** Resolve the identity payload the way the kiosk front-end does (QR scan). */
    private function scan(StudentProfile $profile): array
    {
        return $this->postJson(route('kiosk.scan'), ['token' => $profile->qr_token])
            ->assertOk()
            ->json('identity');
    }

    // ── The gate boolean ──────────────────────────────────────────────────────

    public function test_student_with_no_appointment_today_gets_the_no_schedule_screen(): void
    {
        $profile = $this->student();

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_student_with_a_scheduled_appointment_today_goes_on(): void
    {
        $profile = $this->student();
        Appointment::factory()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        // Straight to Privacy Consent.
        $this->assertTrue($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_an_appointment_at_a_different_hour_today_still_goes_on(): void
    {
        $profile = $this->student();
        // Whatever the hour, it is today's appointment — the kiosk never
        // turns a student away for arriving outside their slot.
        Appointment::factory()->inSlot('16:00:00')->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->assertTrue($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_cancelled_same_day_appointment_gets_the_no_schedule_screen(): void
    {
        $profile = $this->student();
        Appointment::factory()->cancelled()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
        ]);

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_an_already_completed_appointment_today_gets_the_no_schedule_screen(): void
    {
        $profile = $this->student();
        // Encoded this morning. Submit links only a `scheduled` appointment, so
        // letting the student through here would refuse them after every vital.
        Appointment::factory()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
        ]);

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_appointment_on_another_day_does_not_count(): void
    {
        $profile = $this->student();
        Appointment::factory()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'scheduled',
        ]);
        Appointment::factory()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_another_students_appointment_today_does_not_count(): void
    {
        $profile = $this->student('KIOSK-SCHEDULE-A');
        $other = $this->student('KIOSK-SCHEDULE-B');

        Appointment::factory()->create([
            'student_id' => $other->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }
}
