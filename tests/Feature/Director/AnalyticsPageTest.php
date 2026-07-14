<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Director Analytics — Medical Cases by College (FR-ANL-02):
 *  - the PRD acceptance dataset (3 Respiratory in CCS, 2 in CEA) produces
 *    exactly those bars, all colleges present, sorted by volume;
 *  - encoded records only (FR-ANL-07);
 *  - one count per record × category (D-23);
 *  - grouped by the capture-time college snapshot, so a transfer never
 *    re-attributes a past case (FR-STU-09, D-17).
 */
class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private College $cbs;

    private User $director;

    private User $nurse;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);
        $this->cbs = College::create(['code' => 'CBS', 'name' => 'College of Business Studies']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->student = User::factory()->create(['role' => 'student']);
    }

    /**
     * Persist one visit + clearance with the given case categories, frozen
     * to $college (the capture-time snapshot column, not the profile).
     */
    private function makeCase(College $college, array $categories, string $visitStatus = 'encoded'): ClearanceRecord
    {
        static $seq = 7000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $this->student->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => $visitStatus,
            'checked_in_at' => now(),
        ]);

        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);

        foreach ($categories as $category) {
            $record->caseCategories()->create(['case_category' => $category]);
        }

        return $record;
    }

    /** The dataset values for one category, in the chart's college order. */
    private function seriesFor(array $chart, string $category): array
    {
        foreach ($chart['datasets'] as $dataset) {
            if ($dataset['label'] === $category) {
                return $dataset['data'];
            }
        }

        $this->fail("No dataset for category {$category}");
    }

    public function test_guests_and_other_roles_cannot_open_analytics(): void
    {
        $this->get('/director/analytics')->assertRedirect('/login');

        // The role middleware bounces other roles to their own home.
        $this->actingAs($this->nurse)->get('/director/analytics')->assertRedirect();
    }

    public function test_prd_acceptance_dataset_produces_exactly_those_bars(): void
    {
        // AC: 3 Respiratory System cases in CCS, 2 in CEA.
        $this->makeCase($this->ccs, ['Respiratory System']);
        $this->makeCase($this->ccs, ['Respiratory System']);
        $this->makeCase($this->ccs, ['Respiratory System']);
        $this->makeCase($this->cea, ['Respiratory System']);
        $this->makeCase($this->cea, ['Respiratory System']);

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertSee('Medical Cases by College')
            ->assertSee('total cases');

        $chart = $response->viewData('chart');

        // Sorted by volume descending; zero-case CBS still gets a row.
        $this->assertSame(['CCS', 'CEA', 'CBS'], $chart['labels']);
        $this->assertSame([3, 2, 0], $this->seriesFor($chart, 'Respiratory System'));
        $this->assertSame(5, $response->viewData('totalCases'));

        // One dataset per medical system (all 8), the others all zero.
        $this->assertCount(count(ClearanceRecord::CASE_CATEGORIES), $chart['datasets']);
        $this->assertSame([0, 0, 0], $this->seriesFor($chart, 'Cardiovascular System'));
    }

    public function test_multi_category_case_counts_once_in_each_system(): void
    {
        // D-23: one record spanning two systems → one count in each.
        $this->makeCase($this->ccs, ['Cardiovascular System', 'Respiratory System']);

        $response = $this->actingAs($this->director)->get('/director/analytics');
        $chart = $response->viewData('chart');

        $this->assertSame(1, $this->seriesFor($chart, 'Cardiovascular System')[0]);
        $this->assertSame(1, $this->seriesFor($chart, 'Respiratory System')[0]);
        $this->assertSame(2, $response->viewData('totalCases'));
    }

    public function test_unencoded_visits_and_uncategorized_records_count_nothing(): void
    {
        // FR-ANL-07: a not-yet-encoded visit never enters case statistics —
        // even if a categorized record somehow existed for it.
        $this->makeCase($this->ccs, ['Respiratory System'], visitStatus: 'captured');

        // Encoded but no case category → contributes nothing (FR-ANL-03 rule).
        $this->makeCase($this->cea, []);

        $response = $this->actingAs($this->director)->get('/director/analytics');

        $this->assertSame(0, $response->viewData('totalCases'));
    }

    public function test_college_snapshot_wins_over_current_profile_college(): void
    {
        // D-17: the case was captured under CEA; the student has since
        // transferred to CCS. The bar must stay with CEA.
        StudentProfile::factory()
            ->forCollege($this->ccs)
            ->create(['user_id' => $this->student->id]);

        $this->makeCase($this->cea, ['Alimentary System']);

        $chart = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->viewData('chart');

        $this->assertSame(['CEA', 'CBS', 'CCS'], $chart['labels']);
        $this->assertSame([1, 0, 0], $this->seriesFor($chart, 'Alimentary System'));
    }
}
