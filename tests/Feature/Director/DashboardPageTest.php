<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Director Dashboard (FR-ANL-01): four KPI cards from live data plus the
 * Pending Batch Approvals preview panel, capped at 3 rows with a "View all →"
 * link. Empty states are intentional copy, not blank space. D-135 removed the
 * Flagged Anomalies page and its dashboard panel; the Flagged Visits KPI stays.
 */
class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $admin;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Persist a batch directly (bypassing the admin form). */
    private function makeBatch(array $overrides = [], ?College $college = null): BatchRequest
    {
        static $seq = 700;

        return BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => ($college ?? $this->ccs)->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
        ], $overrides));
    }

    /**
     * A kiosk visit + vitals for a freshly-named student. Flags default off;
     * pass vitals overrides to trip them.
     *
     * @param  array<string, mixed>  $vitals
     */
    private function makeVisit(string $name, array $vitals = [], string $status = 'captured'): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('D###'),
            'student_id' => $student->id,
            'college_id' => $this->ccs->id,
            'login_method' => 'qr',
            'status' => $status,
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(fake()->unique()->numberBetween(1, 5000)),
        ]);

        VitalSigns::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ], $vitals));

        return $visit;
    }

    /** An encoded visit with a saved clearance record. */
    private function makeEncodedClearance(string $name): ClinicVisit
    {
        $visit = $this->makeVisit($name, [], 'encoded');

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'purpose' => 'On-the-job Training',
            'encoded_at' => now(),
        ]);

        return $visit;
    }

    // ── 1. Access control ─────────────────────────────────────────────────────

    public function test_dashboard_is_refused_for_guests_and_other_roles(): void
    {
        $this->get('/director/dashboard')->assertRedirect('/login');

        foreach (['student', 'nurse', 'college_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/director/dashboard')->assertRedirect();
        }
    }

    // ── 2. KPI cards ──────────────────────────────────────────────────────────

    public function test_kpi_cards_count_from_live_data(): void
    {
        // 2 encoded clearances.
        $this->makeEncodedClearance('Encoded One');
        $this->makeEncodedClearance('Encoded Two');

        // 1 pending batch out of 3 (decided ones don't count).
        $this->makeBatch();
        $this->makeBatch(['status' => 'approved']);
        $this->makeBatch(['status' => 'rejected']);

        // 3 appointments today — but 1 is cancelled (BR-02: doesn't count) —
        // plus 1 tomorrow (out of scope). Expect 2.
        Appointment::factory()->count(2)->onDate(today()->toDateString())->create();
        Appointment::factory()->cancelled()->onDate(today()->toDateString())->create();
        Appointment::factory()->onDate(today()->addDay()->toDateString())->create();

        // 1 flagged visit — captured, not yet encoded: flags surface from
        // capture (FR-ANL-07). The unflagged + encoded visits don't count.
        $this->makeVisit('Flagged Student', ['is_bp_flagged' => true, 'bp_systolic' => 145, 'bp_diastolic' => 92]);
        $this->makeVisit('Normal Student');

        $response = $this->actingAs($this->director)->get('/director/dashboard')->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(2, $stats['clearances']);
        $this->assertSame(1, $stats['pendingBatches']);
        $this->assertSame(2, $stats['todaysAppointments']);
        $this->assertSame(1, $stats['flaggedVisits']);
    }

    // ── 3. Pending Batch Approvals panel ─────────────────────────────────────

    public function test_batch_panel_previews_the_three_newest_pending_batches(): void
    {
        $colleges = collect(['CEA', 'CBS', 'CHTM', 'COE'])->map(
            fn (string $code) => College::create(['code' => $code, 'name' => "College {$code}"])
        );

        // 4 pending batches, oldest → newest; only the 3 newest preview.
        // (created_at isn't fillable, so it's set directly, not via create().)
        $ages = [4, 3, 2, 1];
        $batches = $colleges->map(function (College $college, int $i) use ($ages): BatchRequest {
            $batch = $this->makeBatch([], $college);
            $batch->created_at = now()->subDays($ages[$i]);
            $batch->save();

            return $batch;
        });
        $oldest = $batches[0];

        // A decided batch never previews, whatever its age.
        $this->makeBatch(['status' => 'approved']);

        $this->actingAs($this->director)
            ->get('/director/dashboard')
            ->assertOk()
            ->assertSee('Pending Batch Approvals')
            ->assertSee('4 pending')
            ->assertSee('College CBS')
            ->assertSee('College CHTM')
            ->assertSee('College COE')
            ->assertDontSee($oldest->college->name)
            ->assertSee(route('director.batches.index'));
    }

    // ── 4. Flagged Anomalies is gone (D-135) ──────────────────────────────────

    public function test_flagged_anomalies_page_and_panel_are_gone(): void
    {
        $visit = $this->makeVisit('Bp Flagged', ['is_bp_flagged' => true, 'bp_systolic' => 145, 'bp_diastolic' => 92]);

        // The page, its record view and its sidebar item no longer exist.
        $this->actingAs($this->director)->get('/director/anomalies')->assertNotFound();
        $this->actingAs($this->director)->get("/director/anomalies/{$visit->id}")->assertNotFound();

        // The dashboard keeps the Flagged Visits count but previews no
        // flagged student and links to no anomalies page.
        $this->actingAs($this->director)
            ->get('/director/dashboard')
            ->assertOk()
            ->assertSee('Flagged Visits')
            ->assertDontSee('Flagged Anomalies')
            ->assertDontSee('Bp Flagged')
            ->assertDontSee('/director/anomalies');
    }

    // ── 5. Empty states ───────────────────────────────────────────────────────

    public function test_empty_dashboard_shows_intentional_empty_states(): void
    {
        $this->actingAs($this->director)
            ->get('/director/dashboard')
            ->assertOk()
            ->assertSee('No pending batch approvals')
            ->assertSee('New requests from College Admins will appear here.');
    }
}
