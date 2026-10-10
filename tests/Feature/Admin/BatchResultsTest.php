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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Batch Results on Batch Tracking and each batch's results page (FR-ADM-12,
 * D-55, D-86, D-111).
 *
 * A College Admin who booked a cohort wants to see, per batch, how far it has
 * got. Every APPROVED batch of their college gets one row on the Batch Results
 * card — Batch ID, Purpose, Scheduled date (D-115), Status — and a View link to the
 * batch's own page: three summary cards and a filterable table of every
 * student's status and result.
 *
 * Two rules carry it, and both live on the models so every surface reads one
 * definition:
 *   - Appointment::clearanceProgress() — one student's status (D-110's keys)
 *   - BatchRequest::resultsStatus() — D-111: the batch's status follows its
 *     clinic days. Scheduled → In progress (day 1) → Still in progress → In
 *     progress (the 2nd-chance day) → Completed / Finished incomplete / No
 *     one attended, and Completed the moment everyone is encoded.
 *
 * The result is CLINICAL. PRD §6.6 (opened by D-53, moved by D-55 and D-111)
 * permits exactly one field of it — Fit / Unfit — to the admin of the
 * student's own college; the privacy cases pin down that nothing else of the
 * record reaches the page.
 *
 * D-55 also trimmed the batch roster: its D-53 Status and Result columns and
 * roll-up are gone, which the last section asserts. D-87 removed per-student
 * withdrawal: the few rows it already produced are hidden everywhere.
 */
class BatchResultsTest extends TestCase
{
    use RefreshDatabase;

    /** The clinic day most cases run on — a Thursday. */
    private const CLINIC_DAY = '2026-09-10';

    /** The day after — where D-110's job books a 2nd chance when nothing else is booked. */
    private const NEXT_DAY = '2026-09-11';

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $director;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        // The 2nd-chance job queues one email per student — counted, never sent.
        Queue::fake();

        // Two days before the clinic day, mid-morning: every batch below is
        // SUBMITTED now (its Date requested is Sep 8, 2026).
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

    /** A batch on $date at 9 AM with no students yet — approved unless told otherwise. */
    private function makeBatch(
        string $status = 'approved',
        string $date = self::CLINIC_DAY,
        ?College $college = null,
        array $overrides = [],
    ): BatchRequest {
        static $seq = 800;

        $isDecided = in_array($status, ['approved', 'rejected', 'cancelled'], true);
        $isScheduled = in_array($status, ['approved', 'cancelled'], true);

        return BatchRequest::create(array_merge([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => ($college ?? $this->ccs)->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',    // D-62
            'reason' => 'fieldtrip',
            'service_type' => 'medical',   // D-60: the only service
            // D-108: the date and hour span the Director scheduled.
            'scheduled_date' => $isScheduled ? $date : null,
            'scheduled_time' => $isScheduled ? '09:00:00' : null,
            'scheduled_blocks' => $isScheduled ? 1 : null,
            'status' => $status,
            'reviewed_by' => $isDecided ? $this->director->id : null,
            'reviewed_at' => $isDecided ? now() : null,
        ], $overrides));
    }

    /**
     * Put one student called $name ("First Last") on $batch and drive them to
     * $stage:
     *
     *   'booked'      — appointment only, nothing at the kiosk
     *   'withdrawn'   — a seat pulled before D-87 removed withdrawal
     *   'resting'     — sent to rest at the kiosk, not back yet (D-72)
     *   'in_clinic'   — kiosk visit captured, the clinic has not encoded it
     *   'Fit'/'Unfit' — encoded with that outcome
     */
    private function addStudent(BatchRequest $batch, string $stage, string $name): Appointment
    {
        [$first, $last] = explode(' ', $name, 2);

        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'college_id' => $batch->college_id,
            'first_name' => $first,
            'last_name' => $last,
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

        if (! in_array($stage, ['booked', 'withdrawn'], true)) {
            $this->visit($appointment, $stage);
        }

        return $appointment;
    }

