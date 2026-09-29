<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Http\Requests\Director\RejectBatchRequest;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-90 on the Director's Batch Approvals page: a "Conflicts with …" line on
 * each pending row that can't share the clinic with another, the approve
 * popup's warning on the batch that came SECOND (with a suggested rejection
 * reason), and the Batch ID search.
 *
 * D-91 end to end: the seats-left rule means approving two conflicting batches
 * one after the other can never put more than 12 students in an hour.
 */
class BatchConflictWarningTest extends TestCase
{
    use RefreshDatabase;

    private College $cit;

    private College $ccs;

    private User $director;

    private User $admin;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cit = College::create(['code' => 'CIT', 'name' => 'College of Industrial Technology']);
        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->date = now()->addDays(7)->toDateString();
    }

    private function pendingBatch(College $college, int $students, string $start = '07:00:00'): BatchRequest
    {
        static $seq = 300;

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => $this->date,
            'requested_time' => $start,
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($students),
        ]);

        User::factory()->count($students)->create()->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    /** CIT asks for 7 AM first; CCS asks for the same hour a few minutes later. */
    private function citThenCcs(): array
    {
        $cit = $this->pendingBatch($this->cit, 10);
        $this->travel(5)->minutes();
        $ccs = $this->pendingBatch($this->ccs, 8);

        return [$cit, $ccs];
    }

    public function test_both_rows_say_which_batch_they_conflict_with_and_who_came_first(): void
    {
        [$cit, $ccs] = $this->citThenCcs();

        $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSeeInOrder(["Conflicts with {$cit->reference_no}", 'submitted first'])
            ->assertSeeInOrder(["Conflicts with {$ccs->reference_no}", 'submitted after this']);
    }

    public function test_only_the_batch_that_came_second_gets_the_conflict_popup(): void
    {
        [$cit, $ccs] = $this->citThenCcs();

        $popups = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->viewData('conflictPopups');

        $this->assertArrayNotHasKey($cit->id, $popups);
        $this->assertArrayHasKey($ccs->id, $popups);

        $popup = $popups[$ccs->id];
        $this->assertSame($cit->reference_no, $popup['earlier'][0]['ref']);
        $this->assertSame('CIT', $popup['earlier'][0]['college']);
        // CIT keeps 7 AM; CCS's 8 students fit at any later start.
        $this->assertSame('8:00 AM', $popup['freeStarts'][0]);
    }

    public function test_the_suggested_reason_names_free_times_not_the_other_college(): void
    {
        [$cit, $ccs] = $this->citThenCcs();

        $reason = $this->actingAs($this->director)
            ->get('/director/batches')
            ->viewData('conflictPopups')[$ccs->id]['reason'];

        $this->assertStringContainsString('starting at 8:00 AM', $reason);
        $this->assertStringNotContainsString('CIT', $reason);
        $this->assertStringNotContainsString($cit->reference_no, $reason);
        $this->assertGreaterThanOrEqual(RejectBatchRequest::REASON_MIN, mb_strlen($reason));
        $this->assertLessThanOrEqual(RejectBatchRequest::REASON_MAX, mb_strlen($reason));

        // It passes the reject endpoint's own rules as written.
        $this->actingAs($this->director)
            ->post("/director/batches/{$ccs->id}/reject", ['rejection_reason' => $reason])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $ccs->fresh()->status);
        $this->assertSame($reason, $ccs->fresh()->rejection_reason);
    }

    public function test_the_reason_says_so_when_no_start_time_is_left(): void
    {
        // Every hour but 7 AM is already full, and CIT's 10 leave no room there.
        foreach (app(ClinicScheduleService::class)->slots() as $slot) {
            if ($slot !== '07:00:00') {
                Appointment::factory()->count(12)->inSlot($slot)->create([
                    'scheduled_date' => $this->date,
                    'status' => 'scheduled',
                ]);
            }
        }

        [, $ccs] = $this->citThenCcs();

        $popup = $this->actingAs($this->director)->get('/director/batches')->viewData('conflictPopups')[$ccs->id];

        $this->assertSame([], $popup['freeStarts']);
        $this->assertStringContainsString('No other start time is free', $popup['reason']);
    }

    public function test_batches_that_can_share_the_hour_show_no_conflict(): void
    {
        $this->pendingBatch($this->cit, 5);
        $this->pendingBatch($this->ccs, 5);

        $response = $this->actingAs($this->director)->get('/director/batches')->assertOk();

        $response->assertDontSee('Conflicts with');
        $this->assertSame([], $response->viewData('conflictPopups'));
    }

    public function test_approving_both_in_turn_never_puts_more_than_twelve_in_an_hour(): void
    {
        [$cit, $ccs] = $this->citThenCcs();

        $this->actingAs($this->director)->post("/director/batches/{$cit->id}/approve")
            ->assertSessionHas('status');

        // D-91: 7 AM now holds 10, so CCS's 8 don't fit — refused, and the
        // message says how many seats are left.
        $this->actingAs($this->director)->post("/director/batches/{$ccs->id}/approve")
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'has only 2 seats left'));

        $this->assertSame('pending', $ccs->fresh()->status);
        $this->assertSame(10, Appointment::where('scheduled_time', '07:00:00')->count());
    }

    public function test_search_finds_a_batch_by_id_on_any_page(): void
    {
        $oldest = $this->pendingBatch($this->cit, 1);
        foreach (range(1, 11) as $i) {
            $this->pendingBatch($this->ccs, 1);
        }

        $response = $this->actingAs($this->director)
            ->get('/director/batches?q='.$oldest->reference_no)
            ->assertOk();

        $this->assertSame([$oldest->id], $response->viewData('batchRequests')->pluck('id')->all());
        $response->assertSee('value="'.$oldest->reference_no.'"', false);
    }

    public function test_a_search_with_no_match_says_so_and_can_be_cleared(): void
    {
        $this->pendingBatch($this->cit, 1);

        $this->actingAs($this->director)
            ->get('/director/batches?q=BR-1999')
            ->assertOk()
            ->assertSee('No batch request matches “BR-1999”.', false)
            ->assertSee('Clear');
    }
}
