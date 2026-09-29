<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\SendAppointmentCancelledMail;
use App\Mail\AppointmentCancelledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * College Admin cancels their own batch request (FR-ADM-11): a PENDING one
 * (D-52), or since D-92 an APPROVED one until its first clinic hour starts and
 * as long as no student has used the kiosk — always with a written reason.
 *
 * The rule under test throughout is BatchRequest::isCancellable(), enforced on
 * a LOCKED row, so what the page draws and what the endpoint allows can never
 * disagree.
 */
class BatchCancelTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'The field trip was moved to next semester.';

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

    /** A batch in the given status, with $studentCount students on it. Its clinic day is 5 days out, 9–10 AM. */
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

    private function cancel(BatchRequest $batch, ?User $as = null, ?string $reason = self::REASON): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->delete("/admin/batches/{$batch->id}/cancel", $reason === null ? [] : ['cancellation_reason' => $reason]);
    }

    /** Move the clock to the start of the batch's first clinic hour. */
    private function startFirstHour(BatchRequest $batch): void
    {
        $this->travelTo(Carbon::parse($batch->requested_date->toDateString().' 09:00:00'));
    }

    /** One of the batch's students reaches the kiosk (a resting first pass is enough). */
    private function giveKioskVisit(BatchRequest $batch, string $status = 'resting'): void
    {
        $appointment = Appointment::where('batch_request_id', $batch->id)->firstOrFail();

        ClinicVisit::create([
            'reference_no' => 'HP-'.now()->year.'-9001',
            'student_id' => $appointment->student_id,
            'college_id' => $batch->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $status,
            'checked_in_at' => now(),
        ]);
    }

    // ── Pending (D-52) ───────────────────────────────────────────────────────

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
        $this->assertSame(self::REASON, $batch->cancellation_reason);
    }

    public function test_cancelling_a_pending_batch_creates_no_appointments_and_emails_nobody(): void
    {
        Queue::fake();
        $batch = $this->makeBatch('pending');

        $this->cancel($batch)->assertRedirect('/admin/batches');

        // A pending batch never had appointments; cancelling must not invent any,
        // and no student was ever told about it.
        $this->assertSame(0, Appointment::where('batch_request_id', $batch->id)->count());
        Queue::assertNothingPushed();
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

    // ── The reason (D-92) ────────────────────────────────────────────────────

    public function test_a_reason_is_required(): void
    {
        $batch = $this->makeBatch('pending');

        $this->cancel($batch, reason: null)->assertSessionHasErrors('cancellation_reason');

        $this->assertSame('pending', $batch->refresh()->status);
    }

    public function test_a_reason_shorter_than_ten_characters_is_refused(): void
    {
        $batch = $this->makeBatch('approved');

        $this->cancel($batch, reason: 'oops')->assertSessionHasErrors('cancellation_reason');

        $this->assertSame('approved', $batch->refresh()->status);
        $this->assertSame(4, Appointment::where('batch_request_id', $batch->id)->where('status', 'scheduled')->count());
    }

    // ── Approved (D-92) ──────────────────────────────────────────────────────

    public function test_an_approved_batch_can_be_cancelled_before_its_first_hour(): void
    {
        Queue::fake();
        $batch = $this->makeBatch('approved');

        $response = $this->cancel($batch);

        $response->assertRedirect('/admin/batches');
        $response->assertSessionHas('status');

        $batch->refresh();

        $this->assertSame('cancelled', $batch->status);
        $this->assertSame(self::REASON, $batch->cancellation_reason);
        // The approval really happened, so its stamp stays.
        $this->assertSame($this->director->id, $batch->reviewed_by);
        $this->assertTrue($batch->wasCancelledAfterApproval());
        $this->assertSame(4, Appointment::where('batch_request_id', $batch->id)->where('status', 'cancelled')->count());
    }

    public function test_every_student_is_emailed_once(): void
    {
        Queue::fake();
        $batch = $this->makeBatch('approved');

        $this->cancel($batch);

        Queue::assertPushed(SendAppointmentCancelledMail::class, 4);
    }

    public function test_a_student_withdrawn_earlier_is_not_emailed_again(): void
    {
        Queue::fake();
        $batch = $this->makeBatch('approved');
        // A withdrawal from before D-87 removed it — already cancelled and emailed then.
        Appointment::where('batch_request_id', $batch->id)->first()->update(['status' => 'cancelled']);

        $this->cancel($batch);

        Queue::assertPushed(SendAppointmentCancelledMail::class, 3);
    }

    public function test_cancelling_frees_the_seats(): void
    {
        $batch = $this->makeBatch('approved');
        $schedule = app(ClinicScheduleService::class);
        $date = $batch->scheduled_date->toDateString();

        $this->assertSame(4, $schedule->bookedInSlot($date, '09:00:00'));

        $this->cancel($batch);

        $this->assertSame(0, $schedule->bookedInSlot($date, '09:00:00'));
    }

    public function test_an_approved_batch_cannot_be_cancelled_once_its_first_hour_starts(): void
    {
        Queue::fake();
        $batch = $this->makeBatch('approved');
        $this->startFirstHour($batch);

        $response = $this->cancel($batch);

        $response->assertRedirect('/admin/batches');
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'first clinic hour'));

        $this->assertSame('approved', $batch->refresh()->status);
        $this->assertNull($batch->cancelled_at);
        $this->assertSame(4, Appointment::where('batch_request_id', $batch->id)->where('status', 'scheduled')->count());
        Queue::assertNothingPushed();
    }

    public function test_an_approved_batch_can_still_be_cancelled_a_minute_before_its_first_hour(): void
    {
        $batch = $this->makeBatch('approved');
        $this->travelTo(Carbon::parse($batch->requested_date->toDateString().' 08:59:00'));

        $this->cancel($batch)->assertSessionHas('status');

        $this->assertSame('cancelled', $batch->refresh()->status);
    }

    public function test_an_approved_batch_cannot_be_cancelled_once_a_student_used_the_kiosk(): void
    {
        $batch = $this->makeBatch('approved');
        // Even a RESTING first pass (D-72) counts — the student came.
        $this->giveKioskVisit($batch, 'resting');

        $this->cancel($batch)
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'kiosk'));

        $this->assertSame('approved', $batch->refresh()->status);
    }

    public function test_the_cancelled_batch_keeps_its_students_on_the_roster(): void
    {
        $batch = $this->makeBatch('approved');
        $this->cancel($batch);

        // D-87's "withdrawn" filter must not swallow a whole cancelled batch.
        $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}")
            ->assertOk()
            ->assertViewHas('totalCount', 4);
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

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

        $this->cancel($batch, as: $this->otherAdmin, reason: 'A second, different reason.')->assertSessionHas('error');

        $batch->refresh();

        $this->assertSame($this->admin->id, $batch->cancelled_by);
        $this->assertEquals($firstStamp, $batch->cancelled_at);
        $this->assertSame(self::REASON, $batch->cancellation_reason);
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

        $this->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON])->assertRedirect('/login');
        $this->assertSame('pending', $batch->refresh()->status);
    }

    // ── Batch Tracking (FR-ADM-05) ───────────────────────────────────────────

    public function test_tracking_page_offers_cancel_on_pending_and_not_yet_started_rows(): void
    {
        $pending = $this->makeBatch('pending');
        $approved = $this->makeBatch('approved');
        $visited = $this->makeBatch('approved');
        $this->giveKioskVisit($visited);

        $content = $this->actingAs($this->admin)->get('/admin/batches')->assertOk()->getContent();

        // Two of the three rows carry a Cancel button…
        $this->assertSame(2, substr_count($content, 'cancelTarget = JSON.parse'));

        // …and the payload names the row it would act on, so the reference
        // inside it is the proof.
        foreach ([$pending, $approved] as $batch) {
            $this->assertMatchesRegularExpression(
                '/cancelTarget = JSON\.parse\(.*'.preg_quote($batch->reference_no, '/').'/',
                $content,
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/cancelTarget = JSON\.parse\(.*'.preg_quote($visited->reference_no, '/').'/',
            $content,
        );
    }

    public function test_the_dialogs_form_posts_to_the_cancel_route_with_a_reason(): void
    {
        $batch = $this->makeBatch('pending');

        $response = $this->actingAs($this->admin)->get('/admin/batches');

        $response->assertOk();

        // The action is assembled in the browser from a base + the clicked id,
        // so nothing else in the suite would catch a wrong base — it would
        // simply 404 on click. Pin the exact string the page ships.
        $response->assertSee(':action="`http://localhost/admin/batches/${cancelTarget?.id}/cancel`"', false);
        $response->assertSee('name="_method" value="DELETE"', false);
        $response->assertSee('name="cancellation_reason"', false);

        // And that assembled URL is really the route, for this batch.
        $this->assertSame(
            "http://localhost/admin/batches/{$batch->id}/cancel",
            route('admin.batches.cancel', $batch->id),
        );
    }

    public function test_tracking_page_has_no_cancel_column_when_nothing_is_cancellable(): void
    {
        $batch = $this->makeBatch('approved');
        $this->startFirstHour($batch);

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

    public function test_the_director_can_read_the_reason(): void
    {
        $batch = $this->makeBatch('approved');
        $this->cancel($batch, as: $this->otherAdmin);

        $this->actingAs($this->director)->get('/director/batches')
            ->assertOk()
            ->assertSee('View reason')
            ->assertSee('after you approved it')
            ->assertSee(self::REASON)
            ->assertSee($this->otherAdmin->name);
    }

    // ── The Activity Log (FR-ADM-10, D-49 — derived, not logged) ─────────────

    public function test_the_activity_log_records_the_cancellation_and_its_reason(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch, as: $this->otherAdmin);

        $response = $this->actingAs($this->admin)->get('/admin/activity');

        $response->assertOk();
        $response->assertSee('Cancelled');
        // The actor is the admin who pressed the button, not the submitter.
        $response->assertSee($this->otherAdmin->name);
        $response->assertSee(self::REASON);
    }

    public function test_the_activity_log_keeps_the_approval_of_a_batch_cancelled_later(): void
    {
        $batch = $this->makeBatch('approved');
        $this->cancel($batch);

        $this->actingAs($this->admin)->get('/admin/activity')
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('Cancelled')
            ->assertSee(self::REASON);
    }

    public function test_the_activity_log_shows_no_cancellation_entry_while_pending(): void
    {
        $this->makeBatch('pending');

        $response = $this->actingAs($this->admin)->get('/admin/activity');

        $response->assertOk();
        $response->assertSee('Submitted');
        $response->assertDontSee(self::REASON);
    }

    // ── The roster page ──────────────────────────────────────────────────────

    public function test_the_roster_explains_a_cancelled_pending_batch(): void
    {
        $batch = $this->makeBatch('pending');
        $this->cancel($batch);

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee('This request was cancelled');
        $response->assertSee($this->admin->name);
        $response->assertSee('No appointments');
        $response->assertSee(self::REASON);
    }

    public function test_the_roster_explains_a_batch_cancelled_after_approval(): void
    {
        $batch = $this->makeBatch('approved');
        $this->cancel($batch);

        $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}")
            ->assertOk()
            ->assertSee('after the Clinic Director approved it')
            ->assertDontSee('No appointments');
    }

    // ── The student's email (D-92) ───────────────────────────────────────────

    public function test_the_email_names_the_batch_the_date_and_the_reason(): void
    {
        $batch = $this->makeBatch('approved');
        $this->cancel($batch, reason: 'Postponed <b>until</b> further notice.');

        $appointment = Appointment::where('batch_request_id', $batch->id)->firstOrFail();
        $mail = new AppointmentCancelledMail($appointment);
        $html = $mail->render();

        $this->assertStringContainsString($batch->reference_no, $html);
        $this->assertStringContainsString($appointment->reference_no, $html);
        $this->assertStringContainsString($appointment->scheduled_date->format('l, F j, Y'), $html);
        $this->assertStringContainsString('Your approved schedule is cancelled', $html);
        // Typed by an admin, so it is escaped — never live markup in a webmail.
        $this->assertStringContainsString('Postponed &lt;b&gt;until&lt;/b&gt; further notice.', $html);
        $this->assertStringContainsString('was cancelled', $mail->envelope()->subject);
    }

    public function test_the_email_job_goes_to_the_students_own_address(): void
    {
        $batch = $this->makeBatch('approved', studentCount: 1);
        Mail::fake();

        // Sync queue in the test env: the job runs during the request.
        $this->cancel($batch);

        $student = Appointment::where('batch_request_id', $batch->id)->firstOrFail()->student;

        Mail::assertSent(
            AppointmentCancelledMail::class,
            fn (AppointmentCancelledMail $mail): bool => $mail->hasTo($student->email),
        );
    }
}
