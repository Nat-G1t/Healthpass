<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

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

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /** Submit a valid batch for $count CCS students; returns the response. */
    private function submitBatch(int $count = 1, array $overrides = [])
    {
        $students = StudentProfile::factory()->count($count)->forCollege($this->ccs)->create();

        return $this->actingAs($this->admin)->post('/admin/batches', array_merge([
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(7)->toDateString(),
            'students' => $students->pluck('id')->all(),
        ], $overrides));
    }

    // ── FR-ADM-04: one batch row + one pivot row per student ────────────────

    public function test_submitting_30_students_creates_one_batch_and_exactly_30_pivot_rows(): void
    {
        $students = StudentProfile::factory()->count(30)->forCollege($this->ccs)->create();

        $requestedDate = now()->addDays(10)->toDateString();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'reason' => 'graduation',
                'service_type' => 'dental',
                'requested_date' => $requestedDate,
                'students' => $students->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseCount('batch_request_students', 30);

        $batch = BatchRequest::sole();
        $this->assertSame('pending', $batch->status);
        $this->assertSame($this->ccs->id, $batch->college_id);
        $this->assertSame($this->admin->id, $batch->requested_by);
        $this->assertSame('graduation', $batch->reason);
        $this->assertSame('dental', $batch->service_type);
        // D-29: the admin's proposed date is stored at submission…
        $this->assertSame($requestedDate, $batch->requested_date->toDateString());
        // …while the FINAL date stays empty until the Director approves.
        $this->assertNull($batch->scheduled_date);

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

    // ── BR-05 / FR-ADM-06: server-side scope, never the request ─────────────

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
                'reason' => 'ojt',
                'service_type' => 'medical',
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
            ->assertSee($batch->requested_date->format('l, F j, Y'))
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
            'reason' => 'ojt',
            'service_type' => 'medical',
        ]);

        // Scoped fetch → 404, so batch ids can't be enumerated across colleges.
        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $foreignBatch))
            ->assertNotFound();
    }
}
