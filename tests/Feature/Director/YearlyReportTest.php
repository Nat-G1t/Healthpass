<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\DirectorYearlyReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * The Director's Yearly Clearance Report (D-94): counts only — every college
 * with its five counts, and every one of its programs underneath, zeros
 * included — for a span of years, downloaded as a PDF.
 *
 * The numbers are checked against App\Services\DirectorYearlyReport directly;
 * the HTTP tests check access, validation, the file name, and the HTML the
 * PDF is rendered from (dompdf is swapped out, PDF bytes are never parsed).
 */
class YearlyReportTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned so "the current year" is 2026 in every test.
        $this->travelTo(now()->setDate(2026, 9, 29)->setTime(10, 0));

        // A small, fixed catalog, so "every program" is known exactly.
        config([
            'programs.CCS.programs' => ['BSIT', 'BSCS'],
            'programs.COE.programs' => ['BSEd'],
        ]);

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Education']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** One visit for a new student of `$sex`, captured under `$college` / `$program`. */
    private function makeVisit(
        College $college,
        ?string $program,
        string $checkedInAt,
        string $result = 'Fit',
        string $sex = 'M',
        string $status = 'encoded',
    ): ClinicVisit {
        static $seq = 5000;

        $student = User::factory()->create(['role' => 'student', 'name' => 'Student '.$seq]);
        StudentProfile::factory()->forCollege($college)->create(['user_id' => $student->id, 'sex' => $sex]);

        // No batch: the Director's report counts every encoded visit.
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->parse($checkedInAt)->toDateString(),
            'status' => 'completed',
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'appointment_id' => $appointment->id,
            'course' => $program,
            'login_method' => 'qr',
            'status' => $status,
            'checked_in_at' => now()->parse($checkedInAt),
        ]);

        if ($status === 'encoded') {
            ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $this->nurse->id,
                'result' => $result,
                'encoded_at' => now(),
            ]);
        }

        return $visit;
    }

    /** @return array<string, array<string, mixed>> the year's colleges keyed by code */
    private function colleges(int $year = 2026): array
    {
        return collect((new DirectorYearlyReport($year))->colleges())->keyBy('code')->all();
    }

    /** @return array<string, array<string, int>> one college's program counts keyed by program */
    private function programs(string $code, int $year = 2026): array
    {
        return collect($this->colleges($year)[$code]['programs'])->pluck('counts', 'program')->all();
    }

    private function download(string $query, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->director)->get('/director/analytics/yearly-report'.$query);
    }

    /**
     * Download with dompdf swapped out, and hand back the view name and data.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function capturePdf(string $query): array
    {
        $captured = [];

        $pdf = Mockery::mock(DomPdf::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturnUsing(
            fn (string $name): Response => new Response('%PDF', 200, ['Content-Disposition' => "attachment; filename={$name}"]),
        );

        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function (string $view, array $data) use (&$captured, $pdf) {
            $captured = [$view, $data];

            return $pdf;
        });

        $this->download($query)->assertOk();

        return $captured;
    }

    // ── The numbers ──────────────────────────────────────────────────────────

    public function test_every_college_and_every_catalog_program_appears_even_at_zero(): void
    {
        $colleges = $this->colleges();

        $this->assertSame(['CCS', 'COE'], array_keys($colleges));
        $this->assertSame(['BSIT', 'BSCS'], array_keys($this->programs('CCS')));
        $this->assertSame(['BSEd'], array_keys($this->programs('COE')));
        $this->assertSame(['total' => 0, 'fit' => 0, 'unfit' => 0, 'male' => 0, 'female' => 0], $colleges['COE']['counts']);
    }

    public function test_a_program_row_counts_took_clearance_fit_unfit_male_and_female(): void
    {
        $this->makeVisit($this->ccs, 'BSIT', '2026-03-01 09:00', 'Fit', 'M');
        $this->makeVisit($this->ccs, 'BSIT', '2026-03-02 09:00', 'Unfit', 'F');
        $this->makeVisit($this->ccs, 'BSIT', '2026-03-03 09:00', 'Fit', 'F');
        $this->makeVisit($this->ccs, 'BSCS', '2026-03-04 09:00', 'Unfit', 'M');

        $programs = $this->programs('CCS');

        $this->assertSame(['total' => 3, 'fit' => 2, 'unfit' => 1, 'male' => 1, 'female' => 2], $programs['BSIT']);
        $this->assertSame(['total' => 1, 'fit' => 0, 'unfit' => 1, 'male' => 1, 'female' => 0], $programs['BSCS']);
        // The college's line is its programs added up.
        $this->assertSame(['total' => 4, 'fit' => 2, 'unfit' => 2, 'male' => 2, 'female' => 2], $this->colleges()['CCS']['counts']);
    }

    public function test_a_program_outside_the_catalog_still_counts_on_its_own_row(): void
    {
        $this->makeVisit($this->ccs, null, '2026-03-01 09:00');            // pre-D-43, none recorded
        $this->makeVisit($this->ccs, 'Old Program', '2026-03-02 09:00');   // since renamed

        $programs = $this->programs('CCS');

        $this->assertSame(['BSIT', 'BSCS', DirectorYearlyReport::NO_PROGRAM, 'Old Program'], array_keys($programs));
        $this->assertSame(2, $this->colleges()['CCS']['counts']['total']);
    }

    public function test_every_encoded_visit_counts_without_a_batch_but_nothing_unencoded(): void
    {
        $this->makeVisit($this->ccs, 'BSIT', '2026-03-01 09:00');
        $this->makeVisit($this->ccs, 'BSIT', '2026-03-02 09:00', status: 'captured');
        $this->makeVisit($this->ccs, 'BSIT', '2026-03-03 09:00', status: 'resting');

        $this->assertSame(1, (new DirectorYearlyReport(2026))->summary()['total']);
    }

    public function test_a_visit_counts_under_the_college_recorded_at_the_visit(): void
    {
        // Captured under COE, whatever the student's profile says today.
        $this->makeVisit($this->coe, 'BSEd', '2026-03-01 09:00');

        $this->assertSame(1, $this->colleges()['COE']['counts']['total']);
        $this->assertSame(0, $this->colleges()['CCS']['counts']['total']);
    }

    public function test_the_year_boundary_follows_manila_time(): void
    {
        $this->makeVisit($this->ccs, 'BSIT', '2025-12-31 23:30');
        $this->makeVisit($this->ccs, 'BSIT', '2026-01-01 00:10');

        $this->assertSame(1, (new DirectorYearlyReport(2025))->summary()['total']);
        $this->assertSame(1, (new DirectorYearlyReport(2026))->summary()['total']);
    }

    // ── The download ─────────────────────────────────────────────────────────

    public function test_the_director_downloads_a_span_as_one_pdf(): void
    {
        $response = $this->download('?from=2025&to=2026')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $response->assertHeader('Content-Disposition', 'attachment; filename=HealthPass-Yearly-Report-All-Colleges-2025-2026.pdf');
    }

    public function test_every_other_role_and_a_guest_are_refused(): void
    {
        $this->get('/director/analytics/yearly-report?from=2026&to=2026')->assertRedirect('/login');

        foreach (['student', 'nurse', 'college_admin'] as $role) {
            $this->download('?from=2026&to=2026', as: User::factory()->create(['role' => $role, 'managed_college_id' => $this->ccs->id]))
                ->assertRedirect();
        }
    }

    public function test_the_span_is_validated_like_the_admin_report(): void
    {
        $this->download('?from=2020&to=2026')->assertSessionHasErrors('from');
        $this->download('?from=2026&to=2027')->assertSessionHasErrors('to');
        $this->download('?from=2026&to=2024')->assertSessionHasErrors('to');
    }

    public function test_the_pdf_opens_with_span_totals_by_college_then_a_section_per_year(): void
    {
        $this->makeVisit($this->ccs, 'BSIT', '2025-05-01 09:00', 'Fit', 'M');
        $this->makeVisit($this->coe, 'BSEd', '2026-05-01 09:00', 'Unfit', 'F');

        [$view, $data] = $this->capturePdf('?from=2025&to=2026');
        $html = view($view, $data)->render();

        $this->assertSame(['total' => 2, 'fit' => 1, 'unfit' => 1, 'male' => 1, 'female' => 1], $data['spanSummary']);
        $this->assertSame([1, 1], array_map(fn (array $college): int => $college['counts']['total'], $data['spanColleges']));

        $this->assertStringContainsString('January 1, 2025 – December 31, 2026', $html);
        $this->assertSame(2, substr_count($html, '<div class="year">'));
        // Each year lists every college (shaded line) and every program under it.
        $this->assertSame(4, substr_count($html, '<tr class="college">'));
        $this->assertSame(2, substr_count($html, '<td class="program">BSCS</td>'));
        // Counts only — no student is ever named.
        $this->assertStringNotContainsString('Student 5', $html);
    }

    // ── The analytics page ───────────────────────────────────────────────────

    public function test_the_analytics_page_offers_the_start_and_end_year_popup(): void
    {
        $this->actingAs($this->director)->get('/director/analytics')
            ->assertOk()
            ->assertSee('Yearly Report (PDF)')
            ->assertSee(route('director.analytics.yearly-report'), false)
            ->assertSeeInOrder(['name="from"', '<option value="2026" selected', '<option value="2021"', 'name="to"'], false)
            ->assertSee('Covers every college and program.');
    }
}
