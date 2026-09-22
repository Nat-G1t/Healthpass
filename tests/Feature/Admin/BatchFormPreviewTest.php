<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\College;
use App\Models\User;
use App\Support\AssessmentDocument;
use App\Support\ClearanceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New Batch Request card 1 (D-62) — each form tile embeds the REAL official
 * form, blank, from admin.batches.form-preview instead of a photograph.
 */
class BatchFormPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        return User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $college->id,
        ]);
    }

    public function test_the_clearance_preview_is_the_blank_official_form(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.batches.form-preview', 'clearance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(ClearanceDocument::FORM_CODE, $html);
        $this->assertStringNotContainsString(AssessmentDocument::FORM_CODE, $html);
        // The paper's own purposes print; nothing is shaded.
        $this->assertStringContainsString('Field Trip/Educational Tour', $html);
    }

    public function test_the_assessment_preview_is_the_front_page_only(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.batches.form-preview', 'assessment'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, AssessmentDocument::FORM_CODE));
        $this->assertStringContainsString('PAST MEDICAL HISTORY &amp; FAMILY HISTORY', $html);
        // Section II onwards is the back page.
        $this->assertStringNotContainsString('IMMUNIZATION PROFILE', $html);
    }

    public function test_an_unknown_form_type_is_a_404_not_a_default_form(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/batches/form-preview/nonsense')
            ->assertNotFound();
    }

    public function test_the_preview_is_closed_to_guests_and_students(): void
    {
        $this->get(route('admin.batches.form-preview', 'clearance'))
            ->assertRedirect(route('login'));

        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student)
            ->get(route('admin.batches.form-preview', 'clearance'))
            ->assertRedirect();

        $this->assertStringNotContainsString(ClearanceDocument::FORM_CODE, (string) $response->getContent());
    }
}
