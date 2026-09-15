<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use App\Support\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D-57 — what each sidebar badge counts, and what clears it (FR-UI-05).
 *
 * Two kinds of badge:
 *  - "since last seen": opening the page stamps users.nav_seen_at for that
 *    page, and only events after the stamp count. A key never stamped falls
 *    back to the account's created_at.
 *  - work counts (Live Queue, Batch Approvals): what is waiting right now.
 *    Opening the page changes nothing; doing the work does.
 *
 * Every viewer is created at 09:00 (their baseline) and each test then starts
 * at 09:05, so anything a test creates is strictly after the baseline. Events
 * are made by writing the same columns the real endpoints write — the badges
 * read rows, so how a row changed does not matter to them.
 */
class NavBadgesTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $student;

    private User $admin;

    private User $nurse;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00:00'));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->student = $this->makeStudent();
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->director = User::factory()->create(['role' => 'director']);

        $this->travel(5)->minutes();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function badges(User $user): array
    {
        return NavBadges::forUser($user->fresh());
    }

    private function makeStudent(?College $college = null): User
    {
        $student = User::factory()->create(['role' => 'student']);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'college_id' => ($college ?? $this->ccs)->id,
        ]);

        return $student;
    }

    private function makeBatch(string $status = 'pending', ?College $college = null, ?User $requester = null): BatchRequest
    {
        static $seq = 900;

        $isDecided = in_array($status, ['approved', 'rejected'], true);

        return BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => ($college ?? $this->ccs)->id,
            'requested_by' => ($requester ?? $this->admin)->id,
            'reason' => 'graduation',
            'service_type' => 'medical',
            'requested_date' => '2026-09-20',
            'requested_time' => '09:00:00',
            'requested_blocks' => 1,
            'scheduled_date' => $status === 'approved' ? '2026-09-20' : null,
            'status' => $status,
            'reviewed_by' => $isDecided ? $this->director->id : null,
            'reviewed_at' => $isDecided ? now() : null,
        ]);
    }

    /** The Director's decision, as BatchApprovalController writes it. */
    private function decide(BatchRequest $batch, string $status): void
    {
        $batch->update([
            'status' => $status,
            'scheduled_date' => $status === 'approved' ? $batch->requested_date : null,
            'rejection_reason' => $status === 'rejected' ? 'The clinic is closed that day.' : null,
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);
    }

    private function cancel(BatchRequest $batch, User $by): void
    {
        $batch->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by->id]);
    }

    private function batchAppointment(User $student, BatchRequest $batch): Appointment
    {
        return Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => '2026-09-20',
            'scheduled_time' => '09:00:00',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);
    }

    /** @param  array<string, bool>  $flags */
    private function captureVisit(User $student, ?Appointment $appointment = null, array $flags = [], ?College $college = null): ClinicVisit
    {
        static $seq = 9000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $student->id,
            'college_id' => ($college ?? $this->ccs)->id,
            'appointment_id' => $appointment?->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'checked_in_at' => now(),
        ]);

        VitalSigns::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 170.0,
            'weight_kg' => 65.0,
            'bmi' => 22.5,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 120,
            'bp_diastolic' => 80,
            'entry_method' => 'manual',
            'is_bp_flagged' => false,
            'is_temp_flagged' => false,
            'is_bmi_flagged' => false,
        ], $flags));

        return $visit;
    }

    /** The nurse's Save & Close, as EncodeController writes it. */
    private function encode(ClinicVisit $visit): void
    {
        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);

        $visit->update(['status' => 'encoded']);
    }

    // ── Which items carry a badge ────────────────────────────────────────────

    public function test_each_role_gets_only_its_own_badged_items(): void
    {
        // Dashboards, Book Appointment, New Batch Request, Analytics, My ID &
        // Profile, Enable Kiosk Mode, Staff Accounts and Change Password carry
        // no badge, so they are not in the map at all.
        $this->assertSame(['student.my-appointments', 'student.records', 'student.tutorial'], array_keys($this->badges($this->student)));
        $this->assertSame(['admin.batches.index', 'admin.activity'], array_keys($this->badges($this->admin)));
        $this->assertSame(['nurse.queue'], array_keys($this->badges($this->nurse)));
        $this->assertSame(['director.batches.index', 'director.anomalies'], array_keys($this->badges($this->director)));
    }

    // ── Student ──────────────────────────────────────────────────────────────

    public function test_an_appointment_the_college_booked_counts_until_my_appointments_is_opened(): void
    {
        $this->batchAppointment($this->student, $this->makeBatch('approved'));

        $this->assertSame(1, $this->badges($this->student)['student.my-appointments']);

        // The stamp lands BEFORE the page renders, so the item you are on reads 0.
        $this->actingAs($this->student)->get(route('student.my-appointments'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-for="student.my-appointments"', false);

        $this->assertSame(0, $this->badges($this->student)['student.my-appointments']);
    }

    public function test_a_withdrawn_batch_appointment_counts(): void
    {
        $appointment = $this->batchAppointment($this->student, $this->makeBatch('approved'));
        $this->actingAs($this->student)->get(route('student.my-appointments'))->assertOk();

        $this->travel(1)->minutes();
        // FR-ADM-07: the College Admin withdraws the seat.
        $appointment->update(['status' => 'cancelled']);

        $this->assertSame(1, $this->badges($this->student)['student.my-appointments']);
    }

    public function test_the_students_own_self_bookings_never_count(): void
    {
        Appointment::factory()->create(['student_id' => $this->student->id, 'source' => 'self']);
        Appointment::factory()->cancelled()->create(['student_id' => $this->student->id, 'source' => 'self']);

        $this->assertSame(0, $this->badges($this->student)['student.my-appointments']);
    }

    public function test_an_encoded_record_counts_until_my_records_is_opened(): void
    {
        $visit = $this->captureVisit($this->student);
        $this->assertSame(0, $this->badges($this->student)['student.records'], 'A captured visit has no result yet.');

        $this->encode($visit);
        // Another student's result is never theirs to see.
        $this->encode($this->captureVisit($this->makeStudent()));

        $this->assertSame(1, $this->badges($this->student)['student.records']);

        $this->actingAs($this->student)->get(route('student.records'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-for="student.records"', false);

        $this->assertSame(0, $this->badges($this->student)['student.records']);
    }

    public function test_the_tutorial_dot_stays_until_the_student_finishes_the_walkthrough(): void
    {
        $this->assertSame(1, $this->badges($this->student)['student.tutorial']);

        // Opening the page alone does not finish it.
        $this->actingAs($this->student)->get(route('student.tutorial'))->assertOk();
        $this->assertSame(1, $this->badges($this->student)['student.tutorial']);

        $this->actingAs($this->student)->post(route('student.tutorial.complete'))->assertNoContent();

        $this->assertSame(0, $this->badges($this->student)['student.tutorial']);
    }

    public function test_finishing_the_tutorial_again_keeps_the_first_timestamp(): void
    {
        $this->actingAs($this->student)->post(route('student.tutorial.complete'))->assertNoContent();
        $first = $this->student->fresh()->nav_seen_at[NavBadges::TUTORIAL_COMPLETED];

        $this->travel(1)->days();
        $this->actingAs($this->student)->post(route('student.tutorial.complete'))->assertNoContent();

        $this->assertSame($first, $this->student->fresh()->nav_seen_at[NavBadges::TUTORIAL_COMPLETED]);
    }

    public function test_only_a_student_can_finish_the_tutorial(): void
    {
        $this->post(route('student.tutorial.complete'))->assertRedirect(route('login'));

        foreach ([$this->admin, $this->nurse, $this->director] as $user) {
            // The student group's role middleware turns other roles away to
            // their own dashboard — the house rule for every /student route.
            $this->actingAs($user)->post(route('student.tutorial.complete'))
                ->assertRedirect(EnsureRole::dashboardFor($user));

            $this->assertNull($user->fresh()->nav_seen_at);
        }
    }

    // ── College Admin ────────────────────────────────────────────────────────

    public function test_director_decisions_count_on_batch_tracking(): void
    {
        $toApprove = $this->makeBatch();
        $toReject = $this->makeBatch();
        $this->assertSame(0, $this->badges($this->admin)['admin.batches.index'], 'A pending batch has nothing to read yet.');

        $this->decide($toApprove, 'approved');
        $this->assertSame(1, $this->badges($this->admin)['admin.batches.index']);

        $this->decide($toReject, 'rejected');
        $this->assertSame(2, $this->badges($this->admin)['admin.batches.index']);
    }

    public function test_an_encode_for_a_batch_student_counts_until_batch_tracking_is_opened(): void
    {
        $student = $this->makeStudent();
        $visit = $this->captureVisit($student, $this->batchAppointment($student, $this->makeBatch('approved')));

        $this->actingAs($this->admin)->get(route('admin.batches.index'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-for="admin.batches.index"', false);
        $this->assertSame(0, $this->badges($this->admin)['admin.batches.index']);

        $this->travel(1)->minutes();
        $this->encode($visit);
        // The same student's walk-in is not on any batch.
        $this->encode($this->captureVisit($student));

        $this->assertSame(1, $this->badges($this->admin)['admin.batches.index']);

        $this->actingAs($this->admin)->get(route('admin.batches.index'))->assertOk();
        $this->assertSame(0, $this->badges($this->admin)['admin.batches.index']);
    }

    public function test_another_colleges_events_never_count_for_the_admin(): void
    {
        $coeAdmin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->coe->id]);
        $coeStudent = $this->makeStudent($this->coe);
        $this->travel(1)->minutes();

        $coeBatch = $this->makeBatch('pending', $this->coe, $coeAdmin);
        $this->decide($coeBatch, 'approved');
        $this->cancel($this->makeBatch('pending', $this->coe, $coeAdmin), $coeAdmin);
        $this->encode($this->captureVisit($coeStudent, $this->batchAppointment($coeStudent, $coeBatch), college: $this->coe));

        $this->assertSame(['admin.batches.index' => 0, 'admin.activity' => 0], $this->badges($this->admin));
        // The COE admin sees the decision and the encode; their own submissions
        // and cancellation are not news to them.
        $this->assertSame(['admin.batches.index' => 2, 'admin.activity' => 1], $this->badges($coeAdmin));
    }

    public function test_the_activity_log_counts_everyone_elses_entries_but_not_the_viewers_own(): void
    {
        $colleague = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->travel(1)->minutes();

        $mine = $this->makeBatch();
        $this->assertSame(0, $this->badges($this->admin)['admin.activity'], 'Your own submission is not news to you.');

        $theirs = $this->makeBatch(requester: $colleague);
        $this->assertSame(1, $this->badges($this->admin)['admin.activity']);

        // The Director deciding YOUR batch is still something you have not seen.
        $this->decide($mine, 'approved');
        $this->assertSame(2, $this->badges($this->admin)['admin.activity']);

        $this->cancel($theirs, $colleague);
        $this->assertSame(3, $this->badges($this->admin)['admin.activity']);

        $this->cancel($this->makeBatch(), $this->admin);
        $this->assertSame(3, $this->badges($this->admin)['admin.activity'], 'Your own submission and cancellation add nothing.');

        $this->actingAs($this->admin)->get(route('admin.activity'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-for="admin.activity"', false);

        $this->assertSame(0, $this->badges($this->admin)['admin.activity']);
        $this->assertSame(4, $this->badges($colleague)['admin.activity'], "The colleague's own two entries are excluded from theirs.");
    }

    // ── Nurse ────────────────────────────────────────────────────────────────

    public function test_the_live_queue_counts_waiting_visits_and_opening_it_does_not_clear_them(): void
    {
        $visits = collect(range(1, 3))->map(fn (): ClinicVisit => $this->captureVisit($this->makeStudent()));
        $this->assertSame(3, $this->badges($this->nurse)['nurse.queue']);

        $this->encode($visits->first());
        $this->assertSame(2, $this->badges($this->nurse)['nurse.queue']);

        $this->actingAs($this->nurse)->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('data-nav-badge-for="nurse.queue"', false);

        $this->assertSame(2, $this->badges($this->nurse)['nurse.queue']);
    }

    // ── Director ─────────────────────────────────────────────────────────────

    public function test_batch_approvals_counts_pending_requests_and_opening_it_does_not_clear_them(): void
    {
        $this->makeBatch();
        $this->makeBatch();
        $this->makeBatch('approved');
        $this->makeBatch('rejected');
        $this->cancel($this->makeBatch(), $this->admin);

        $this->assertSame(2, $this->badges($this->director)['director.batches.index']);

        $this->actingAs($this->director)->get(route('director.batches.index'))
            ->assertOk()
            ->assertSee('data-nav-badge-for="director.batches.index"', false);

        $this->assertSame(2, $this->badges($this->director)['director.batches.index']);
    }

    public function test_flagged_anomalies_counts_flagged_visits_captured_since_last_seen(): void
    {
        $this->captureVisit($this->makeStudent(), flags: ['is_bp_flagged' => true]);
        $this->captureVisit($this->makeStudent());
        $this->assertSame(1, $this->badges($this->director)['director.anomalies'], 'An unflagged visit is not an anomaly.');

        $this->actingAs($this->director)->get(route('director.anomalies'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-for="director.anomalies"', false);
        $this->assertSame(0, $this->badges($this->director)['director.anomalies']);

        $this->travel(1)->minutes();
        $this->captureVisit($this->makeStudent(), flags: ['is_temp_flagged' => true]);

        $this->assertSame(1, $this->badges($this->director)['director.anomalies']);
    }

    // ── The seen stamp ───────────────────────────────────────────────────────

    public function test_stamping_a_page_as_seen_never_changes_updated_at(): void
    {
        $updatedAt = $this->student->updated_at;

        $this->actingAs($this->student)->get(route('student.records'))->assertOk();

        $fresh = $this->student->fresh();
        $this->assertArrayHasKey('student.records', $fresh->nav_seen_at);
        $this->assertTrue($updatedAt->equalTo($fresh->updated_at), 'Opening a page is not an edit to the account.');
    }

    public function test_a_key_never_stamped_falls_back_to_when_the_account_was_created(): void
    {
        // Flagged at 09:05, before this Director's account exists.
        $this->captureVisit($this->makeStudent(), flags: ['is_bp_flagged' => true]);

        $this->travel(1)->minutes();
        $newDirector = User::factory()->create(['role' => 'director']);
        $this->assertSame(0, $this->badges($newDirector)['director.anomalies']);

        $this->travel(1)->minutes();
        $this->captureVisit($this->makeStudent(), flags: ['is_bmi_flagged' => true]);

        $this->assertSame(1, $this->badges($newDirector)['director.anomalies']);
        $this->assertNull($newDirector->fresh()->nav_seen_at);
    }
}
