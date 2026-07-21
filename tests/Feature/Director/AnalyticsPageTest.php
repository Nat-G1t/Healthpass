<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Director Analytics after the D-32 rescope rebuild (FR-ANL-09..13 + the
 * amended FR-ANL-04). Every aggregate gets an exact-count test — the
 * SQLite suite is what catches raw-SQL portability drift:
 *
 *  - visits count from CAPTURE (FR-ANL-07 as rewritten): captured and
 *    encoded medical visits alike, plus COMPLETED dental appointments
 *    (D-33) — scheduled ones never count;
 *  - the month + college filters scope every card except the trend.
 */
class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Education']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A student user whose profile has the given college and sex. */
    private function makeStudent(College $college, string $sex = 'M'): User
    {
        $student = User::factory()->create(['role' => 'student']);
        StudentProfile::factory()
            ->forCollege($college)
            ->create(['user_id' => $student->id, 'sex' => $sex]);

        return $student;
    }

    /**
     * Persist one medical visit (with clearance record when encoded),
     * frozen to $college's capture-time snapshot, plus its 1:1 vitals row
     * (every kiosk visit has one).
     *
     * @param  array  $vitals  Overrides for the vital_signs columns.
     */
    private function makeVisit(
        User $student,
        College $college,
        string $checkedInAt,
        string $visitStatus = 'encoded',
        array $vitals = [],
        ?int $appointmentId = null,
    ): ClinicVisit {
        static $seq = 7000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
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

    /** A dental appointment on $date in the given status. */
    private function makeDental(User $student, string $date, string $status = 'completed'): Appointment
    {
        return Appointment::factory()
            ->dental()
            ->onDate($date)
            ->create(['student_id' => $student->id, 'status' => $status]);
    }

    private function page(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->director)->get('/director/analytics'.$query);
    }

    public function test_guests_and_other_roles_cannot_open_analytics(): void
    {
        $this->get('/director/analytics')->assertRedirect('/login');

        // The role middleware bounces other roles to their own home.
        $this->actingAs($this->nurse)->get('/director/analytics')->assertRedirect();
    }

    public function test_page_renders_with_no_data_at_all(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Clinic Visits by College')
            ->assertSee('Vital-Sign Flags')
            ->assertSee('Students Screened by Sex')
            ->assertSee('BMI Distribution')
            ->assertSee('No visits recorded');
    }

    public function test_visits_by_college_splits_medical_and_dental_with_zero_rows(): void
    {
        // FR-ANL-09, May 2026: CCS gets 2 medical (one still CAPTURED —
        // it counts, FR-ANL-07) + 1 completed dental. COE gets nothing but
        // must still appear as a zero row. A scheduled dental in May and a
        // completed dental in June must not enter May's card.
        $ccsStudent = $this->makeStudent($this->ccs);
        $this->makeVisit($ccsStudent, $this->ccs, '2026-05-05');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-05-12', visitStatus: 'captured');
        $this->makeDental($ccsStudent, '2026-05-20');
        $this->makeDental($ccsStudent, '2026-05-25', status: 'scheduled');
        $this->makeDental($ccsStudent, '2026-06-02');

        $response = $this->page('?month=2026-05')->assertOk();

        $this->assertSame([
            ['code' => 'CCS', 'medical' => 2, 'dental' => 1, 'total' => 3],
            ['code' => 'COE', 'medical' => 0, 'dental' => 0, 'total' => 0],
        ], $response->viewData('collegeRows'));

        $this->assertSame(3, $response->viewData('totalVisits'));
        $this->assertSame(2, $response->viewData('totalMedical'));
        $this->assertSame(1, $response->viewData('totalDental'));
    }

    public function test_purpose_buckets_including_walk_in_not_specified(): void
    {
        // 2 OJT-purposed visits, 1 appointment with NO purpose and 1 with
        // no appointment at all — both land in "Walk-in / not specified".
        $student = $this->makeStudent($this->ccs);

        $ojt = fn () => Appointment::factory()
            ->withPurpose('On-the-job Training')
            ->onDate('2026-05-05')
            ->create(['student_id' => $student->id, 'status' => 'completed']);
        $this->makeVisit($student, $this->ccs, '2026-05-05', appointmentId: $ojt()->id);
        $this->makeVisit($student, $this->ccs, '2026-05-06', appointmentId: $ojt()->id);

        $purposeless = Appointment::factory()
            ->medical()
            ->onDate('2026-05-07')
            ->create(['student_id' => $student->id, 'status' => 'completed']);
        $this->makeVisit($student, $this->ccs, '2026-05-07', appointmentId: $purposeless->id);
        $this->makeVisit($student, $this->ccs, '2026-05-08'); // true walk-in

        $response = $this->page('?month=2026-05')->assertOk();

        // Sorted by count desc; the 2–2 tie breaks alphabetically.
        $this->assertSame([
            ['label' => 'On-the-job Training', 'count' => 2],
            ['label' => 'Walk-in / not specified', 'count' => 2],
        ], $response->viewData('purposeRows'));
    }

    public function test_flag_counts_and_rates_include_captured_visits(): void
    {
        // 4 screenings in May: one BP-flagged and still CAPTURED (counts
        // immediately, FR-ANL-07/10), one temp-flagged, two clean.
        // Rates are % of the month's 4 screenings, one decimal.
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-05-03', visitStatus: 'captured', vitals: [
            'bp_systolic' => 150, 'bp_diastolic' => 95, 'is_bp_flagged' => true,
        ]);
        $this->makeVisit($student, $this->ccs, '2026-05-04', vitals: [
            'temperature_c' => 38.1, 'is_temp_flagged' => true,
        ]);
        $this->makeVisit($student, $this->ccs, '2026-05-05');
        $this->makeVisit($student, $this->ccs, '2026-05-06');

        $response = $this->page('?month=2026-05')->assertOk();

        $this->assertSame(4, $response->viewData('screenings'));

        $tiles = collect($response->viewData('flagTiles'))
            ->map(fn (array $tile) => [$tile['label'], $tile['count'], $tile['rate']])
            ->all();

        $this->assertSame([
            ['High Blood Pressure', 1, 25.0],
            ['Fever', 1, 25.0],
            ['Abnormal BMI', 0, 0.0],
        ], $tiles);
    }

    public function test_flag_rates_round_to_one_decimal(): void
    {
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-05-03', vitals: ['is_bp_flagged' => true]);
        $this->makeVisit($student, $this->ccs, '2026-05-04');
        $this->makeVisit($student, $this->ccs, '2026-05-05');

        $tiles = $this->page('?month=2026-05')->viewData('flagTiles');

        $this->assertSame(33.3, $tiles[0]['rate']); // 1 of 3 screenings
    }

    public function test_trend_covers_all_months_and_ignores_both_filters(): void
    {
        // Medical: Jan ×2, Feb ×1. Dental completed: Feb ×2, Mar ×1 (a
        // dental-only month). Scheduled dental never enters the series.
        $ccsStudent = $this->makeStudent($this->ccs);
        $coeStudent = $this->makeStudent($this->coe);
        $this->makeVisit($ccsStudent, $this->ccs, '2026-01-10');
        $this->makeVisit($coeStudent, $this->coe, '2026-01-20');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-02-05');
        $this->makeDental($ccsStudent, '2026-02-10');
        $this->makeDental($ccsStudent, '2026-02-15');
        $this->makeDental($ccsStudent, '2026-03-01');
        $this->makeDental($ccsStudent, '2026-03-20', status: 'scheduled');

        $expected = function ($response): void {
            $trend = $response->viewData('trend');
            $this->assertSame(['Jan', 'Feb', 'Mar'], $trend['labels']);
            $this->assertSame([2, 1, 0], $trend['datasets'][0]['data']); // medical
            $this->assertSame([0, 2, 1], $trend['datasets'][1]['data']); // dental
        };

        // The same series regardless of the selected month AND college —
        // the trend ignores both filters by design (FR-ANL-11/13).
        $expected($this->page('?month=2026-01')->assertOk());
        $expected($this->page('?month=2026-02&college='.$this->ccs->id)->assertOk());
    }

    public function test_bmi_buckets_split_on_the_rule_boundaries(): void
    {
        // 17.0 under · 18.5 + 24.9 normal · 25.0 + 29.9 overweight · 30.0 obese
        $student = $this->makeStudent($this->ccs);
        foreach ([17.0, 18.5, 24.9, 25.0, 29.9, 30.0] as $bmi) {
            $this->makeVisit($student, $this->ccs, '2026-05-10', vitals: ['bmi' => $bmi]);
        }

        $response = $this->page('?month=2026-05')->assertOk();

        $this->assertSame(6, $response->viewData('bmiTotal'));
        $this->assertSame(
            [1, 2, 2, 1],
            array_column($response->viewData('bmiRows'), 'count'),
        );
    }

    public function test_donut_counts_captured_visits_by_profile_sex(): void
    {
        // FR-ANL-04 as amended: people screened = ALL captured kiosk
        // visits — the encoded-only rule is retired (FR-ANL-07).
        $male = $this->makeStudent($this->ccs, 'M');
        $female = $this->makeStudent($this->ccs, 'F');
        $this->makeVisit($male, $this->ccs, '2026-05-03');
        $this->makeVisit($male, $this->ccs, '2026-05-04');
        $this->makeVisit($male, $this->ccs, '2026-05-05', visitStatus: 'captured');
        $this->makeVisit($female, $this->ccs, '2026-05-06');

        $response = $this->page('?month=2026-05')->assertOk();

        $bySex = $response->viewData('bySex');
        $this->assertSame(['Male', 3, 75], [$bySex[0]['label'], $bySex[0]['count'], $bySex[0]['percent']]);
        $this->assertSame(['Female', 1, 25], [$bySex[1]['label'], $bySex[1]['count'], $bySex[1]['percent']]);
        $this->assertSame(4, $response->viewData('totalScreened'));
        $this->assertSame([3, 1], $response->viewData('donut')['datasets'][0]['data']);
    }

    public function test_month_filter_scopes_every_card_except_the_trend(): void
    {
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-04-10', vitals: ['is_bp_flagged' => true, 'bmi' => 31.0, 'is_bmi_flagged' => true]);
        $this->makeVisit($student, $this->ccs, '2026-05-10');
        $this->makeDental($student, '2026-04-15');

        $april = $this->page('?month=2026-04')->assertOk();
        $this->assertSame(2, $april->viewData('totalVisits')); // 1 medical + 1 dental
        $this->assertSame(1, $april->viewData('screenings'));
        $this->assertSame(1, $april->viewData('flagTiles')[0]['count']);
        $this->assertSame(1, $april->viewData('totalScreened'));
        $this->assertSame([0, 0, 0, 1], array_column($april->viewData('bmiRows'), 'count'));

        $may = $this->page('?month=2026-05')->assertOk();
        $this->assertSame(1, $may->viewData('totalVisits'));
        $this->assertSame(0, $may->viewData('flagTiles')[0]['count']);
        $this->assertSame([0, 1, 0, 0], array_column($may->viewData('bmiRows'), 'count'));
    }

    public function test_college_filter_scopes_every_card_except_the_trend(): void
    {
        // Same month, two colleges. The medical side scopes on the
        // capture-time snapshot; the dental side on the student's CURRENT
        // college (FR-ANL-09 stated limitation).
        $ccsStudent = $this->makeStudent($this->ccs, 'M');
        $coeStudent = $this->makeStudent($this->coe, 'F');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-05-05', vitals: ['is_bp_flagged' => true]);
        $this->makeVisit($coeStudent, $this->coe, '2026-05-06');
        $this->makeDental($ccsStudent, '2026-05-10');
        $this->makeDental($coeStudent, '2026-05-11');

        $response = $this->page('?month=2026-05&college='.$this->ccs->id)->assertOk();

        $this->assertSame($this->ccs->id, $response->viewData('selectedCollegeId'));
        $this->assertSame([
            ['code' => 'CCS', 'medical' => 1, 'dental' => 1, 'total' => 2],
        ], $response->viewData('collegeRows'));
        $this->assertSame(1, $response->viewData('screenings'));
        $this->assertSame(1, $response->viewData('flagTiles')[0]['count']);
        $this->assertSame([1, 0], $response->viewData('donut')['datasets'][0]['data']);
        $this->assertSame(1, $response->viewData('bmiTotal'));
    }

    public function test_invalid_month_and_college_fall_back_gracefully(): void
    {
        // FR-ANL-13: unknown formats/ids degrade to the defaults — never
        // a 500 or a validation error on this read-only page.
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-05-10');

        $response = $this->page('?month=banana&college=999')->assertOk();

        $this->assertSame('2026-05', $response->viewData('selectedMonth'));
        $this->assertNull($response->viewData('selectedCollegeId'));
        $this->assertSame(1, $response->viewData('totalVisits'));
    }

    public function test_month_picker_lists_visit_and_dental_months_newest_first(): void
    {
        // A dental-only month belongs in the picker too (FR-ANL-13) —
        // and the newest month with ANY data is the default scope.
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-03-10');
        $this->makeDental($student, '2026-06-05');

        $response = $this->page()->assertOk();

        $this->assertSame(
            [
                ['value' => '2026-06', 'label' => 'June 2026'],
                ['value' => '2026-03', 'label' => 'March 2026'],
            ],
            $response->viewData('availableMonths'),
        );
        $this->assertSame('2026-06', $response->viewData('selectedMonth'));
    }

    public function test_removed_medical_cases_views_are_gone(): void
    {
        // D-32: no cases chart/matrix on the page, and the print + CSV
        // export routes no longer exist.
        $this->page()
            ->assertOk()
            ->assertDontSee('Medical Cases by College')
            ->assertDontSee('Summary of Medical Cases')
            ->assertDontSee('Preview &amp; Print', false)
            ->assertDontSee('Export CSV');

        $this->page('/summary-print')->assertNotFound();
        $this->actingAs($this->director)->get('/director/anomalies/export')->assertNotFound();
    }
}
