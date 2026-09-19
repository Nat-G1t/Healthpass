<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Printable Monthly Clinic Report (FR-ADM-09, D-46) — the artifact a college
 * hands over, printed from the same figures the FR-ADM-08 page shows.
 *
 * The three things this file exists to prove:
 *  1. It is a COLLEGE ADMIN page. Every other role, and an admin with no
 *     managed college, is refused (FR-AUTH-03 / FR-AUTH-06).
 *  2. SCOPE IS SERVER-DERIVED, exactly as on the page it prints: a ?college=
 *     in the print URL changes nothing (FR-ADM-06).
 *  3. THE FILTERS CARRY THROUGH, so the printout is the page the admin was
 *     looking at — including zero-visit programs, which have to be on the
 *     paper report or a program reads as "not reported" rather than "no
 *     visits".
 */
class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    /** Real CCS programs, so config/programs.php (D-42) backs them. */
    private const BSIT = 'Bachelor of Science in Information Technology';

    private const BSCS = 'Bachelor of Science in Computer Science';

    private const ACT = 'Associate in Computer Technology';

    private const BSIS = 'Bachelor of Science in Information Systems';

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Education']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
            'name' => 'Ana Reyes',
        ]);

        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A student whose CURRENT profile carries this college, program and sex. */
    private function makeStudent(College $college, ?string $course = null, string $sex = 'M'): User
    {
        $student = User::factory()->create(['role' => 'student']);

        StudentProfile::factory()
            ->forCollege($college)
            ->create([
                'user_id' => $student->id,
                'sex' => $sex,
                ...($course === null ? [] : ['course' => $course]),
            ]);

        return $student;
    }

    /**
     * One medical visit with its 1:1 vitals row, frozen to the college AND
     * program snapshot it was captured under (D-17 / D-43).
     */
    private function makeVisit(
        User $student,
        College $college,
        ?string $course,
        string $checkedInAt,
        array $vitals = [],
    ): ClinicVisit {
        static $seq = 9000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'course' => $course,
            'login_method' => 'qr',
            'status' => 'encoded',
            'checked_in_at' => now()->parse($checkedInAt),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 170.0,
            'weight_kg' => 63.5,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 110,
            'bp_diastolic' => 70,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            ...$vitals,
        ]);

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);

        return $visit;
    }

    private function report(string $query = ''): TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/analytics/print'.$query);
    }

    /** The report's visit counts keyed by program, for readable assertions. */
    private function programTotals(TestResponse $response): array
    {
        return collect($response->viewData('programRows'))
            ->mapWithKeys(fn (array $row) => [$row['program'] => $row['visits']])
            ->all();
    }

    // ── Access control (FR-AUTH-03 / FR-AUTH-06) ─────────────────────────────

    public function test_guests_students_nurses_and_the_director_are_refused(): void
    {
        $this->get('/admin/analytics/print')->assertRedirect('/login');

        // The role middleware bounces every other role to its own home.
        foreach (['student', 'nurse', 'director'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/analytics/print')
                ->assertRedirect();
        }
    }

    public function test_an_admin_with_no_managed_college_is_refused(): void
    {
        // `college.scope`: with no college there is no scope to print, so the
        // report must not render at all rather than fall back to everything.
        $unassigned = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => null,
        ]);

        $this->actingAs($unassigned)->get('/admin/analytics/print')->assertForbidden();
    }

    // ── The report itself (FR-ADM-09) ────────────────────────────────────────

    public function test_the_report_renders_for_the_admins_college_with_correct_totals(): void
    {
        $bsit = $this->makeStudent($this->ccs, self::BSIT, 'M');
        $bscs = $this->makeStudent($this->ccs, self::BSCS, 'F');
        $coeStudent = $this->makeStudent($this->coe, 'Bachelor of Elementary Education');

        // CCS: 2 visits (one flagged, one obese).
        $this->makeVisit($bsit, $this->ccs, self::BSIT, '2026-05-05', ['is_bp_flagged' => true, 'bmi' => 31.0]);
        $this->makeVisit($bscs, $this->ccs, self::BSCS, '2026-05-06', ['bmi' => 22.0]);

        // COE activity in the same month — none of it may reach the paper.
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-07');

        $response = $this->report('?month=2026-05')->assertOk();

        // Header identity: college, month, and who generated it.
        $response->assertSee('Monthly Clinic Report')
            ->assertSee('College of Computing Studies')
            ->assertSee('CCS')
            ->assertSee('May 2026')
            ->assertSee('Ana Reyes')
            ->assertDontSee('Bachelor of Elementary Education');

        // Summary line + program table.
        $this->assertSame(2, $response->viewData('totalVisits'));
        $this->assertSame([
            // Equal counts keep alphabetical order (the tie-break).
            self::BSCS => 1,
            self::BSIT => 1,
            self::ACT => 0,
            self::BSIS => 0,
        ], $this->programTotals($response));

        // Flags, BMI and sex, all over the same two CCS screenings.
        $this->assertSame(2, $response->viewData('screenings'));
        $this->assertSame(1, $response->viewData('flagTiles')[0]['count']);
        $this->assertSame(50.0, $response->viewData('flagTiles')[0]['rate']);
        $this->assertSame([0, 1, 0, 1], array_column($response->viewData('bmiRows'), 'count'));
        $this->assertSame(2, $response->viewData('bmiTotal'));
        $this->assertSame(2, $response->viewData('totalScreened'));
        $this->assertSame(
            [['Male', 1, 50], ['Female', 1, 50]],
            array_map(
                fn (array $slice) => [$slice['label'], $slice['count'], $slice['percent']],
                $response->viewData('bySex'),
            ),
        );

        // Purpose bucket: neither visit has an appointment purpose.
        $this->assertSame(
            [['label' => 'Not specified', 'count' => 2]],
            $response->viewData('purposeRows'),
        );
    }

    public function test_the_report_matches_the_analytics_page_for_the_same_filters(): void
    {
        // The whole point of building both on ClinicAnalytics: the printout
        // can never quote a different number than the screen it came from.
        $bsit = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($bsit, $this->ccs, self::BSIT, '2026-05-05', ['is_temp_flagged' => true, 'bmi' => 17.0]);

        $page = $this->actingAs($this->admin)->get('/admin/analytics?month=2026-05')->assertOk();
        $print = $this->report('?month=2026-05')->assertOk();

        foreach (['totalVisits', 'programRows',
            'purposeRows', 'screenings', 'flagTiles', 'bmiRows', 'bmiTotal',
            'bySex', 'totalScreened'] as $key) {
            $this->assertSame($page->viewData($key), $print->viewData($key), "mismatch on {$key}");
        }
    }

    // ── SECURITY (FR-ADM-06) ─────────────────────────────────────────────────

    public function test_a_forged_college_parameter_does_not_change_the_numbers(): void
    {
        // ?college= is not validated and then rejected on this route — it is
        // never read. The CCS admin asking for COE still gets CCS.
        $ccsStudent = $this->makeStudent($this->ccs, self::BSIT);
        $coeStudent = $this->makeStudent($this->coe, 'Bachelor of Elementary Education');
        $this->makeVisit($ccsStudent, $this->ccs, self::BSIT, '2026-05-05');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-06');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-07');

        $clean = $this->report('?month=2026-05')->assertOk();
        $forged = $this->report('?month=2026-05&college='.$this->coe->id)->assertOk();

        $this->assertSame(1, $forged->viewData('totalVisits'));
        $this->assertSame($clean->viewData('programRows'), $forged->viewData('programRows'));
        $this->assertSame($clean->viewData('bySex'), $forged->viewData('bySex'));
        $forged->assertSee('College of Computing Studies')
            ->assertDontSee('College of Education');
    }

    public function test_a_foreign_program_filter_degrades_to_all_programs(): void
    {
        // A program belonging to ANOTHER college is refused exactly like a
        // made-up one — the catalog check is against the MANAGED college.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');

        foreach (['banana', 'Bachelor of Elementary Education'] as $program) {
            $response = $this->report('?month=2026-05&program='.urlencode($program))->assertOk();

            $this->assertNull($response->viewData('selectedProgram'));
            $this->assertCount(4, $response->viewData('programRows')); // all of CCS
            $response->assertDontSee('Filtered to:');
        }
    }

    // ── Filters carried in from the analytics page ───────────────────────────

    public function test_the_month_filter_carries_through_from_the_query_string(): void
    {
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-04-10', ['is_bp_flagged' => true, 'bmi' => 31.0]);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-10');

        $april = $this->report('?month=2026-04')->assertOk();
        $april->assertSee('April 2026');
        $this->assertSame(1, $april->viewData('totalVisits'));
        $this->assertSame(1, $april->viewData('flagTiles')[0]['count']);
        $this->assertSame([0, 0, 0, 1], array_column($april->viewData('bmiRows'), 'count'));

        $may = $this->report('?month=2026-05')->assertOk();
        $may->assertSee('May 2026');
        $this->assertSame(1, $may->viewData('totalVisits'));
        $this->assertSame(0, $may->viewData('flagTiles')[0]['count']);
        $this->assertSame([0, 1, 0, 0], array_column($may->viewData('bmiRows'), 'count'));
    }

    public function test_the_program_filter_carries_through_and_is_stated_on_the_report(): void
    {
        $bsit = $this->makeStudent($this->ccs, self::BSIT);
        $bscs = $this->makeStudent($this->ccs, self::BSCS);
        $this->makeVisit($bsit, $this->ccs, self::BSIT, '2026-05-05');
        $this->makeVisit($bscs, $this->ccs, self::BSCS, '2026-05-06');

        $response = $this->report('?month=2026-05&program='.urlencode(self::BSIT))->assertOk();

        $this->assertSame(self::BSIT, $response->viewData('selectedProgram'));
        $this->assertSame([self::BSIT => 1], $this->programTotals($response));
        $this->assertSame(1, $response->viewData('totalVisits'));
        $this->assertSame(1, $response->viewData('screenings'));

        // A filtered report has to SAY it is filtered, or partial figures get
        // read as the whole college's.
        $response->assertSee('Filtered to:')->assertSee(self::BSIT);
    }

    public function test_an_unparseable_month_falls_back_to_the_newest_month_with_data(): void
    {
        // Same degrade rule as the page (FR-ANL-13): a hand-edited URL still
        // prints something, it never errors.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-03-10');

        $this->report('?month=banana')->assertOk()->assertSee('March 2026');
    }

    // ── Report contents (FR-ADM-09) ──────────────────────────────────────────

    public function test_zero_visit_programs_appear_in_the_table(): void
    {
        // On paper a missing program reads as "not reported", not "no
        // visits" — so every program the college offers gets a row.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');

        $response = $this->report('?month=2026-05')->assertOk();

        $this->assertSame([
            self::BSIT => 1,
            self::ACT => 0,
            self::BSCS => 0,
            self::BSIS => 0,
        ], $this->programTotals($response));

        $response->assertSee(self::ACT)->assertSee(self::BSIS);
    }

    public function test_the_report_carries_the_config_thresholds_not_hardcoded_ones(): void
    {
        // The captions come from config('healthpass.thresholds') via the
        // service — nothing in the print view restates 140/90 or 37.2.
        $thresholds = config('healthpass.thresholds');

        $this->report()
            ->assertOk()
            // Escaped needles: Blade renders these through {{ }}, so the ">"
            // in the fever caption arrives as &gt;.
            ->assertSee("≥ {$thresholds['bp_systolic']}/{$thresholds['bp_diastolic']}")
            ->assertSee("> {$thresholds['temperature_max']} °C");
    }

    public function test_the_report_is_a_standalone_print_document(): void
    {
        // The report is its own document — it self-prints and carries no app
        // shell around it.
        $this->report()
            ->assertOk()
            ->assertSee('window.print()', false)
            // The marker partials/print-frame checks before firing the dialog.
            ->assertSee('data-hp-print-doc', false)
            ->assertSee('covers data captured by HealthPass only')
            ->assertDontSee('hp-sidebar');  // the app shell's <aside>
    }

    public function test_the_report_renders_with_no_data_at_all(): void
    {
        $this->report()
            ->assertOk()
            ->assertSee('Clinic Visits by Program')
            ->assertSee('Visits by Purpose')
            ->assertSee('Vital-Sign Flags')
            ->assertSee('BMI Distribution')
            ->assertSee('Students Screened by Sex')
            ->assertSee('No medical visits recorded for this month.');
    }

    public function test_the_analytics_page_links_to_the_report_with_its_filters(): void
    {
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');

        $this->actingAs($this->admin)
            ->get('/admin/analytics?month=2026-05&program='.urlencode(self::BSIT))
            ->assertOk()
            ->assertSee('Print Monthly Report')
            // e(): Blade escapes the & between the two query parameters.
            ->assertSee(
                e(route('admin.analytics.print', ['month' => '2026-05', 'program' => self::BSIT])),
                false,
            )
            // Printed IN PLACE: the link loads the report into the page's
            // hidden print frame, it does not open a new tab.
            ->assertSee('data-print-trigger', false)
            ->assertSee('id="hp-print-frame"', false)
            ->assertDontSee('target="_blank"', false);
    }

    public function test_nothing_dental_is_left_on_the_report(): void
    {
        // D-60: the Medical / Dental / Total columns became one Visits column
        // and the summary line dropped the split.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');

        $this->report('?month=2026-05')
            ->assertOk()
            ->assertDontSee('Dental', escape: false)
            ->assertSee('total visits');
    }

    public function test_the_report_prints_in_place_rather_than_in_a_new_tab(): void
    {
        // The document self-prints ONLY when it is the top window — inside the
        // analytics page's frame the parent fires the dialog, and both firing
        // would open it twice.
        $this->report()
            ->assertOk()
            ->assertSee('window.self === window.top', false);
    }
}
