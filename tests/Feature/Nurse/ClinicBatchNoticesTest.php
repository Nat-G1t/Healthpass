<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Jobs\SendClinicBatchApprovedMail;
use App\Jobs\SendClinicBatchCancelledMail;
use App\Mail\ClinicBatchApprovedMail;
use App\Mail\ClinicBatchCancelledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\User;
use App\Support\BatchNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-137 — the clinic hears when the Director approves a batch, and when an
 * approved batch is cancelled:
 *
 *   - Upcoming Batches: a per-user dot beside View on every upcoming batch
 *     the nurse or physician has never opened, and a sidebar badge counting
 *     them (Batch Results keeps no badge — O4)
 *   - an email to every ACTIVE nurse and physician on approval, and on the
 *     cancel of an APPROVED batch (by its college or the Director) — counts
 *     and a link, never student names
 *
 * The fixture's clock: "now" is Friday Sep 11, 2026, 10 AM; its batches ran
 * on Thursday Sep 10, so they are Batch Results batches unless moved.
 */
class ClinicBatchNoticesTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    /** The Monday after the fixture's "now". */
    private const MONDAY = '2026-09-14';

    private const REASON = 'The field trip was called off by the dean.';

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();

        $this->physician = User::factory()->create(['role' => 'physician', 'name' => 'Rey Physician']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function upcomingPage(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('nurse.batches.index', $query))->assertOk();
    }

    private function upcomingDot(User $user, BatchRequest $batch): bool
    {
        return $this->upcomingPage($user)->viewData('dots')[$batch->id] ?? false;
    }

    private function openBatch(User $user, BatchRequest $batch): void
    {
        $this->actingAs($user)->get(route('nurse.batches.results', $batch))->assertOk();
    }

    /** A pending batch with $count students on its roster, waiting for the Director. */
    private function pendingBatch(int $count): BatchRequest
    {
        $batch = $this->makeBatch(status: 'pending');

        User::factory()->count($count)->create(['role' => 'student'])->each(
            fn (User $student) => BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]),
        );

        return $batch;
    }

    private function approve(BatchRequest $batch, string $date = self::MONDAY): TestResponse
    {
        return $this->actingAs($this->director)->post(
            "/director/batches/{$batch->id}/approve",
            ['scheduled_date' => $date, 'start_time' => '09:00:00'],
        );
    }

    /** @return list<int> the user ids the clinic notices of $job went to */
    private function noticeRecipients(string $job): array
    {
        return Queue::pushed($job)->map(fn ($pushed): int => $pushed->staff->id)->sort()->values()->all();
    }

    // ── The dot on Upcoming ──────────────────────────────────────────────────

    public function test_an_upcoming_batch_carries_a_dot_until_this_user_opens_it(): void
    {
        $batch = $this->batchOn(self::MONDAY);

        $this->assertTrue($this->upcomingDot($this->nurse, $batch));
        $this->assertTrue($this->upcomingDot($this->physician, $batch));
        $this->upcomingPage($this->nurse)->assertSee('data-notify-dot', false);

        $this->openBatch($this->nurse, $batch);

        $this->assertFalse($this->upcomingDot($this->nurse, $batch));
        // Per user: the physician has not opened it, so their dot stays.
        $this->assertTrue($this->upcomingDot($this->physician, $batch));
        $this->upcomingPage($this->nurse)->assertDontSee('data-notify-dot', false);
    }

    public function test_a_batch_nobody_opened_before_its_day_ended_does_not_light_up_batch_results(): void
    {
        $batch = $this->batchOn('2026-09-11', '13:00:00');
        $this->assertTrue($this->upcomingDot($this->nurse, $batch));

        // The day passes: the batch moves to Batch Results, without a dot.
        $this->travelTo('2026-09-12 09:00:00');

        $dots = $this->actingAs($this->nurse)->get(route('nurse.batch-results'))->assertOk()->viewData('dots');
        $this->assertFalse($dots[$batch->id]);
        $this->assertSame(0, BatchNotifications::count($this->nurse));
    }

    /** Approve + cancel only: a 2nd-chance day booked on a batch already opened is no new dot. */
    public function test_a_second_chance_day_does_not_re_dot_an_opened_batch(): void
    {
        $batch = $this->makeBatch();
        $missed = $this->addStudent($batch, 'booked');
        $this->openBatch($this->nurse, $batch);

        $secondChance = Appointment::factory()->create([
            'student_id' => $missed->student_id,
            'scheduled_date' => self::MONDAY,
            'scheduled_time' => '09:00:00',
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'rescheduled_from_id' => $missed->id,
        ]);
        BatchRequestStudent::where('appointment_id', $missed->id)->update(['appointment_id' => $secondChance->id]);

        $response = $this->upcomingPage($this->nurse);
        $this->assertSame([$batch->reference_no], $response->viewData('upcomingBatches')->pluck('reference_no')->all());
        $this->assertFalse($response->viewData('dots')[$batch->id]);
    }

    public function test_only_approved_batches_ever_carry_a_dot(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->makeBatch(status: $status)->update(['scheduled_date' => self::MONDAY, 'scheduled_time' => '09:00:00']);
        }

        $this->assertSame(0, BatchNotifications::count($this->nurse));
    }

    // ── The badge ────────────────────────────────────────────────────────────

    public function test_the_upcoming_batches_badge_counts_the_unopened_batches(): void
    {
        $first = $this->batchOn(self::MONDAY);
        $second = $this->batchOn(self::MONDAY, '10:00:00');
        // A finished batch never opened is no news (and Batch Results has no badge — O4).
        $this->completedBatch();

        $this->assertSame(2, BatchNotifications::count($this->nurse));
        $this->upcomingPage($this->nurse)
            ->assertSee('data-nav-badge-for="nurse.batches.index"', false)
            ->assertDontSee('data-nav-badge-for="nurse.batch-results"', false);

        $this->openBatch($this->nurse, $first);
        $this->assertSame(1, BatchNotifications::count($this->nurse));
        $this->assertSame(2, BatchNotifications::count($this->physician));

        $this->openBatch($this->nurse, $second);
        $this->assertSame(0, BatchNotifications::count($this->nurse));
        $this->upcomingPage($this->nurse)->assertDontSee('data-nav-badge-for="nurse.batches.index"', false);
    }

    // ── The pager (D-131) ────────────────────────────────────────────────────

    public function test_next_carries_a_dot_when_an_unopened_batch_is_on_a_later_page(): void
    {
        $batches = collect(range(1, 11))->map(fn (): BatchRequest => $this->batchOn(self::MONDAY, students: 0));

        // The first ten (page 1, in id order) are opened; the eleventh is not.
        $batches->take(10)->each(fn (BatchRequest $batch) => BatchNotifications::markSeen($this->nurse, $batch, 'scheduled'));

        $response = $this->upcomingPage($this->nurse);
        $this->assertSame(['previous' => false, 'next' => true], $response->viewData('pagerDots'));
        $this->assertNotContains(true, $response->viewData('dots'));

        $response = $this->upcomingPage($this->nurse, ['upcoming_page' => 2]);
        $this->assertSame(['previous' => false, 'next' => false], $response->viewData('pagerDots'));
        $this->assertTrue($response->viewData('dots')[$batches->last()->id]);
    }

    // ── The approval email ───────────────────────────────────────────────────

    public function test_approval_emails_every_active_nurse_and_physician_once(): void
    {
        User::factory()->create(['role' => 'nurse', 'status' => 'inactive']);
        $batch = $this->pendingBatch(3);

        $this->approve($batch)->assertRedirect(route('director.batches.index'));

        $this->assertSame(
            collect([$this->nurse->id, $this->physician->id])->sort()->values()->all(),
            $this->noticeRecipients(SendClinicBatchApprovedMail::class),
        );
        Queue::assertPushed(SendClinicBatchApprovedMail::class,
            fn (SendClinicBatchApprovedMail $job): bool => $job->batch->is($batch) && $job->studentCount === 3);
    }

    public function test_a_refused_approval_emails_the_clinic_nothing(): void
    {
        $batch = $this->pendingBatch(2);

        // Yesterday: refused, still pending.
        $this->approve($batch, '2026-09-10');

        $this->assertSame('pending', $batch->fresh()->status);
        Queue::assertNotPushed(SendClinicBatchApprovedMail::class);
    }

    public function test_the_approval_email_names_the_schedule_and_counts_without_student_names(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Ana Abad');
        $this->addStudent($batch, 'booked', 'Ben Bueno');
        $batch = $this->moveTo($batch, self::MONDAY);
        $batch->update(['wants_printed_copies' => true, 'printed_copies_approved' => true]);

        $mail = new ClinicBatchApprovedMail($this->physician, $batch, 2);

        $mail->assertHasSubject("HealthPass — new batch {$batch->reference_no} on Sep 14, 2026");
        $mail->assertSeeInHtml('Hi Rey Physician')
            ->assertSeeInHtml($batch->reference_no)
            ->assertSeeInHtml('College of Computing Studies (CCS)')
            ->assertSeeInHtml('Medical Clearance')
            ->assertSeeInHtml('Field Trip/Educational Tour')
            ->assertSeeInHtml('Monday, September 14, 2026')
            ->assertSeeInHtml('9:00 AM')
            ->assertSeeInHtml('2 students')
            ->assertSeeInHtml('once it is encoded')
            ->assertSeeInHtml(route('nurse.batches.results', $batch))
            ->assertDontSeeInHtml('Abad')
            ->assertDontSeeInHtml('Bueno');
    }

    public function test_the_approval_email_skips_a_batch_cancelled_while_queued_and_an_inactive_account(): void
    {
        Mail::fake();
        $batch = $this->batchOn(self::MONDAY);

        (new SendClinicBatchApprovedMail($this->nurse, $batch, 1))->handle();
        Mail::assertSent(ClinicBatchApprovedMail::class, fn (ClinicBatchApprovedMail $mail): bool => $mail->hasTo($this->nurse->email));

        $inactive = User::factory()->create(['role' => 'physician', 'status' => 'inactive']);
        (new SendClinicBatchApprovedMail($inactive, $batch, 1))->handle();

        $batch->update(['status' => 'cancelled']);
        (new SendClinicBatchApprovedMail($this->physician, $batch, 1))->handle();

        Mail::assertSentCount(1);
    }

    // ── The cancellation email ───────────────────────────────────────────────

    public function test_the_college_cancelling_an_approved_batch_emails_the_clinic(): void
    {
        $batch = $this->batchOn(self::MONDAY, students: 2);

        $this->actingAs($this->admin)
            ->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertSame(
            collect([$this->nurse->id, $this->physician->id])->sort()->values()->all(),
            $this->noticeRecipients(SendClinicBatchCancelledMail::class),
        );
        Queue::assertPushed(SendClinicBatchCancelledMail::class, fn (SendClinicBatchCancelledMail $job): bool => $job->studentCount === 2);
    }

    public function test_the_director_cancelling_an_approved_batch_emails_the_clinic(): void
    {
        $batch = $this->batchOn(self::MONDAY, students: 3);

        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $batch->fresh()->status);
        Queue::assertPushed(SendClinicBatchCancelledMail::class, 2);
        Queue::assertPushed(SendClinicBatchCancelledMail::class, fn (SendClinicBatchCancelledMail $job): bool => $job->studentCount === 3);
    }

    /** A pending batch never reached the clinic. */
    public function test_cancelling_a_pending_batch_emails_the_clinic_nothing(): void
    {
        $batch = $this->pendingBatch(2);

        $this->actingAs($this->admin)
            ->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON]);

        $this->assertSame('cancelled', $batch->fresh()->status);
        Queue::assertNotPushed(SendClinicBatchCancelledMail::class);
    }

    public function test_the_cancellation_email_says_who_cancelled_and_why_without_student_names(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Ana Abad');
        $batch = $this->moveTo($batch, self::MONDAY);

        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/cancel", ['cancellation_reason' => self::REASON]);

        $mail = new ClinicBatchCancelledMail($this->nurse, $batch->fresh(), 1);

        $mail->assertHasSubject("HealthPass — batch {$batch->reference_no} on Sep 14, 2026 was cancelled");
        $mail->assertSeeInHtml('The Clinic Director has cancelled')
            ->assertSeeInHtml($batch->reference_no)
            ->assertSeeInHtml('Monday, September 14, 2026')
            ->assertSeeInHtml('1 student')
            ->assertSeeInHtml('Dina Director (Clinic Director)')
            ->assertSeeInHtml(self::REASON)
            ->assertDontSeeInHtml('Abad')
            // A cancelled batch has no page on the Clinic Dashboard.
            ->assertDontSeeInHtml(route('nurse.batches.results', $batch));
    }
}
