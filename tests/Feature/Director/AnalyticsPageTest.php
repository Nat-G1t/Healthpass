<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
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
 * Director Analytics after the D-32 rescope rebuild (FR-ANL-09..13 + the
 * amended FR-ANL-04). Every aggregate gets an exact-count test — the
 * SQLite suite is what catches raw-SQL portability drift:
 *
 *  - visits count from CAPTURE (FR-ANL-07 as rewritten): captured and
 *    encoded visits alike;
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
            'is_hr_flagged' => false,
            'is_rr_flagged' => false,
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

    public function test_visits_by_college_counts_captured_visits_with_zero_rows(): void
    {
        // FR-ANL-09, May 2026: CCS gets 2 visits (one still CAPTURED — it
        // counts, FR-ANL-07). COE gets nothing but must still appear as a
        // zero row. A June visit must not enter May's card.
        $ccsStudent = $this->makeStudent($this->ccs);
        $this->makeVisit($ccsStudent, $this->ccs, '2026-05-05');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-05-12', visitStatus: 'captured');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-06-02');

        $response = $this->page('?month=2026-05')->assertOk();

        $this->assertSame([
            ['code' => 'CCS', 'visits' => 2],
            ['code' => 'COE', 'visits' => 0],
        ], $response->viewData('collegeRows'));

        $this->assertSame(2, $response->viewData('totalVisits'));
    }

    /** D-62: an appointment on a batch with the given form + reason. */
    private function batchAppointment(User $student, string $date, string $formType, string $reason): Appointment
    {
        static $seq = 1;

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq++),
            'college_id' => $this->ccs->id,
            'requested_by' => $this->director->id,
            'form_type' => $formType,
            'reason' => $reason,
            'reason_detail' => $reason === 'others' ? 'Quiz bee' : null,
            'service_type' => 'medical',
            'requested_date' => $date,
            'scheduled_date' => $date,
            'status' => 'approved',
        ]);

        return Appointment::factory()->medical()->onDate($date)->create([
            'student_id' => $student->id,
            'status' => 'completed',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);
    }

    public function test_purpose_rows_follow_the_batch_reason(): void
    {
        // D-62: the batch reason IS the purpose. 2 OJT visits (assessment),
        // 1 field trip (clearance), one "Others" on EACH form — same printed
        // label, so they merge into one bar — plus a retired pre-D-62 reason
        // and a legacy visit with no appointment, both "Not specified".
        $student = $this->makeStudent($this->ccs);

        $visit = function (string $date, string $formType, string $reason) use ($student): void {
            $this->makeVisit($student, $this->ccs, $date,
                appointmentId: $this->batchAppointment($student, $date, $formType, $reason)->id);
        };

        $visit('2026-05-04', 'assessment', 'ojt');
        $visit('2026-05-05', 'assessment', 'ojt');
        $visit('2026-05-06', 'clearance', 'fieldtrip');
        $visit('2026-05-07', 'clearance', 'others');
        $visit('2026-05-08', 'assessment', 'others');
        $visit('2026-05-11', 'clearance', 'graduation'); // retired key
        $this->makeVisit($student, $this->ccs, '2026-05-12'); // legacy, no appointment

        $response = $this->page('?month=2026-05')->assertOk();

        // Sorted by count desc; ties break alphabetically.
        $this->assertSame([
            ['label' => 'Not specified', 'count' => 2],
            ['label' => 'On-the-job Training', 'count' => 2],
            ['label' => 'Others, Specify', 'count' => 2],
            ['label' => 'Field Trip/Educational Tour', 'count' => 1],
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
            ['High Heart Rate', 0, 0.0],
            ['Abnormal Respiratory Rate', 0, 0.0],
        ], $tiles);
    }

    /**
     * D-66 — the card carries FIVE tiles, and the two new flags count and rate
     * against the same denominator (the month's captured screenings).
     */
    public function test_heart_rate_and_respiratory_rate_have_their_own_tiles(): void
    {
        // 5 screenings in June: one HR-flagged (still captured — HR is known
        // from capture) and one RR-flagged (encoded — RR is measured there).
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-06-01', visitStatus: 'captured', vitals: [
            'heart_rate_bpm' => 118, 'is_hr_flagged' => true,
        ]);
        $this->makeVisit($student, $this->ccs, '2026-06-02', vitals: [
            'respiratory_rate' => 26, 'is_rr_flagged' => true,
        ]);
        $this->makeVisit($student, $this->ccs, '2026-06-03');
        $this->makeVisit($student, $this->ccs, '2026-06-04');
        $this->makeVisit($student, $this->ccs, '2026-06-05');

        $response = $this->page('?month=2026-06')->assertOk();

        $this->assertSame(5, $response->viewData('screenings'));

        $tiles = collect($response->viewData('flagTiles'))
            ->map(fn (array $tile) => [$tile['label'], $tile['count'], $tile['rate']])
            ->all();

        $this->assertSame([
            ['High Blood Pressure', 0, 0.0],
            ['Fever', 0, 0.0],
            ['Abnormal BMI', 0, 0.0],
            ['High Heart Rate', 1, 20.0],
            ['Abnormal Respiratory Rate', 1, 20.0],
        ], $tiles);
    }

    /** D-66 — the tile captions quote config, never a literal. */
    public function test_the_new_tile_captions_come_from_config(): void
    {
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-06-01');

        $tiles = $this->page('?month=2026-06')->viewData('flagTiles');

        $this->assertSame('> 100 bpm · flagged at capture', $tiles[3]['sub']);
        $this->assertSame(
            'outside 12–20/min · measured by the clinic at encode',
            $tiles[4]['sub'],
        );
    }

    /**
     * D-78 — an underweight BMI is flagged, so it counts in the Abnormal BMI
     * tile and in Flagged Vitals by Sex (FR-ANL-14); the caption quotes both
     * config bounds. The flag is derived through the ONE rule, never asserted.
     */
    public function test_an_underweight_bmi_counts_as_abnormal(): void
    {
        $female = $this->makeStudent($this->ccs, 'F');
        $this->makeVisit($female, $this->ccs, '2026-06-02', vitals: [
            'bmi' => 17.0, 'is_bmi_flagged' => VitalSigns::isBmiFlagged(17.0),
        ]);
        $this->makeVisit($female, $this->ccs, '2026-06-03');

        $response = $this->page('?month=2026-06')->assertOk();

        $bmiTile = $response->viewData('flagTiles')[2];
        $this->assertSame(['Abnormal BMI', 1], [$bmiTile['label'], $bmiTile['count']]);
        $this->assertSame('BMI < 18.5 or ≥ 25 · flagged at capture', $bmiTile['sub']);

        $bmiRow = $response->viewData('flagsBySexRows')[0];
        $this->assertSame(['Abnormal BMI', 0, 1], [$bmiRow['label'], $bmiRow['male'], $bmiRow['female']]);
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
        // Jan ×2 (both colleges), Feb ×1, Mar ×1 — one series since D-60.
        $ccsStudent = $this->makeStudent($this->ccs);
        $coeStudent = $this->makeStudent($this->coe);
        $this->makeVisit($ccsStudent, $this->ccs, '2026-01-10');
        $this->makeVisit($coeStudent, $this->coe, '2026-01-20');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-02-05');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-03-01');

        $expected = function ($response): void {
            $trend = $response->viewData('trend');
            $this->assertSame(['Jan', 'Feb', 'Mar'], $trend['labels']);
            $this->assertCount(1, $trend['datasets']);
            $this->assertSame([2, 1, 1], $trend['datasets'][0]['data']);
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

        $april = $this->page('?month=2026-04')->assertOk();
        $this->assertSame(1, $april->viewData('totalVisits'));
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
        // Same month, two colleges. Every card scopes on the capture-time
        // college snapshot (FR-STU-09).
        $ccsStudent = $this->makeStudent($this->ccs, 'M');
        $coeStudent = $this->makeStudent($this->coe, 'F');
        $this->makeVisit($ccsStudent, $this->ccs, '2026-05-05', vitals: ['is_bp_flagged' => true]);
        $this->makeVisit($coeStudent, $this->coe, '2026-05-06');

        $response = $this->page('?month=2026-05&college='.$this->ccs->id)->assertOk();

        $this->assertSame($this->ccs->id, $response->viewData('selectedCollegeId'));
        $this->assertSame([
            ['code' => 'CCS', 'visits' => 1],
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

    public function test_month_picker_lists_visit_months_newest_first(): void
    {
        // FR-ANL-13: every month with a visit is offered, newest first —
        // and the newest month with data is the default scope.
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-03-10');
        $this->makeVisit($student, $this->ccs, '2026-06-05');

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

    // ── Card swap: by-College becomes by-Program for one college (D-46) ──────

    public function test_the_by_college_card_renders_with_all_colleges_selected(): void
    {
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-05-05');

        $this->page('?month=2026-05')
            ->assertOk()
            ->assertSee('Clinic Visits by College')
            ->assertDontSee('Clinic Visits by Program');
    }

    public function test_the_by_program_card_replaces_it_when_one_college_is_selected(): void
    {
        // D-46: a single college bar has nothing to compare itself against, so
        // the card drops one level to that college's programs. Same builder as
        // the College Admin page (FR-ADM-08), same totals.
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-05-05');

        $response = $this->page('?month=2026-05&college='.$this->ccs->id)->assertOk();

        $response->assertSee('Clinic Visits by Program')
            ->assertDontSee('Clinic Visits by College')
            // A real CCS program from the catalog (D-42), zero rows included.
            ->assertSee('Bachelor of Science in Information Technology');

        // The swapped-in rows describe the same visits, so they still sum to
        // the card's headline totals.
        $this->assertSame(1, $response->viewData('totalVisits'));
        $this->assertSame(
            $response->viewData('totalVisits'),
            array_sum(array_column($response->viewData('programRows'), 'visits')),
        );
    }

    public function test_nothing_dental_is_left_on_the_page(): void
    {
        // D-60: the service split is gone — no legend, no column, no wording,
        // with data on the page and without.
        $student = $this->makeStudent($this->ccs);
        $this->makeVisit($student, $this->ccs, '2026-05-05');

        foreach (['', '?month=2026-05', '?month=2026-05&college='.$this->ccs->id] as $query) {
            $this->page($query)->assertOk()->assertDontSee('Dental', escape: false);
        }
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
