<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\StoreBatchRequestRequest;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Programs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * New Batch Request — create page + validation (FR-ADM-02/03, BR-06/07).
 *
 * Persistence (the write path + reference-number minting, FR-ADM-04) is
 * covered separately in BatchRequestSubmitTest.
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

        // D-109: a valid batch carries a signed memo; keep it off the real disk.
        Storage::fake('local');

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
        // D-105: a batch needs at least two students.
        $students = StudentProfile::factory()->count(2)->forCollege($this->ccs)->create();

        return array_merge([
            'form_type' => 'assessment',
            'reason' => 'ojt',
            // D-108: no clinic date and no start hour — the Director picks both.
            'students' => $students->pluck('id')->all(),
            'memo' => UploadedFile::fake()->image('memo.jpg'),   // D-109
        ], $overrides);
    }

    // ── Create page (FR-ADM-03: scoped roster) ──────────────────────────────

    public function test_create_page_renders_the_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('New Batch Request')
            ->assertSee('Submit Batch Request');
    }

    public function test_create_page_calls_the_reason_purpose_and_ships_the_minimum(): void
    {
        // D-105: the batch's reason is shown as "Purpose"; the browser gets the
        // minimum from the Form Request instead of typing its own 2.
        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertViewHas('minStudents', StoreBatchRequestRequest::MIN_STUDENTS)
            ->assertSee('>Purpose</label>', false)
            ->assertDontSee('>Reason</label>', false)
            ->assertSee('Select at least 2 students.');
    }

    // ── D-62: the form chooser ───────────────────────────────────────────────

    public function test_create_page_renders_both_form_tiles_and_a_disabled_reason_select(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('Choose the form the clinic will use')
            // Assessment tile first (left), clearance second (right) — the mock-up.
            ->assertSeeInOrder(['data-form-tile="assessment"', 'data-form-tile="clearance"'], false)
            // Each tile embeds the real form's live preview, not a photo.
            ->assertSee(route('admin.batches.form-preview', 'assessment'), false)
            ->assertSee(route('admin.batches.form-preview', 'clearance'), false)
            ->assertSee('Medical Assessment Form')
            ->assertSee('Medical Clearance');

        // The reason select is rendered disabled until a form is chosen.
        // ` disabled="disabled"` — the real attribute, not the Tailwind
        // `disabled:` classes or Alpine's `x-bind:disabled`.
        $this->assertMatchesRegularExpression('/<select[^>]*name="reason"[^>]*\sdisabled="disabled"/s', $response->getContent());
    }

    public function test_the_reason_select_is_enabled_when_old_input_restores_a_form(): void
    {
        // After a failed submit old() brings the form back, so the select must
        // not start disabled (Alpine would enable it anyway, but no flash).
        $response = $this->actingAs($this->admin)
            ->withSession(['_old_input' => ['form_type' => 'clearance', 'reason' => 'outbound']])
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee("formType:      'clearance'", false);

        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="reason"[^>]*\sdisabled="disabled"/s', $response->getContent());
    }

    public function test_the_form_type_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => null]))
            ->assertSessionHasErrors(['form_type', 'reason']);
    }

    public function test_an_unknown_form_type_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => 'dental']))
            ->assertSessionHasErrors('form_type');
    }

    public function test_an_array_form_type_is_a_validation_error_not_a_crash(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => ['clearance']]))
            ->assertSessionHasErrors(['form_type', 'reason']);
    }

    public function test_a_reason_from_the_other_form_is_rejected(): void
    {
        // 'fieldtrip' is a Medical Clearance purpose, not an Assessment one.
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => 'assessment', 'reason' => 'fieldtrip']))
            ->assertSessionHasErrors(['reason' => 'That purpose is not on the chosen form.']);

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => 'clearance', 'reason' => 'ojt']))
            ->assertSessionHasErrors('reason');
    }

    public function test_a_retired_pre_d62_reason_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => 'clearance', 'reason' => 'graduation']))
            ->assertSessionHasErrors('reason');
    }

    public function test_every_reason_of_each_form_is_accepted(): void
    {
        foreach (BatchRequest::REASONS_BY_FORM as $formType => $reasons) {
            foreach (array_keys($reasons) as $reason) {
                $this->actingAs($this->admin)
                    ->post('/admin/batches', $this->validPayload([
                        'form_type' => $formType,
                        'reason' => $reason,
                        'reason_detail' => $reason === 'others' ? 'Regional quiz bee' : null,
                    ]))
                    ->assertSessionHasNoErrors();
            }
        }

        $this->assertSame(8, BatchRequest::count());
    }

    public function test_the_specify_text_is_capped_at_120_characters(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['reason' => 'others', 'reason_detail' => str_repeat('x', 121)]))
            ->assertSessionHasErrors(['reason_detail' => 'Please keep the specified purpose to 120 characters — it prints on one line of the form.']);

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['reason' => 'others', 'reason_detail' => str_repeat('x', 120)]))
            ->assertSessionHasNoErrors();
    }

    public function test_the_chosen_form_type_is_stored(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['form_type' => 'clearance', 'reason' => 'outbound']))
            ->assertSessionHasNoErrors();

        $batch = BatchRequest::sole();
        $this->assertSame('clearance', $batch->form_type);
        $this->assertSame('outbound', $batch->reason);
        $this->assertSame('Outbound Activities', $batch->reasonText());
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

    public function test_create_page_hides_deactivated_students(): void
    {
        $active = StudentProfile::factory()->forCollege($this->ccs)->create();
        $inactive = StudentProfile::factory()->forCollege($this->ccs)->create();
        $inactive->user->update(['status' => 'inactive']);

        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee($active->student_number)
            ->assertDontSee($inactive->student_number);
    }

    // ── FR-ADM-03: the Program filter lists the college's whole catalog ─────

    public function test_create_page_offers_every_catalog_program_of_own_college_only(): void
    {
        // The filter bar sits above the roster, so the college needs a student.
        StudentProfile::factory()->forCollege($this->ccs)->create();

        $response = $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('<option value="">All programs</option>', false);

        // Every CCS program, in catalog order, even with no student registered.
        $ccsPrograms = Programs::forCollege($this->ccs->id);
        $this->assertNotEmpty($ccsPrograms);
        $response->assertSeeInOrder(
            array_map(fn (string $p): string => '<option value="'.e($p).'">', $ccsPrograms),
            false,
        );

        foreach (Programs::forCollege($this->cea->id) as $program) {
            $response->assertDontSee('<option value="'.e($program).'">', false);
        }
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

    // ── D-108: the Director schedules the batch, not the college ────────────

    public function test_create_page_has_no_date_or_hour_inputs(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertDontSee('Requested clinic date')
            ->assertDontSee('Start time')
            ->assertDontSee('name="requested_date"', false)
            ->assertDontSee('name="requested_time"', false)
            ->assertSee('The Clinic Director picks the clinic date and hours');

        $response->assertViewMissing('fullDays');
        $response->assertViewMissing('slots');
    }

    public function test_the_mini_calendar_feed_is_gone(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/admin/batches/availability?year=2026&month=9')
            ->assertNotFound();
    }

    // ── BR-06: reason + conditional reason_detail ───────────────────────────

    public function test_form_purpose_memo_and_students_are_required_and_nothing_else(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', [])
            ->assertSessionHasErrors([
                'form_type',
                'memo' => 'Please attach the signed memo.',   // D-109
                // D-105: the batch's reason is called its purpose on screen.
                'reason' => 'Please choose a purpose for this batch request.',
                'students' => 'Select at least 2 students for this batch.',
            ])
            // D-108: the college no longer picks a date or an hour.
            ->assertSessionDoesntHaveErrors(['requested_date', 'requested_time']);
    }

    /** D-108: a posted date or hour is never read — the batch is stored without one. */
    public function test_a_posted_date_and_hour_are_ignored(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload([
                'requested_date' => now()->subDay()->toDateString(),   // would have failed D-29
                'requested_time' => '25:00:00',
                'scheduled_date' => now()->addDays(3)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        $batch = BatchRequest::sole();
        $this->assertNull($batch->requested_date);
        $this->assertNull($batch->requested_time);
        $this->assertNull($batch->requested_blocks);
        $this->assertNull($batch->scheduled_date);
        $this->assertNull($batch->scheduled_time);
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
            ->assertSessionHasErrors(['reason_detail' => 'Please specify the purpose when choosing "Others".']);
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
                'form_type' => 'clearance',
                'reason' => 'fieldtrip',
                'reason_detail' => 'stray text that should be ignored',
            ]))
            ->assertSessionHasNoErrors();
    }

    // ── FR-ADM-02 as amended by D-60: no service type ────────────────────────

    public function test_the_page_no_longer_offers_a_service_type(): void
    {
        // D-60: dental is gone, so the field is gone with it — the server
        // always writes 'medical' (asserted in BatchRequestSubmitTest).
        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertDontSee('Service Type')
            ->assertDontSee('Dental')
            ->assertDontSee('name="service_type"', escape: false);
    }

    // ── BR-07 + FR-ADM-06: student selection and scope ──────────────────────

    public function test_empty_student_selection_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => []]))
            ->assertSessionHasErrors(['students' => 'Select at least 2 students for this batch.']);
    }

    public function test_a_single_student_is_rejected(): void
    {
        // BR-07 (D-105): a batch needs at least two students.
        $own = StudentProfile::factory()->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => [$own->id]]))
            ->assertSessionHasErrors(['students' => 'Select at least 2 students for this batch.']);

        $this->assertSame(0, BatchRequest::count());
    }

    public function test_another_colleges_student_is_rejected(): void
    {
        $foreign = StudentProfile::factory()->forCollege($this->cea)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => [$foreign->id]]))
            ->assertSessionHasErrors('students.0');
    }

    public function test_a_deactivated_student_is_rejected(): void
    {
        $inactive = StudentProfile::factory()->forCollege($this->ccs)->create();
        $inactive->user->update(['status' => 'inactive']);

        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['students' => [$inactive->id]]))
            ->assertSessionHasErrors('students.0');

        $this->assertSame(0, BatchRequest::count());
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

    // ── Valid submission (persistence asserted in BatchRequestSubmitTest) ────

    public function test_a_valid_submission_passes_validation(): void
    {
        $students = StudentProfile::factory()->count(3)->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)
            ->post('/admin/batches', [
                'form_type' => 'clearance',
                'reason' => 'fieldtrip',
                'students' => $students->pluck('id')->all(),
                'memo' => UploadedFile::fake()->create('memo.pdf', 200, 'application/pdf'),   // D-109
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.batches.confirmation', BatchRequest::sole()));
    }
}
