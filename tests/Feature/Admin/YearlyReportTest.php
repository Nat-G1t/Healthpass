<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\YearlyClearanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Yearly Clearance Report (FR-ADM-13, D-81) — one calendar year's clearances
 * for the admin's college, downloaded as a PDF.
 *
 * The numbers are checked against App\Services\YearlyClearanceReport directly
 * (fast and exact); the HTTP tests only check access, validation and the
 * download headers — PDF bytes are never parsed.
 */
class YearlyReportTest extends TestCase
{
    use RefreshDatabase;

    private const BSIT = 'Bachelor of Science in Information Technology';

    private const BSCS = 'Bachelor of Science in Computer Science';

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned so "the current year" is 2026 in every test.
        $this->travelTo(now()->setDate(2026, 9, 25)->setTime(10, 0));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Education']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    private function makeStudent(College $college, string $last, string $first, string $sex = 'M', ?string $middle = null): User
    {
        $student = User::factory()->create(['role' => 'student']);

        StudentProfile::factory()->forCollege($college)->create([
            'user_id' => $student->id,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'sex' => $sex,
        ]);

        return $student;
    }

    /** An approved batch submitted by `$college` (D-61: every visit comes from one). */
    private function makeBatch(College $college): BatchRequest
    {
        static $seq = 100;

        return BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => '2026-03-01',
            'scheduled_date' => '2026-03-01',
            'status' => 'approved',
        ]);
    }

    /**
     * One visit at `$checkedInAt`, linked to an appointment from `$batch`
     * (a batch of `$college` by default; `false` = a pre-D-61 self-booking
     * with no batch). An `encoded` visit also gets its Fit/Unfit row;
     * `captured` and `resting` visits have none, as in the real flow.
     */
    private function makeVisit(
        User $student,
        College $college,
        string $checkedInAt,
        string $result = 'Fit',
        string $status = 'encoded',
        ?string $course = self::BSIT,
        BatchRequest|false|null $batch = null,
    ): ClinicVisit {
        static $seq = 7000;

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->parse($checkedInAt)->toDateString(),
            'status' => 'completed',
            'source' => $batch === false ? 'self' : 'batch',
            'batch_request_id' => $batch === false ? null : ($batch ?? $this->makeBatch($college))->id,
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => $college->id,
            'appointment_id' => $appointment->id,
            'course' => $course,
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

    private function report(int $year = 2026): YearlyClearanceReport
    {
        return new YearlyClearanceReport($this->ccs, $year);
    }

    private function download(string $query = '?year=2026'): TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/analytics/yearly-report'.$query);
    }

    // ── The download (HTTP layer) ────────────────────────────────────────────

    public function test_a_college_admin_downloads_the_report_as_a_pdf(): void
    {
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $this->makeVisit($student, $this->ccs, '2026-03-14 09:00');

        $response = $this->download()->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename=HealthPass-Yearly-Report-CCS-2026.pdf',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_every_other_role_and_a_guest_are_refused(): void
    {
        $this->get('/admin/analytics/yearly-report?year=2026')->assertRedirect('/login');

        // The role middleware bounces every other role to its own home.
        foreach (['student', 'nurse', 'director'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/analytics/yearly-report?year=2026')
                ->assertRedirect();
        }

        $this->actingAs(User::factory()->physician()->create())
            ->get('/admin/analytics/yearly-report?year=2026')
            ->assertRedirect();
    }

    public function test_an_admin_with_no_managed_college_is_refused(): void
    {
        $unassigned = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => null]);

        $this->actingAs($unassigned)->get('/admin/analytics/yearly-report?year=2026')->assertForbidden();
    }

    public function test_an_empty_year_still_downloads(): void
    {
        $this->download('?year=2021')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=HealthPass-Yearly-Report-CCS-2021.pdf');

        $this->assertSame([], $this->report(2021)->rows());
        $this->assertSame(
            ['total' => 0, 'fit' => 0, 'unfit' => 0, 'male' => 0, 'female' => 0],
            $this->report(2021)->summary(),
        );
    }

    public function test_the_first_year_and_the_current_year_are_accepted(): void
    {
        $this->download('?year=2021')->assertOk();
        $this->download('?year=2026')->assertOk();
    }

    public function test_a_year_outside_the_range_or_not_a_number_is_refused(): void
    {
        foreach (['?year=2020', '?year=2027', '?year=abc', ''] as $query) {
            $this->download($query)->assertRedirect()->assertSessionHasErrors('year');
        }
    }

    // ── Scope (FR-AUTH-06 / FR-ADM-06) ───────────────────────────────────────

    public function test_another_colleges_clearances_never_appear(): void
    {
        $ours = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $theirs = $this->makeStudent($this->coe, 'Reyes', 'Maria', 'F');
        $this->makeVisit($ours, $this->ccs, '2026-03-14 09:00');
        $this->makeVisit($theirs, $this->coe, '2026-03-15 09:00');

        $this->assertSame(['Santos, Juan'], array_column($this->report()->rows(), 'name'));
    }

    public function test_a_forged_college_parameter_changes_nothing(): void
    {
        $theirs = $this->makeStudent($this->coe, 'Reyes', 'Maria', 'F');
        $this->makeVisit($theirs, $this->coe, '2026-03-15 09:00');

        // Still this admin's college in the file name — ?college= is never read.
        $this->download('?year=2026&college='.$this->coe->id)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=HealthPass-Yearly-Report-CCS-2026.pdf');
    }

    // ── Privacy: only the college's own batches (§6.6, D-81) ────────────────

    public function test_a_visit_from_another_colleges_batch_is_left_out(): void
    {
        // A student on a COE batch who then switched to CCS: the visit is
        // captured under CCS, but CCS never requested that clearance.
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $this->makeVisit($student, $this->ccs, '2026-03-14 09:00', batch: $this->makeBatch($this->coe));

        $this->assertSame([], $this->report()->rows());
        $this->assertSame([], (new YearlyClearanceReport($this->coe, 2026))->rows());
    }

    public function test_a_self_booked_visit_with_no_batch_is_left_out(): void
    {
        // Pre-D-61 self-booking: no batch of this college generated it.
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $this->makeVisit($student, $this->ccs, '2026-03-14 09:00', batch: false);

        $this->assertSame(0, $this->report()->summary()['total']);
    }

    public function test_a_visit_with_no_appointment_is_left_out(): void
    {
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $visit = $this->makeVisit($student, $this->ccs, '2026-03-14 09:00');
        $visit->update(['appointment_id' => null]);

        $this->assertSame(0, $this->report()->summary()['total']);
    }

    // ── What counts ──────────────────────────────────────────────────────────

    public function test_only_encoded_visits_count(): void
    {
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $this->makeVisit($student, $this->ccs, '2026-03-14 09:00', 'Fit');
        $this->makeVisit($student, $this->ccs, '2026-04-14 09:00', status: 'captured');
        $this->makeVisit($student, $this->ccs, '2026-05-14 09:00', status: 'resting');

        $summary = $this->report()->summary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame($summary['total'], $summary['fit'] + $summary['unfit']);
    }

    public function test_the_counts_match_a_known_mix_and_a_repeat_student_counts_twice(): void
    {
        $juan = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $maria = $this->makeStudent($this->ccs, 'Reyes', 'Maria', 'F');
        $ana = $this->makeStudent($this->ccs, 'Cruz', 'Ana', 'F');

        $this->makeVisit($juan, $this->ccs, '2026-02-01 09:00', 'Unfit');
        $this->makeVisit($juan, $this->ccs, '2026-08-01 09:00', 'Fit');   // cleared again
        $this->makeVisit($maria, $this->ccs, '2026-03-01 09:00', 'Fit');
        $this->makeVisit($ana, $this->ccs, '2026-04-01 09:00', 'Unfit');

        $this->assertSame(
            ['total' => 4, 'fit' => 2, 'unfit' => 2, 'male' => 2, 'female' => 2],
            $this->report()->summary(),
        );
        $this->assertCount(2, array_filter($this->report()->rows(), fn (array $row) => $row['name'] === 'Santos, Juan'));
    }

    public function test_the_year_boundary_follows_manila_time(): void
    {
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan');
        $this->makeVisit($student, $this->ccs, '2025-12-31 23:30');
        $this->makeVisit($student, $this->ccs, '2026-01-01 00:10');

        $this->assertSame(['Dec 31, 2025'], array_map(
            fn (array $row) => $row['checkedInAt']->format('M j, Y'),
            $this->report(2025)->rows(),
        ));
        $this->assertSame(['Jan 1, 2026'], array_map(
            fn (array $row) => $row['checkedInAt']->format('M j, Y'),
            $this->report(2026)->rows(),
        ));
    }

    // ── The rows ─────────────────────────────────────────────────────────────

    public function test_rows_come_out_oldest_first_then_by_last_name(): void
    {
        $zamora = $this->makeStudent($this->ccs, 'Zamora', 'Rex');
        $abalos = $this->makeStudent($this->ccs, 'Abalos', 'Mark');
        $cruz = $this->makeStudent($this->ccs, 'Cruz', 'Carlo');

        $this->makeVisit($cruz, $this->ccs, '2026-06-01 09:00');
        $this->makeVisit($zamora, $this->ccs, '2026-03-01 09:00');
        $this->makeVisit($abalos, $this->ccs, '2026-03-01 09:00');   // same second as Zamora

        $this->assertSame(
            ['Abalos, Mark', 'Zamora, Rex', 'Cruz, Carlo'],
            array_column($this->report()->rows(), 'name'),
        );
    }

    public function test_a_row_carries_the_name_status_date_and_visit_program(): void
    {
        $student = $this->makeStudent($this->ccs, 'Santos', 'Juan', middle: 'Dizon');
        $this->makeVisit($student, $this->ccs, '2026-03-14 09:00', 'Unfit', course: self::BSCS);
        $this->makeVisit($student, $this->ccs, '2026-04-14 09:00', course: null);

        [$first, $second] = $this->report()->rows();

        $this->assertSame('Santos, Juan D.', $first['name']);
        $this->assertSame('Unfit', $first['result']);
        $this->assertSame('Mar 14, 2026', $first['checkedInAt']->format('M j, Y'));
        // The program recorded AT the visit (D-43), not the profile's.
        $this->assertSame(self::BSCS, $first['program']);
        $this->assertSame(YearlyClearanceReport::NO_PROGRAM, $second['program']);
    }

    // ── The analytics page ───────────────────────────────────────────────────

    public function test_the_analytics_page_offers_the_button_and_every_year_newest_first(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/analytics')
            ->assertOk()
            ->assertSee('Yearly Report (PDF)')
            ->assertSee('Download Yearly Report')
            ->assertSee(route('admin.analytics.yearly-report'), false)
            ->assertSeeInOrder([
                '<option value="2026" selected',
                '<option value="2025"',
                '<option value="2024"',
                '<option value="2023"',
                '<option value="2022"',
                '<option value="2021"',
            ], false);

        $this->assertStringNotContainsString('<option value="2020"', $response->getContent());
        $this->assertStringNotContainsString('<option value="2027"', $response->getContent());
    }

    public function test_the_director_analytics_page_has_no_yearly_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'director']))
            ->get('/director/analytics')
            ->assertOk()
            ->assertDontSee('Yearly Report');
    }
}
