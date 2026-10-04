<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New Batch Request — persistence + confirmation screen (FR-ADM-04, BR-05/06/07).
 *
 * Validation-only behaviour (reasons, service type, scope rejection messages)
 * is covered in BatchRequestCreateTest; this file covers what a VALID (or
 * almost-valid) submission writes to the database and what the admin sees
 * afterwards.
 */
class BatchRequestSubmitTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Written around D-37's 12 an hour and 120 a day, which these tests'
        // numbers (13 → 12 + 1, a full hour of 12, a 121-student batch …)
        // still exercise. The D-95 default of 20 is asserted in
        // the unit test ClinicScheduleServiceTest.
        config(['healthpass.hourly_capacity' => 12, 'healthpass.daily_capacity' => 120]);

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /** Submit a valid batch for $count CCS students (2 = the D-105 minimum); returns the response. */
    private function submitBatch(int $count = 2, array $overrides = [])
    {
        $students = StudentProfile::factory()->count($count)->forCollege($this->ccs)->create();

        return $this->actingAs($this->admin)->post('/admin/batches', array_merge([
            'form_type' => 'assessment',
            'reason' => 'ojt',
            // D-108: no clinic date and no start hour — the Director picks both.
            'students' => $students->pluck('id')->all(),
        ], $overrides));
    }

    // ── FR-ADM-04: one batch row + one pivot row per student ────────────────

    public function test_submitting_30_students_creates_one_batch_and_exactly_30_pivot_rows(): void
    {
        $students = StudentProfile::factory()->count(30)->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'form_type' => 'clearance',
                'reason' => 'fieldtrip',
                'students' => $students->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseCount('batch_request_students', 30);

        $batch = BatchRequest::sole();
        $this->assertSame('pending', $batch->status);
        $this->assertSame($this->ccs->id, $batch->college_id);
        $this->assertSame($this->admin->id, $batch->requested_by);
        $this->assertSame('clearance', $batch->form_type);   // D-62
        $this->assertSame('fieldtrip', $batch->reason);
        $this->assertSame('medical', $batch->service_type);
        // D-108: no date and no hours — the Director schedules it at approval.
        // The legacy requested_* columns stay empty on every new batch.
        $this->assertNull($batch->requested_date);
        $this->assertNull($batch->requested_time);
        $this->assertNull($batch->requested_blocks);
        $this->assertNull($batch->scheduled_date);
        $this->assertNull($batch->scheduled_time);
        $this->assertNull($batch->scheduled_blocks);

        // The pivot stores USER ids (data dictionary: student_id → users),
        // not the student_profile ids the form posts.
        $this->assertEqualsCanonicalizing(
            $students->pluck('user_id')->all(),
            BatchRequestStudent::pluck('student_id')->all(),
        );

        // appointment_id stays NULL until the Director approves (BR-08).
        $this->assertSame(0, BatchRequestStudent::whereNotNull('appointment_id')->count());
    }

    public function test_reference_numbers_are_minted_sequentially_in_br_format(): void
    {
        $this->submitBatch()->assertSessionHasNoErrors();
        $this->submitBatch()->assertSessionHasNoErrors();

        $year = now()->year;

        $this->assertSame(
            ["BR-{$year}-001", "BR-{$year}-002"],
            BatchRequest::orderBy('id')->pluck('reference_no')->all(),
        );
    }

    // ── BR-07 (D-105): a batch needs at least two students ──────────────────

    public function test_a_one_student_batch_is_refused(): void
    {
        $this->submitBatch(1)->assertSessionHasErrors([
            'students' => 'Select at least 2 students for this batch.',
        ]);

        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_a_two_student_batch_is_created(): void
    {
        $this->submitBatch(2)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseCount('batch_request_students', 2);
    }

    // ── The one schedule rule left at submission: one clinic day ────────────

    public function test_a_batch_of_121_students_is_rejected(): void
    {
        // 121 > 12 × 10 — no start hour the Director could pick fits it in one day.
        $this->submitBatch(121)->assertSessionHasErrors('students');

        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_a_batch_of_exactly_120_students_is_accepted(): void
    {
        $this->submitBatch(120)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_request_students', 120);
    }

    // ── D-108: capacity and clashes are the Director's to check ─────────────

    /** A full clinic day is no reason to refuse a request that has no day yet. */
    public function test_a_full_clinic_day_does_not_block_a_submission(): void
    {
        Appointment::factory()->count(120)->inSlot('07:00:00')->create([
            'scheduled_date' => now()->addDays(7)->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submitBatch(12)->assertSessionHasNoErrors();

        $this->assertSame('pending', BatchRequest::sole()->status);
    }

    /** BR-25 moved wholly to approval: there is no date to clash on yet. */
    public function test_a_student_already_on_another_batch_can_still_be_submitted(): void
    {
        $students = StudentProfile::factory()->count(2)->forCollege($this->ccs)->create();

        $other = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-950',
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'status' => 'approved',
            'scheduled_date' => now()->addDays(7)->toDateString(),
            'scheduled_time' => '07:00:00',
            'scheduled_blocks' => 1,
        ]);
        BatchRequestStudent::create(['batch_request_id' => $other->id, 'student_id' => $students[0]->user_id]);

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'form_type' => 'assessment',
                'reason' => 'ojt',
                'students' => $students->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionDoesntHaveErrors('clashes.'.$students[0]->id);

        $this->assertDatabaseCount('batch_requests', 2);
    }

    // ── BR-05 / FR-ADM-06: server-side scope, never the request ─────────────

    public function test_a_posted_service_type_is_ignored_and_the_batch_is_medical(): void
    {
        // D-60: dental is gone and the field is off the form, so the server
        // always writes 'medical' and never reads the body's service_type.
        $this->submitBatch(overrides: ['service_type' => 'dental'])
            ->assertSessionHasNoErrors();

        $this->assertSame('medical', BatchRequest::sole()->service_type);
    }

    public function test_college_id_comes_from_the_admin_scope_not_the_request(): void
    {
        // A tampered college_id in the POST body must be ignored outright.
        $this->submitBatch(overrides: ['college_id' => $this->cea->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->ccs->id, BatchRequest::sole()->college_id);
    }

    public function test_a_foreign_college_student_rejects_the_whole_submission(): void
    {
        $own = StudentProfile::factory()->forCollege($this->ccs)->create();
        $foreign = StudentProfile::factory()->forCollege($this->cea)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'form_type' => 'assessment',
                'reason' => 'ojt',
                'students' => [$own->id, $foreign->id],
            ])
            ->assertSessionHasErrors('students.1');

        // ALL-OR-NOTHING: the own-college student must not be saved either.
        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_others_without_detail_persists_nothing(): void
    {
        $this->submitBatch(overrides: ['reason' => 'others'])
            ->assertSessionHasErrors('reason_detail');

        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    // ── FR-ADM-04: confirmation screen ──────────────────────────────────────

    public function test_submit_redirects_to_a_confirmation_screen_with_the_batch_details(): void
    {
        $response = $this->submitBatch(3);

        $batch = BatchRequest::sole();
        $response->assertRedirect(route('admin.batches.confirmation', $batch));

        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $batch))
            ->assertOk()
            ->assertSee($batch->reference_no)
            ->assertSee('Pending Director Approval')
            // D-105: the batch's reason is shown as its Purpose.
            ->assertSee('>Purpose</dt>', false)
            ->assertDontSee('>Reason</dt>', false)
            // D-108: nothing is scheduled yet, and the page says who will do it.
            ->assertSee('>Clinic date</dt>', false)
            ->assertSee('To be scheduled by the Director')
            ->assertDontSee('Requested Date')
            ->assertSee($batch->created_at->format('l, F j, Y'))
            ->assertSee('View Tracking')
            ->assertSee('Back to Dashboard');
    }

    public function test_another_colleges_confirmation_screen_is_not_found(): void
    {
        $ceaAdmin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->cea->id,
        ]);

        $foreignBatch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-900',
            'college_id' => $this->cea->id,
            'requested_by' => $ceaAdmin->id,
            'form_type' => 'assessment',
            'reason' => 'ojt',
            'service_type' => 'medical',
        ]);

        // Scoped fetch → 404, so batch ids can't be enumerated across colleges.
        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $foreignBatch))
            ->assertNotFound();
    }
}
