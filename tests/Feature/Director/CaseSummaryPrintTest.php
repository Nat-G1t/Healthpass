<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\CollegeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Printable monthly Summary of Medical Cases (FR-ANL-06 / FR-ANL-03):
 *  - month-scoped by the VISIT date (checked_in_at) — a case belongs to
 *    the month the student was seen, not the encode month;
 *  - same counting rules as the on-screen matrix (encoded + categorized
 *    only, one count per record × category, D-23);
 *  - students only — the paper form's FACULTY/NASA columns are omitted;
 *  - a missing/invalid month falls back to the newest month with data.
 */
class CaseSummaryPrintTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    private User $nurse;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // All 12 colleges, so the printed form shows the full fixed-order
        // column set (the paper form has every unit, zero-case included).
        $this->seed(CollegeSeeder::class);
        $this->ccs = College::where('code', 'CCS')->firstOrFail();
        $this->cea = College::where('code', 'CEA')->firstOrFail();

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->student = User::factory()->create(['role' => 'student']);
    }

    /**
     * Persist one encoded, categorized case in $college, checked in at
     * $checkedInAt — the date that decides which month it counts toward.
     */
    private function makeCase(College $college, array $categories, CarbonInterface $checkedInAt): ClearanceRecord
    {
        static $seq = 6000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $this->student->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'checked_in_at' => $checkedInAt,
        ]);

        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'encoded_at' => $checkedInAt->copy()->addDay(),
        ]);

        foreach ($categories as $category) {
            $record->caseCategories()->create(['case_category' => $category]);
        }

        return $record;
    }

    public function test_guests_and_other_roles_cannot_open_the_summary_print(): void
    {
        $this->get('/director/analytics/summary-print')->assertRedirect('/login');

        // The role middleware bounces other roles to their own home.
        $this->actingAs($this->nurse)->get('/director/analytics/summary-print')->assertRedirect();
    }

    public function test_summary_prints_the_official_letterhead_title_and_month(): void
    {
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-12-05'));

        $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-12')
            ->assertOk()
            ->assertSee('PAMPANGA STATE UNIVERSITY')
            ->assertSee('HEALTH SERVICES UNIT')
            ->assertSee('SUMMARY OF MEDICAL CASES')
            ->assertSee('DECEMBER 2025')
            // Standalone print doc marker (the iframe print script keys on it).
            ->assertSee('data-hp-print-doc', escape: false);
    }

    public function test_columns_are_the_twelve_colleges_with_no_faculty_or_nasa(): void
    {
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-12-05'));

        $response = $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-12')
            ->assertOk()
            ->assertSee('(COE)')
            ->assertSee('(CCS)')
            ->assertSee('(LHS)');

        // Students only (PRD FR-ANL-02) — the paper form's extra columns
        // are intentionally omitted.
        $response->assertDontSee('FACULTY');
        $response->assertDontSee('NASA');
    }

    public function test_counts_only_the_requested_months_visits(): void
    {
        // Two Respiratory cases in December, one in November.
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-12-03'));
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-12-20'));
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-11-15'));

        $response = $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-12')
            ->assertOk();

        // December: CCS Respiratory = 2, grand total 2 (November excluded).
        $this->assertSame(2, $response->viewData('counts')[$this->ccs->id]['Respiratory System']);
        $this->assertSame(2, $response->viewData('grandTotal'));

        // Switching to November shows the one case there instead.
        $november = $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-11')
            ->assertOk();
        $this->assertSame(1, $november->viewData('grandTotal'));
    }

    public function test_multi_category_case_counts_once_in_each_system(): void
    {
        // D-23: one record spanning two systems → one count in each.
        $this->makeCase($this->cea, ['Cardiovascular System', 'Respiratory System'], now()->parse('2025-12-10'));

        $response = $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-12')
            ->assertOk();

        $counts = $response->viewData('counts');
        $this->assertSame(1, $counts[$this->cea->id]['Cardiovascular System']);
        $this->assertSame(1, $counts[$this->cea->id]['Respiratory System']);
        $this->assertSame(2, $response->viewData('grandTotal'));
    }

    public function test_captured_and_uncategorized_visits_never_count(): void
    {
        // Captured (un-encoded) → excluded (FR-ANL-07).
        $captured = ClinicVisit::create([
            'reference_no' => 'HP-2026-6900',
            'student_id' => $this->student->id,
            'college_id' => $this->ccs->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'checked_in_at' => now()->parse('2025-12-05'),
        ]);
        $captured->clearanceRecord()->create([
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'encoded_at' => now()->parse('2025-12-06'),
        ])->caseCategories()->create(['case_category' => 'Respiratory System']);

        // Encoded but no category → contributes nothing to the cells.
        $this->makeCase($this->ccs, [], now()->parse('2025-12-07'));

        $response = $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-12')
            ->assertOk();

        $this->assertSame(0, $response->viewData('grandTotal'));
    }

    public function test_missing_or_invalid_month_falls_back_to_newest_with_data(): void
    {
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-10-05'));
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-12-05'));

        // No month param → newest month with data (December).
        $this->actingAs($this->director)
            ->get('/director/analytics/summary-print')
            ->assertOk()
            ->assertSee('DECEMBER 2025');

        // Malformed month (13 is not a month) → same fallback, no error.
        $this->actingAs($this->director)
            ->get('/director/analytics/summary-print?month=2025-13')
            ->assertOk()
            ->assertSee('DECEMBER 2025');
    }

    public function test_analytics_page_offers_the_month_picker_not_csv_exports(): void
    {
        $this->makeCase($this->ccs, ['Respiratory System'], now()->parse('2025-12-05'));

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertSee('Preview &amp; Print', escape: false)
            ->assertSee('December 2025');

        // The removed CSV exports must be gone from the page.
        $response->assertDontSee('Export cases');
        $response->assertDontSee('Export records');

        // And the month picker feeds the print route.
        $months = $response->viewData('availableMonths');
        $this->assertSame([['value' => '2025-12', 'label' => 'December 2025']], $months);
    }
}
