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
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Batch Results card and popup on Batch Tracking (FR-ADM-12, D-55).
 *
 * A College Admin who booked a cohort wants to see, per batch, who has
 * finished and who did not show, without opening each batch. Every APPROVED
 * batch of their college gets one row — its reference, its Time of
 * Completion, and a View button whose popup lists each student's status and
 * result.
 *
 * Two rules carry the page, and both live on the models so the column and the
 * popup read one definition:
 *   - Appointment::clearanceProgress() — a student with no clinic visit is
 *     ABSENT once the server clock reaches `healthpass.absent_cutoff`
 *     (8:00 PM) on the clinic date, and Not yet attended before that
 *   - BatchRequest::resultsCompletedAt() — a batch is finished once every
 *     non-withdrawn student is Completed or Absent
 *
 * The result is CLINICAL. PRD §6.6 (opened by D-53, moved here by D-55)
 * permits exactly one field of it — Fit / Unfit — to the admin of the
 * student's own college; the privacy cases pin down that nothing else of the
 * record reaches the page.
 *
 * D-55 also trimmed the batch roster: its D-53 Status and Result columns and
 * roll-up are gone, which the last section asserts.
 */
class BatchResultsTest extends TestCase
{
    use RefreshDatabase;

    /** The clinic day most cases run on — a Thursday. */
    private const CLINIC_DAY = '2026-09-10';

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        // Two days before the clinic day, mid-morning: nobody can be absent yet.
        $this->travelTo(Carbon::parse('2026-09-08 09:00:00'));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A batch on $date with no students yet — approved unless told otherwise. */
    private function makeBatch(
        string $status = 'approved',
        string $date = self::CLINIC_DAY,
        ?College $college = null,
    ): BatchRequest {
        static $seq = 800;

        $isDecided = in_array($status, ['approved', 'rejected'], true);

        return BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => ($college ?? $this->ccs)->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',    // D-62
            'reason' => 'fieldtrip',
            'service_type' => 'medical',   // D-60: the only service
            'requested_date' => $date,
            'requested_time' => '09:00:00',
            'requested_blocks' => 1,
            'scheduled_date' => $status === 'approved' ? $date : null,
            'status' => $status,
            'reviewed_by' => $isDecided ? $this->director->id : null,
            'reviewed_at' => $isDecided ? now() : null,
        ]);
    }

    /**
     * Put one student on $batch and drive them to $stage:
     *
     *   'booked'      — appointment only, nothing at the kiosk
     *   'withdrawn'   — the admin pulled the seat
     *   'in_clinic'   — kiosk visit captured, the nurse has not encoded it
     *   'Fit'/'Unfit' — encoded with that outcome, at $encodedAt
     */
    private function addStudent(
        BatchRequest $batch,
        string $stage,
        string $name,
        string $encodedAt = self::CLINIC_DAY.' 10:15:00',
    ): Appointment {
        static $seq = 8000;

        $seq++;

        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'college_id' => $batch->college_id,
        ]);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => $batch->service_type,
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
            'reference_no' => 'HP-2026-'.$seq,
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
            'encoded_at' => $encodedAt,
        ]);

        // The nurse's encode is what flips the appointment (EncodeController).
        $appointment->update(['status' => 'completed']);

        return $appointment;
    }

    private function tracking(): TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/batches');
    }

    /**
     * The popup payload the page embedded for $batch.
     *
     * @return array{ref: string, service: string, date: string, span: string, students: list<array<string, ?string>>}
     */
    private function popup(TestResponse $response, BatchRequest $batch): array
    {
        return $response->viewData('resultPopups')[$batch->id];
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // ── Which batches the card lists ─────────────────────────────────────────

    public function test_the_card_lists_every_approved_batch_newest_clinic_date_first(): void
    {
        $earlier = $this->makeBatch(date: self::CLINIC_DAY);
        $later = $this->makeBatch(date: '2026-09-12');
        $pending = $this->makeBatch('pending');
        $rejected = $this->makeBatch('rejected');
        $cancelled = $this->makeBatch('cancelled');

        $response = $this->tracking();

        $response->assertOk();
        $response->assertSee('Batch Results');
        $response->assertSee('Time of Completion');
        $response->assertViewHas(
            'approvedBatches',
            fn (Collection $batches): bool => $batches->pluck('reference_no')->all()
                === [$later->reference_no, $earlier->reference_no],
        );

        // The other three are still on the requests list below — just not here.
        $this->assertArrayNotHasKey($pending->id, $response->viewData('resultPopups'));
        $this->assertArrayNotHasKey($rejected->id, $response->viewData('resultPopups'));
        $this->assertArrayNotHasKey($cancelled->id, $response->viewData('resultPopups'));
    }

    public function test_the_card_is_not_rendered_without_an_approved_batch(): void
    {
        $this->makeBatch('pending');
        $this->makeBatch('rejected');

        $response = $this->tracking();

        $response->assertOk();
        $response->assertDontSee('Batch Results');
        $response->assertDontSee('Time of Completion');
    }

    public function test_another_colleges_approved_batch_never_appears(): void
    {
        $own = $this->makeBatch();
        $foreign = $this->makeBatch(college: $this->coe);
        $this->addStudent($foreign, 'Unfit', 'Gina Other');

        $response = $this->tracking();

        $response->assertOk();
        $response->assertSee($own->reference_no);
        $response->assertDontSee($foreign->reference_no);
        $response->assertDontSee('Gina Other');
        $this->assertArrayNotHasKey($foreign->id, $response->viewData('resultPopups'));
    }

    // ── Time of Completion ───────────────────────────────────────────────────

    public function test_a_student_not_yet_attended_keeps_the_batch_in_progress(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->tracking();

        $response->assertSee('In progress');
        $response->assertDontSee('No one attended');
    }

    public function test_a_student_at_the_clinic_keeps_the_batch_in_progress(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');

        $this->tracking()->assertSee('In progress');
    }

    public function test_a_no_show_becomes_absent_at_eight_pm_on_the_clinic_day(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 19:59:00'));
        $response = $this->tracking();

        $this->assertSame('awaiting', $this->popup($response, $batch)['students'][0]['status']);
        $response->assertSee('In progress');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:00:00'));
        $response = $this->tracking();

        $this->assertSame('absent', $this->popup($response, $batch)['students'][0]['status']);
        $response->assertSee('No one attended');
        $response->assertDontSee('In progress');
    }

    public function test_overriding_the_absent_cutoff_moves_the_boundary(): void
    {
        config(['healthpass.absent_cutoff' => '18:00']);

        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 17:59:00'));
        $this->assertSame('awaiting', $this->popup($this->tracking(), $batch)['students'][0]['status']);

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 18:00:00'));
        $this->assertSame('absent', $this->popup($this->tracking(), $batch)['students'][0]['status']);
    }

    public function test_a_student_at_the_clinic_past_the_cutoff_keeps_the_batch_in_progress(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        // The next morning: Dino is absent, but Cara is still waiting on the nurse.
        $this->travelTo(Carbon::parse('2026-09-11 08:00:00'));
        $response = $this->tracking();

        $statuses = array_column($this->popup($response, $batch)['students'], 'status');
        $this->assertSame(['in_clinic', 'absent'], $statuses);
        $response->assertSee('In progress');
    }

    public function test_a_finished_batch_shows_the_time_of_its_last_encode(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Unfit', 'Ben Flagged', self::CLINIC_DAY.' 15:42:00');
        $this->addStudent($batch, 'Fit', 'Ana Cleared', self::CLINIC_DAY.' 11:05:00');
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->travelTo(Carbon::parse('2026-09-11 08:00:00'));
        $response = $this->tracking();

        $response->assertSee('Sep 10, 2026 · 3:42 PM');
        $response->assertDontSee('In progress');
        $response->assertDontSee('No one attended');
    }

    public function test_withdrawn_students_do_not_block_finishing(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared', self::CLINIC_DAY.' 09:20:00');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        // Still the clinic day, well before the cutoff — nobody left to wait for.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 10:00:00'));

        $this->tracking()->assertSee('Sep 10, 2026 · 9:20 AM');
    }

    public function test_a_batch_where_everyone_was_absent_or_withdrawn_reads_no_one_attended(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $this->addStudent($batch, 'booked', 'Elmo Absent');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->travelTo(Carbon::parse('2026-09-11 08:00:00'));
        $response = $this->tracking();

        $response->assertSee('No one attended');
        $response->assertDontSee('In progress');
    }

    // ── The popup ────────────────────────────────────────────────────────────

    public function test_the_popup_carries_the_batch_header_and_one_row_per_student(): void
    {
        $batch = $this->makeBatch();
        $fit = $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 13:00:00'));
        $response = $this->tracking();
        $popup = $this->popup($response, $batch);

        $this->assertSame($batch->reference_no, $popup['ref']);
        $this->assertSame('Thursday, September 10, 2026', $popup['date']);
        $this->assertSame('9:00 AM – 10:00 AM (1 slot)', $popup['span']);

        $this->assertSame([
            'name' => 'Ana Cleared',
            'number' => $fit->student->studentProfile->student_number,
            'hour' => '9:00 AM – 10:00 AM',
            'status' => 'completed',
            'result' => 'Fit',
        ], $popup['students'][0]);

        $this->assertSame(
            [['completed', 'Fit'], ['completed', 'Unfit'], ['in_clinic', null], ['awaiting', null], ['withdrawn', null]],
            array_map(fn (array $row): array => [$row['status'], $row['result']], $popup['students']),
        );

        // Embedded in the page for Alpine to open — through Js::from().
        $response->assertSee('results = JSON.parse', false);
        $response->assertSee('Ana Cleared', false);
    }

    public function test_a_name_with_an_apostrophe_cannot_break_the_payload(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', "Jo O'Brien");

        $response = $this->tracking()->assertOk();

        // The payload sits inside a single-quoted JSON.parse('…') string: a raw
        // apostrophe there would end the string and break the Alpine
        // expression. Js::from() escapes it, and the name still round-trips.
        $this->assertStringNotContainsString("O'Brien", $response->getContent());
        $this->assertSame("Jo O'Brien", $this->popup($response, $batch)['students'][0]['name']);
    }

    public function test_the_popup_carries_the_outcome_and_nothing_else_of_the_record(): void
    {
        $batch = $this->makeBatch();
        $appointment = $this->addStudent($batch, 'Unfit', 'Ben Flagged');

        VitalSigns::create([
            'clinic_visit_id' => $appointment->clinicVisit->id,
            'height_cm' => 171.2,
            'weight_kg' => 93.4,
            'bmi' => 31.9,
            'temperature_c' => 38.7,
            'heart_rate_bpm' => 104,
            'bp_systolic' => 152,
            'bp_diastolic' => 96,
            'entry_method' => 'manual',
            'is_temp_flagged' => true,
            'is_bp_flagged' => true,
            'is_bmi_flagged' => true,
        ]);

        $response = $this->tracking();
        $row = $this->popup($response, $batch)['students'][0];

        // §6.6 (D-53, moved here by D-55): the OUTCOME, and only the outcome.
        $this->assertSame('Unfit', $row['result']);
        $this->assertSame(['name', 'number', 'hour', 'status', 'result'], array_keys($row));

        $response->assertDontSee('Borderline blood pressure');
        $response->assertDontSee('93.4');
        $response->assertDontSee('38.7');
        $response->assertDontSee('HP-2026-');
    }

    public function test_the_card_does_not_query_per_student(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');

        // Warm-up: anything a first request writes once (the last-active stamp)
        // must not be mistaken for a per-student query.
        $this->tracking();
        $withOne = $this->countQueries(fn () => $this->tracking());

        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->assertSame($withOne, $this->countQueries(fn () => $this->tracking()));
    }

    // ── The roster, trimmed by D-55 ──────────────────────────────────────────

    public function test_the_roster_no_longer_carries_status_result_or_the_roll_up(): void
    {
        $batch = $this->makeBatch();
        $unfit = $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $booked = $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertDontSee('Clearance results');
        $response->assertDontSee('>Status<', false);
        $response->assertDontSee('>Result<', false);
        $response->assertDontSee('Unfit');
        $response->assertDontSee('At the clinic');
        $response->assertDontSee('Not yet attended');

        // What stays: the appointment, its hour, and the Withdraw action.
        $response->assertSee($unfit->reference_no);
        $response->assertSee($booked->reference_no);
        $response->assertSee('9:00 AM – 10:00 AM');
        $response->assertSee('Withdraw');
    }

    public function test_the_roster_still_states_the_date_the_hour_span_and_the_purpose(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee('Clinic date');
        $response->assertSee('Thursday, September 10, 2026');
        $response->assertSee('Hour span');
        $response->assertSee('9:00 AM');
        $response->assertSee('Form');
        $response->assertSee('Medical Clearance');   // D-62 form type
        $response->assertSee('Purpose');
        $response->assertSee('Field Trip/Educational Tour');
    }

    public function test_a_pending_batch_roster_still_lists_course_and_year(): void
    {
        $batch = $this->makeBatch('pending');
        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => User::factory()->create(['role' => 'student'])->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee('Course &amp; Year', false);
    }

    public function test_another_colleges_roster_is_not_readable(): void
    {
        $batch = $this->makeBatch(college: $this->coe);
        $this->addStudent($batch, 'Unfit', 'Gina Other');

        $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}")->assertNotFound();
    }
}
