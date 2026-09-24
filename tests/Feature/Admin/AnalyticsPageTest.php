<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

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
 * College Admin Analytics (FR-ADM-08, D-45) — the Director's cards scoped to
 * one college and broken out per program.
 *
 * The two things this file exists to prove:
 *  1. SCOPE IS SERVER-DERIVED. The page reads managedCollege(); a ?college=
 *     in the URL changes nothing (FR-AUTH-06 / FR-ADM-06).
 *  2. PROGRAM ROWS READ THE D-43 SNAPSHOT, so a student who shifts program
 *     does not retroactively rewrite last month's report.
 *
 * Exact counts everywhere: the suite runs on SQLite while dev/prod is MySQL,
 * so these are also the portability guard on the shared service's SQL.
 */
class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    /** Two real CCS programs, so config/programs.php (D-42) backs them. */
    private const BSIT = 'Bachelor of Science in Information Technology';

    private const BSCS = 'Bachelor of Science in Computer Science';

    private const ACT = 'Associate in Computer Technology';

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
     *
     * @param  array  $vitals  Overrides for the vital_signs columns.
     */
    private function makeVisit(
        User $student,
        College $college,
        ?string $course,
        string $checkedInAt,
        string $visitStatus = 'encoded',
        array $vitals = [],
        ?int $appointmentId = null,
    ): ClinicVisit {
        static $seq = 8000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'course' => $course,
            'appointment_id' => $appointmentId,
            'login_method' => 'qr',
            'status' => $visitStatus,
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

        if ($visitStatus === 'encoded') {
            ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $this->nurse->id,
                'result' => 'Fit',
                'encoded_at' => now(),
            ]);
        }

        return $visit;
    }

    private function page(string $query = ''): TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/analytics'.$query);
    }

    /** The card's visit counts keyed by program, for readable assertions. */
    private function programTotals(TestResponse $response): array
    {
        return collect($response->viewData('programRows'))
            ->mapWithKeys(fn (array $row) => [$row['program'] => $row['visits']])
            ->all();
    }

    // ── Access control (FR-AUTH-03 / FR-AUTH-06) ─────────────────────────────

    public function test_guests_students_nurses_and_the_director_are_refused(): void
    {
        $this->get('/admin/analytics')->assertRedirect('/login');

        // The role middleware bounces every other role to its own home.
        foreach (['student', 'nurse', 'director'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/analytics')
                ->assertRedirect();
        }
    }

    public function test_an_admin_with_no_managed_college_is_refused(): void
    {
        // `college.scope` (FR-AUTH-06): without a college there is no scope to
        // derive, so the page must not render at all rather than fall back to
        // showing everything.
        $unassigned = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => null,
        ]);

        $this->actingAs($unassigned)->get('/admin/analytics')->assertForbidden();
    }

    // ── Scope (FR-ADM-06) ────────────────────────────────────────────────────

    public function test_the_page_shows_only_the_admins_own_college(): void
    {
        $ccsStudent = $this->makeStudent($this->ccs, self::BSIT);
        $coeStudent = $this->makeStudent($this->coe);

        $this->makeVisit($ccsStudent, $this->ccs, self::BSIT, '2026-05-05');

        // COE activity in the same month — none of it may be counted.
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-06');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-07');

        $response = $this->page('?month=2026-05')->assertOk();

        $this->assertSame(1, $response->viewData('totalVisits'));
        $this->assertSame(1, $response->viewData('screenings'));
        $this->assertSame(1, $response->viewData('totalScreened'));
        $this->assertSame(1, $response->viewData('bmiTotal'));

        // COE's program never appears as a row — only CCS's catalog does.
        $this->assertArrayNotHasKey('Bachelor of Elementary Education', $this->programTotals($response));
        $response->assertSee('College of Computing Studies')->assertDontSee('Bachelor of Elementary Education');
    }

    public function test_a_forged_college_parameter_changes_nothing(): void
    {
        // SECURITY (FR-ADM-06): ?college= is not validated and then rejected —
        // it is never read. The CCS admin asking for COE gets CCS.
        $ccsStudent = $this->makeStudent($this->ccs, self::BSIT);
        $coeStudent = $this->makeStudent($this->coe);
        $this->makeVisit($ccsStudent, $this->ccs, self::BSIT, '2026-05-05');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-06');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-05-07');

        $clean = $this->page('?month=2026-05')->assertOk();
        $forged = $this->page('?month=2026-05&college='.$this->coe->id)->assertOk();

        $this->assertSame(1, $forged->viewData('totalVisits'));
        $this->assertSame($clean->viewData('programRows'), $forged->viewData('programRows'));
        $this->assertSame($clean->viewData('trend'), $forged->viewData('trend'));
        $this->assertSame($clean->viewData('donut'), $forged->viewData('donut'));
    }

    public function test_the_trend_stays_inside_the_college(): void
    {
        // FR-ANL-11 ignores the MONTH — but not the college. The Director's
        // trend is clinic-wide; an admin's must never be.
        $ccsStudent = $this->makeStudent($this->ccs, self::BSIT);
        $coeStudent = $this->makeStudent($this->coe);
        $this->makeVisit($ccsStudent, $this->ccs, self::BSIT, '2026-01-10');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-01-20');
        $this->makeVisit($ccsStudent, $this->ccs, self::BSIT, '2026-02-10');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-02-11');

        $trend = $this->page('?month=2026-01')->assertOk()->viewData('trend');

        $this->assertSame(['Jan', 'Feb'], $trend['labels']);
        $this->assertSame([1, 1], $trend['datasets'][0]['data']); // CCS only
    }

    public function test_the_month_picker_only_offers_months_this_college_has_data_in(): void
    {
        $ccsStudent = $this->makeStudent($this->ccs, self::BSIT);
        $coeStudent = $this->makeStudent($this->coe);
        $this->makeVisit($ccsStudent, $this->ccs, self::BSIT, '2026-03-10');
        $this->makeVisit($coeStudent, $this->coe, 'Bachelor of Elementary Education', '2026-06-10');

        $response = $this->page()->assertOk();

        $this->assertSame(
            [['value' => '2026-03', 'label' => 'March 2026']],
            $response->viewData('availableMonths'),
        );
        // …and the default month is the newest one CCS has data in, not June.
        $this->assertSame('2026-03', $response->viewData('selectedMonth'));
    }

    // ── Clinic Visits by Program (FR-ADM-08) ─────────────────────────────────

    public function test_program_rows_are_exact_and_zero_visit_programs_still_render(): void
    {
        $bsit = $this->makeStudent($this->ccs, self::BSIT);
        $bscs = $this->makeStudent($this->ccs, self::BSCS);

        // BSIT: 2 visits (one still CAPTURED — it counts, FR-ANL-07).
        // BSCS: 1. ACT and BSIS: nothing at all, and must still appear as
        // zero rows (the FR-ANL-09 rule, one level down).
        $this->makeVisit($bsit, $this->ccs, self::BSIT, '2026-05-05');
        $this->makeVisit($bsit, $this->ccs, self::BSIT, '2026-05-12', visitStatus: 'captured');
        $this->makeVisit($bscs, $this->ccs, self::BSCS, '2026-05-06');

        $response = $this->page('?month=2026-05')->assertOk();

        $this->assertSame([
            self::BSIT => 2,
            self::BSCS => 1,
            // Zero rows, alphabetical among themselves (the tie-break).
            self::ACT => 0,
            'Bachelor of Science in Information Systems' => 0,
        ], $this->programTotals($response));

        $this->assertSame(3, $response->viewData('totalVisits'));
    }

    public function test_a_visit_counts_under_its_snapshot_program_not_the_students_current_one(): void
    {
        // The D-43 guarantee: the student was BSIT when they were screened and
        // has since shifted to BSCS. May's report must not move with them.
        $shifter = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($shifter, $this->ccs, self::BSIT, '2026-05-05');

        $shifter->studentProfile()->update(['course' => self::BSCS]);

        $rows = $this->programTotals($this->page('?month=2026-05')->assertOk());

        $this->assertSame(1, $rows[self::BSIT]);
        $this->assertSame(0, $rows[self::BSCS]);
    }

    public function test_visits_with_no_program_snapshot_fall_into_a_not_specified_row(): void
    {
        // The D-43 column is nullable and never backfilled, so pre-D-43 visits
        // carry no program. They must still be counted somewhere, or the
        // card's headline would disagree with every other card on the page.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');
        $this->makeVisit($student, $this->ccs, null, '2026-05-06');
        $this->makeVisit($student, $this->ccs, null, '2026-05-07');

        $response = $this->page('?month=2026-05')->assertOk();
        $rows = $this->programTotals($response);

        $this->assertSame(2, $rows['Not specified']);
        $this->assertSame(3, $response->viewData('totalVisits'));
        $this->assertSame($response->viewData('screenings'), $response->viewData('totalVisits'));
    }

    public function test_the_not_specified_row_is_absent_when_every_visit_has_a_program(): void
    {
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');

        $this->assertArrayNotHasKey('Not specified', $this->programTotals($this->page('?month=2026-05')->assertOk()));
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    public function test_the_program_filter_narrows_every_card(): void
    {
        $bsit = $this->makeStudent($this->ccs, self::BSIT, 'M');
        $bscs = $this->makeStudent($this->ccs, self::BSCS, 'F');

        // BSIT: 1 flagged visit. BSCS: 1 clean visit.
        $this->makeVisit($bsit, $this->ccs, self::BSIT, '2026-05-05', vitals: ['is_bp_flagged' => true, 'bmi' => 31.0]);
        $this->makeVisit($bscs, $this->ccs, self::BSCS, '2026-05-06', vitals: ['bmi' => 22.0]);

        $response = $this->page('?month=2026-05&program='.urlencode(self::BSIT))->assertOk();

        $this->assertSame(self::BSIT, $response->viewData('selectedProgram'));
        // Only the filtered program's row remains, like the Director's college.
        $this->assertSame([self::BSIT => 1], $this->programTotals($response));
        $this->assertSame(1, $response->viewData('screenings'));
        $this->assertSame(1, $response->viewData('flagTiles')[0]['count']);
        $this->assertSame([1, 0], $response->viewData('donut')['datasets'][0]['data']);
        $this->assertSame(1, $response->viewData('bmiTotal'));
        $this->assertSame([0, 0, 0, 1], array_column($response->viewData('bmiRows'), 'count'));
        $this->assertSame([['label' => 'Not specified', 'count' => 1]], $response->viewData('purposeRows'));
        // …the trend too: it ignores only the MONTH.
        $this->assertSame([1], $response->viewData('trend')['datasets'][0]['data']);
    }

    public function test_the_month_filter_scopes_every_card_except_the_trend(): void
    {
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-04-10', vitals: ['is_bp_flagged' => true, 'bmi' => 31.0]);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-10');

        $april = $this->page('?month=2026-04')->assertOk();
        $this->assertSame(1, $april->viewData('totalVisits'));
        $this->assertSame(1, $april->viewData('screenings'));
        $this->assertSame(1, $april->viewData('flagTiles')[0]['count']);
        $this->assertSame([0, 0, 0, 1], array_column($april->viewData('bmiRows'), 'count'));

        $may = $this->page('?month=2026-05')->assertOk();
        $this->assertSame(1, $may->viewData('totalVisits'));
        $this->assertSame(0, $may->viewData('flagTiles')[0]['count']);
        $this->assertSame([0, 1, 0, 0], array_column($may->viewData('bmiRows'), 'count'));

        // The trend is the same series under either month (FR-ANL-11).
        $this->assertSame($april->viewData('trend'), $may->viewData('trend'));
    }

    public function test_an_unknown_or_foreign_program_degrades_to_all_programs(): void
    {
        // Same rule as the Director's college filter (FR-ANL-13): a
        // hand-edited URL degrades, it never errors — and a program belonging
        // to ANOTHER college is refused exactly like a made-up one.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-10');

        foreach (['banana', 'Bachelor of Elementary Education'] as $program) {
            $response = $this->page('?month=2026-05&program='.urlencode($program))->assertOk();

            $this->assertNull($response->viewData('selectedProgram'));
            $this->assertSame(1, $response->viewData('totalVisits'));
            $this->assertCount(4, $response->viewData('programRows')); // all of CCS
        }
    }

    public function test_nothing_dental_is_left_on_the_page(): void
    {
        // D-60: the service split is gone — no legend, no column, no wording.
        $student = $this->makeStudent($this->ccs, self::BSIT);
        $this->makeVisit($student, $this->ccs, self::BSIT, '2026-05-05');

        foreach (['', '?month=2026-05'] as $query) {
            $this->page($query)->assertOk()->assertDontSee('Dental', escape: false);
        }
    }

    public function test_the_page_renders_with_no_data_at_all(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Clinic Visits by Program')
            ->assertSee('Vital-Sign Flags')
            ->assertSee('Students Screened by Sex')
            ->assertSee('BMI Distribution')
            ->assertSee('No visits recorded');
    }

    public function test_count_up_numbers_render_their_real_values(): void
    {
        // The load-up animation counts these up from 0 in JS; the HTML must
        // still carry the REAL numbers (the no-JS / reduced-motion state).
        $male = $this->makeStudent($this->ccs, self::BSIT, 'M');
        $female = $this->makeStudent($this->ccs, self::BSCS, 'F');
        $this->makeVisit($male, $this->ccs, self::BSIT, '2026-05-03', vitals: ['is_bp_flagged' => true]);
        $this->makeVisit($female, $this->ccs, self::BSCS, '2026-05-04');

        $this->page('?month=2026-05')
            ->assertOk()
            ->assertSee('<span data-count-up>2</span>', false)                // visits in the month
            ->assertSee('text-hp-slate" data-count-up>1</p>', false)           // the BP flag tile
            ->assertSee('leading-none text-hp-slate" data-count-up>2</p>', false); // donut centre total
    }
}
