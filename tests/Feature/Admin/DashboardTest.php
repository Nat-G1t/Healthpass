<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin Dashboard + college scoping foundation.
 *
 * Coverage:
 *   FR-ADM-01  — scope banner, four stat cards, batch table (incl. empty state)
 *   FR-AUTH-06 — all admin queries filtered by managed_college_id server-side;
 *                the value is never readable from the request
 *   FR-ADM-06  — another college's data never appears, regardless of client state
 *   FR-AUTH-03 — role isolation on /admin/*
 */
class DashboardTest extends TestCase
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

    private function makeBatch(College $college, string $status = 'pending'): BatchRequest
    {
        static $seq = 0;

        return BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', ++$seq),
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'status' => $status,
        ]);
    }

    // ── FR-ADM-01: page content ──────────────────────────────────────────────

    public function test_dashboard_shows_college_scope_banner(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('College of Computing Studies')
            ->assertSee('you can only manage students and batch requests for your assigned college');
    }

    public function test_dashboard_shows_empty_state_when_no_batches(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('No batch requests yet');
    }

    public function test_stat_cards_count_only_own_college(): void
    {
        StudentProfile::factory()->count(3)->forCollege($this->ccs)->create();
        StudentProfile::factory()->count(5)->forCollege($this->cea)->create();

        $this->makeBatch($this->ccs, 'pending');
        $this->makeBatch($this->ccs, 'approved');
        $this->makeBatch($this->ccs, 'approved');
        $this->makeBatch($this->cea, 'pending'); // other college — must not count

        $response = $this->actingAs($this->admin)->get('/admin/dashboard');

        $response->assertOk();
        $stats = $response->viewData('stats');

        $this->assertSame(3, $stats['students']);
        $this->assertSame(3, $stats['batches']);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(2, $stats['approved']);
    }

    // ── FR-ADM-06: cross-college isolation ───────────────────────────────────

    public function test_table_never_lists_another_colleges_batches(): void
    {
        $own = $this->makeBatch($this->ccs);
        $foreign = $this->makeBatch($this->cea);

        $this->actingAs($this->admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee($own->reference_no)
            ->assertDontSee($foreign->reference_no);
    }

    // ── FR-AUTH-06: scope is immune to request tampering ────────────────────

    public function test_college_id_in_the_request_cannot_change_the_scope(): void
    {
        $foreign = $this->makeBatch($this->cea);

        $this->actingAs($this->admin)
            ->get('/admin/dashboard?college_id='.$this->cea->id.'&managed_college_id='.$this->cea->id)
            ->assertOk()
            ->assertSee('College of Computing Studies')
            ->assertDontSee($foreign->reference_no);
    }

    public function test_admin_without_assigned_college_is_refused(): void
    {
        $orphan = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => null,
        ]);

        $this->actingAs($orphan)
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    // ── FR-AUTH-03: role isolation ───────────────────────────────────────────

    public function test_other_roles_cannot_reach_the_admin_dashboard(): void
    {
        foreach (['student', 'nurse', 'director'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get('/admin/dashboard')
                ->assertRedirect();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }
}
