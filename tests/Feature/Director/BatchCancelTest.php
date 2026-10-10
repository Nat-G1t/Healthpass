<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

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
use App\Support\BatchNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * D-129 (FR-DIRA-12): the Clinic Director cancels an approved batch from its
 * results page, with a reason, on the College Admin's rule — approved, first
 * clinic hour not started, no student has a visit — through the same shared
 * action (App\Actions\CancelBatch) as the college's own Cancel.
 */
class BatchCancelTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'The clinic is closed for the accreditation visit.';

    private College $ccs;

    private User $admin;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->director = User::factory()->create(['role' => 'director', 'name' => 'Dr. Clinic Director']);
    }

    /** A batch in $status with $studentCount students; an approved one sits tomorrow, 9–10 AM. */
    private function makeBatch(string $status = 'approved', int $studentCount = 3): BatchRequest
    {
        static $seq = 900;

        $date = now()->addDay()->toDateString();
        $approved = $status === 'approved';

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'graduation',
            'service_type' => 'medical',
            'status' => $status,
            'scheduled_date' => $approved ? $date : null,
            'scheduled_time' => $approved ? '09:00:00' : null,
            'scheduled_blocks' => $approved ? app(ClinicScheduleService::class)->blocksFor($studentCount) : null,
            'rejection_reason' => $status === 'rejected' ? 'The clinic is closed that week.' : null,
            'reviewed_by' => in_array($status, ['approved', 'rejected'], true) ? $this->director->id : null,
            'reviewed_at' => in_array($status, ['approved', 'rejected'], true) ? now() : null,
        ]);

        User::factory()->count($studentCount)->create(['role' => 'student'])->each(
            function (User $student) use ($batch, $approved, $date): void {
                StudentProfile::factory()->create(['user_id' => $student->id, 'college_id' => $this->ccs->id]);

                $appointmentId = $approved ? Appointment::factory()->create([
                    'student_id' => $student->id,
                    'service_type' => 'medical',
                    'scheduled_date' => $date,
                    'scheduled_time' => '09:00:00',
                    'status' => 'scheduled',
                    'source' => 'batch',
                    'batch_request_id' => $batch->id,
                    'created_by' => $this->director->id,
                ])->id : null;

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
        return $this->actingAs($as ?? $this->director)
            ->post("/director/batches/{$batch->id}/cancel", $reason === null ? [] : ['cancellation_reason' => $reason]);
    }

    private function resultsPage(BatchRequest $batch): TestResponse
    {
        return $this->actingAs($this->director)->get("/director/batch-tracking/{$batch->id}/results");
    }

    private function giveKioskVisit(BatchRequest $batch): void
    {
        $appointment = Appointment::where('batch_request_id', $batch->id)->firstOrFail();

        ClinicVisit::create([
            'reference_no' => 'HP-'.now()->year.'-9101',
            'student_id' => $appointment->student_id,
            'college_id' => $batch->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => 'resting',
            'checked_in_at' => now(),
        ]);
    }

    // ── Button visibility ────────────────────────────────────────────────────

    public function test_the_button_shows_on_an_approved_batch_whose_first_hour_is_tomorrow(): void
    {
        $batch = $this->makeBatch();

        $this->resultsPage($batch)
            ->assertOk()
            ->assertSee('data-director-cancel', false)
            ->assertSee('Cancel '.$batch->reference_no.'?')
            ->assertSee('Each student is emailed this reason.')
            ->assertSee('Keep batch')
            ->assertSee('action="'.route('director.batches.cancel', $batch).'"', false);
    }

    public function test_the_button_is_hidden_once_the_first_hour_has_started(): void
    {
        $batch = $this->makeBatch();
        Carbon::setTestNow(Carbon::parse($batch->scheduled_date->toDateString().' 09:00:00'));

        $this->resultsPage($batch)->assertOk()->assertDontSee('data-director-cancel', false);
    }

    public function test_the_button_is_hidden_once_a_student_has_a_visit(): void
    {
        $batch = $this->makeBatch();
        $this->giveKioskVisit($batch);

        $this->resultsPage($batch)->assertOk()->assertDontSee('data-director-cancel', false);
    }

    public function test_pending_rejected_and_cancelled_batches_have_no_results_page_and_no_button(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->resultsPage($this->makeBatch($status))->assertNotFound();
        }
    }

    public function test_the_college_and_clinic_results_headers_have_no_cancel_button(): void
    {
        $batch = $this->makeBatch();

        $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}/results")
            ->assertOk()->assertDontSee('data-director-cancel', false);
    }

    // ── Cancel ───────────────────────────────────────────────────────────────

    public function test_a_reason_is_required(): void
    {
        $batch = $this->makeBatch();

        $this->cancel($batch, reason: null)->assertSessionHasErrors('cancellation_reason');
        $this->assertSame('approved', $batch->refresh()->status);
    }

    public function test_the_reason_must_be_ten_to_five_hundred_characters(): void
    {
        $batch = $this->makeBatch();

        $this->cancel($batch, reason: 'Too short')->assertSessionHasErrors('cancellation_reason');
        $this->cancel($batch, reason: str_repeat('a', 501))->assertSessionHasErrors('cancellation_reason');
        $this->assertSame('approved', $batch->refresh()->status);
    }

    public function test_the_director_cancels_the_batch_and_its_scheduled_appointments(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();

        $this->cancel($batch)
            ->assertRedirect(route('director.tracking.index'))
            ->assertSessionHas('status');

        $batch->refresh();
        $this->assertSame('cancelled', $batch->status);
        $this->assertSame($this->director->id, $batch->cancelled_by);
        $this->assertSame(self::REASON, $batch->cancellation_reason);
        $this->assertNotNull($batch->cancelled_at);
        $this->assertTrue($batch->wasCancelledByDirector());
        // The approval stays on the row (D-92): it really happened.
        $this->assertTrue($batch->wasCancelledAfterApproval());
        $this->assertSame(0, Appointment::where('batch_request_id', $batch->id)->where('status', 'scheduled')->count());
        $this->assertSame(3, Appointment::where('batch_request_id', $batch->id)->where('status', 'cancelled')->count());
    }

    public function test_one_mail_job_per_student_saying_the_clinic_director_cancelled(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();

        $this->cancel($batch);

        Queue::assertPushed(SendAppointmentCancelledMail::class, 3);

        $html = (new AppointmentCancelledMail(Appointment::where('batch_request_id', $batch->id)->firstOrFail()))->render();
        $this->assertStringContainsString('The Clinic Director has cancelled', $html);
        $this->assertStringNotContainsString('College of Computing Studies</strong> has cancelled', $html);
        $this->assertStringContainsString(self::REASON, $html);
    }

    public function test_a_college_cancel_email_still_names_the_college(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();

        $this->actingAs($this->admin)->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON]);

        $html = (new AppointmentCancelledMail(Appointment::where('batch_request_id', $batch->id)->firstOrFail()))->render();
        $this->assertStringContainsString('College of Computing Studies</strong> has cancelled', $html);
        $this->assertStringNotContainsString('The Clinic Director has cancelled', $html);
    }

    public function test_a_visit_created_after_the_page_loaded_refuses_the_cancel_and_changes_nothing(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->resultsPage($batch)->assertSee('data-director-cancel', false);

        // A student reaches the kiosk between the page and the POST.
        $this->giveKioskVisit($batch);

        $this->cancel($batch)
            ->assertRedirect(route('director.tracking.results', $batch))
            ->assertSessionHas('error', "{$batch->reference_no} can no longer be cancelled — a student from this batch has already used the clinic kiosk.");

        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertNull($batch->cancelled_by);
        $this->assertNull($batch->cancellation_reason);
        $this->assertSame(3, Appointment::where('batch_request_id', $batch->id)->where('status', 'scheduled')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_batch_its_college_cancelled_meanwhile_is_a_404_and_keeps_the_colleges_stamp(): void
    {
        $batch = $this->makeBatch();
        $this->resultsPage($batch)->assertSee('data-director-cancel', false);

        $this->actingAs($this->admin)->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => 'The college called the event off.']);

        // The route model is loaded fresh, so it now reads cancelled: a 404 like
        // any other non-approved batch — nothing changes.
        $this->cancel($batch)->assertNotFound();
        $this->assertSame($this->admin->id, $batch->refresh()->cancelled_by);
    }

    public function test_a_pending_batch_is_a_404_the_director_rejects_it_instead(): void
    {
        $batch = $this->makeBatch('pending');

        $this->cancel($batch)->assertNotFound();
        $this->assertSame('pending', $batch->refresh()->status);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_a_non_director_cannot_cancel(): void
    {
        $batch = $this->makeBatch();

        foreach (['college_admin', 'nurse', 'physician', 'student'] as $role) {
            $user = $role === 'college_admin' ? $this->admin : User::factory()->create(['role' => $role]);
            $this->cancel($batch, as: $user)->assertRedirect();
        }

        $this->post('/logout');
        $this->post("/director/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON])->assertRedirect('/login');

        $this->assertSame('approved', $batch->refresh()->status);
    }

    // ── Director's Batch Tracking ────────────────────────────────────────────

    public function test_the_batch_moves_to_rejected_and_cancelled_tagged_cancelled_by_director(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->cancel($batch);

        $this->actingAs($this->director)->get('/director/batch-tracking')
            ->assertOk()
            ->assertSee('Cancelled by Director')
            ->assertDontSee('Cancelled by college')
            ->assertDontSee('No rejected or cancelled batches.');

        $this->actingAs($this->director)->get("/director/batch-tracking/{$batch->id}")
            ->assertOk()
            ->assertSee('Cancelled by Director')
            ->assertSee('(Clinic Director)')
            ->assertSee(self::REASON);

        $this->resultsPage($batch)->assertNotFound();
    }

    public function test_a_college_cancelled_batch_still_says_cancelled_by_college(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->actingAs($this->admin)->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON]);

        $this->actingAs($this->director)->get('/director/batch-tracking')
            ->assertOk()
            ->assertSee('Cancelled by college')
            ->assertDontSee('Cancelled by Director');
    }

    public function test_the_directors_own_cancel_is_no_news_to_the_director(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->cancel($batch);

        $this->assertSame(0, BatchNotifications::count($this->director));
        $this->assertSame([$batch->id => false], BatchNotifications::dots($this->director, [$batch->refresh()->load('canceller')]));
    }

    // ── College Admin ────────────────────────────────────────────────────────

    public function test_the_college_sees_cancelled_the_directors_reason_and_a_dot_that_clears_when_opened(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->cancel($batch);

        $this->actingAs($this->admin)->get('/admin/batches')
            ->assertOk()
            ->assertSeeInOrder([$batch->reference_no, 'Cancelled', 'View'])
            ->assertSee('>Director&#039;s Reason</th>', false)
            ->assertSee('Cancelled by the Clinic Director')
            ->assertSee(self::REASON, false)            // inside the Js::from() payload
            ->assertSee('data-notify-dot', false);
        $this->assertSame(1, BatchNotifications::count($this->admin));

        // The reason popup posts here in the background.
        $this->actingAs($this->admin)->post("/admin/batches/{$batch->id}/seen")->assertNoContent();

        $this->assertSame(0, BatchNotifications::count($this->admin));
        $this->actingAs($this->admin)->get('/admin/batches')->assertOk()->assertDontSee('data-notify-dot', false);
    }

    public function test_a_college_cancelled_batch_has_no_reason_popup_and_no_dot_for_the_college(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->actingAs($this->admin)->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON]);

        $this->actingAs($this->admin)->get('/admin/batches')
            ->assertOk()
            ->assertDontSee("Director's Reason")
            ->assertDontSee('data-notify-dot', false);
        $this->assertSame(0, BatchNotifications::count($this->admin));
        $this->actingAs($this->admin)->post("/admin/batches/{$batch->id}/seen")->assertNotFound();
    }

    public function test_the_activity_log_and_roster_name_the_director(): void
    {
        Queue::fake();
        $batch = $this->makeBatch();
        $this->cancel($batch);

        $this->actingAs($this->admin)->get('/admin/activity')
            ->assertOk()
            ->assertSeeInOrder(['Dr. Clinic Director', 'Clinic Director', 'Cancelled', $batch->reference_no, self::REASON]);

        $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}")
            ->assertOk()
            ->assertSee('Dr. Clinic Director')
            ->assertSee('(Clinic Director)');
    }

    // ── Capacity ─────────────────────────────────────────────────────────────

    public function test_the_freed_hour_accepts_a_new_batch(): void
    {
        Queue::fake();
        config(['healthpass.hourly_capacity' => 3]);
        $full = $this->makeBatch('approved', 3);
        $date = $full->scheduled_date->toDateString();
        $waiting = $this->makeBatch('pending', 2);
        $approve = fn (): TestResponse => $this->actingAs($this->director)
            ->post("/director/batches/{$waiting->id}/approve", ['scheduled_date' => $date, 'start_time' => '09:00:00']);

        // 9–10 AM is full, so the waiting batch cannot have it…
        $approve();
        $this->assertSame('pending', $waiting->refresh()->status);

        $this->cancel($full);

        // …until the Director cancels the batch holding it.
        $approve()->assertSessionHasNoErrors();
        $this->assertSame('approved', $waiting->refresh()->status);
        $this->assertSame(2, app(ClinicScheduleService::class)->bookedInSlot($date, '09:00:00'));
    }
}
