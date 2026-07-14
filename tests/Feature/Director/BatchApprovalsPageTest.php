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
        $pending = $this->makeBatch($this->ccs);
        $approved = $this->makeBatch($this->ccs, ['status' => 'approved']);
        $rejected = $this->makeBatch($this->cea, ['status' => 'rejected']);

        $response = $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            // Pending row: reject is a plain form action.
            ->assertSee("/director/batches/{$pending->id}/reject")
            // Decided rows show static text (FR-DIRA-05)…
            ->assertSee('✓ Approved')
            ->assertSee('✕ Rejected');

        // The approve trigger appears exactly ONCE — only the pending row has
        // one. (Its URL sits inside a Js::from() payload whose slash escaping
        // is an implementation detail, so we count trigger CALLS — the
        // 'openApprove(JSON.parse' shape — not the plain function name, which
        // also occurs in the page's Alpine component definition.)
        $this->assertSame(1, substr_count($response->getContent(), 'openApprove(JSON.parse'));

        // …decided rows expose NO reject endpoint either (FR-DIRA-05).
        foreach ([$approved, $rejected] as $decided) {
            $response->assertDontSee("/director/batches/{$decided->id}/reject");
        }
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
