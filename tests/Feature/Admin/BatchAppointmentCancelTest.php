<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\SendAppointmentWithdrawnMail;
use App\Mail\AppointmentWithdrawnMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * College Admin batch roster + appointment withdrawal (FR-ADM-07, D-40).
 *
 * The other half of D-39: a batch student cannot cancel their own appointment
 * and their email tells them to contact their college admin — this is what the
 * admin does about it. Withdrawing flips the appointment to `cancelled`, which
 * is all it takes to free the seat, because every capacity count in the app
 * filters on `status != 'cancelled'`.
 */
class BatchAppointmentCancelTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $admin;

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

        $this->director = User::factory()->create(['role' => 'director']);
    }

    /**
     * An APPROVED batch for $college with $studentCount students, each already
     * holding a generated appointment on $date in the given slot.
     *
     * @return array{0: BatchRequest, 1: Collection<int, Appointment>}
     */
    private function makeApprovedBatch(
        int $studentCount,
        ?College $college = null,
        ?string $date = null,
        string $slot = '09:00:00',
    ): array {
        static $seq = 400;

        $college ??= $this->ccs;
        $date ??= now()->addDays(5)->toDateString();

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => $date,
            'requested_time' => $slot,
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($studentCount),
            'scheduled_date' => $date,
            'status' => 'approved',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);

        $appointments = collect();

        User::factory()->count($studentCount)->create(['role' => 'student'])->each(
            function (User $student) use ($batch, $date, $slot, $appointments, $college): void {
                StudentProfile::factory()->create([
                    'user_id' => $student->id,
                    'college_id' => $college->id,
                ]);

                $appointment = Appointment::factory()->create([
                    'student_id' => $student->id,
                    'service_type' => 'medical',
                    'scheduled_date' => $date,
                    'scheduled_time' => $slot,
                    'status' => 'scheduled',
                    'source' => 'batch',
                    'batch_request_id' => $batch->id,
                    'created_by' => $this->director->id,
                ]);

                BatchRequestStudent::create([
                    'batch_request_id' => $batch->id,
                    'student_id' => $student->id,
                    'appointment_id' => $appointment->id,
                ]);

                $appointments->push($appointment);
            }
        );

        return [$batch, $appointments];
    }

    private function withdraw(BatchRequest $batch, Appointment $appointment, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->delete("/admin/batches/{$batch->id}/appointments/{$appointment->id}");
    }

    // ── The roster page ──────────────────────────────────────────────────────

    public function test_admin_can_see_the_roster_of_an_approved_batch(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(3);

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee($batch->reference_no);

        foreach ($appointments as $appointment) {
            $response->assertSee($appointment->reference_no);
            $response->assertSee($appointment->student->name);
        }

        $response->assertSee('9:00 AM – 10:00 AM');
        $response->assertSee('Withdraw');
    }

    public function test_roster_of_another_colleges_batch_is_a_404(): void
    {
        [$batch] = $this->makeApprovedBatch(2, $this->coe);

        $this->actingAs($this->admin)
            ->get("/admin/batches/{$batch->id}")
            ->assertNotFound();
    }

    public function test_a_pending_batch_roster_shows_no_withdraw_action(): void
    {
        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-777',
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'graduation',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(6)->toDateString(),
            'requested_time' => '10:00:00',
            'requested_blocks' => 1,
            'status' => 'pending',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee($student->name);
        // No appointments exist before approval, so nothing to withdraw.
        $response->assertDontSee('Withdraw');
    }

    // ── Withdrawing ──────────────────────────────────────────────────────────

    public function test_admin_can_withdraw_one_students_appointment(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(3);
        $target = $appointments->first();

        $this->withdraw($batch, $target)
            ->assertRedirect("/admin/batches/{$batch->id}")
            ->assertSessionHas('status');

        $this->assertSame('cancelled', $target->fresh()->status);

        // Only that one — the rest of the cohort is untouched.
        foreach ($appointments->skip(1) as $other) {
            $this->assertSame('scheduled', $other->fresh()->status);
        }
    }

    public function test_withdrawing_keeps_the_row_and_its_pivot_link_for_audit(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(2);
        $target = $appointments->first();

        $this->withdraw($batch, $target);

        // The appointment is flipped, never deleted...
        $this->assertDatabaseHas('appointments', [
            'id' => $target->id,
            'status' => 'cancelled',
            'batch_request_id' => $batch->id,
        ]);

        // ...and the batch still remembers the student was on it.
        $this->assertDatabaseHas('batch_request_students', [
            'batch_request_id' => $batch->id,
            'student_id' => $target->student_id,
            'appointment_id' => $target->id,
        ]);
    }

    public function test_withdrawing_frees_the_hour_slot_for_another_student(): void
    {
        $date = now()->addDays(5)->toDateString();
        $schedule = app(ClinicScheduleService::class);
        $capacity = $schedule->hourlyCapacity();

        // Fill the 9 AM hour exactly to the cap with one batch.
        [$batch, $appointments] = $this->makeApprovedBatch($capacity, $this->ccs, $date, '09:00:00');

        $this->assertSame($capacity, $schedule->bookedInSlot($date, '09:00:00'));

        // A student cannot book into it — the hour is full (D-37).
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->post('/student/appointments', [
            'service' => 'medical',
            'date' => $date,
            'time' => '09:00:00',
            'purpose' => 'On-the-job Training',
        ])->assertSessionHasErrors('time');

        // The admin withdraws one student...
        $this->withdraw($batch, $appointments->first());

        // ...which frees a seat with no extra bookkeeping, because every
        // capacity count filters on status != 'cancelled'.
        $this->assertSame($capacity - 1, $schedule->bookedInSlot($date, '09:00:00'));

        $this->actingAs($student)->post('/student/appointments', [
            'service' => 'medical',
            'date' => $date,
            'time' => '09:00:00',
            'purpose' => 'On-the-job Training',
        ])->assertSessionHasNoErrors();

        $this->assertSame($capacity, $schedule->bookedInSlot($date, '09:00:00'));
    }

    public function test_an_appointment_dated_today_can_still_be_withdrawn(): void
    {
        // "The student phoned in sick this morning" — the commonest reason a
        // seat needs freeing, so unlike the student rule this stays allowed.
        [$batch, $appointments] = $this->makeApprovedBatch(1, $this->ccs, today()->toDateString());

        $this->withdraw($batch, $appointments->first())
            ->assertSessionHas('status');

        $this->assertSame('cancelled', $appointments->first()->fresh()->status);
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    public function test_admin_cannot_withdraw_an_appointment_from_another_colleges_batch(): void
    {
        [$foreignBatch, $foreignAppointments] = $this->makeApprovedBatch(2, $this->coe);
        $target = $foreignAppointments->first();

        $this->withdraw($foreignBatch, $target)->assertNotFound();

        $this->assertSame('scheduled', $target->fresh()->status);
    }

    public function test_an_appointment_id_from_a_different_batch_is_a_404(): void
    {
        [$ownBatch] = $this->makeApprovedBatch(1);
        [, $otherAppointments] = $this->makeApprovedBatch(1);

        // Own batch id in the path, someone else's appointment id — the
        // appointment must belong to THAT batch.
        $this->withdraw($ownBatch, $otherAppointments->first())->assertNotFound();

        $this->assertSame('scheduled', $otherAppointments->first()->fresh()->status);
    }

    public function test_a_checked_in_appointment_cannot_be_withdrawn(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $target = $appointments->first();

        // The student is already at the kiosk; a clinic_visit may point here.
        $target->update(['status' => 'checked_in']);

        $this->withdraw($batch, $target)
            ->assertRedirect("/admin/batches/{$batch->id}")
            ->assertSessionHas('error');

        $this->assertSame('checked_in', $target->fresh()->status);
    }

    public function test_a_completed_appointment_cannot_be_withdrawn(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $target = $appointments->first();
        $target->update(['status' => 'completed']);

        $this->withdraw($batch, $target)->assertSessionHas('error');

        $this->assertSame('completed', $target->fresh()->status);
    }

    public function test_a_past_appointment_cannot_be_withdrawn(): void
    {
        // Nothing left to free, and it would rewrite history.
        [$batch, $appointments] = $this->makeApprovedBatch(1, $this->ccs, now()->subDay()->toDateString());

        $this->withdraw($batch, $appointments->first())->assertSessionHas('error');

        $this->assertSame('scheduled', $appointments->first()->fresh()->status);
    }

    public function test_a_duplicate_withdraw_is_a_harmless_no_op(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(2);
        $target = $appointments->first();

        $this->withdraw($batch, $target)->assertSessionHas('status');
        $this->withdraw($batch, $target)->assertSessionHas('error');

        $this->assertSame('cancelled', $target->fresh()->status);
    }

    public function test_a_self_booked_appointment_is_not_withdrawable_here(): void
    {
        [$batch] = $this->makeApprovedBatch(1);

        // Belongs to the batch by id but is marked self-booked — the admin
        // route only ever withdraws batch-generated seats.
        $selfBooked = Appointment::factory()->create([
            'source' => 'self',
            'batch_request_id' => $batch->id,
            'scheduled_date' => now()->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->withdraw($batch, $selfBooked)->assertSessionHas('error');

        $this->assertSame('scheduled', $selfBooked->fresh()->status);
    }

    // ── Role / scope guards ──────────────────────────────────────────────────

    public function test_a_student_cannot_reach_the_withdraw_endpoint(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $student = User::factory()->create(['role' => 'student']);

        $this->withdraw($batch, $appointments->first(), $student)
            ->assertRedirect(route('student.dashboard'));

        $this->assertSame('scheduled', $appointments->first()->fresh()->status);
    }

    public function test_the_director_cannot_reach_the_withdraw_endpoint(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(1);

        $this->withdraw($batch, $appointments->first(), $this->director)
            ->assertRedirect(route('director.dashboard'));

        $this->assertSame('scheduled', $appointments->first()->fresh()->status);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(1);

        $this->delete("/admin/batches/{$batch->id}/appointments/{$appointments->first()->id}")
            ->assertRedirect(route('login'));
    }

    // ── Withdrawal notice (FR-STU-13, D-41) ──────────────────────────────────

    public function test_withdrawing_emails_exactly_that_one_student(): void
    {
        Mail::fake();

        [$batch, $appointments] = $this->makeApprovedBatch(4);
        $target = $appointments->first();

        $this->withdraw($batch, $target);

        Mail::assertSent(AppointmentWithdrawnMail::class, 1);
        Mail::assertSent(
            AppointmentWithdrawnMail::class,
            fn (AppointmentWithdrawnMail $mail): bool => $mail->appointment->is($target)
                && $mail->hasTo($target->student->email),
        );

        // The other three are still booked and must hear nothing.
        foreach ($appointments->skip(1) as $other) {
            Mail::assertNotSent(
                AppointmentWithdrawnMail::class,
                fn (AppointmentWithdrawnMail $mail): bool => $mail->hasTo($other->student->email),
            );
        }
    }

    public function test_withdrawing_queues_exactly_one_job(): void
    {
        Queue::fake();

        [$batch, $appointments] = $this->makeApprovedBatch(3);

        $this->withdraw($batch, $appointments->first());

        Queue::assertPushed(SendAppointmentWithdrawnMail::class, 1);
    }

    public function test_the_withdrawal_email_names_the_cancelled_date_slot_and_reference(): void
    {
        $date = now()->addDays(5)->toDateString();
        [$batch, $appointments] = $this->makeApprovedBatch(1, $this->ccs, $date, '09:00:00');
        $target = $appointments->first();

        $this->withdraw($batch, $target);

        $body = (new AppointmentWithdrawnMail($target->fresh()))->render();

        $this->assertStringContainsString($target->reference_no, $body);
        $this->assertStringContainsString(
            Carbon::parse($date)->format('l, F j, Y'),
            $body,
        );
        $this->assertStringContainsString('9:00 AM – 10:00 AM', $body);
        $this->assertStringContainsString('College of Computing Studies', $body);
        // It must tell them they can rebook themselves — a withdrawn student is
        // an ordinary student again and self-booking is open to them.
        $this->assertStringContainsString('Book an appointment in HealthPass', $body);
    }

    public function test_the_withdrawal_email_carries_no_health_data(): void
    {
        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $target = $appointments->first();

        $this->withdraw($batch, $target);

        $body = (new AppointmentWithdrawnMail($target->fresh()))->render();

        // FR-STU-08: scheduling notices never surface clearance outcomes.
        foreach (['Fit', 'Unfit', 'BMI', 'blood pressure', 'vital'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body);
        }
    }

    public function test_a_refused_withdrawal_emails_nobody(): void
    {
        Mail::fake();
        Queue::fake();

        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $target = $appointments->first();
        $target->update(['status' => 'checked_in']);

        $this->withdraw($batch, $target)->assertSessionHas('error');

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_duplicate_withdraw_emails_the_student_only_once(): void
    {
        Queue::fake();

        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $target = $appointments->first();

        $this->withdraw($batch, $target)->assertSessionHas('status');
        $this->withdraw($batch, $target)->assertSessionHas('error');

        Queue::assertPushed(SendAppointmentWithdrawnMail::class, 1);
    }

    public function test_a_withdrawn_appointment_with_no_slot_renders_without_error(): void
    {
        // Pre-D-37 batch appointment: scheduled_time NULL, belongs to no slot.
        [$batch, $appointments] = $this->makeApprovedBatch(1);
        $target = $appointments->first();
        $target->update(['scheduled_time' => null]);

        $this->withdraw($batch, $target)->assertSessionHas('status');

        $body = (new AppointmentWithdrawnMail($target->fresh()))->render();

        $this->assertStringContainsString($target->reference_no, $body);
        $this->assertStringContainsString('—', $body);
    }

    public function test_the_job_does_not_email_about_an_appointment_that_is_not_cancelled(): void
    {
        Mail::fake();

        [, $appointments] = $this->makeApprovedBatch(1);

        // Still 'scheduled' — a stale job must not claim it was withdrawn.
        (new SendAppointmentWithdrawnMail($appointments->first()))->handle();

        Mail::assertNothingSent();
    }

    public function test_batch_tracking_links_each_batch_to_its_roster(): void
    {
        [$batch] = $this->makeApprovedBatch(1);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee(route('admin.batches.show', $batch->id), escape: false);
    }
}
