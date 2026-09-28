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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Batch Results card and popup on Batch Tracking (FR-ADM-12, D-55, D-86).
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
 *   - BatchRequest::resultsCompletedAt() / resultsStatus() — D-86: a batch is
 *     done at that same cutoff even if some students never came, or earlier,
 *     at its last encode, when everyone was encoded before the cutoff
 *
 * The result is CLINICAL. PRD §6.6 (opened by D-53, moved here by D-55)
 * permits exactly one field of it — Fit / Unfit — to the admin of the
 * student's own college; the privacy cases pin down that nothing else of the
 * record reaches the page.
 *
 * D-55 also trimmed the batch roster: its D-53 Status and Result columns and
 * roll-up are gone, which the last section asserts. D-87 removed per-student
 * withdrawal: the few rows it already produced are hidden from the popup, the
 * roster and the counts, and the endpoint is gone.
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
     *   'withdrawn'   — a seat pulled before D-87 removed withdrawal
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

    /**
     * The Batch Results row for $batch as [status key, completion time]. Read
     * from the model the page rendered, because the popup template carries
     * every badge's text (Alpine shows one), so "Completed" is always in the
     * HTML and cannot be asserted with assertSee().
     *
     * @return array{0: string, 1: ?string}
     */
    private function resultRow(TestResponse $response, BatchRequest $batch): array
    {
        $row = $response->viewData('approvedBatches')->getCollection()->firstWhere('id', $batch->id);

        return [$row->resultsStatus(), $row->resultsCompletedAt()?->format('M j, Y · g:i A')];
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

    public function test_the_card_pages_at_ten_independently_of_the_requests_list(): void
    {
        // 12 approved + 1 pending: the card has two pages, the list below too.
        foreach (range(1, 12) as $i) {
            $this->makeBatch();
        }
        $this->makeBatch('pending');

        $page1 = $this->tracking()->assertOk();
        $this->assertCount(10, $page1->viewData('approvedBatches')->items());
        $this->assertCount(10, $page1->viewData('resultPopups'));
        $this->assertSame(12, $page1->viewData('approvedBatches')->total());

        // FR-UI-06: the card has its own page parameter, so paging it leaves
        // the requests list on its first page (and vice versa).
        $page2 = $this->actingAs($this->admin)->get('/admin/batches?results_page=2')->assertOk();
        $this->assertCount(2, $page2->viewData('approvedBatches')->items());
        $this->assertSame(1, $page2->viewData('batchRequests')->currentPage());
        $this->assertStringContainsString('results_page=2', $page2->viewData('batchRequests')->nextPageUrl());
        $page2->assertSee('Batch Results');
    }

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
            fn (LengthAwarePaginator $batches): bool => $batches->getCollection()->pluck('reference_no')->all()
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
        // D-86: done at the cutoff itself, and the column says when.
        $this->assertSame(['no_one_attended', 'Sep 10, 2026 · 8:00 PM'], $this->resultRow($response, $batch));
        $response->assertSee('Sep 10, 2026 · 8:00 PM');
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

    public function test_a_student_never_encoded_by_the_cutoff_did_not_finish_and_the_batch_completes(): void
    {
        // D-86 (was D-55's "keeps the batch in progress"): Cara reached the
        // kiosk but the nurse never encoded her. The clinic day is over, so she
        // "did not finish" and the batch is done at 8:00 PM regardless.
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->travelTo(Carbon::parse('2026-09-11 08:00:00'));
        $response = $this->tracking();

        $statuses = array_column($this->popup($response, $batch)['students'], 'status');
        $this->assertSame(['did_not_finish', 'absent'], $statuses);
        // Cara came, so this is Completed — not "No one attended".
        $this->assertSame(['completed', 'Sep 10, 2026 · 8:00 PM'], $this->resultRow($response, $batch));
        $response->assertDontSee('In progress');
    }

    public function test_a_batch_with_a_no_show_completes_at_the_cutoff_not_its_last_encode(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Unfit', 'Ben Flagged', self::CLINIC_DAY.' 15:42:00');
        $this->addStudent($batch, 'Fit', 'Ana Cleared', self::CLINIC_DAY.' 11:05:00');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        // A minute before the cutoff Dino may still turn up.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 19:59:00'));
        $response = $this->tracking();
        $this->assertSame(['in_progress', null], $this->resultRow($response, $batch));
        $response->assertSee('In progress');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:00:00'));
        $response = $this->tracking();
        $this->assertSame(['completed', 'Sep 10, 2026 · 8:00 PM'], $this->resultRow($response, $batch));
        $response->assertSee('Sep 10, 2026 · 8:00 PM');
        $response->assertDontSee('3:42 PM');
        $response->assertDontSee('No one attended');
    }

    public function test_a_batch_everyone_finished_early_completes_at_its_last_encode(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Unfit', 'Ben Flagged', self::CLINIC_DAY.' 15:42:00');
        $this->addStudent($batch, 'Fit', 'Ana Cleared', self::CLINIC_DAY.' 11:05:00');

        // Well before the cutoff — nobody left to wait for.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 16:00:00'));
        $response = $this->tracking();

        $this->assertSame(['completed', 'Sep 10, 2026 · 3:42 PM'], $this->resultRow($response, $batch));
        $response->assertSee('Sep 10, 2026 · 3:42 PM');
        $response->assertDontSee('In progress');
    }

    public function test_an_encode_after_the_cutoff_does_not_move_the_completion_time(): void
    {
        // Cara did not finish by 8:00 PM; the nurse encodes her next morning.
        // The batch was already done at the cutoff, and stays done then.
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared', self::CLINIC_DAY.' 10:15:00');
        $this->addStudent($batch, 'Fit', 'Cara Late', '2026-09-11 09:00:00');

        $this->travelTo(Carbon::parse('2026-09-11 10:00:00'));

        $this->assertSame(['completed', 'Sep 10, 2026 · 8:00 PM'], $this->resultRow($this->tracking(), $batch));
    }

    public function test_old_withdrawn_students_do_not_block_finishing(): void
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
        $this->assertSame(['no_one_attended', 'Sep 10, 2026 · 8:00 PM'], $this->resultRow($response, $batch));
    }

    // ── Search (D-86) ────────────────────────────────────────────────────────

    public function test_the_results_search_finds_a_batch_on_any_page(): void
    {
        // 12 approved batches on one date: newest id first, so the first one
        // made sits on page 2 of the card.
        $oldest = $this->makeBatch();
        foreach (range(1, 11) as $i) {
            $this->makeBatch();
        }

        $this->assertArrayNotHasKey($oldest->id, $this->tracking()->viewData('resultPopups'));

        $suffix = substr($oldest->reference_no, -3);
        $response = $this->actingAs($this->admin)->get('/admin/batches?q='.$suffix)->assertOk();

        $this->assertSame(
            [$oldest->reference_no],
            $response->viewData('approvedBatches')->getCollection()->pluck('reference_no')->all(),
        );
        // It filters the Batch Results card only — the requests list is whole.
        $this->assertSame(12, $response->viewData('batchRequests')->total());
        $response->assertSee('value="'.$suffix.'"', false);
    }

    public function test_a_search_with_no_match_keeps_the_card_and_says_so(): void
    {
        $this->makeBatch();

        $response = $this->actingAs($this->admin)->get('/admin/batches?q=nothing-like-this')->assertOk();

        $response->assertSee('Batch Results');
        $response->assertSee('No approved batch matches');
        $response->assertSee('Clear');
        $this->assertSame([], $response->viewData('resultPopups'));
    }

    public function test_the_search_never_reaches_another_colleges_batch(): void
    {
        $this->makeBatch();
        $foreign = $this->makeBatch(college: $this->coe);

        $response = $this->actingAs($this->admin)->get('/admin/batches?q='.$foreign->reference_no)->assertOk();

        // The term is echoed back in the box and the no-match line, so assert
        // on what the card actually lists rather than on the raw HTML.
        $this->assertCount(0, $response->viewData('approvedBatches')->items());
        $this->assertArrayNotHasKey($foreign->id, $response->viewData('resultPopups'));
        $response->assertSee('No approved batch matches');
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

        // D-87: Fina, withdrawn before withdrawal was removed, is not listed.
        $this->assertSame(
            [['completed', 'Fit'], ['completed', 'Unfit'], ['in_clinic', null], ['awaiting', null]],
            array_map(fn (array $row): array => [$row['status'], $row['result']], $popup['students']),
        );

        // Embedded in the page for Alpine to open — through Js::from().
        $response->assertSee('results = JSON.parse', false);
        $response->assertSee('Ana Cleared', false);
    }

    public function test_old_withdrawn_students_are_hidden_from_the_popup_and_the_counts(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $response = $this->tracking()->assertOk();

        $this->assertSame(['Dino Booked'], array_column($this->popup($response, $batch)['students'], 'name'));
        $response->assertDontSee('Fina Pulled');
        $response->assertDontSee('Withdrawn');
        // The requests list's Students column counts only who is still booked.
        $this->assertSame(1, (int) $response->viewData('batchRequests')->getCollection()
            ->firstWhere('id', $batch->id)->batch_request_students_count);
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

        // What stays: the appointment and its hour. D-87 removed Withdraw.
        $response->assertSee($unfit->reference_no);
        $response->assertSee($booked->reference_no);
        $response->assertSee('9:00 AM – 10:00 AM');
        $response->assertDontSee('Withdraw');
    }

    public function test_the_roster_hides_old_withdrawn_students(): void
    {
        $batch = $this->makeBatch();
        $booked = $this->addStudent($batch, 'booked', 'Dino Booked');
        $pulled = $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$batch->id}");

        $response->assertOk();
        $response->assertSee('Dino Booked');
        $response->assertSee($booked->reference_no);
        $response->assertDontSee('Fina Pulled');
        $response->assertDontSee($pulled->reference_no);
        $response->assertDontSee('withdrawn');
        $response->assertSee('for 1 student');
        $this->assertSame(1, $response->viewData('totalCount'));
    }

    public function test_the_per_student_withdraw_endpoint_is_gone(): void
    {
        $batch = $this->makeBatch();
        $appointment = $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->assertFalse(Route::has('admin.batches.appointments.cancel'));

        $response = $this->actingAs($this->admin)
            ->delete("/admin/batches/{$batch->id}/appointments/{$appointment->id}");

        $this->assertContains($response->status(), [404, 405]);
        $this->assertSame('scheduled', $appointment->fresh()->status);
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
