<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-college isolation for College Admins (FR-ADM-06, FR-AUTH-06, BR-05).
 *
 * Every test plays a hostile CCS admin using direct request manipulation
 * (crafted ids, extra form fields, query-string tampering) to reach another
 * college's data. All of it must fail SERVER-side — the UI never shipping a
 * foreign option is not a defense.
 *
 * Attack surface = every /admin route (dashboard, batch create/store/index/
 * confirmation) plus the shared /profile update any admin can reach.
 */
class CollegeScopeTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $admin;

    private StudentProfile $ownStudent;

    private StudentProfile $foreignStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->ownStudent = StudentProfile::factory()->forCollege($this->ccs)->create();
        $this->foreignStudent = StudentProfile::factory()->forCollege($this->cea)->create();
    }

    /** A valid store payload for the admin's own college (override to attack). */
    private function batchPayload(array $overrides = []): array
    {
        return array_merge([
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(7)->toDateString(),
            'students' => [$this->ownStudent->id],
        ], $overrides);
    }

    private function createBatchFor(College $college): BatchRequest
    {
        return BatchRequest::create([
            'reference_no' => 'BR-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'status' => 'pending',
        ]);
    }

    // ── 1. Listing/searching another college's students ──────────────────────
    // The batch form's "search endpoint" is the create page itself: it ships
    // the full roster once and the browser filters it, so the roster payload
    // is exactly what a scraper would read.

    public function test_batch_form_roster_contains_only_managed_college_students(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.batches.create'))
            ->assertOk();

        $response->assertViewHas('students', function ($students): bool {
            return $students->pluck('id')->doesntContain($this->foreignStudent->id)
                && $students->pluck('id')->contains($this->ownStudent->id);
        });

        // Belt and braces: the foreign student must not leak into the HTML/JSON
        // payload either. Student numbers are unique, names are not.
        $response->assertDontSee($this->foreignStudent->student_number);
    }

    public function test_batch_form_roster_ignores_query_string_tampering(): void
    {
        // Query params the UI never sends — must change nothing server-side.
        $this->actingAs($this->admin)
            ->get(route('admin.batches.create').'?college_id='.$this->cea->id.'&search='.$this->foreignStudent->last_name)
            ->assertOk()
            ->assertDontSee($this->foreignStudent->student_number)
            ->assertViewHas('college', fn ($college): bool => $college->id === $this->ccs->id);
    }

    public function test_dashboard_counts_only_managed_college_students(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['students'] === 1);
    }

    // ── 2. Reading another college's batch request by URL/id ─────────────────

    public function test_foreign_batch_confirmation_is_404(): void
    {
        $foreignBatch = $this->createBatchFor($this->cea);

        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $foreignBatch->id))
            ->assertNotFound();
    }

    public function test_own_batch_confirmation_still_loads(): void
    {
        // Positive control: proves the 404 above is scoping, not a broken route.
        $ownBatch = $this->createBatchFor($this->ccs);

        $this->actingAs($this->admin)
            ->get(route('admin.batches.confirmation', $ownBatch->id))
            ->assertOk()
            ->assertSee($ownBatch->reference_no);
    }

    public function test_batch_tracking_lists_only_own_college_batches(): void
    {
        $ownBatch = $this->createBatchFor($this->ccs);
        $foreignBatch = $this->createBatchFor($this->cea);

        $this->actingAs($this->admin)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertSee($ownBatch->reference_no)
            ->assertDontSee($foreignBatch->reference_no);
    }

    // ── 3. Submitting a batch containing another college's student ids ───────

    public function test_batch_submit_rejects_foreign_student_ids(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.batches.store'), $this->batchPayload([
                'students' => [$this->ownStudent->id, $this->foreignStudent->id],
            ]))
            ->assertSessionHasErrors('students.1');

        // The whole batch must be refused — not saved minus the foreign id.
        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    public function test_batch_submit_ignores_college_id_in_payload(): void
    {
        // college_id is not a form field; smuggling one in must not re-home
        // the batch (BR-05: the college comes from the session scope).
        $this->actingAs($this->admin)
            ->post(route('admin.batches.store'), $this->batchPayload([
                'college_id' => $this->cea->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('batch_requests', 1);
        $this->assertDatabaseHas('batch_requests', ['college_id' => $this->ccs->id]);
        $this->assertDatabaseMissing('batch_requests', ['college_id' => $this->cea->id]);
    }

    // ── 4. Altering managed_college_id through admin-facing requests ─────────
    // managed_college_id IS in User::$fillable (provisioning uses it), so any
    // endpoint that fills the user from request input is a live target.

    public function test_profile_update_cannot_change_managed_college_id(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('profile.update'), [
                'name' => 'Still Me',
                'managed_college_id' => $this->cea->id,
                'role' => 'director',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->admin->refresh();
        $this->assertSame($this->ccs->id, $this->admin->managed_college_id);
        $this->assertSame('college_admin', $this->admin->role);
    }

    public function test_batch_store_payload_cannot_change_managed_college_id(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.batches.store'), $this->batchPayload([
                'managed_college_id' => $this->cea->id,
            ]));

        $this->assertSame(
            $this->ccs->id,
            $this->admin->refresh()->managed_college_id,
        );
    }

    // ── 5. The middleware guarantee the controllers rely on ──────────────────

    public function test_admin_without_assigned_college_gets_403_everywhere(): void
    {
        $orphan = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => null,
        ]);

        $this->actingAs($orphan)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($orphan)->get(route('admin.batches.create'))->assertForbidden();
        $this->actingAs($orphan)->post(route('admin.batches.store'), $this->batchPayload())->assertForbidden();
        $this->actingAs($orphan)->get(route('admin.batches.index'))->assertForbidden();
    }
}
