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
 * Director Analytics — Medical Cases by College (FR-ANL-02) and the
 * Summary of Medical Cases matrix (FR-ANL-03):
 *  - the PRD acceptance dataset (3 Respiratory in CCS, 2 in CEA) produces
 *    exactly those bars and matrix cells, all colleges present;
 *  - encoded records only (FR-ANL-07);
 *  - one count per record × category (D-23);
 *  - grouped by the capture-time college snapshot, so a transfer never
 *    re-attributes a past case (FR-STU-09, D-17);
 *  - matrix columns in the FR-ANL-03 fixed order; uncategorized encoded
 *    records excluded from cells but surfaced in a footnote.
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
    private function makeCase(College $college, array $categories, string $visitStatus = 'encoded', ?User $student = null): ClearanceRecord
    {
        static $seq = 7000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => ($student ?? $this->student)->id,
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

    public function test_matrix_shows_prd_acceptance_cells_in_fixed_college_order(): void
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
            ->assertSee('Summary of Medical Cases')
            ->assertSee('Rows = medical system');

        // FR-ANL-03 fixed column order (…CEA, CBS, …CCS…), NOT the chart's
        // volume-descending order — only the three seeded colleges exist here.
        $matrixCodes = $response->viewData('matrixColleges')->pluck('code')->all();
        $this->assertSame(['CEA', 'CBS', 'CCS'], $matrixCodes);

        // Exactly those cells: cells and column totals come from the same
        // $counts/$totals the chart uses; the TOTAL column is categoryTotals.
        $counts = $response->viewData('counts');
        $this->assertSame(3, $counts[$this->ccs->id]['Respiratory System']);
        $this->assertSame(2, $counts[$this->cea->id]['Respiratory System']);
        $this->assertArrayNotHasKey($this->cbs->id, $counts);

        $this->assertSame(
            ['Respiratory System' => 5],
            $response->viewData('categoryTotals'),
        );
    }

    public function test_matrix_grand_total_matches_the_by_sex_donut_source(): void
    {
        // The FR-ANL-04 donut will count ClinicVisit::encoded() rows — the
        // same shared scope the matrix query starts from. On this dataset
        // (every record has exactly one category) the two totals coincide.
        // They diverge BY DESIGN per the PRD AC once records carry multiple
        // categories or no category — the donut counts people screened, the
        // matrix counts record × category pairs.
        $this->makeCase($this->ccs, ['Respiratory System']);
        $this->makeCase($this->ccs, ['Respiratory System']);
        $this->makeCase($this->ccs, ['Respiratory System']);
        $this->makeCase($this->cea, ['Respiratory System']);
        $this->makeCase($this->cea, ['Respiratory System']);

        $response = $this->actingAs($this->director)->get('/director/analytics');

        $this->assertSame(5, $response->viewData('totalCases'));
        $this->assertSame(5, ClinicVisit::encoded()->count());
    }

    public function test_matrix_footnotes_encoded_records_without_category(): void
    {
        // Encoded, no category → excluded from cells, counted in the footnote.
        $this->makeCase($this->cea, []);
        // Captured (un-encoded) → invisible everywhere, footnote included.
        $this->makeCase($this->ccs, ['Respiratory System'], visitStatus: 'captured');

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertSee('1 encoded without category');

        $this->assertSame(1, $response->viewData('uncategorizedCount'));
        $this->assertSame(0, $response->viewData('totalCases'));
    }

    public function test_matrix_footnote_hidden_when_every_record_has_a_category(): void
    {
        $this->makeCase($this->ccs, ['Respiratory System']);

        $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertDontSee('encoded without category');
    }

    /** A student user whose profile has the given sex. */
    private function makeStudentOfSex(string $sex): User
    {
        $student = User::factory()->create(['role' => 'student']);
        StudentProfile::factory()
            ->forCollege($this->ccs)
            ->create(['user_id' => $student->id, 'sex' => $sex]);

        return $student;
    }

    public function test_by_sex_donut_counts_encoded_visits_by_profile_sex(): void
    {
        // FR-ANL-04: 3 male + 1 female encoded visits; a captured visit
        // never enters the donut (FR-ANL-07), whatever the sex.
        $male = $this->makeStudentOfSex('M');
        $female = $this->makeStudentOfSex('F');

        $this->makeCase($this->ccs, ['Respiratory System'], student: $male);
        $this->makeCase($this->ccs, [], student: $male);
        $this->makeCase($this->cea, [], student: $male);
        $this->makeCase($this->ccs, ['Alimentary System'], student: $female);
        $this->makeCase($this->ccs, ['Respiratory System'], visitStatus: 'captured', student: $female);

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertSee('Screened Students by Sex');

        $bySex = $response->viewData('bySex');
        $this->assertSame(['Male', 3, 75], [$bySex[0]['label'], $bySex[0]['count'], $bySex[0]['percent']]);
        $this->assertSame(['Female', 1, 25], [$bySex[1]['label'], $bySex[1]['count'], $bySex[1]['percent']]);

        $this->assertSame(4, $response->viewData('totalScreened'));
        $this->assertSame([3, 1], $response->viewData('donut')['datasets'][0]['data']);
    }

    public function test_donut_shares_the_encoded_scope_but_counts_people_not_cases(): void
    {
        // PRD §4.9 AC: the donut counts people screened (one per encoded
        // visit), the matrix counts record × category pairs — same
        // encoded() base scope, intentionally different totals.
        $male = $this->makeStudentOfSex('M');

        // One person, two systems (D-23): donut 1, matrix 2.
        $this->makeCase($this->ccs, ['Cardiovascular System', 'Respiratory System'], student: $male);

        $response = $this->actingAs($this->director)->get('/director/analytics');
        $this->assertSame(1, $response->viewData('totalScreened'));
        $this->assertSame(2, $response->viewData('totalCases'));

        // One more person, no category: donut counts them, matrix doesn't.
        $this->makeCase($this->ccs, [], student: $male);

        $response = $this->actingAs($this->director)->get('/director/analytics');
        $this->assertSame(2, $response->viewData('totalScreened'));
        $this->assertSame(ClinicVisit::encoded()->count(), $response->viewData('totalScreened'));
        $this->assertSame(2, $response->viewData('totalCases'));
        $this->assertSame(1, $response->viewData('uncategorizedCount'));
    }

    public function test_cases_by_system_chart_splits_each_bar_by_sex(): void
    {
        // FR-ANL-08 with the by-sex split: 2 male + 1 female Respiratory,
        // plus a female multi-category case adding one Respiratory AND one
        // Cardiovascular count (D-23). A captured visit counts nothing
        // (FR-ANL-07).
        $male = $this->makeStudentOfSex('M');
        $female = $this->makeStudentOfSex('F');

        $this->makeCase($this->ccs, ['Respiratory System'], student: $male);
        $this->makeCase($this->cea, ['Respiratory System'], student: $male);
        $this->makeCase($this->ccs, ['Respiratory System'], student: $female);
        $this->makeCase($this->ccs, ['Respiratory System', 'Cardiovascular System'], student: $female);
        $this->makeCase($this->ccs, ['Respiratory System'], visitStatus: 'captured', student: $male);

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertSee('Cases by Medical System');

        $chart = $response->viewData('systemChart');

        // Sorted by total volume descending, all 8 systems present.
        $this->assertCount(8, $chart['labels']);
        $this->assertSame(['Respiratory System', 'Cardiovascular System'], array_slice($chart['labels'], 0, 2));
        $this->assertSame([4, 1, 0], array_slice($chart['totals'], 0, 3));

        // Male segment first, Female second — Respiratory splits 2/2,
        // Cardiovascular 0/1.
        [$maleSet, $femaleSet] = $chart['datasets'];
        $this->assertSame('Male', $maleSet['label']);
        $this->assertSame('Female', $femaleSet['label']);
        $this->assertSame([2, 0], array_slice($maleSet['data'], 0, 2));
        $this->assertSame([2, 1], array_slice($femaleSet['data'], 0, 2));

        // Colors follow the CATEGORY through the sort (Respiratory = the
        // college chart's navy), Female = its 50% white tint.
        $this->assertSame('#1E3A8A', $maleSet['backgroundColor'][0]);
        $this->assertSame('#8E9CC4', $femaleSet['backgroundColor'][0]);

        // Same counting rules as the matrix: bar totals sum to its total.
        $this->assertSame($response->viewData('totalCases'), array_sum($chart['totals']));
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
