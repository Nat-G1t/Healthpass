<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Batch results on the roster page (FR-ADM-07 as amended by D-53).
 *
 * The College Admin who booked a graduation cohort has to know two things the
 * roster could not tell them before: who has actually been to the clinic, and
 * what the nurse encoded. `appointments.status` alone cannot answer the first
 * — the kiosk LINKS a clinic visit but leaves the status on `scheduled`, and
 * only the nurse's encode flips it to `completed` — so the progress rule reads
 * the visit and its clearance record too.
 *
 * The second is CLINICAL, and D-53 amended PRD §6.6 to permit exactly one
 * field of it (Fit / Unfit) to the admin of that student's own college. The
 * tests at the bottom pin down that the rest of the record stays out.
 */
class BatchResultsTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** An approved batch on $date with no students yet. */
    private function makeApprovedBatch(?string $date = null, ?College $college = null): BatchRequest
    {
        static $seq = 800;

        $college ??= $this->ccs;
        $date ??= now()->addDays(3)->toDateString();

        return BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'graduation',
            'service_type' => 'medical',
            'requested_date' => $date,
            'requested_time' => '09:00:00',
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor(4),
            'scheduled_date' => $date,
            'status' => 'approved',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Put one student on $batch and drive them to $stage:
     *
     *   'booked'    — appointment only, nothing at the kiosk
     *   'withdrawn' — the admin pulled the seat
     *   'in_clinic' — kiosk visit captured, the nurse has not encoded it
     *   'Fit'/'Unfit' — encoded with that outcome
     */
    private function addStudent(BatchRequest $batch, string $stage, string $name): Appointment
    {
        static $seq = 8000;

        $seq++;

        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'college_id' => $batch->college_id,
        ]);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $batch->scheduled_date,
            'scheduled_time' => '09:00:00',
            'status' => $stage === 'withdrawn' ? 'cancelled' : 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
            'appointment_id' => $appointment->id,
        ]);

        if (in_array($stage, ['booked', 'withdrawn'], true)) {
            return $appointment;
        }

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-'.now()->year.'-'.$seq,
            'student_id' => $student->id,
            'college_id' => $batch->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $stage === 'in_clinic' ? 'captured' : 'encoded',
            'checked_in_at' => now(),
        ]);

        if ($stage === 'in_clinic') {
            return $appointment;
        }

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => $stage,
            'nurse_notes' => 'Borderline blood pressure, advise follow-up.',
            'encoded_at' => now(),
        ]);

        // The nurse's encode is what flips the appointment (EncodeController).
        $appointment->update(['status' => 'completed']);

        return $appointment;
    }

    private function roster(BatchRequest $batch, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->get("/admin/batches/{$batch->id}");
    }

    // ── Per-student result ───────────────────────────────────────────────────

    public function test_roster_shows_fit_and_unfit_per_student(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'Unfit', 'Ben Flagged');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Ana Cleared');
        $response->assertSee('Ben Flagged');
        $response->assertSee('Fit');
        $response->assertSee('Unfit');
        $response->assertSee('Result');
    }

    public function test_a_student_who_has_not_attended_shows_no_result(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'booked', 'Carla Booked');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Not yet attended');
        $response->assertDontSee('Completed');
    }

    public function test_a_captured_visit_reads_as_at_the_clinic_with_no_result_yet(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'in_clinic', 'Dino Waiting');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('At the clinic');
        // The nurse has not ruled — there must be no outcome on the page.
        $response->assertDontSee('>Fit<', false);
        $response->assertDontSee('>Unfit<', false);
    }

    public function test_a_past_clinic_date_with_no_visit_reads_as_did_not_attend(): void
    {
        $batch = $this->makeApprovedBatch(now()->subDays(4)->toDateString());
        $this->addStudent($batch, 'booked', 'Elmo Absent');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Did not attend');
    }

    public function test_a_withdrawn_student_reads_as_withdrawn_and_carries_no_result(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Withdrawn');
        $response->assertDontSee('>Fit<', false);
    }

    // ── The roll-up ──────────────────────────────────────────────────────────

    public function test_the_summary_rolls_up_the_whole_batch(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'Fit', 'Ben Cleared');
        $this->addStudent($batch, 'Unfit', 'Cara Flagged');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Clearance results');
        $response->assertSee('2 Fit');
        $response->assertSee('1 Unfit');
        $response->assertSee('1 still to attend');
    }

    public function test_a_batch_with_one_booked_student_rolls_up_as_still_to_attend(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('1 still to attend');
    }

    public function test_the_summary_degrades_cleanly_on_a_batch_with_no_appointments(): void
    {
        // The only state that produces no parts at all — every appointment
        // falls into one of the counted buckets.
        $batch = $this->makeApprovedBatch();

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Nothing to report');
    }

    // ── Schedule + purpose (what the page has to state) ──────────────────────

    public function test_the_page_states_the_date_the_hour_span_and_the_purpose(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Clinic date');
        $response->assertSee($batch->requested_date->format('l, F j, Y'));
        $response->assertSee('Hour span');
        $response->assertSee('9:00 AM');
        $response->assertSee('Purpose');
        $response->assertSee('Graduation Clearance');
        $response->assertSee('Medical Clearance');
    }

    // ── A non-approved batch has no results to show ──────────────────────────

    public function test_a_pending_batch_shows_no_result_column(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $batch->update(['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null]);

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertDontSee('Clearance results');
        $response->assertSee('Course &amp; Year', false);
    }

    // ── Scope + §6.6 (D-53 opens the OUTCOME, and only the outcome) ──────────

    public function test_another_colleges_batch_is_not_readable(): void
    {
        $batch = $this->makeApprovedBatch(college: $this->coe);
        $this->addStudent($batch, 'Unfit', 'Gina Other');

        $this->roster($batch)->assertNotFound();
    }

    public function test_the_roster_exposes_the_outcome_but_not_the_rest_of_the_record(): void
    {
        $batch = $this->makeApprovedBatch();
        $this->addStudent($batch, 'Unfit', 'Ben Flagged');

        $response = $this->roster($batch);

        $response->assertOk();
        $response->assertSee('Unfit');
        // §6.6 as amended by D-53 opens the OUTCOME only — the nurse's notes,
        // the visit reference and the physician's details stay out of reach.
        $response->assertDontSee('Borderline blood pressure');
        $response->assertDontSee('HP-'.now()->year);
    }
}
