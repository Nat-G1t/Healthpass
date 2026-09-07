<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * College Admin cancels their own PENDING batch request (FR-ADM-11, D-52).
 *
 * Before D-52 a college that changed its mind had to ask the Director to
 * REJECT the batch, which put a rejection on the college's record for
 * something the college itself wanted withdrawn. This is that withdrawal.
 *
 * The rule under test throughout is BatchRequest::isCancellable() — pending
 * only — enforced on a LOCKED row, so what the page draws and what the
 * endpoint allows can never disagree.
 */
class BatchCancelTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $otherAdmin;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);

        // A SECOND admin on the same college (D-47 made this easy) — the
        // Activity Log has to name whoever actually cancelled, not the
        // submitter, and that only shows up when the two differ.
        $this->otherAdmin = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Second CCS Administrator',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->director = User::factory()->create(['role' => 'director']);
    }

    /** A batch in the given status, with $studentCount students on it. */
    private function makeBatch(
        string $status = 'pending',
        int $studentCount = 4,
        ?College $college = null,
        ?User $requestedBy = null,
    ): BatchRequest {
        static $seq = 700;

        $college ??= $this->ccs;
        $date = now()->addDays(5)->toDateString();

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => ($requestedBy ?? $this->admin)->id,
            'reason' => 'graduation',
            'service_type' => 'medical',
            'requested_date' => $date,
            'requested_time' => '09:00:00',
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($studentCount),
            'status' => $status,
            'scheduled_date' => $status === 'approved' ? $date : null,
            'rejection_reason' => $status === 'rejected' ? 'The clinic is closed that week.' : null,
            'reviewed_by' => in_array($status, ['approved', 'rejected'], true) ? $this->director->id : null,
            'reviewed_at' => in_array($status, ['approved', 'rejected'], true) ? now() : null,
        ]);

        User::factory()->count($studentCount)->create(['role' => 'student'])->each(
            function (User $student) use ($batch, $college, $status, $date): void {
                StudentProfile::factory()->create([
                    'user_id' => $student->id,
                    'college_id' => $college->id,
                ]);

                // Appointments only exist once the Director approved (BR-08).
                $appointmentId = null;

                if ($status === 'approved') {
                    $appointmentId = Appointment::factory()->create([
                        'student_id' => $student->id,
                        'service_type' => 'medical',
                        'scheduled_date' => $date,
                        'scheduled_time' => '09:00:00',
                        'status' => 'scheduled',
                        'source' => 'batch',
                        'batch_request_id' => $batch->id,
                        'created_by' => $this->director->id,
                    ])->id;
                }

                BatchRequestStudent::create([
                    'batch_request_id' => $batch->id,
                    'student_id' => $student->id,
                    'appointment_id' => $appointmentId,
                ]);
            }
        );

        return $batch;
    }

    private function cancel(BatchRequest $batch, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->delete("/admin/batches/{$batch->id}/cancel");
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_admin_can_cancel_a_pending_batch(): void
    {
        $batch = $this->makeBatch('pending');

        $response = $this->cancel($batch);

        $response->assertRedirect('/admin/batches');
        $response->assertSessionHas('status');

        $batch->refresh();

        $this->assertSame('cancelled', $batch->status);
        $this->assertNotNull($batch->cancelled_at);
        $this->assertSame($this->admin->id, $batch->cancelled_by);
    }

    public function test_cancelling_creates_no_appointments_and_touches_none(): void
    {
        $batch = $this->makeBatch('pending');

        $this->cancel($batch)->assertRedirect('/admin/batches');

        // A pending batch never had appointments; cancelling must not invent any.
        $this->assertSame(0, Appointment::where('batch_request_id', $batch->id)->count());
    }

    public function test_the_director_decision_fields_are_left_alone(): void
    {
        $batch = $this->makeBatch('pending');

        $this->cancel($batch);

        $batch->refresh();

        // A cancellation is not a decision — reusing these would credit the
        // Director with an action they never took (the whole reason D-52 added
        // its own two columns).
        $this->assertNull($batch->reviewed_by);
        $this->assertNull($batch->reviewed_at);
        $this->assertNull($batch->rejection_reason);
    }

    public function test_cancelled_by_names_the_admin_who_acted_not_the_submitter(): void
    {
        $batch = $this->makeBatch('pending', requestedBy: $this->admin);

        $this->cancel($batch, as: $this->otherAdmin);

        $this->assertSame($this->otherAdmin->id, $batch->refresh()->cancelled_by);
        $this->assertSame($this->admin->id, $batch->requested_by);
    }

    // ── The guard: pending only ──────────────────────────────────────────────

    public function test_an_approved_batch_cannot_be_cancelled(): void
    {
        $batch = $this->makeBatch('approved');

        $response = $this->cancel($batch);

        $response->assertRedirect('/admin/batches');
        $response->assertSessionHas('error');

        $this->assertSame('approved', $batch->refresh()->status);
        $this->assertNull($batch->cancelled_at);
    }

    public function test_an_approved_batchs_appointments_survive_a_cancel_attempt(): void
    {
        $batch = $this->makeBatch('approved');

        $this->cancel($batch);

        // The refusal must not have swept the cohort's seats away — this is the
        // silent mass-cancellation D-52 declined to build.
        $this->assertSame(
            4,
            Appointment::where('batch_request_id', $batch->id)->where('status', 'scheduled')->count(),
        );
    }

    public function test_a_rejected_batch_cannot_be_cancelled(): void
    {
        $batch = $this->makeBatch('rejected');

        $this->cancel($batch)->assertSessionHas('error');

        $this->assertSame('rejected', $batch->refresh()->status);
    }

    public function test_a_duplicate_cancel_post_is_a_no_op_and_keeps_the_first_stamp(): void
    {
        $batch = $this->makeBatch('pending');

        $this->cancel($batch);
        $firstStamp = $batch->refresh()->cancelled_at;

        $this->travel(2)->minutes();

        $this->cancel($batch, as: $this->otherAdmin)->assertSessionHas('error');

        $batch->refresh();

        $this->assertSame($this->admin->id, $batch->cancelled_by);
        $this->assertEquals($firstStamp, $batch->cancelled_at);
    }

    // ── Scope (FR-ADM-06) ────────────────────────────────────────────────────

    public function test_another_colleges_batch_cannot_be_cancelled(): void
    {
        $batch = $this->makeBatch('pending', college: $this->coe);

        $this->cancel($batch)->assertNotFound();

        $this->assertSame('pending', $batch->refresh()->status);
    }

    public function test_non_admins_cannot_cancel(): void
    {
        $batch = $this->makeBatch('pending');

        $this->cancel($batch, as: $this->director)->assertRedirect();
        $this->assertSame('pending', $batch->refresh()->status);

        $this->post('/logout');

        $this->delete("/admin/batches/{$batch->id}/cancel")->assertRedirect('/login');
        $this->assertSame('pending', $batch->refresh()->status);
    }

    // ── Batch Tracking (FR-ADM-05) ───────────────────────────────────────────

    public function test_tracking_page_offers_cancel_only_on_a_pending_row(): void
    {
        $pending = $this->makeBatch('pending');
        $approved = $this->makeBatch('approved');

        $response = $this->actingAs($this->admin)->get('/admin/batches');

        $response->assertOk();

        $content = $response->getContent();

        // Exactly one of the two rows carries a Cancel button…
        $this->assertSame(1, substr_count($content, 'cancelTarget = JSON.parse'));

        // …and it is the PENDING one — the payload names the row it would act
        // on, so the reference inside it is the proof.
        $this->assertMatchesRegularExpression(
            '/cancelTarget = JSON\.parse\(.*'.preg_quote($pending->reference_no, '/').'/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/cancelTarget = JSON\.parse\(.*'.preg_quote($approved->reference_no, '/').'/',
            $content,
        );
    }

    public function test_the_dialogs_form_posts_to_the_cancel_route(): void
    {
        $batch = $this->makeBatch('pending');

        $response = $this->actingAs($this->admin)->get('/admin/batches');

        $response->assertOk();

        // The action is assembled in the browser from a base + the clicked id,
        // so nothing else in the suite would catch a wrong base — it would
        // simply 404 on click. Pin the exact string the page ships.
        $response->assertSee(':action="`http://localhost/admin/batches/${cancelTarget?.id}/cancel`"', false);
        $response->assertSee('name="_method" value="DELETE"', false);

        // And that assembled URL is really the route, for this batch.
        $this->assertSame(
            "http://localhost/admin/batches/{$batch->id}/cancel",
            route('admin.batches.cancel', $batch->id),
        );
    }

    public function test_tracking_page_has_no_cancel_column_when_nothing_is_pending(): void
    {
        $this->makeBatch('approved');

        $response = $this->actingAs($this->admin)->get('/admin/batches');

        $response->assertOk();
        // The dialog itself is always in the DOM — it is one hidden template
        // for the whole page. What must be absent is any ROW payload feeding it.
        $response->assertDontSee('cancelTarget = JSON.parse', false);
    }

    public function test_tracking_page_shows_the_cancelled_status(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch);

        $response = $this->actingAs($this->admin)->get('/admin/batches');

        $response->assertOk();
        $response->assertSee($batch->reference_no);
        $response->assertSee('Cancelled');
    }

    // ── The Director's side ──────────────────────────────────────────────────

    public function test_the_director_cannot_approve_a_cancelled_batch(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch);

        $response = $this->actingAs($this->director)->post("/director/batches/{$batch->id}/approve");

        $response->assertSessionHas('error');
        $this->assertSame('cancelled', $batch->refresh()->status);
        $this->assertSame(0, Appointment::where('batch_request_id', $batch->id)->count());
    }

    public function test_the_director_cannot_reject_a_cancelled_batch(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch);

        $response = $this->actingAs($this->director)->post("/director/batches/{$batch->id}/reject", [
            'rejection_reason' => 'The clinic cannot take this cohort that week.',
        ]);

        $response->assertSessionHas('error');

        $batch->refresh();

        $this->assertSame('cancelled', $batch->status);
        $this->assertNull($batch->rejection_reason);
    }

    public function test_the_approvals_page_labels_it_cancelled_not_rejected(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch);

        $response = $this->actingAs($this->director)->get('/director/batches');

        $response->assertOk();
        $response->assertSee('Cancelled by college');
        $response->assertDontSee('✕ Rejected');
    }

    // ── The Activity Log (FR-ADM-10, D-49 — derived, not logged) ─────────────

    public function test_the_activity_log_records_the_cancellation(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch, as: $this->otherAdmin);

        $response = $this->actingAs($this->admin)->get('/admin/activity');

        $response->assertOk();
        $response->assertSee('Cancelled');
        // The actor is the admin who pressed the button, not the submitter.
        $response->assertSee($this->otherAdmin->name);
        $response->assertSee('Withdrawn before the Clinic Director reviewed it');
    }

    public function test_the_activity_log_shows_no_cancellation_entry_while_pending(): void
    {
        $this->makeBatch('pending');

        $response = $this->actingAs($this->admin)->get('/admin/activity');

        $response->assertOk();
        $response->assertSee('Submitted');
        $response->assertDontSee('Withdrawn before the Clinic Director reviewed it');
    }

    // ── The roster page ──────────────────────────────────────────────────────

    public function test_the_roster_explains_a_cancelled_batch(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch);

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee('This request was cancelled');
        $response->assertSee($this->admin->name);
        $response->assertSee('No appointments');
    }
}
