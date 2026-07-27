<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'reason' => 'ojt',
            'service_type' => 'medical',
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
            ->assertExactJson(['booked' => 3, 'capacity' => 3]);
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
