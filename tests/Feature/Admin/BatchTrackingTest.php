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
 * badge — pending shown as "Pending Director Approval" — plus the D-36
 * Rejection Reason column, which appears only when a rejected row exists.
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

    public function test_tracking_shows_a_form_type_column_before_reason(): void
    {
        // D-62
        $this->makeBatch($this->ccs, ['form_type' => 'clearance', 'reason' => 'fieldtrip']);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSeeInOrder(['Form Type', 'Reason'])
            ->assertSeeInOrder(['Medical Clearance', 'Field Trip/Educational Tour']);
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

    // ── D-36: rejection reason column + modal ────────────────────────────────

    public function test_the_rejection_reason_column_is_absent_when_nothing_is_rejected(): void
    {
        $this->makeBatch($this->ccs);
        $this->makeBatch($this->ccs, ['status' => 'approved']);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertDontSee('Rejection Reason');
    }

    public function test_a_rejected_row_shows_its_reason_with_the_reviewing_director(): void
    {
        $director = User::factory()->create(['role' => 'director', 'name' => 'Dr. Reyes']);
        $reviewedAt = now()->subDay();
        // Deliberately plain ASCII: the payload goes through Js::from(), whose
        // json_encode escapes non-ASCII to \uXXXX, so a literal em dash here
        // would never appear verbatim in the HTML.
        $reason = 'Date unavailable, the clinic is closed that week for inventory.';

        $this->makeBatch($this->ccs, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $director->id,
            'reviewed_at' => $reviewedAt,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertSee('Rejection Reason')
            ->assertSee('View')
            ->assertSee($reason, false)          // inside the Js::from() payload
            ->assertSee('Dr. Reyes', false)
            ->assertSee($reviewedAt->format('M j, Y g:i A'), false);
    }

    /**
     * The column header renders once a rejected row exists, but non-rejected
     * rows in the same list still get an em dash rather than a View button.
     */
    public function test_non_rejected_rows_show_an_em_dash_in_the_reason_column(): void
    {
        $this->makeBatch($this->ccs, [
            'status' => 'rejected',
            'rejection_reason' => 'Date unavailable, please resubmit.',
        ]);
        $this->makeBatch($this->ccs, ['status' => 'approved']);

        $content = $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->getContent();

        // One View trigger for the one rejected row; the approved row gets a dash.
        $this->assertSame(1, substr_count($content, 'detail = JSON.parse'));
        $this->assertStringContainsString('&mdash;', $content);
    }

    /** The reason is Director-written free text — it must be escaped on output. */
    public function test_a_reason_containing_markup_is_escaped(): void
    {
        $this->makeBatch($this->ccs, [
            'status' => 'rejected',
            'rejection_reason' => '<script>alert(1)</script> please resubmit',
        ]);

        $content = $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $content);
    }

    /** FR-ADM-06: another college's rejection reason is never readable. */
    public function test_an_admin_cannot_see_another_colleges_rejection_reason(): void
    {
        $secret = 'CEA cohort rejected, their paperwork is incomplete.';

        $this->makeBatch($this->cea, [
            'status' => 'rejected',
            'rejection_reason' => $secret,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/batches')
            ->assertOk()
            ->assertDontSee($secret, false)
            ->assertDontSee('Rejection Reason');
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
