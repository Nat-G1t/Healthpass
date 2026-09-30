<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Director's printable Monthly Clinic Report (D-97): the College Admin's
 * report one level up — visits by college plus a College Summary table, or
 * with one college picked, that college's programs and its one summary row.
 *
 * The College Summary is raw SQL (SUM(CASE …)), so this file is also the
 * SQLite check CLAUDE.md asks for.
 */
class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private const BSIT = 'Bachelor of Science in Information Technology';

    private College $ccs;

    private College $coe;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Education']);
        $this->director = User::factory()->create(['role' => 'director', 'name' => 'Dr. Dela Cruz']);
    }

    /** One captured visit in May 2026 with its vitals row. */
    private function makeVisit(College $college, string $sex, array $flags = [], string $checkedInAt = '2026-05-10 09:00:00'): void
    {
        static $seq = 7000;

        $student = User::factory()->create(['role' => 'student']);
        StudentProfile::factory()->forCollege($college)->create(['user_id' => $student->id, 'sex' => $sex]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'course' => $college->is($this->ccs) ? self::BSIT : null,
            'login_method' => 'qr',
            'status' => 'captured',
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
            ...$flags,
        ]);
    }

    private function report(string $query = ''): TestResponse
    {
        return $this->actingAs($this->director)->get('/director/analytics/print'.$query);
    }

    /** @return array<string, array> summary rows keyed by college code */
    private function summaryByCode(TestResponse $response): array
    {
        return collect($response->viewData('summaryRows'))->keyBy('code')->all();
    }

    public function test_only_the_director_can_print_it(): void
    {
        $this->get('/director/analytics/print')->assertRedirect('/login');

        foreach (['student', 'nurse', 'physician'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/director/analytics/print')
                ->assertRedirect();
        }

        $admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->actingAs($admin)->get('/director/analytics/print')->assertRedirect();
    }

    public function test_all_colleges_prints_visits_by_college_and_the_college_summary(): void
    {
        // CCS: 3 visits (2 M, 1 F) — one with BP + BMI flags (flagged ONCE),
        // one with a fever. COE: 1 female visit, abnormal BMI.
        $this->makeVisit($this->ccs, 'M', ['is_bp_flagged' => true, 'is_bmi_flagged' => true]);
        $this->makeVisit($this->ccs, 'M', ['is_temp_flagged' => true]);
        $this->makeVisit($this->ccs, 'F');
        $this->makeVisit($this->coe, 'F', ['is_bmi_flagged' => true]);
        // Another month — must not count.
        $this->makeVisit($this->coe, 'M', ['is_bp_flagged' => true], '2026-04-10 09:00:00');

        $response = $this->report('?month=2026-05')
            ->assertOk()
            ->assertSee('All colleges')
            ->assertSee('Clinic Visits by College')
            ->assertSee('College Summary')
            ->assertDontSee('Clinic Visits by Program')
            ->assertSee('by Dr. Dela Cruz');

        $summary = $this->summaryByCode($response);

        $this->assertSame(['code' => 'CCS', 'visits' => 3, 'flagged' => 2, 'bmi' => 1, 'male' => 2, 'female' => 1], $summary['CCS']);
        $this->assertSame(['code' => 'COE', 'visits' => 1, 'flagged' => 1, 'bmi' => 1, 'male' => 0, 'female' => 1], $summary['COE']);
        $this->assertSame(['visits' => 4, 'flagged' => 3, 'bmi' => 2, 'male' => 2, 'female' => 2], $response->viewData('summaryTotals'));

        // The visits column agrees with the by-college table.
        $this->assertSame(4, $response->viewData('totalVisits'));
    }

    public function test_one_college_prints_only_that_college_with_its_programs(): void
    {
        $this->makeVisit($this->ccs, 'M');
        $this->makeVisit($this->coe, 'F');

        $response = $this->report("?month=2026-05&college={$this->ccs->id}")
            ->assertOk()
            ->assertSee('College of Computing Studies (CCS)')
            ->assertSee('Clinic Visits by Program')
            ->assertSee(self::BSIT);

        $this->assertSame(['CCS'], array_keys($this->summaryByCode($response)));
        $this->assertSame(1, $response->viewData('totalVisits'));
    }

    public function test_a_hand_edited_college_falls_back_to_all_colleges(): void
    {
        $this->report('?month=2026-05&college=abc')->assertOk()->assertSee('All colleges');
        $this->report('?month=2026-05&college=99999')->assertOk()->assertSee('All colleges');
    }

    public function test_the_analytics_page_links_the_report_with_its_filters(): void
    {
        $this->makeVisit($this->ccs, 'M');

        $this->actingAs($this->director)
            ->get("/director/analytics?month=2026-05&college={$this->ccs->id}")
            ->assertOk()
            ->assertSee('Print Monthly Report')
            ->assertSee(e(route('director.analytics.print', ['month' => '2026-05', 'college' => $this->ccs->id])), false);
    }
}