    /** A kiosk visit on $appointment, as addStudent() describes $stage. */
    private function visit(Appointment $appointment, string $stage): ClinicVisit
    {
        static $seq = 8000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $appointment->student_id,
            'college_id' => $appointment->batchRequest->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => match ($stage) {
                'resting' => 'resting',
                'in_clinic' => 'captured',
                default => 'encoded',
            },
            'checked_in_at' => now(),
        ]);

        if (in_array($stage, ['Fit', 'Unfit'], true)) {
            $this->encode($visit, $stage);
        }

        return $visit;
    }

    /** The clinic encodes $visit — and, like EncodeController, completes its appointment. */
    private function encode(ClinicVisit $visit, string $result): void
    {
        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => $result,
            'nurse_notes' => 'Borderline blood pressure, advise follow-up.',
            'encoded_at' => now(),
        ]);

        $visit->update(['status' => 'encoded']);
        $visit->appointment->update(['status' => 'completed']);
    }

    /**
     * 8:00 PM on the clinic day: D-110's real job books every missed student a
     * 2nd chance (the earliest free hour of NEXT_DAY — 8:00 AM here).
     */
    private function runSecondChanceJobAtTheCutoff(): void
    {
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:00:00'));
        $this->artisan('healthpass:second-chances')->assertExitCode(0);
    }

    /** The appointment $student's roster row on $batch points at now (the 2nd chance, once booked). */
    private function currentAppointment(BatchRequest $batch, Appointment $first): Appointment
    {
        return BatchRequestStudent::where('batch_request_id', $batch->id)
            ->where('student_id', $first->student_id)
            ->firstOrFail()
            ->appointment;
    }

    private function tracking(): TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/batches');
    }

    private function results(BatchRequest $batch): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.batches.results', $batch->id));
    }

    /**
     * The batch's status key, read BOTH from the Batch Results card and from
     * the results page — each through its own eager-loads — and required to
     * agree.
     */
    private function statusOf(BatchRequest $batch): string
    {
        $card = $this->tracking()->viewData('approvedBatches')->getCollection()
            ->firstWhere('id', $batch->id)->resultsStatus();
        $page = $this->results($batch)->assertOk()->viewData('results')['status'];

        $this->assertSame($card, $page, 'The Batch Results card and the results page disagree.');

        return $page;
    }

    /** @return array<string, string> student name => status key, from the results page */
    private function studentStatuses(BatchRequest $batch): array
    {
        return array_column($this->results($batch)->viewData('results')['rows'], 'status', 'name');
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // ── The Batch Results card ───────────────────────────────────────────────

    public function test_the_card_shows_batch_id_purpose_scheduled_date_status_and_a_view_link(): void
    {
        $batch = $this->makeBatch(overrides: [
            'reason' => 'others',
            'reason_detail' => 'Inter-university robotics competition in Baguio City, two nights and three days',
        ]);
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $response = $this->tracking()->assertOk();

        $response->assertSeeInOrder(['>Batch ID<', '>Purpose<', '>Scheduled date<', '>Status<'], false);
        $response->assertDontSee('Date requested');
        $response->assertDontSee('Time of Completion');
        $response->assertSee($batch->reference_no);
        // Cut to 60 characters, like the requests list's Purpose.
        $response->assertSee('Inter-university robotics competition in Baguio City, two ni...');
        $response->assertDontSee('three days');
        // D-115 (reverses D-111 Q10): the Director's clinic date, not the
        // day the college submitted it.
        $response->assertSee('Sep 10, 2026');
        $response->assertSee('Scheduled');
        $response->assertSee('href="'.route('admin.batches.results', $batch->id).'"', false);
        // D-111: the popup is gone.
        $response->assertDontSee('results = JSON.parse', false);
        $response->assertViewMissing('resultPopups');
    }

    public function test_the_card_pages_at_ten_independently_of_the_requests_list(): void
    {
        // 12 approved + 1 pending: the card has two pages, the list below too.
        foreach (range(1, 12) as $i) {
            $this->makeBatch();
        }
        $this->makeBatch('pending');

        $page1 = $this->tracking()->assertOk();
        $this->assertCount(10, $page1->viewData('approvedBatches')->items());
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
        $response->assertViewHas(
            'approvedBatches',
            fn (LengthAwarePaginator $batches): bool => $batches->getCollection()->pluck('reference_no')->all()
                === [$later->reference_no, $earlier->reference_no],
        );

        // The other three are still on the requests list below — just with no results page.
        foreach ([$pending, $rejected, $cancelled] as $notApproved) {
            $response->assertDontSee(route('admin.batches.results', $notApproved->id));
        }
    }

    public function test_the_card_is_not_rendered_without_an_approved_batch(): void
    {
        $this->makeBatch('pending');
        $this->makeBatch('rejected');

        $response = $this->tracking();

        $response->assertOk();
        $response->assertDontSee('Batch Results');
        $response->assertDontSee('data-notify-dot', false);
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
        $response->assertDontSee(route('admin.batches.results', $foreign->id));
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

    // ── Search (D-86) ────────────────────────────────────────────────────────

    public function test_the_results_search_finds_a_batch_on_any_page(): void
    {
        // 12 approved batches on one date: newest id first, so the first one
        // made sits on page 2 of the card.
        $oldest = $this->makeBatch();
        foreach (range(1, 11) as $i) {
            $this->makeBatch();
        }

        $this->tracking()->assertDontSee(route('admin.batches.results', $oldest->id));

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
        $this->assertCount(0, $response->viewData('approvedBatches')->items());
    }

    public function test_the_search_never_reaches_another_colleges_batch(): void
    {
        $this->makeBatch();
        $foreign = $this->makeBatch(college: $this->coe);

        $response = $this->actingAs($this->admin)->get('/admin/batches?q='.$foreign->reference_no)->assertOk();

        // The term is echoed back in the box and the no-match line, so assert
        // on what the card actually lists rather than on the raw HTML.
        $this->assertCount(0, $response->viewData('approvedBatches')->items());
        $response->assertSee('No approved batch matches');
    }

    // ── Batch statuses (D-111) ───────────────────────────────────────────────

    public function test_a_batch_is_scheduled_until_its_first_hour_starts(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->assertSame('scheduled', $this->statusOf($batch));   // two days before

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 08:59:59'));
        $this->assertSame('scheduled', $this->statusOf($batch));

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 09:00:00'));
        $this->assertSame('in_progress', $this->statusOf($batch));
    }

    public function test_a_batch_is_in_progress_from_its_first_hour_until_the_cutoff(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 09:00:00'));
        $this->assertSame('in_progress', $this->statusOf($batch));
        $this->tracking()->assertSee('In progress');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 19:59:59'));
        $this->assertSame('in_progress', $this->statusOf($batch));
    }

    public function test_a_batch_is_completed_the_moment_everyone_is_encoded(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'Unfit', 'Ben Flagged');

        // Q11: right away — 3 PM on day 1, five hours before the cutoff.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 15:00:00'));

        $this->assertSame('completed', $this->statusOf($batch));
        $this->tracking()->assertSee('Completed');
    }

    public function test_the_evening_after_day_one_with_a_second_chance_booked_is_still_in_progress(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->runSecondChanceJobAtTheCutoff();
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 21:00:00'));

        $this->assertSame(['Dino Booked' => 'rescheduled', 'Ana Cleared' => 'completed'], $this->studentStatuses($batch));
        $this->assertSame('still_in_progress', $this->statusOf($batch));
        $this->tracking()->assertSee('Still in progress');

        // Still waiting the next morning, before the 2nd-chance day's first hour.
        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 06:59:59'));
        $this->assertSame('still_in_progress', $this->statusOf($batch));
    }

    public function test_the_second_chance_day_is_in_progress_from_its_first_hour(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->runSecondChanceJobAtTheCutoff();

        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 08:00:00'));
        $this->assertSame('in_progress', $this->statusOf($batch));

        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 19:59:59'));
        $this->assertSame('in_progress', $this->statusOf($batch));
    }

    public function test_a_batch_with_a_student_who_missed_the_second_chance_too_finishes_incomplete(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->runSecondChanceJobAtTheCutoff();
        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 20:00:00'));

        $this->assertSame(['Dino Booked' => 'unattended', 'Ana Cleared' => 'completed'], $this->studentStatuses($batch));
        $this->assertSame('finished_incomplete', $this->statusOf($batch));
        $this->tracking()->assertSee('Done / Incomplete');   // D-121's label for the key
    }

    public function test_a_second_chance_that_everyone_attends_completes_the_batch(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $dino = $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->runSecondChanceJobAtTheCutoff();
        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 10:00:00'));
        $this->visit($this->currentAppointment($batch, $dino), 'Fit');

        $this->assertSame('completed', $this->statusOf($batch));
    }

    public function test_a_batch_where_nobody_came_on_either_day_reads_no_one_attended(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $this->addStudent($batch, 'booked', 'Elmo Absent');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->runSecondChanceJobAtTheCutoff();
        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 20:00:00'));

        $this->assertSame('no_one_attended', $this->statusOf($batch));
        $this->tracking()->assertSee('No one attended');
    }

    public function test_a_captured_visit_still_waiting_at_the_cutoff_is_removed_and_rescheduled(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $cara = $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $visit = $cara->clinicVisit;

        // Before 8 PM she is At the clinic and the day is in progress.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 19:00:00'));
        $this->assertSame('in_clinic', $this->studentStatuses($batch)['Cara Waiting']);

        // D-132 (amends D-110 Q3): still waiting at the cutoff, so the job
        // removes her visit and books her 2nd chance like an absent student.
        $this->runSecondChanceJobAtTheCutoff();
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 21:00:00'));

        $this->assertModelMissing($visit);
        $this->assertSame('rescheduled', $this->studentStatuses($batch)['Cara Waiting']);
        $this->assertSame('still_in_progress', $this->statusOf($batch));

        // Her 2nd-chance day: the batch is in progress again.
        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 10:00:00'));
        $this->assertSame('in_progress', $this->statusOf($batch));
    }

    public function test_a_resting_student_reads_rest_retake_and_keeps_the_day_in_progress(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 10:00:00'));
        $this->addStudent($batch, 'resting', 'Rita Resting');

        $this->assertSame('rest_retake', $this->studentStatuses($batch)['Rita Resting']);
        $this->assertSame('in_progress', $this->statusOf($batch));
    }

    public function test_old_withdrawn_students_do_not_hold_a_batch_open(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 10:00:00'));

        $this->assertSame('completed', $this->statusOf($batch));
    }

    public function test_overriding_the_absent_cutoff_moves_the_end_of_the_clinic_day(): void
    {
        config(['healthpass.absent_cutoff' => '18:00']);

        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 17:59:00'));
        $this->assertSame(['Dino Booked' => 'scheduled'], $this->studentStatuses($batch));
        $this->assertSame('in_progress', $this->statusOf($batch));

        // Before the 2nd-chance job's next run, the missed student is Unattended.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 18:00:00'));
        $this->assertSame(['Dino Booked' => 'unattended'], $this->studentStatuses($batch));
        $this->assertSame('no_one_attended', $this->statusOf($batch));
    }

    // ── Summary cards (D-111) ────────────────────────────────────────────────

    public function test_the_summary_cards_count_a_mixed_batch(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'Fit', 'Abe Cleared');
        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');
        $this->addStudent($batch, 'booked', 'Elmo Booked');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->runSecondChanceJobAtTheCutoff();
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 21:00:00'));

        $response = $this->results($batch)->assertOk();

        // Fina (withdrawn before D-87) is in none of the counts. Cara, still
        // waiting at the cutoff, is rescheduled with Dino and Elmo (D-132).
        $this->assertSame(
            ['total' => 6, 'completed' => 3, 'fit' => 2, 'rescheduled' => 3],
            $response->viewData('results')['summary'],
        );
        $response->assertSeeInOrder(['Completed', '3', 'out of 6 students completed'], false);
        $response->assertSeeInOrder(['Fit', '2', 'out of 3 completed students are fit'], false);
        $response->assertSeeInOrder(['Rescheduled', '3', 'out of 6 students were rescheduled'], false);
    }

    public function test_a_rescheduled_student_is_still_counted_after_completing(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $dino = $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->runSecondChanceJobAtTheCutoff();
        $this->assertSame(1, $this->results($batch)->viewData('results')['summary']['rescheduled']);

        // Dino comes on his 2nd-chance day and is encoded Unfit.
        $this->travelTo(Carbon::parse(self::NEXT_DAY.' 09:00:00'));
        $this->visit($this->currentAppointment($batch, $dino), 'Unfit');

        $this->assertSame(
            ['total' => 2, 'completed' => 2, 'fit' => 1, 'rescheduled' => 1],
            $this->results($batch)->viewData('results')['summary'],
        );
    }

    // ── The results page (D-111) ─────────────────────────────────────────────

    public function test_the_results_page_opens_for_the_batch_colleges_admin(): void
    {
        $batch = $this->makeBatch(overrides: ['scheduled_blocks' => 2]);
        $this->addStudent($batch, 'Fit', 'Ana Cleared');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 10:00:00'));
        $response = $this->results($batch)->assertOk();

        $response->assertSee('Batch Tracking');
        $response->assertSee('href="'.route('admin.batches.index').'"', false);
        $response->assertSee('Batch '.$batch->reference_no);
        $response->assertSee('Medical Clearance');
        $response->assertSee('Field Trip/Educational Tour');
        $response->assertSee('Sep 10, 2026, 9:00 AM – 11:00 AM (2 slots)');
        $response->assertDontSee('2nd chance:');
        $this->assertSame('completed', $response->viewData('results')['status']);
    }

    public function test_the_header_names_the_second_chance_day(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->runSecondChanceJobAtTheCutoff();

        $this->results($batch)->assertOk()->assertSee('2nd chance: Sep 11, 2026, 8:00 AM');
    }

    public function test_another_colleges_batch_is_not_found(): void
    {
        $foreign = $this->makeBatch(college: $this->coe);
        $this->addStudent($foreign, 'Unfit', 'Gina Other');

        $this->results($foreign)->assertNotFound();
    }

    public function test_a_batch_that_is_not_approved_has_no_results_page(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->results($this->makeBatch($status))->assertNotFound();
        }

        $this->actingAs($this->admin)->get('/admin/batches/999999/results')->assertNotFound();
    }

    public function test_only_a_college_admin_with_a_college_can_open_the_page(): void
    {
        $batch = $this->makeBatch();
        $url = route('admin.batches.results', $batch->id);

        $this->get($url)->assertRedirect(route('login'));

        // Every other role is sent back to its own dashboard (EnsureRole).
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get($url)->assertRedirect('/student/dashboard');
        $this->actingAs($this->nurse)->get($url)->assertRedirect('/nurse/dashboard');
        $this->actingAs($this->director)->get($url)->assertRedirect('/director/dashboard');

        // An admin with no college is refused outright (college.scope).
        $orphan = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => null]);
        $this->actingAs($orphan)->get($url)->assertForbidden();
    }

    public function test_the_page_carries_the_outcome_and_nothing_else_of_the_record(): void
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

        $response = $this->results($batch)->assertOk();
        $row = $response->viewData('results')['rows'][0];

        // §6.6 (D-53, moved here by D-55 and D-111): the OUTCOME, and only the outcome.
        // D-113 adds the encoded visit's database id — the handle the document
        // download posts — which is an id, not its HP- reference or any record.
        $this->assertSame('Unfit', $row['result']);
        $this->assertSame(['name', 'number', 'status', 'result', 'visit_id'], array_keys($row));
        $this->assertSame($appointment->clinicVisit->id, $row['visit_id']);

        $response->assertDontSee('Borderline blood pressure');
        $response->assertDontSee('93.4');
        $response->assertDontSee('38.7');
        $response->assertDontSee('HP-2026-');
    }

    public function test_the_table_lists_every_student_by_last_name_without_withdrawn_ones(): void
    {
        $batch = $this->makeBatch();
        $ana = $this->addStudent($batch, 'Fit', 'Ana Zamora');
        $this->addStudent($batch, 'booked', 'Ben Abad');
        $this->addStudent($batch, 'in_clinic', 'Al Abad');
        $this->addStudent($batch, 'withdrawn', 'Fina Pulled');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 12:00:00'));
        $response = $this->results($batch)->assertOk();

        $this->assertSame([
            ['name' => 'Al Abad', 'status' => 'in_clinic', 'result' => null],
            ['name' => 'Ben Abad', 'status' => 'scheduled', 'result' => null],
            ['name' => 'Ana Zamora', 'status' => 'completed', 'result' => 'Fit'],
        ], array_map(
            fn (array $row): array => ['name' => $row['name'], 'status' => $row['status'], 'result' => $row['result']],
            $response->viewData('results')['rows'],
        ));
        $this->assertSame($ana->student->studentProfile->student_number, $response->viewData('results')['rows'][2]['number']);

        // D-87: withdrawn before withdrawal was removed — not listed, not shown.
        $response->assertDontSee('Fina Pulled');
        $response->assertDontSee('Withdrawn');
        // Embedded for Alpine to filter — through Js::from().
        $response->assertSee('batchResults(JSON.parse', false);
    }

    public function test_the_table_has_the_filters_and_scrolls_instead_of_paging(): void
    {
        $batch = $this->makeBatch();
        foreach (range(1, 12) as $i) {
            $this->addStudent($batch, 'booked', "Student Number{$i}");
        }

        $response = $this->results($batch)->assertOk();

        $this->assertCount(12, $response->viewData('results')['rows']);
        $response->assertSeeInOrder(['>Name<', '>Student ID<', '>Completion status<', '>Result<'], false);
        $response->assertDontSee('>Hour<', false);

        // Result filter, completion-status filter (D-110's student statuses) and search.
        $response->assertSeeInOrder(['All results', '>Fit<', '>Unfit<'], false);
        $response->assertSeeInOrder([
            '<option value="">All</option>',
            '<option value="scheduled">Scheduled</option>',
            '<option value="in_clinic">At the clinic</option>',
            '<option value="rest_retake">Rest / Retake</option>',
            '<option value="rescheduled">Rescheduled</option>',
            '<option value="completed">Completed</option>',
            '<option value="unattended">Unattended</option>',
        ], false);
        $response->assertSee('placeholder="Name or student ID"', false);
        $response->assertSee('No students match these filters.');

        // Q13: ten rows tall and scrolls with a pinned header — no pager.
        $response->assertSee('overflow-y-auto', false);
        $response->assertSee('sticky top-0', false);
        $response->assertDontSee('Showing 1');
        $response->assertDontSee('Page 1 of');
    }

    public function test_a_name_with_an_apostrophe_cannot_break_the_payload(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit', "Jo O'Brien");

        $response = $this->results($batch)->assertOk();

        // The rows sit inside a single-quoted JSON.parse('…') string: a raw
        // apostrophe there would end the string and break the Alpine
        // expression. Js::from() escapes it, and the name still round-trips.
        $this->assertStringNotContainsString("O'Brien", $response->getContent());
        $this->assertSame("Jo O'Brien", $response->viewData('results')['rows'][0]['name']);
    }

    public function test_the_page_does_not_query_per_student(): void
    {
        $batch = $this->makeBatch();
        // Not Fit: a Completed batch is "settled" for the D-120 badge, which
        // then skips working its status out — fewer queries for a reason that
        // has nothing to do with the student count. Waiting keeps the batch
        // Still in progress before AND after the students below are added.
        $this->addStudent($batch, 'in_clinic', 'Ana Waiting');

        $this->results($batch);
        $withOne = $this->countQueries(fn () => $this->results($batch));

        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->assertSame($withOne, $this->countQueries(fn () => $this->results($batch)));
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
        $response->assertDontSee('Unattended');

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
        $response->assertSee('Hours');   // "Hour span" before D-108
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
