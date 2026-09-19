<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * New Batch Request — persistence + confirmation screen (FR-ADM-04, BR-05/06/07).
 *
 * Validation-only behaviour (reasons, service type, scope rejection messages)
 * is covered in BatchRequestCreateTest; this file covers what a VALID (or
 * almost-valid) submission writes to the database and what the admin sees
 * afterwards.
 */
class BatchRequestSubmitTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /** Submit a valid batch for $count CCS students; returns the response. */
    private function submitBatch(int $count = 1, array $overrides = [])
    {
        $students = StudentProfile::factory()->count($count)->forCollege($this->ccs)->create();

        return $this->actingAs($this->admin)->post('/admin/batches', array_merge([
            'form_type' => 'assessment',
            'reason' => 'ojt',
            'requested_date' => now()->addDays(7)->toDateString(),
            'requested_time' => '07:00:00', // D-37: start hour of the batch span
            'students' => $students->pluck('id')->all(),
        ], $overrides));
    }

    // ── FR-ADM-04: one batch row + one pivot row per student ────────────────

    public function test_submitting_30_students_creates_one_batch_and_exactly_30_pivot_rows(): void
    {
        $students = StudentProfile::factory()->count(30)->forCollege($this->ccs)->create();

        $requestedDate = now()->addDays(10)->toDateString();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'form_type' => 'clearance',
                'reason' => 'fieldtrip',
                'requested_date' => $requestedDate,
                'requested_time' => '08:00:00',
                'students' => $students->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseCount('batch_request_students', 30);

        $batch = BatchRequest::sole();
        $this->assertSame('pending', $batch->status);
        $this->assertSame($this->ccs->id, $batch->college_id);
        $this->assertSame($this->admin->id, $batch->requested_by);
        $this->assertSame('clearance', $batch->form_type);   // D-62
        $this->assertSame('fieldtrip', $batch->reason);
        $this->assertSame('medical', $batch->service_type);
        // D-29: the admin's proposed date is stored at submission…
        $this->assertSame($requestedDate, $batch->requested_date->toDateString());
        // D-37: …together with the span — start hour as posted, LENGTH derived
        // server-side from the roster (30 students ÷ 12 per hour → 3 hours).
        $this->assertSame('08:00:00', $batch->requested_time);
        $this->assertSame(3, (int) $batch->requested_blocks);
        // …while the FINAL date stays empty until the Director approves.
        $this->assertNull($batch->scheduled_date);

        // The pivot stores USER ids (data dictionary: student_id → users),
        // not the student_profile ids the form posts.
        $this->assertEqualsCanonicalizing(
            $students->pluck('user_id')->all(),
            BatchRequestStudent::pluck('student_id')->all(),
        );

        // appointment_id stays NULL until the Director approves (BR-08).
        $this->assertSame(0, BatchRequestStudent::whereNotNull('appointment_id')->count());
    }

    public function test_reference_numbers_are_minted_sequentially_in_br_format(): void
    {
        $this->submitBatch()->assertSessionHasNoErrors();
        $this->submitBatch()->assertSessionHasNoErrors();

        $year = now()->year;

        $this->assertSame(
            ["BR-{$year}-001", "BR-{$year}-002"],
            BatchRequest::orderBy('id')->pluck('reference_no')->all(),
        );
    }

    // ── D-37: the batch's hour span (FR-ADM-04) ─────────────────────────────

    public function test_a_batch_of_25_spans_three_hours(): void
    {
        // 25 ÷ 12 per hour → 3 contiguous blocks: 7–8, 8–9, 9–10.
        $this->submitBatch(25, ['requested_time' => '07:00:00'])->assertSessionHasNoErrors();

        $batch = BatchRequest::sole();
        $this->assertSame(3, (int) $batch->requested_blocks);
        $this->assertSame(
            ['07:00:00', '08:00:00', '09:00:00'],
            $batch->requestedSpan(),
        );
        $this->assertSame('7:00 AM – 10:00 AM (3 slots)', $batch->requestedSpanLabel());
    }

    public function test_a_batch_of_24_spans_two_hours(): void
    {
        $this->submitBatch(24, ['requested_time' => '07:00:00'])->assertSessionHasNoErrors();

        $this->assertSame(2, (int) BatchRequest::sole()->requested_blocks);
    }

    public function test_a_batch_of_121_students_is_rejected(): void
    {
        // 121 > 12 × 10 — no start hour can fit it in one clinic day.
        $this->submitBatch(121)->assertSessionHasErrors('students');

        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_a_batch_of_exactly_120_students_fills_the_whole_day(): void
    {
        $this->submitBatch(120, ['requested_time' => '07:00:00'])->assertSessionHasNoErrors();

        $this->assertSame(10, (int) BatchRequest::sole()->requested_blocks);
    }

    public function test_a_span_that_would_run_past_closing_time_is_rejected(): void
    {
        // 25 students need 3 hours; starting at 3 PM would end at 6 PM.
        $this->submitBatch(25, ['requested_time' => '15:00:00'])
            ->assertSessionHasErrors('requested_time');

        $this->assertDatabaseCount('batch_requests', 0);
    }

    public function test_a_span_ending_exactly_at_closing_time_is_accepted(): void
    {
        $this->submitBatch(25, ['requested_time' => '14:00:00'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
    }

    public function test_a_full_hour_inside_the_span_is_rejected_naming_that_hour(): void
    {
        $date = now()->addDays(7)->toDateString();

        // The batch would run 7–10 AM; the middle hour is already full.
        Appointment::factory()->count(12)->inSlot('08:00:00')->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $response = $this->submitBatch(25, [
            'requested_date' => $date,
            'requested_time' => '07:00:00',
        ]);

        $response->assertSessionHasErrors('requested_time');

        $message = session('errors')->first('requested_time');
        $this->assertStringContainsString('8:00 AM – 9:00 AM', $message);

        $this->assertDatabaseCount('batch_requests', 0);
    }

    public function test_a_full_hour_outside_the_span_does_not_block_the_batch(): void
    {
        $date = now()->addDays(7)->toDateString();

        // The batch runs 7–9 AM; 2 PM being full is irrelevant.
        Appointment::factory()->count(12)->inSlot('14:00:00')->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->submitBatch(13, [
            'requested_date' => $date,
            'requested_time' => '07:00:00',
        ])->assertSessionHasNoErrors();
    }

    // ── BR-23: an hour that has already passed today ────────────────────────

    public function test_a_start_hour_that_already_passed_today_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->submitBatch(5, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '11:00:00',
        ])->assertSessionHasErrors([
            'requested_time' => 'The 11:00 AM – 12:00 PM slot has already passed. Please pick a later time today.',
        ]);

        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_the_current_hour_is_still_a_valid_batch_start_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->submitBatch(5, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '12:00:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame('12:00:00', BatchRequest::sole()->requested_time);
    }

    public function test_a_passed_hour_is_still_a_valid_batch_start_on_a_later_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->submitBatch(5, [
            'requested_date' => today()->addDay()->toDateString(),
            'requested_time' => '07:00:00',
        ])->assertSessionHasNoErrors();
    }

    public function test_the_create_page_ships_todays_elapsed_hours_from_the_server(): void
    {
        // The browser is never asked what time it is (same rule as BR-20).
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertViewHas('today', '2026-07-27')
            ->assertViewHas('elapsedSlotsToday', [
                '07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00',
            ]);
    }

    public function test_a_start_time_outside_clinic_hours_is_rejected(): void
    {
        $this->submitBatch(1, ['requested_time' => '18:00:00'])
            ->assertSessionHasErrors('requested_time');
    }

    public function test_the_span_length_is_derived_not_taken_from_the_request(): void
    {
        // A crafted POST claiming a 1-hour span for 25 students must not shrink
        // the batch's footprint — the server computes blocks from the roster.
        $this->submitBatch(25, [
            'requested_time' => '07:00:00',
            'requested_blocks' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, (int) BatchRequest::sole()->requested_blocks);
    }

    // ── D-54 / BR-25: students already scheduled at this time ───────────────
    // Every case runs on Nat's reported date, Thu Sep 10, from Sep 1.

    /** @param  list<StudentProfile>  $profiles */
    private function postBatch(array $profiles, array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->from('/admin/batches/create')
            ->post('/admin/batches', array_merge([
                'form_type' => 'assessment',
                'reason' => 'ojt',
                'requested_date' => '2026-09-10',
                'requested_time' => '09:00:00',
                'students' => array_map(fn (StudentProfile $profile): int => $profile->id, $profiles),
            ], $overrides));
    }

    /** A pre-D-61 self-booking, as an old database may still hold one. */
    private function legacySelfBooking(StudentProfile $profile, string $slot): Appointment
    {
        return Appointment::factory()->inSlot($slot)->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => '2026-09-10',
            'source' => 'self',
        ]);
    }

    /** @param  list<StudentProfile>  $profiles */
    private function existingBatch(string $reference, array $profiles, string $status, string $start, int $blocks): BatchRequest
    {
        $batch = BatchRequest::create([
            'reference_no' => $reference,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'assessment',
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => '2026-09-10',
            'scheduled_date' => $status === 'approved' ? '2026-09-10' : null,
            'requested_time' => $start,
            'requested_blocks' => $blocks,
            'status' => $status,
        ]);

        foreach ($profiles as $profile) {
            $batch->batchRequestStudents()->create(['student_id' => $profile->user_id]);
        }

        return $batch;
    }

    public function test_a_student_on_an_overlapping_batch_is_refused_with_one_error_per_clashing_student(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        [$held, $free] = StudentProfile::factory()->count(2)->forCollege($this->ccs)->create()->all();
        $this->existingBatch('BR-2026-003', [$held], 'pending', '09:00:00', 1);

        $this->postBatch([$held, $free])
            ->assertRedirect('/admin/batches/create')
            ->assertSessionHasErrors(["clashes.{$held->id}" => 'on batch BR-2026-003, 9:00 AM – 10:00 AM'])
            ->assertSessionDoesntHaveErrors("clashes.{$free->id}");

        // Only the batch that was there first.
        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseCount('batch_request_students', 1);
    }

    public function test_a_student_on_an_overlapping_pending_batch_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $profile = StudentProfile::factory()->forCollege($this->ccs)->create();
        $this->existingBatch('BR-2026-004', [$profile], 'pending', '09:00:00', 2);

        $this->postBatch([$profile], ['requested_time' => '10:00:00'])
            ->assertSessionHasErrors(["clashes.{$profile->id}" => 'on batch BR-2026-004, 9:00 AM – 11:00 AM']);

        // Only the batch that was there first.
        $this->assertDatabaseCount('batch_requests', 1);
    }

    public function test_a_legacy_self_booking_inside_the_span_no_longer_blocks_a_batch(): void
    {
        // D-61 removed self-booking and with it D-54's self-booking clash.
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $profile = StudentProfile::factory()->forCollege($this->ccs)->create();
        $this->legacySelfBooking($profile, '09:00:00');

        $this->postBatch([$profile])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
    }

    public function test_a_span_that_misses_every_clash_is_created(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $profile = StudentProfile::factory()->forCollege($this->ccs)->create();
        $this->existingBatch('BR-2026-003', [$profile], 'pending', '09:00:00', 1);
        $this->existingBatch('BR-2026-004', [$profile], 'pending', '11:00:00', 2);

        // One student → 10–11 AM: after the one batch, before the other.
        $this->postBatch([$profile], ['requested_time' => '10:00:00'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 3);
    }

    public function test_removing_the_clashing_student_lets_the_batch_submit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        [$held, $free] = StudentProfile::factory()->count(2)->forCollege($this->ccs)->create()->all();
        $this->existingBatch('BR-2026-003', [$held], 'pending', '09:00:00', 1);

        $this->postBatch([$held, $free])->assertSessionHasErrors("clashes.{$held->id}");

        // What the popup's "Remove these students from the batch" button does.
        $this->postBatch([$free])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 2);
        $newBatch = BatchRequest::where('reference_no', '!=', 'BR-2026-003')->sole();
        $this->assertSame([$free->user_id], $newBatch->batchRequestStudents()->pluck('student_id')->all());
    }

    public function test_a_student_withdrawn_from_an_approved_batch_raises_no_clash(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $profile = StudentProfile::factory()->forCollege($this->ccs)->create();
        $batch = $this->existingBatch('BR-2026-004', [$profile], 'approved', '09:00:00', 2);

        // FR-ADM-07: withdrawn = their appointment cancelled, the pivot row kept.
        $appointment = Appointment::factory()->inSlot('09:00:00')->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => '2026-09-10',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'status' => 'cancelled',
        ]);
        $batch->batchRequestStudents()->update(['appointment_id' => $appointment->id]);

        $this->postBatch([$profile])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 2);
    }

    public function test_the_new_batch_page_opens_a_popup_listing_every_clashing_student(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        [$early, $onBatch, $free] = StudentProfile::factory()->count(3)->forCollege($this->ccs)->create()->all();
        $this->existingBatch('BR-2026-003', [$early], 'approved', '09:00:00', 1);
        $this->existingBatch('BR-2026-004', [$onBatch], 'pending', '09:00:00', 2);

        $this->postBatch([$early, $onBatch, $free]);

        // The redirect flashed the errors and old input; the page reads both.
        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('Some students are already scheduled at this time')
            ->assertSee('on batch BR-2026-003, 9:00 AM – 10:00 AM')
            ->assertSee('on batch BR-2026-004, 9:00 AM – 11:00 AM')
            ->assertSee('Remove these students from the batch')
            ->assertViewHas('students', fn ($students) => $students->contains('id', $free->id));
    }

    /**
     * The race the Form Request's unlocked read cannot see: another batch
     * holding the student at 9 AM lands the moment the Form Request's clash
     * query returns. Only the locked re-check inside store() can catch it.
     */
    public function test_a_batch_submitted_mid_submit_is_caught_by_the_locked_recheck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $profile = StudentProfile::factory()->forCollege($this->ccs)->create();
        $competitorInjected = false;

        DB::listen(function ($query) use (&$competitorInjected, $profile): void {
            if ($competitorInjected || ! str_contains($query->sql, 'from "batch_request_students"')) {
                return;
            }

            $competitorInjected = true; // set FIRST — the inserts below re-enter this listener
            $this->existingBatch('BR-2026-003', [$profile], 'pending', '09:00:00', 1);
        });

        $this->postBatch([$profile])->assertSessionHasErrors("clashes.{$profile->id}");

        $this->assertTrue($competitorInjected, 'The competing batch was never injected — the test no longer models the race.');
        // Only the competitor; the submission itself wrote nothing.
        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseCount('batch_request_students', 1);
    }

    // ── BR-05 / FR-ADM-06: server-side scope, never the request ─────────────

    public function test_a_posted_service_type_is_ignored_and_the_batch_is_medical(): void
    {
        // D-60: dental is gone and the field is off the form, so the server
        // always writes 'medical' and never reads the body's service_type.
        $this->submitBatch(overrides: ['service_type' => 'dental'])
            ->assertSessionHasNoErrors();

        $this->assertSame('medical', BatchRequest::sole()->service_type);
    }

    public function test_college_id_comes_from_the_admin_scope_not_the_request(): void
    {
        // A tampered college_id in the POST body must be ignored outright.
        $this->submitBatch(overrides: ['college_id' => $this->cea->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->ccs->id, BatchRequest::sole()->college_id);
    }

    public function test_a_foreign_college_student_rejects_the_whole_submission(): void
    {
        $own = StudentProfile::factory()->forCollege($this->ccs)->create();
        $foreign = StudentProfile::factory()->forCollege($this->cea)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'form_type' => 'assessment',
                'reason' => 'ojt',
                'students' => [$own->id, $foreign->id],
            ])
            ->assertSessionHasErrors('students.1');

        // ALL-OR-NOTHING: the own-college student must not be saved either.
        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_others_without_detail_persists_nothing(): void
    {
        $this->submitBatch(overrides: ['reason' => 'others'])
            ->assertSessionHasErrors('reason_detail');

        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    // ── FR-ADM-04: confirmation screen ──────────────────────────────────────

    public function test_submit_redirects_to_a_confirmation_screen_with_the_batch_details(): void
    {
        $response = $this->submitBatch(3);

        $batch = BatchRequest::sole();
        $response->assertRedirect(route('admin.batches.confirmation', $batch));

        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $batch))
            ->assertOk()
            ->assertSee($batch->reference_no)
            ->assertSee('Pending Director Approval')
            ->assertSee($batch->requested_date->format('l, F j, Y'))
            ->assertSee($batch->created_at->format('l, F j, Y'))
            ->assertSee('View Tracking')
            ->assertSee('Back to Dashboard');
    }

    public function test_another_colleges_confirmation_screen_is_not_found(): void
    {
        $ceaAdmin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->cea->id,
        ]);

        $foreignBatch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-900',
            'college_id' => $this->cea->id,
            'requested_by' => $ceaAdmin->id,
            'form_type' => 'assessment',
            'reason' => 'ojt',
            'service_type' => 'medical',
        ]);

        // Scoped fetch → 404, so batch ids can't be enumerated across colleges.
        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $foreignBatch))
            ->assertNotFound();
    }
}
