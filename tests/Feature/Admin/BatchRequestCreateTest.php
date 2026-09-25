<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Programs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'form_type' => 'assessment',
            'reason' => 'ojt',
            'requested_date' => now()->addDays(7)->toDateString(),
            'requested_time' => '07:00:00', // D-37: start hour of the batch span
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
            ->assertSee('Submit Batch Request');
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
            ->assertSessionHasErrors('reason');

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
            ->assertSessionHasErrors('reason_detail');

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

    // ── D-54: the requested clinic date is a mini calendar ──────────────────

    public function test_create_page_carries_the_mini_calendar_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Asia/Manila'));

        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('Requested clinic date')
            // Still submitted under the same name, now from a hidden input.
            ->assertSee('name="requested_date"', false)
            ->assertViewHas('year', 2026)
            ->assertViewHas('month', 9)
            ->assertViewHas('fullDays', [])
            ->assertViewHas('cutoffDays', [])
            ->assertViewHas('bookingDays', config('healthpass.booking_days'));
    }

    public function test_availability_endpoint_reports_full_and_cutoff_days(): void
    {
        // 6 PM on Sep 8: today is past closing (BR-20) and has no hour left
        // (BR-23); Sep 15 is at the daily cap. (This used to be compared with
        // the student booking calendar's endpoint, which D-61 removed.)
        Carbon::setTestNow(Carbon::parse('2026-09-08 18:00', 'Asia/Manila'));
        config(['healthpass.daily_capacity' => 2]);

        Appointment::factory()->count(2)->create(['scheduled_date' => '2026-09-15', 'status' => 'scheduled']);

        $query = ['year' => 2026, 'month' => 9];

        $this->actingAs($this->admin)
            ->getJson(route('admin.batches.availability', $query))
            ->assertOk()
            ->assertExactJson(['full_days' => [8, 15], 'cutoff_days' => [8]]);
    }

    public function test_availability_endpoint_is_refused_for_other_roles_and_guests(): void
    {
        $url = route('admin.batches.availability', ['year' => 2026, 'month' => 9]);

        $this->get($url)->assertRedirect('/login');

        foreach (['student', 'nurse', 'director'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get($url)
                ->assertRedirect()
                ->assertSessionHas('error');
        }
    }

    // ── BR-06: reason + conditional reason_detail ───────────────────────────

    public function test_reason_and_date_and_students_are_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', [])
            ->assertSessionHasErrors(['form_type', 'reason', 'requested_date', 'students']);
    }

    // ── D-29: the admin proposes the clinic date ─────────────────────────────

    public function test_a_past_requested_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload([
                'requested_date' => now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('requested_date');
    }

    public function test_a_malformed_requested_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/batches', $this->validPayload(['requested_date' => 'next tuesday']))
            ->assertSessionHasErrors('requested_date');
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
            ->assertSessionHasErrors('students');
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
                'requested_date' => now()->addDays(7)->toDateString(),
                'requested_time' => '07:00:00',
                'students' => $students->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.batches.confirmation', BatchRequest::sole()));
    }
}
