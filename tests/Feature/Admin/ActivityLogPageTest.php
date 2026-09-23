<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * College Activity Log (FR-ADM-10, D-49).
 *
 * A college can have more than one College Admin, so each needs to see what the
 * other did — and the Director's decisions had no trace on the admin side at
 * all. The page is DERIVED from `batch_requests`; there is no activity_logs
 * table and nothing writes an audit row, so these tests double as proof that the
 * log cannot disagree with Batch Tracking.
 */
class ActivityLogPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'First Administrator',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->director = User::factory()->create([
            'role' => 'director',
            'name' => 'Clinic Director',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function batch(User $requester, College $college, array $overrides = [], int $students = 3): BatchRequest
    {
        static $seq = 900;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $requester->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(7)->toDateString(),
            'requested_time' => '07:00:00',
            'requested_blocks' => 1,
            'status' => 'pending',
        ], $overrides));

        for ($i = 0; $i < $students; $i++) {
            BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => User::factory()->create(['role' => 'student'])->id,
            ]);
        }

        return $batch;
    }

    // ── Access control ───────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.activity'))->assertRedirect(route('login'));
    }

    public function test_other_roles_are_refused(): void
    {
        $refused = [
            [User::factory()->create(['role' => 'student']), '/student/dashboard'],
            [User::factory()->create(['role' => 'nurse']), '/nurse/dashboard'],
            [$this->director, '/director/dashboard'],
        ];

        foreach ($refused as [$user, $home]) {
            $this->actingAs($user)->get(route('admin.activity'))->assertRedirect($home);
        }
    }

    public function test_an_admin_with_no_college_is_refused(): void
    {
        // college.scope 403s a null managed_college_id before the controller runs,
        // which is what stops managedCollege() ever being null (FR-AUTH-06).
        $homeless = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => null]);

        $this->actingAs($homeless)->get(route('admin.activity'))->assertForbidden();
    }

    // ── The log itself ───────────────────────────────────────────────────────

    public function test_the_page_renders_with_nothing_to_show(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee('Nothing has happened yet');
    }

    public function test_a_submission_appears_with_its_author(): void
    {
        $batch = $this->batch($this->admin, $this->ccs);

        $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee($batch->reference_no)
            ->assertSee('First Administrator')
            ->assertSee('Submitted')
            ->assertSee('3 students');
    }

    public function test_one_admin_sees_what_the_other_admin_did(): void
    {
        // The reason this feature exists.
        $second = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Second Administrator',
            'managed_college_id' => $this->ccs->id,
        ]);

        $batch = $this->batch($second, $this->ccs);

        $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee('Second Administrator')
            ->assertSee($batch->reference_no);
    }

    public function test_an_approval_appears_attributed_to_the_director(): void
    {
        $batch = $this->batch($this->admin, $this->ccs, [
            'status' => 'approved',
            'scheduled_date' => now()->addDays(7)->toDateString(),
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('Clinic Director')
            ->assertSee($batch->reference_no);
    }

    public function test_a_rejection_shows_the_directors_written_reason(): void
    {
        $this->batch($this->admin, $this->ccs, [
            'status' => 'rejected',
            'rejection_reason' => 'Requested hour is already at capacity — please resubmit.',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee('Rejected')
            ->assertSee('Requested hour is already at capacity');
    }

    public function test_a_pending_batch_contributes_only_its_submission(): void
    {
        $this->batch($this->admin, $this->ccs);

        $response = $this->actingAs($this->admin)->get(route('admin.activity'))->assertOk();

        $response->assertSee('Submitted');
        $response->assertDontSee('Approved');
        $response->assertDontSee('Rejected');
    }

    public function test_a_decided_batch_produces_two_entries(): void
    {
        $batch = $this->batch($this->admin, $this->ccs, [
            'status' => 'approved',
            'scheduled_date' => now()->addDays(7)->toDateString(),
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);
        // Submitted yesterday, decided today — a batch is never reviewed in the
        // same instant it is created.
        $batch->forceFill(['created_at' => now()->subDay()])->save();

        $entries = $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->viewData('entries');

        $this->assertCount(2, $entries);
        $this->assertSame(['approved', 'submitted'], collect($entries->items())->pluck('type')->all());
    }

    // ── Scope: the whole point of FR-ADM-06 ──────────────────────────────────

    public function test_another_colleges_activity_is_never_shown(): void
    {
        $foreignAdmin = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Engineering Administrator',
            'managed_college_id' => $this->coe->id,
        ]);

        $foreign = $this->batch($foreignAdmin, $this->coe);
        $own = $this->batch($this->admin, $this->ccs);

        $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee($own->reference_no)
            ->assertDontSee($foreign->reference_no)
            ->assertDontSee('Engineering Administrator');
    }

    public function test_a_college_id_in_the_query_string_is_ignored(): void
    {
        $foreignAdmin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->coe->id,
        ]);
        $foreign = $this->batch($foreignAdmin, $this->coe);

        // There is no ?college= handling on this page at all — not validated,
        // not rejected, simply never read. Nothing to override.
        $this->actingAs($this->admin)
            ->get(route('admin.activity').'?college='.$this->coe->id)
            ->assertOk()
            ->assertDontSee($foreign->reference_no);
    }

    // ── Ordering and paging ──────────────────────────────────────────────────

    public function test_entries_are_newest_first(): void
    {
        $older = $this->batch($this->admin, $this->ccs);
        $older->forceFill(['created_at' => now()->subDays(3)])->save();

        $newer = $this->batch($this->admin, $this->ccs);
        $newer->forceFill(['created_at' => now()->subHour()])->save();

        $entries = $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->viewData('entries');

        $this->assertSame(
            [$newer->reference_no, $older->reference_no],
            collect($entries->items())->map(fn (array $e): string => $e['batch']->reference_no)->all(),
        );
    }

    public function test_the_log_paginates(): void
    {
        // 13 pending batches = 13 entries: ten on page one (FR-UI-06), three on two.
        for ($i = 0; $i < 13; $i++) {
            $this->batch($this->admin, $this->ccs, [], students: 1);
        }

        $entries = $this->actingAs($this->admin)
            ->get(route('admin.activity'))
            ->assertOk()
            ->viewData('entries');

        $this->assertCount(10, $entries->items());
        $this->assertSame(13, $entries->total());

        $page2 = $this->actingAs($this->admin)
            ->get(route('admin.activity').'?page=2')
            ->assertOk()
            ->viewData('entries');

        $this->assertCount(3, $page2->items());
    }
}
