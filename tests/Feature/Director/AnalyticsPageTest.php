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
 * Director Analytics after the D-32 rescope — the By-Sex donut (FR-ANL-04)
 * and the month scope:
 *  - encoded visits only, counted once per visit by the student's profile
 *    sex; captured (un-encoded) visits count nothing;
 *  - the whole page scoped to one month of checked_in_at, defaulting to
 *    the newest month with data (CaseMonths).
 */
class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
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

    /**
     * Persist one visit (with clearance record when encoded) for $student,
     * frozen to the CCS capture-time snapshot.
     */
    private function makeVisit(User $student, string $visitStatus = 'encoded', ?string $checkedInAt = null): ClinicVisit
    {
        static $seq = 7000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $this->ccs->id,
            'login_method' => 'qr',
            'status' => $visitStatus,
            'checked_in_at' => $checkedInAt ? now()->parse($checkedInAt) : now(),
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

    public function test_guests_and_other_roles_cannot_open_analytics(): void
    {
        $this->get('/director/analytics')->assertRedirect('/login');

        // The role middleware bounces other roles to their own home.
        $this->actingAs($this->nurse)->get('/director/analytics')->assertRedirect();
    }

    public function test_page_renders_with_no_data_at_all(): void
    {
        $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertSee('Screened Students by Sex')
            ->assertSee('No encoded visits yet');
    }

    public function test_by_sex_donut_counts_encoded_visits_by_profile_sex(): void
    {
        // FR-ANL-04: 3 male + 1 female encoded visits; a captured visit
        // never enters the donut (FR-ANL-07), whatever the sex.
        $male = $this->makeStudentOfSex('M');
        $female = $this->makeStudentOfSex('F');

        $this->makeVisit($male);
        $this->makeVisit($male);
        $this->makeVisit($male);
        $this->makeVisit($female);
        $this->makeVisit($female, visitStatus: 'captured');

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertSee('Screened Students by Sex');

        $bySex = $response->viewData('bySex');
        $this->assertSame(['Male', 3, 75], [$bySex[0]['label'], $bySex[0]['count'], $bySex[0]['percent']]);
        $this->assertSame(['Female', 1, 25], [$bySex[1]['label'], $bySex[1]['count'], $bySex[1]['percent']]);

        $this->assertSame(4, $response->viewData('totalScreened'));
        $this->assertSame([3, 1], $response->viewData('donut')['datasets'][0]['data']);
        $this->assertSame(ClinicVisit::encoded()->count(), $response->viewData('totalScreened'));
    }

    public function test_month_picker_scopes_the_donut_to_the_selected_month(): void
    {
        // Two visits in December, one in November — different students so
        // the by-sex split is visibly scoped too.
        $dec = $this->makeStudentOfSex('M');
        $nov = $this->makeStudentOfSex('F');
        $this->makeVisit($dec, checkedInAt: '2026-12-03');
        $this->makeVisit($dec, checkedInAt: '2026-12-20');
        $this->makeVisit($nov, checkedInAt: '2026-11-15');

        $december = $this->actingAs($this->director)
            ->get('/director/analytics?month=2026-12')
            ->assertOk();
        $this->assertSame(2, $december->viewData('totalScreened'));
        $this->assertSame([2, 0], $december->viewData('donut')['datasets'][0]['data']);
        $this->assertSame('2026-12', $december->viewData('selectedMonth'));

        $november = $this->actingAs($this->director)
            ->get('/director/analytics?month=2026-11')
            ->assertOk();
        $this->assertSame(1, $november->viewData('totalScreened'));
        $this->assertSame([0, 1], $november->viewData('donut')['datasets'][0]['data']);
    }

    public function test_no_month_param_defaults_to_the_newest_month_with_data(): void
    {
        $student = $this->makeStudentOfSex('M');
        $this->makeVisit($student, checkedInAt: '2026-10-05');
        $this->makeVisit($student, checkedInAt: '2026-12-05');

        $response = $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk();

        // Newest month with data (December) is the default view.
        $this->assertSame('2026-12', $response->viewData('selectedMonth'));
        $this->assertSame(1, $response->viewData('totalScreened'));

        // The picker lists both months, newest first.
        $this->assertSame(
            [
                ['value' => '2026-12', 'label' => 'December 2026'],
                ['value' => '2026-10', 'label' => 'October 2026'],
            ],
            $response->viewData('availableMonths'),
        );
    }

    public function test_removed_medical_cases_views_are_gone(): void
    {
        // D-32: no cases chart/matrix on the page, and the print + CSV
        // export routes no longer exist.
        $this->actingAs($this->director)
            ->get('/director/analytics')
            ->assertOk()
            ->assertDontSee('Medical Cases by College')
            ->assertDontSee('Summary of Medical Cases')
            ->assertDontSee('Preview &amp; Print', false);

        $this->actingAs($this->director)->get('/director/analytics/summary-print')->assertNotFound();
        $this->actingAs($this->director)->get('/director/anomalies/export')->assertNotFound();
    }
}
