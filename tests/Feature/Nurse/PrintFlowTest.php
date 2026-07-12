<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-NRS-05 — the Preview & Print / Reprint flow around the printable form:
 *
 *  - POST print-preview: a CAPTURED visit renders the official form from the
 *    posted (unsaved) assessment — nothing touches the database.
 *  - POST print (reprint): an ENCODED visit re-stamps printed_at and returns
 *    the form; every reprint re-stamps.
 *  - Save & Close with printed=1 stamps printed_at on the new record — the
 *    pre-save Preview & Print happened before the row existed, so the encode
 *    screen carries the fact into the save.
 */
class PrintFlowTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers (same shape as PrintViewTest) ────────────────────────────────

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    private function makeVisit(): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);
        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
            'last_name' => 'Cruz',
            'sex' => 'F',
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('T###'),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 21.5,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
            'last_menstrual_period' => null,
        ]);

        return $visit;
    }

    /** Flip a visit to encoded with its 1:1 clearance record. */
    private function encode(ClinicVisit $visit, User $nurse, array $overrides = []): ClearanceRecord
    {
        $record = ClearanceRecord::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ], $overrides));

        $visit->update(['status' => 'encoded']);

        return $record;
    }

    // ── 1. Preview (POST print-preview, captured visits) ─────────────────────

    public function test_preview_renders_the_form_from_the_posted_unsaved_assessment(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->post(route('nurse.visits.print.preview', $visit), [
                'result' => 'Unfit',
                'nurse_notes' => 'Preview-only observation.',
                'purpose' => 'Sports Activities',
            ])
            ->assertOk()
            ->assertSee('MEDICAL CLEARANCE')
            ->assertSee('Preview-only observation.')
            // Physician block filled from the model constants, not DB defaults
            // (the transient record was never saved).
            ->assertSee(ClearanceRecord::PHYSICIAN_NAME)
            ->assertSee('License No. '.ClearanceRecord::PHYSICIAN_LICENSE_NO)
            // The marker the encode screen's print script keys on.
            ->assertSee('data-hp-print-doc', false);
    }

    public function test_preview_shades_the_posted_result_and_physical_signs(): void
    {
        $visit = $this->makeVisit();

        $html = $this->actingAs($this->nurse())
            ->post(route('nurse.visits.print.preview', $visit), [
                'result' => 'Unfit',
                'ps_chest_lungs' => '1',
            ])
            ->assertOk()
            ->getContent();

        // UNFIT bubble shaded, FIT blank.
        $this->assertMatchesRegularExpression(
            '~<span class="bb"></span> <b>FIT</b>\s*<span class="bb"[^>]*>●</span> <b>UNFIT</b>~u',
            $html
        );
        // Posted '1' → CHEST/LUNGS YES shaded.
        $this->assertMatchesRegularExpression(
            '~CHEST/LUNGS</b></td>\s*<td class="bubbles"><span class="bb">●</span></td>~u',
            $html
        );
    }

    public function test_preview_writes_nothing_and_leaves_the_visit_captured(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->post(route('nurse.visits.print.preview', $visit), ['result' => 'Fit'])
            ->assertOk();

        $this->assertDatabaseCount('clearance_records', 0);
        $this->assertSame('captured', $visit->fresh()->status);
    }

    public function test_preview_requires_a_result(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->from(route('nurse.visits.encode', $visit))
            ->post(route('nurse.visits.print.preview', $visit), [])
            ->assertRedirect(route('nurse.visits.encode', $visit))
            ->assertSessionHasErrors('result');
    }

    public function test_preview_on_an_encoded_visit_returns_404(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->post(route('nurse.visits.print.preview', $visit), ['result' => 'Fit'])
            ->assertNotFound();
    }

    public function test_preview_requires_a_nurse(): void
    {
        $visit = $this->makeVisit();

        $this->post(route('nurse.visits.print.preview', $visit), ['result' => 'Fit'])
            ->assertRedirect(route('login'));

        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)
            ->post(route('nurse.visits.print.preview', $visit), ['result' => 'Fit'])
            ->assertRedirect('/student/dashboard');
    }

    // ── 2. Reprint (POST print, encoded visits) — re-stamps printed_at ───────

    public function test_reprint_stamps_printed_at_and_returns_the_form(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $record = $this->encode($visit, $nurse);

        $this->assertNull($record->printed_at);

        $this->actingAs($nurse)
            ->post(route('nurse.visits.print.reprint', $visit))
            ->assertOk()
            ->assertSee('MEDICAL CLEARANCE')
            ->assertSee('data-hp-print-doc', false);

        $this->assertNotNull($record->fresh()->printed_at);
    }

    public function test_every_reprint_restamps_printed_at(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $record = $this->encode($visit, $nurse);

        $this->actingAs($nurse)->post(route('nurse.visits.print.reprint', $visit))->assertOk();
        $first = $record->fresh()->printed_at;

        $this->travel(10)->minutes();

        $this->actingAs($nurse)->post(route('nurse.visits.print.reprint', $visit))->assertOk();
        $second = $record->fresh()->printed_at;

        $this->assertTrue($second->gt($first), 'Reprint must re-stamp printed_at (FR-NRS-05).');
    }

    public function test_reprint_on_a_captured_visit_returns_404(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->post(route('nurse.visits.print.reprint', $visit))
            ->assertNotFound();
    }

    public function test_plain_get_print_view_does_not_stamp_printed_at(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $record = $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk();

        $this->assertNull($record->fresh()->printed_at);
    }

    // ── 3. Save & Close carries the pre-save print into printed_at ───────────

    public function test_save_with_printed_flag_stamps_printed_at(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->post(route('nurse.visits.encode.store', $visit), [
                'result' => 'Fit',
                'printed' => '1',
            ])
            ->assertRedirect(route('nurse.queue'));

        $this->assertNotNull($visit->fresh()->clearanceRecord->printed_at);
    }

    public function test_save_without_printed_flag_leaves_printed_at_null(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->post(route('nurse.visits.encode.store', $visit), [
                'result' => 'Fit',
                'printed' => '0',
            ])
            ->assertRedirect(route('nurse.queue'));

        $this->assertNull($visit->fresh()->clearanceRecord->printed_at);
    }
}
