<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Director Batch Approvals page (FR-DIRA-01/05/06): every college's requests
 * on one screen, Approve/Reject only on pending rows, and the JSON capacity
 * feed behind the approve modal's warning line.
 */
class BatchApprovalsPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /** Persist a batch directly (bypassing the admin form) for rendering tests. */
    private function makeBatch(College $college, array $overrides = []): BatchRequest
    {
        static $seq = 500;

        return BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'assessment',   // D-62: 'ojt' is an Assessment reason
            'reason' => 'ojt',
            'service_type' => 'medical',
            // D-37: a batch needs an hour span to be approvable, exactly as
            // D-36 made requested_date necessary. Overridden to null by the
            // tests that model a pre-D-37 batch.
            'requested_time' => '07:00:00',
            'requested_blocks' => 1,
        ], $overrides));
    }

    public function test_director_sees_batches_from_all_colleges(): void
    {
        $ccsBatch = $this->makeBatch($this->ccs);
        $ceaBatch = $this->makeBatch($this->cea);

        $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee($ccsBatch->reference_no)
            ->assertSee($ceaBatch->reference_no)
            ->assertSee('CCS')
            ->assertSee('CEA');
    }

    public function test_the_form_type_column_sits_immediately_before_reason(): void
    {
        // D-62: the Director sees which form each batch uses.
        $this->makeBatch($this->ccs);
        $this->makeBatch($this->cea, ['form_type' => 'clearance', 'reason' => 'outbound']);

        $html = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('Medical Assessment Form')
            ->assertSee('Medical Clearance')
            ->assertSee('Outbound Activities')
            ->getContent();

        // Two adjacent header cells: Form Type, then Reason.
        $this->assertMatchesRegularExpression('~>Form Type</th>\s*<th[^>]*>Reason</th>~', $html);
    }

    public function test_both_decision_modals_receive_the_form_type(): void
    {
        $this->makeBatch($this->ccs, ['requested_date' => now()->addDays(3)->toDateString()]);

        $html = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('Form: <strong x-text="batch?.form"></strong>', false)
            ->assertSee('Form: <strong x-text="rejectTarget?.form"></strong>', false)
            ->getContent();

        // Js::from() payloads for Approve and Reject both carry the label.
        // Js::from() writes every quote as a backslash-u0022 escape; undo it
        // (the escape is built from pieces to keep it readable here).
        $decoded = str_replace(chr(92).'u0022', '"', $html);   // chr(92) = backslash
        $this->assertSame(2, substr_count($decoded, '"form":"Medical Assessment Form"'));
    }

    public function test_pending_rows_have_decision_buttons_and_decided_rows_are_static(): void
    {
        $this->makeBatch($this->ccs, ['requested_date' => now()->addDays(5)->toDateString()]);
        $this->makeBatch($this->ccs, ['status' => 'approved']);
        $this->makeBatch($this->cea, ['status' => 'rejected']);

        $response = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            // Decided rows show static text (FR-DIRA-05)…
            ->assertSee('✓ Approved')
            ->assertSee('✕ Rejected');

        // …and expose NO decision trigger: each appears exactly ONCE, for the
        // single pending row. (Both URLs sit inside Js::from() payloads whose
        // slash escaping is an implementation detail, so we count trigger
        // CALLS — the 'openX(JSON.parse' shape — not the plain function names,
        // which also occur in the page's Alpine component definition.)
        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'openApprove(JSON.parse'));
        $this->assertSame(1, substr_count($content, 'openReject(JSON.parse'));
    }

    /**
     * D-36: a pre-D-29 batch has no requested_date, so there is nothing to
     * confirm — Approve is disabled and the Director is told to reject with
     * a resubmit reason. Reject stays available.
     */
    public function test_a_batch_without_a_requested_date_cannot_be_approved_from_the_page(): void
    {
        $this->makeBatch($this->ccs); // no requested_date

        $response = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('predates the requested-date field');

        $content = $response->getContent();
        $this->assertSame(0, substr_count($content, 'openApprove(JSON.parse'));
        $this->assertSame(1, substr_count($content, 'openReject(JSON.parse'));
    }

    /**
     * D-36: same treatment for a batch whose requested date passed while it
     * sat pending — confirm-only can't move it, so Approve is disabled and
     * the notice points at reject-and-resubmit.
     */
    public function test_a_batch_with_a_stale_requested_date_cannot_be_approved_from_the_page(): void
    {
        $this->makeBatch($this->ccs, ['requested_date' => now()->subDay()->toDateString()]);

        $response = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('The requested date has already passed');

        $content = $response->getContent();
        $this->assertSame(0, substr_count($content, 'openApprove(JSON.parse'));
        $this->assertSame(1, substr_count($content, 'openReject(JSON.parse'));
    }

    /**
     * D-37: a batch submitted before the hourly-slot change has no hour span,
     * which is the same "nothing to confirm" situation as a missing date.
     */
    public function test_a_batch_without_a_requested_time_cannot_be_approved_from_the_page(): void
    {
        $this->makeBatch($this->ccs, [
            'requested_date' => now()->addDays(5)->toDateString(),
            'requested_time' => null,
            'requested_blocks' => null,
        ]);

        $response = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('predates the hourly-slot change');

        $content = $response->getContent();
        $this->assertSame(0, substr_count($content, 'openApprove(JSON.parse'));
        $this->assertSame(1, substr_count($content, 'openReject(JSON.parse'));
    }

    /** BR-23: the page mirrors the endpoint's refusal for a lapsed same-day span. */
    public function test_a_batch_whose_hours_have_passed_cannot_be_approved_from_the_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->makeBatch($this->ccs, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '07:00:00',
            'requested_blocks' => 2,
        ]);

        $response = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('has already passed today');

        $content = $response->getContent();
        $this->assertSame(0, substr_count($content, 'openApprove(JSON.parse'));
        $this->assertSame(1, substr_count($content, 'openReject(JSON.parse'));
    }

    public function test_a_same_day_batch_still_ahead_keeps_its_approve_button(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->makeBatch($this->ccs, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '14:00:00',
            'requested_blocks' => 2,
        ]);

        $content = $this->actingAs($this->director)
            ->get('/director/batches')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'openApprove(JSON.parse'));
    }

    public function test_capacity_feed_reports_elapsed_hours_inside_a_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->actingAs($this->director)
            ->getJson('/director/batches/capacity?date='.today()->toDateString().'&time=11:00:00&blocks=3')
            ->assertOk()
            ->assertJsonPath('elapsed_slots', ['11:00 AM – 12:00 PM'])
            ->assertJsonPath('full_slots', []);
    }

    public function test_rows_show_the_batchs_clinic_hour_span(): void
    {
        $this->makeBatch($this->ccs, [
            'requested_date' => now()->addDays(5)->toDateString(),
            'requested_time' => '07:00:00',
            'requested_blocks' => 3,
        ]);

        $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('7:00 AM – 10:00 AM (3 slots)');
    }

    public function test_capacity_feed_reports_full_hours_inside_a_span(): void
    {
        $date = now()->addDays(3)->toDateString();

        Appointment::factory()->count(12)->inSlot('08:00:00')->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->director)
            ->getJson("/director/batches/capacity?date={$date}&time=07:00:00&blocks=3")
            ->assertOk()
            ->assertJsonPath('full_slots', ['8:00 AM – 9:00 AM'])
            ->assertJsonPath('span_label', '7:00 AM – 10:00 AM (3 slots)');
    }

    public function test_rows_show_the_admins_requested_date_or_a_dash(): void
    {
        $requestedDate = now()->addDays(5);
        $this->makeBatch($this->ccs, ['requested_date' => $requestedDate->toDateString()]);
        $this->makeBatch($this->cea); // pre-D-29 batch: no requested date

        $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee($requestedDate->format('M j, Y'))
            ->assertSee('—');
    }

    public function test_capacity_feed_counts_only_non_cancelled_appointments(): void
    {
        config(['healthpass.daily_capacity' => 3]);
        $date = now()->addDays(3)->toDateString();

        Appointment::factory()->count(3)->onDate($date)->create();
        Appointment::factory()->cancelled()->onDate($date)->create();
        Appointment::factory()->onDate(now()->addDays(9)->toDateString())->create(); // other day

        $this->actingAs($this->director)
            ->getJson('/director/batches/capacity?date='.$date)
            ->assertOk()
            ->assertJsonPath('booked', 3)
            ->assertJsonPath('capacity', 3)
            // D-37: no span asked for → nothing to report as full.
            ->assertJsonPath('full_slots', []);
    }

    public function test_capacity_feed_rejects_a_malformed_date(): void
    {
        $this->actingAs($this->director)
            ->getJson('/director/batches/capacity?date=not-a-date')
            ->assertUnprocessable();

        $this->actingAs($this->director)
            ->getJson('/director/batches/capacity')
            ->assertUnprocessable();
    }

    public function test_page_and_feed_are_refused_for_other_roles_and_guests(): void
    {
        $this->get('/director/batches')->assertRedirect('/login');

        foreach (['student', 'nurse', 'college_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/director/batches')->assertRedirect();
            $this->actingAs($user)->get('/director/batches/capacity?date='.now()->toDateString())->assertRedirect();
        }
    }
}
