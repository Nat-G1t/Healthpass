<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch Tracking page (FR-ADM-05, FR-ADM-06): the college's requests with
 * Batch ID, truncated reason, student count, submitted date and a status
 * badge — pending shown as "Pending Director Approval".
 */
class BatchTrackingTest extends TestCase
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

    /** Persist a batch directly (bypassing the form) for list-rendering tests. */
    private function makeBatch(College $college, array $overrides = []): BatchRequest
    {
        static $seq = 100;

        return BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
        ], $overrides));
    }

    public function test_tracking_lists_only_the_own_colleges_batches(): void
    {
        $own = $this->makeBatch($this->ccs);
        $foreign = $this->makeBatch($this->cea);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee($own->reference_no)
            ->assertDontSee($foreign->reference_no);
    }

    public function test_pending_status_is_displayed_as_pending_director_approval(): void
    {
        $this->makeBatch($this->ccs);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee('Pending Director Approval');
    }

    public function test_decided_batches_show_their_own_status(): void
    {
        $this->makeBatch($this->ccs, ['status' => 'approved']);
        $this->makeBatch($this->ccs, ['status' => 'rejected']);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('Rejected')
            ->assertDontSee('Pending Director Approval');
    }

    public function test_tracking_shows_student_count_and_submitted_date(): void
    {
        $batch = $this->makeBatch($this->ccs);

        $students = StudentProfile::factory()->count(3)->forCollege($this->ccs)->create();
        $batch->batchRequestStudents()->createMany(
            $students->map(fn (StudentProfile $s): array => ['student_id' => $s->user_id])->all(),
        );

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee($batch->created_at->format('M j, Y'))
            ->assertSee('>3<', false); // the student-count cell
    }

    public function test_a_long_others_reason_is_truncated(): void
    {
        $detail = str_repeat('Community outreach medical mission requirement. ', 4);
        $this->makeBatch($this->ccs, ['reason' => 'others', 'reason_detail' => $detail]);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee(Str::limit($detail, 60))
            ->assertDontSee($detail);
    }

    public function test_tracking_is_refused_for_other_roles_and_guests(): void
    {
        $this->get('/admin/batches')->assertRedirect('/login');

        foreach (['student', 'nurse', 'director'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/admin/batches')->assertRedirect();
        }

        $orphan = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => null,
        ]);
        $this->actingAs($orphan)->get('/admin/batches')->assertForbidden();
    }
}
