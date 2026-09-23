<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-NRS-09 — the visit page's back link returns to where it was opened from:
 * the Clinic Dashboard (View, with its filters and page kept) or the Live
 * Queue. The dashboard URL is built only from four whitelisted keys, so
 * nothing else in the query string can ride along into the link.
 */
class VisitBackLinkTest extends TestCase
{
    use RefreshDatabase;

    private const DASHBOARD_STATE = ['month' => '2026-09', 'result' => 'Fit', 'q' => 'Cruz', 'page' => '2'];

    private function encodedVisit(string $studentName, User $encoder): ClinicVisit
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $student = User::factory()->create(['role' => 'student', 'name' => $studentName]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('T###'),
            'student_id' => $student->id,
            'college_id' => $college->id,
            'course' => 'Bachelor of Science in Information Technology',
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);

        VitalSigns::create([
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
        ]);

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);

        return $visit;
    }

    public function test_dashboard_view_link_carries_from_and_the_active_filters(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->encodedVisit('Ana Cruz', $nurse);

        $expected = route('nurse.visits.encode', ['visit' => $visit, 'from' => 'dashboard', 'result' => 'Fit', 'q' => 'Cruz']);

        $this->actingAs($nurse)
            // An empty month is dropped, so the link stays clean.
            ->get(route('nurse.dashboard', ['month' => '', 'result' => 'Fit', 'q' => 'Cruz']))
            ->assertOk()
            ->assertSee($expected);
    }

    public function test_opened_from_the_dashboard_it_links_back_with_the_same_filters_and_page(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->encodedVisit('Ana Cruz', $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', ['visit' => $visit, 'from' => 'dashboard'] + self::DASHBOARD_STATE))
            ->assertOk()
            ->assertViewHas('back', [
                'url' => route('nurse.dashboard', self::DASHBOARD_STATE),
                'label' => 'Back to Clinic Dashboard',
            ])
            ->assertSee('Back to Clinic Dashboard')
            ->assertDontSee('Back to Live Queue');
    }

    public function test_without_from_it_links_back_to_the_live_queue(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->encodedVisit('Ana Cruz', $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertViewHas('back', ['url' => route('nurse.queue'), 'label' => 'Back to Live Queue'])
            ->assertSee('Back to Live Queue');
    }

    public function test_unknown_keys_never_reach_the_back_link(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->encodedVisit('Ana Cruz', $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', [
                'visit' => $visit,
                'from' => 'dashboard',
                'result' => 'Fit',
                'evil' => 'https://example.com',
            ]))
            ->assertOk()
            ->assertViewHas('back', fn (array $back): bool => $back['url'] === route('nurse.dashboard', ['result' => 'Fit'])
                && ! str_contains($back['url'], 'evil')
                && ! str_contains($back['url'], 'example.com'));
    }

    public function test_a_physician_gets_the_same_back_link_as_a_nurse(): void
    {
        $physician = User::factory()->physician()->create();
        $visit = $this->encodedVisit('Ana Cruz', $physician);

        $this->actingAs($physician)
            ->get(route('nurse.visits.encode', ['visit' => $visit, 'from' => 'dashboard'] + self::DASHBOARD_STATE))
            ->assertOk()
            ->assertViewHas('back', [
                'url' => route('nurse.dashboard', self::DASHBOARD_STATE),
                'label' => 'Back to Clinic Dashboard',
            ]);
    }
}
