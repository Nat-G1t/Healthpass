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
 * FR-KSK-03a — Walk-in Check ("No Scheduled Clearance Today").
 *
 * The kiosk inserts a screen between Identity Confirm and Privacy Consent that
 * is shown ONLY when the student has NO non-cancelled appointment dated today —
 * medical OR dental (FR-KSK-03a). The decision is made SERVER-SIDE: the identity
 * payload carries a `hasAppointmentToday` boolean that the front-end uses purely
 * to pick which screen to show. These tests assert that boolean for the scan
 * endpoint (login shares the exact same payload builder).
 *
 * Dental now also LINKS at submit (D-33, amending D-3) — that is covered in
 * KioskSubmitTest; here we only assert the UI gate.
 */
class KioskWalkInCheckTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A student user + profile with a known qr_token. */
    private function student(string $token = 'KIOSK-WALKIN-TOKEN'): StudentProfile
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

    public function test_student_with_no_appointment_today_is_flagged_walk_in(): void
    {
        $profile = $this->student();

        // No appointments at all → walk-in screen is shown.
        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_student_with_same_day_medical_appointment_skips_walk_in(): void
    {
        $profile = $this->student();
        Appointment::factory()->medical()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        // A booked medical clearance today → go straight to Privacy Consent.
        $this->assertTrue($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_student_with_same_day_dental_appointment_skips_walk_in(): void
    {
        $profile = $this->student();
        Appointment::factory()->dental()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        // A dental booking today also suppresses the notice (the student has
        // SOMETHING scheduled). It also links at submit now (D-33).
        $this->assertTrue($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_cancelled_same_day_appointment_still_shows_walk_in(): void
    {
        $profile = $this->student();
        Appointment::factory()->medical()->cancelled()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->toDateString(),
        ]);

        // Cancelled doesn't count as scheduled → still a walk-in.
        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_appointment_on_another_day_does_not_count(): void
    {
        $profile = $this->student();
        Appointment::factory()->medical()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'scheduled',
        ]);
        Appointment::factory()->dental()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }

    public function test_another_students_appointment_today_does_not_count(): void
    {
        $profile = $this->student('KIOSK-WALKIN-A');
        $other = $this->student('KIOSK-WALKIN-B');

        // The appointment belongs to a DIFFERENT student.
        Appointment::factory()->medical()->create([
            'student_id' => $other->user_id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->assertFalse($this->scan($profile)['hasAppointmentToday']);
    }
}
