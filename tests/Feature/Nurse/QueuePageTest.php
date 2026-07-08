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
 * FR-NRS-01 — Nurse Live Queue: lists status = 'captured' visits oldest first
 * (first come, first served), tags the longest-waiting row "NEXT", and shows
 * inline vitals + flag badges. Encoded visits have left the queue.
 */
class QueuePageTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /**
     * A captured visit for a freshly-named student, checked in at $checkedInAt.
     * Flags default off; pass overrides to exercise the Flags column.
     *
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

    // ── 1. Access control ─────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('nurse.queue'))->assertRedirect(route('login'));
    }

    public function test_non_nurse_role_is_redirected_to_own_dashboard(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('nurse.queue'))
            ->assertRedirect('/student/dashboard');
    }

    public function test_nurse_can_access_queue(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk();
    }

    // ── 2. Queue contents ─────────────────────────────────────────────────────

    public function test_empty_queue_shows_clear_state(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('Queue is clear')
            ->assertSeeText('0 students waiting');
    }

    public function test_captured_visit_appears_in_queue(): void
    {
        $this->makeVisit('Ana Cruz', now()->subMinutes(5)->toDateTimeString());

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('Ana Cruz')
            ->assertSeeText('1 student waiting');
    }

    public function test_encoded_visit_is_excluded_from_queue(): void
    {
        $visit = $this->makeVisit('Encoded Student', now()->subMinutes(5)->toDateTimeString());
        $visit->update(['status' => 'encoded']);

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertDontSee('Encoded Student')
            ->assertSee('Queue is clear');
    }

    // ── 3. Ordering — oldest first, top row tagged NEXT ───────────────────────

    public function test_visits_are_ordered_oldest_first(): void
    {
        // Created newest-first to prove ordering is by check-in, not insert order.
        $this->makeVisit('Newest Student', now()->subMinutes(1)->toDateTimeString());
        $this->makeVisit('Oldest Student', now()->subMinutes(30)->toDateTimeString());
        $this->makeVisit('Middle Student', now()->subMinutes(10)->toDateTimeString());

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSeeInOrder(['Oldest Student', 'Middle Student', 'Newest Student']);
    }

    public function test_longest_waiting_row_is_tagged_next(): void
    {
        $this->makeVisit('First In Line', now()->subMinutes(20)->toDateTimeString());
        $this->makeVisit('Second In Line', now()->subMinutes(2)->toDateTimeString());

        // "Next" must render before the second student → it tags the top row.
        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('Next')
            ->assertSeeInOrder(['First In Line', 'Next', 'Second In Line']);
    }

    // ── 4. Flags column ───────────────────────────────────────────────────────

    public function test_flagged_vitals_render_badges(): void
    {
        $this->makeVisit('Feverish Student', now()->subMinutes(5)->toDateTimeString(), [
            'temperature_c' => 38.4,
            'is_temp_flagged' => true,
            'bp_systolic' => 150,
            'bp_diastolic' => 95,
            'is_bp_flagged' => true,
        ]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('Temp')
            ->assertSee('BP');
    }
}
