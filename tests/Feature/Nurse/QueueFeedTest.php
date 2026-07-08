<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-NRS-02 / SM-2 — the JSON feed behind the Live Queue poll.
 *
 * Returns captured visits oldest first (FCFS) as a lean payload so the
 * front-end can update the table in place every 4 s. Same nurse-only guard as
 * the page; encoded visits have already left the queue.
 */
class QueueFeedTest extends TestCase
{
    use RefreshDatabase;

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /**
     * @param  array<string, mixed>  $vitals
     */
    private function makeVisit(string $name, string $checkedInAt, array $vitals = []): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('T###'),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => $checkedInAt,
        ]);

        VitalSigns::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ], $vitals));

        return $visit;
    }

    // ── Access control — same guard as the page ───────────────────────────────

    public function test_guest_cannot_read_the_feed(): void
    {
        $this->getJson(route('nurse.queue.feed'))->assertUnauthorized();
    }

    public function test_non_nurse_cannot_read_the_feed(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // The role middleware redirects a wrong-role user away from the group.
        $this->actingAs($student)
            ->get(route('nurse.queue.feed'))
            ->assertRedirect('/student/dashboard');
    }

    public function test_nurse_can_read_the_feed(): void
    {
        $this->actingAs($this->nurse())
            ->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJson(['count' => 0, 'visits' => []]);
    }

    // ── Contents & ordering ───────────────────────────────────────────────────

    public function test_feed_returns_captured_visits_oldest_first(): void
    {
        // Inserted newest-first to prove ordering is by check-in, not insert order.
        $this->makeVisit('Newest Student', now()->subMinutes(1)->toDateTimeString());
        $this->makeVisit('Oldest Student', now()->subMinutes(30)->toDateTimeString());
        $this->makeVisit('Middle Student', now()->subMinutes(10)->toDateTimeString());

        $response = $this->actingAs($this->nurse())
            ->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJsonPath('count', 3);

        $names = array_column($response->json('visits'), 'name');
        $this->assertSame(['Oldest Student', 'Middle Student', 'Newest Student'], $names);
    }

    public function test_encoded_visits_are_excluded(): void
    {
        $visit = $this->makeVisit('Encoded Student', now()->subMinutes(5)->toDateTimeString());
        $visit->update(['status' => 'encoded']);

        $this->actingAs($this->nurse())
            ->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJson(['count' => 0, 'visits' => []]);
    }

    // ── Lean row shape ────────────────────────────────────────────────────────

    public function test_row_carries_the_fields_a_queue_row_needs(): void
    {
        $this->makeVisit('Ana Cruz', now()->subMinutes(5)->toDateTimeString(), [
            'temperature_c' => 38.4,
            'is_temp_flagged' => true,
        ]);

        $response = $this->actingAs($this->nurse())
            ->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJsonPath('visits.0.name', 'Ana Cruz')
            ->assertJsonPath('visits.0.initials', 'AC')
            ->assertJsonPath('visits.0.college', 'College of Computing Studies')
            ->assertJsonPath('visits.0.vitals.is_temp_flagged', true);

        // Humanized time is present and reads as relative.
        $this->assertStringContainsString('ago', $response->json('visits.0.time_human'));
    }
}
