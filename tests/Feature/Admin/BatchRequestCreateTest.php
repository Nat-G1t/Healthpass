<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New Batch Request — create page + validation (FR-ADM-02/03, BR-06/07).
 *
 * Persistence is intentionally NOT covered yet: store() only validates today
 * (Day 50); the write path + reference-number minting land tomorrow, with
 * their own tests.
 */
class BatchRequestCreateTest extends TestCase
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

    /** A valid POST body; override individual keys per test. */
    private function validPayload(array $overrides = []): array
    {
        $student = StudentProfile::factory()->forCollege($this->ccs)->create();

        return array_merge([
            'reason' => 'ojt',
            'service_type' => 'medical',
            'students' => [$student->id],
        ], $overrides);
    }

    // ── Create page (FR-ADM-03: scoped roster) ──────────────────────────────

    public function test_create_page_renders_the_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('New Batch Request')
            ->assertSee('Submit Batch Request')
            ->assertSee('Graduation Clearance')
            ->assertSee('Field Trip / Educational Tour');
    }

    public function test_create_page_lists_only_own_college_students(): void
    {
        $own = StudentProfile::factory()->forCollege($this->ccs)->create();
        $foreign = StudentProfile::factory()->forCollege($this->cea)->create();

        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee($own->student_number)
            ->assertDontSee($foreign->student_number);
    }

    public function test_create_page_is_refused_for_other_roles_and_guests(): void
    {
        $this->get('/admin/batches/create')->assertRedirect('/login');

        foreach (['student', 'nurse', 'director'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/admin/batches/create')->assertRedirect();
        }

        $orphan = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => null,
        ]);
        $this->actingAs($orphan)->get('/admin/batches/create')->assertForbidden();
    }

    // ── BR-06: reason + conditional reason_detail ───────────────────────────

    public function test_reason_and_service_type_and_students_are_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', [])
            ->assertSessionHasErrors(['reason', 'service_type', 'students']);
    }

    public function test_unknown_reason_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['reason' => 'vacation']))
            ->assertSessionHasErrors('reason');
    }

    public function test_others_requires_the_specify_text(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['reason' => 'others']))
            ->assertSessionHasErrors('reason_detail');
    }

    public function test_others_with_specify_text_passes(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload([
                'reason' => 'others',
                'reason_detail' => 'Job fair medical requirement',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_stray_specify_text_is_dropped_for_listed_reasons(): void
    {
        // reason_detail sent with a non-others reason must not error — the
        // Form Request nulls it before validation (mirrors D-28 booking).
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload([
                'reason' => 'graduation',
                'reason_detail' => 'stray text that should be ignored',
            ]))
            ->assertSessionHasNoErrors();
    }

    // ── FR-ADM-02: service type ──────────────────────────────────────────────

    public function test_unknown_service_type_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['service_type' => 'optical']))
            ->assertSessionHasErrors('service_type');
    }

    // ── BR-07 + FR-ADM-06: student selection and scope ──────────────────────

    public function test_empty_student_selection_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => []]))
            ->assertSessionHasErrors('students');
    }

    public function test_another_colleges_student_is_rejected(): void
    {
        $foreign = StudentProfile::factory()->forCollege($this->cea)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => [$foreign->id]]))
            ->assertSessionHasErrors('students.0');
    }

    public function test_a_mixed_selection_is_rejected_even_with_own_students_present(): void
    {
        $own = StudentProfile::factory()->forCollege($this->ccs)->create();
        $foreign = StudentProfile::factory()->forCollege($this->cea)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => [$own->id, $foreign->id]]))
            ->assertSessionHasErrors('students.1');
    }

    public function test_duplicate_student_ids_are_rejected(): void
    {
        $own = StudentProfile::factory()->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => [$own->id, $own->id]]))
            ->assertSessionHasErrors('students.0');
    }

    public function test_non_numeric_student_ids_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => ['abc']]))
            ->assertSessionHasErrors('students.0');
    }

    // ── Valid submission (persistence lands Day 51) ──────────────────────────

    public function test_a_valid_submission_passes_validation(): void
    {
        $students = StudentProfile::factory()->count(3)->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'reason' => 'graduation',
                'service_type' => 'dental',
                'students' => $students->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/batches/create');
    }
}
