<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use App\Services\ClinicAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flagged Vitals by Sex (FR-ANL-14). The grouped query uses raw conditional
 * sums, so this runs on the SQLite suite to catch a MySQL-only function (IF(),
 * say) creeping in — CLAUDE.md's raw-SQL rule.
 */
class FlagsBySexTest extends TestCase
{
    use RefreshDatabase;

    private const ACT = 'Associate in Computer Technology';

    private const BSIS = 'Bachelor of Science in Information Systems';

    private College $ccs;

    private College $coe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Education']);
    }

    /** A student whose profile carries this college and sex. */
    private function makeStudent(College $college, string $sex): User
    {
        $student = User::factory()->create(['role' => 'student']);
        StudentProfile::factory()->forCollege($college)->create(['user_id' => $student->id, 'sex' => $sex]);

        return $student;
    }

    /**
     * One May 2026 visit plus its vitals row, with the given flags set.
     *
     * @param  list<string>  $flags  e.g. ['is_bp_flagged', 'is_hr_flagged']
     */
    private function makeVisit(
        User $student,
        College $college,
        array $flags,
        string $status = 'captured',
        ?string $course = null,
        string $checkedInAt = '2026-05-12 09:00:00',
    ): void {
        static $seq = 8000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'course' => $course,
            'login_method' => 'qr',
            'status' => $status,
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
            'is_bmi_flagged' => in_array('is_bmi_flagged', $flags, true),
            'is_temp_flagged' => in_array('is_temp_flagged', $flags, true),
            'is_bp_flagged' => in_array('is_bp_flagged', $flags, true),
            'is_hr_flagged' => in_array('is_hr_flagged', $flags, true),
            'is_rr_flagged' => in_array('is_rr_flagged', $flags, true),
        ]);
    }

    private function analytics(?College $college = null, ?string $course = null): array
    {
        return (new ClinicAnalytics(CarbonImmutable::parse('2026-05-01'), $college, $course))->flagsBySex();
    }

    /** [short => [male, female, total]] — compact for exact assertions. */
    private function counts(array $result): array
    {
        return collect($result['flagsBySexRows'])
            ->mapWithKeys(fn (array $row) => [$row['short'] => [$row['male'], $row['female'], $row['total']]])
            ->all();
    }

    public function test_each_flag_is_counted_per_sex(): void
    {
        $male1 = $this->makeStudent($this->ccs, 'M');
        $male2 = $this->makeStudent($this->ccs, 'M');
        $female = $this->makeStudent($this->coe, 'F');

        // One visit can carry several flags — each counts under its own bar.
        $this->makeVisit($male1, $this->ccs, ['is_bp_flagged', 'is_hr_flagged']);
        $this->makeVisit($male2, $this->ccs, ['is_bp_flagged', 'is_bmi_flagged'], 'encoded');
        $this->makeVisit($female, $this->coe, ['is_temp_flagged', 'is_rr_flagged', 'is_bp_flagged']);
        $this->makeVisit($female, $this->coe, []); // unflagged — counts nowhere

        $result = $this->analytics();

        $this->assertSame([
            'BMI' => [1, 0, 1],
            'Temp' => [0, 1, 1],
            'BP' => [2, 1, 3],
            'PR' => [1, 0, 1],
            'RR' => [0, 1, 1],
        ], $this->counts($result));

        // Chart payload: short axis labels, full names for the tooltip, the
        // shared by-sex colours.
        $chart = $result['flagsBySex'];
        $this->assertSame(['BMI', 'Temp', 'BP', 'PR', 'RR'], $chart['labels']);
        $this->assertSame(
            ['Abnormal BMI', 'Fever', 'High Blood Pressure', 'High Heart Rate', 'Abnormal Respiratory Rate'],
            $chart['fullLabels'],
        );
        $this->assertSame(['Male', '#FF8C2A', [1, 0, 2, 1, 0]],
            [$chart['datasets'][0]['label'], $chart['datasets'][0]['backgroundColor'], $chart['datasets'][0]['data']]);
        $this->assertSame(['Female', '#FFCAA0', [0, 1, 1, 0, 1]],
            [$chart['datasets'][1]['label'], $chart['datasets'][1]['backgroundColor'], $chart['datasets'][1]['data']]);
    }

    public function test_a_resting_visit_and_other_months_are_excluded(): void
    {
        $male = $this->makeStudent($this->ccs, 'M');

        // D-72: a first pass the clinic never saw — the re-check may clear it.
        $this->makeVisit($male, $this->ccs, ['is_bp_flagged', 'is_temp_flagged'], 'resting');
        $this->makeVisit($male, $this->ccs, ['is_bp_flagged'], 'captured', null, '2026-04-30 16:00:00');

        $this->assertSame(0, collect($this->analytics()['flagsBySexRows'])->sum('total'));
    }

    public function test_the_college_and_program_filters_narrow_it(): void
    {
        $ccsMale = $this->makeStudent($this->ccs, 'M');
        $ccsFemale = $this->makeStudent($this->ccs, 'F');
        $coeMale = $this->makeStudent($this->coe, 'M');

        $this->makeVisit($ccsMale, $this->ccs, ['is_bp_flagged'], 'captured', self::ACT);
        $this->makeVisit($ccsFemale, $this->ccs, ['is_bp_flagged'], 'captured', self::BSIS);
        $this->makeVisit($coeMale, $this->coe, ['is_bp_flagged']);

        $this->assertSame([2, 1, 3], $this->counts($this->analytics())['BP']);
        $this->assertSame([1, 1, 2], $this->counts($this->analytics($this->ccs))['BP']);
        $this->assertSame([0, 1, 1], $this->counts($this->analytics($this->ccs, self::BSIS))['BP']);
    }

    public function test_the_card_and_the_printed_table_render(): void
    {
        $male = $this->makeStudent($this->ccs, 'M');
        $this->makeVisit($male, $this->ccs, ['is_rr_flagged'], 'captured', null, now()->format('Y-m-d H:i:s'));

        $director = User::factory()->create(['role' => 'director']);
        $this->actingAs($director)->get('/director/analytics')
            ->assertOk()
            ->assertSee('Flagged Vitals by Sex')
            ->assertSee('data-flags-by-sex', false);

        $admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->actingAs($admin)->get('/admin/analytics')
            ->assertOk()
            ->assertSee('data-flags-by-sex', false);

        $this->actingAs($admin)->get('/admin/analytics/print')
            ->assertOk()
            ->assertSeeInOrder(['Flagged Vitals by Sex', 'Abnormal Respiratory Rate', '1', '0', '1']);
    }

    public function test_the_card_shows_an_empty_state_with_no_flags(): void
    {
        $director = User::factory()->create(['role' => 'director']);

        $this->actingAs($director)->get('/director/analytics')
            ->assertOk()
            ->assertSee('No flagged vitals for this month yet')
            ->assertDontSee('data-flags-by-sex', false);
    }
}
