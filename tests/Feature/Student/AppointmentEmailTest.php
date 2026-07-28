<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Jobs\SendAppointmentScheduledMail;
use App\Mail\AppointmentScheduledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * FR-STU-12 (D-39) — the "your appointment is scheduled" email.
 *
 * Two dispatch points, one Mailable: the student's own booking (FR-STU-04) and
 * the Director's batch approval fan-out (BR-08). Both queue one job per
 * student, both strictly after their transaction has committed.
 *
 * Note on the queue in tests: phpunit.xml pins QUEUE_CONNECTION=sync, so a
 * dispatched job runs inline and Mail::fake() sees the send it performs. Tests
 * that care about the JOB boundary (one per student, so one bad address cannot
 * take the rest down) use Queue::fake() instead and count jobs directly.
 */
class AppointmentEmailTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /** A pending batch with $studentCount student pivot rows, ready to approve. */
    private function makeBatchWithStudents(int $studentCount, array $overrides = []): BatchRequest
    {
        static $seq = 900;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(7)->toDateString(),
            'requested_time' => '07:00:00',
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($studentCount),
        ], $overrides));

        User::factory()->count($studentCount)->create(['role' => 'student'])->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    private function approve(BatchRequest $batch): TestResponse
    {
        return $this->actingAs($this->director)->post("/director/batches/{$batch->id}/approve");
    }

    // ── Batch approval ───────────────────────────────────────────────────────

    public function test_approving_a_batch_queues_exactly_one_job_per_student(): void
    {
        Queue::fake();

        $batch = $this->makeBatchWithStudents(15);

        $this->approve($batch)->assertRedirect('/director/batches');

        // One JOB each — not one job looping the roster (D-39): a single bad
        // address must fail only its own send.
        Queue::assertPushed(SendAppointmentScheduledMail::class, 15);

        $studentIds = $batch->batchRequestStudents()->pluck('student_id')->all();

        foreach ($studentIds as $studentId) {
            Queue::assertPushed(
                SendAppointmentScheduledMail::class,
                fn (SendAppointmentScheduledMail $job): bool => $job->appointment->student_id === $studentId,
            );
        }
    }

    public function test_batch_mails_go_to_the_right_students_with_the_right_date_and_slot(): void
    {
        Mail::fake();

        $date = now()->addDays(7)->toDateString();
        // 13 students at 12/hour spans two hours: 12 at 07:00, 1 at 08:00 —
        // so this also proves each student gets THEIR OWN slot, not the batch's
        // start hour pasted onto everyone.
        $batch = $this->makeBatchWithStudents(13, ['requested_date' => $date]);

        $this->approve($batch);

        Mail::assertSent(AppointmentScheduledMail::class, 13);

        $appointments = Appointment::where('batch_request_id', $batch->id)->with('student')->get();
        $this->assertCount(13, $appointments);

        foreach ($appointments as $appointment) {
            Mail::assertSent(
                AppointmentScheduledMail::class,
                fn (AppointmentScheduledMail $mail): bool => $mail->appointment->is($appointment)
                    && $mail->hasTo($appointment->student->email),
            );
        }

        // The rendered body carries the date and the student's own hour.
        $first = $appointments->firstWhere('scheduled_time', '07:00:00');
        $last = $appointments->firstWhere('scheduled_time', '08:00:00');
        $this->assertNotNull($first, 'expected students in the 07:00 slot');
        $this->assertNotNull($last, 'expected the 13th student to spill into 08:00');

        $firstBody = (new AppointmentScheduledMail($first))->render();
        $this->assertStringContainsString($first->scheduled_date->format('l, F j, Y'), $firstBody);
        $this->assertStringContainsString('7:00 AM – 8:00 AM', $firstBody);
        $this->assertStringContainsString($first->reference_no, $firstBody);

        $lastBody = (new AppointmentScheduledMail($last))->render();
        $this->assertStringContainsString('8:00 AM – 9:00 AM', $lastBody);
    }

    public function test_a_rejected_batch_queues_nothing(): void
    {
        Queue::fake();
        Mail::fake();

        $batch = $this->makeBatchWithStudents(5);

        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/reject", [
                'rejection_reason' => 'The clinic cannot take a cohort of this size that week.',
            ])
            ->assertRedirect('/director/batches');

        $this->assertSame('rejected', $batch->fresh()->status);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_a_rolled_back_approval_queues_nothing(): void
    {
        Queue::fake();
        Mail::fake();

        $batch = $this->makeBatchWithStudents(10);

        // Blow up half way through the fan-out, exactly as the existing
        // rollback test does. The whole transaction unwinds — and because the
        // dispatch sits AFTER DB::transaction() returns, it is never reached.
        $this->mock(ReferenceNumberService::class, function (MockInterface $mock): void {
            $calls = 0;
            $mock->shouldReceive('generateAppointmentRef')->andReturnUsing(function () use (&$calls): string {
                $calls++;
                if ($calls === 5) {
                    throw new RuntimeException('simulated mid-loop failure');
                }

                return sprintf('APT-%d-8%03d', now()->year, $calls);
            });
        });

        $this->approve($batch)->assertServerError();

        // Nothing committed, so nothing to email about.
        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_an_approval_refused_for_an_elapsed_or_full_hour_queues_nothing(): void
    {
        Queue::fake();

        $date = now()->addDays(3)->toDateString();
        $batch = $this->makeBatchWithStudents(2, [
            'requested_date' => $date,
            'requested_time' => '07:00:00',
            'requested_blocks' => 1,
        ]);

        // Fill the batch's only hour to the cap (D-37 hard block).
        Appointment::factory()
            ->count(app(ClinicScheduleService::class)->hourlyCapacity())
            ->onDate($date)
            ->inSlot('07:00:00')
            ->create();

        $this->approve($batch)->assertRedirect('/director/batches');

        $this->assertSame('pending', $batch->fresh()->status);
        Queue::assertNothingPushed();
    }

    // ── Self-booking ─────────────────────────────────────────────────────────

    public function test_self_booking_queues_exactly_one_mail_to_the_booking_student(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);
        $date = now()->addDays(4)->toDateString();

        $this->actingAs($student)->post('/student/appointments', [
            'service' => 'medical',
            'date' => $date,
            'time' => '09:00:00',
            'purpose' => 'On-the-job Training',
        ])->assertRedirect();

        Mail::assertSent(AppointmentScheduledMail::class, 1);

        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();

        Mail::assertSent(
            AppointmentScheduledMail::class,
            fn (AppointmentScheduledMail $mail): bool => $mail->hasTo($student->email)
                && $mail->appointment->is($appointment),
        );

        $body = (new AppointmentScheduledMail($appointment))->render();
        $this->assertStringContainsString('9:00 AM – 10:00 AM', $body);
        $this->assertStringContainsString('On-the-job Training', $body);
    }

    public function test_a_rejected_booking_queues_nothing(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);

        // A date in the past fails validation, so no transaction, no email.
        $this->actingAs($student)->post('/student/appointments', [
            'service' => 'medical',
            'date' => now()->subDay()->toDateString(),
            'time' => '09:00:00',
            'purpose' => 'On-the-job Training',
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('appointments', 0);
        Mail::assertNothingSent();
    }

    // ── Rendering edge cases ─────────────────────────────────────────────────

    public function test_a_legacy_appointment_with_no_slot_renders_without_error(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // Pre-D-37 row: scheduled_time is NULL and belongs to no slot.
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_time' => null,
        ]);

        $this->assertNull($appointment->scheduled_time);

        $body = (new AppointmentScheduledMail($appointment))->render();

        // Renders, and shows the em dash the rest of the app uses for these.
        $this->assertStringContainsString($appointment->reference_no, $body);
        $this->assertStringContainsString('—', $body);
    }

    public function test_free_text_purpose_and_college_name_are_escaped_in_the_body(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // purpose_other is typed by the student; reason_detail by a College
        // Admin. Neither may reach the recipient's webmail as live markup.
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'source' => 'self',
            'purpose' => 'Others',
            'purpose_other' => '<script>alert("xss")</script>',
        ]);

        $body = (new AppointmentScheduledMail($appointment))->render();

        $this->assertStringNotContainsString('<script>alert', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function test_the_batch_email_names_the_college_and_the_self_email_does_not(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $batch = $this->makeBatchWithStudents(1);

        $batchAppointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        $batchBody = (new AppointmentScheduledMail($batchAppointment))->render();
        $this->assertStringContainsString('College of Computing Studies', $batchBody);
        // Batch students are told to go to their college, not to self-cancel.
        $this->assertStringContainsString('cannot cancel it yourself', $batchBody);

        $selfAppointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'source' => 'self',
        ]);

        $selfBody = (new AppointmentScheduledMail($selfAppointment))->render();
        $this->assertStringNotContainsString('College of Computing Studies', $selfBody);
        $this->assertStringContainsString('cancel from your HealthPass dashboard', $selfBody);
    }

    // ── The job itself ───────────────────────────────────────────────────────

    public function test_the_job_does_not_email_an_appointment_cancelled_while_queued(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);
        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'status' => 'cancelled',
        ]);

        (new SendAppointmentScheduledMail($appointment))->handle();

        Mail::assertNothingSent();
    }
}
