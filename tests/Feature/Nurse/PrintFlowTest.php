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
use Tests\Support\EncodePayload;
use Tests\Support\ReleasesForPrint;
use Tests\TestCase;

/**
 * FR-NRS-05 — the print flow around the printable form. D-127 removed the
 * pre-save Preview & Print, so everything prints an ENCODED visit:
 *
 *  - right after Save, the physician prints what they just saved (their own
 *    encode, today — no release needed);
 *  - POST print (reprint) re-stamps printed_at and returns the form; every
 *    print re-stamps;
 *  - Save itself never stamps printed_at, whatever is posted.
 */
class PrintFlowTest extends TestCase
{
    use RefreshDatabase;
    use ReleasesForPrint;

    // ── Helpers (same shape as PrintViewTest) ────────────────────────────────

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    /** D-126: only the physician previews and saves a result. */
    private function physician(): User
    {
        return User::factory()->physician()->create();
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** @param  array<string, mixed>  $screening */
    private function makeVisit(array $screening = []): ClinicVisit
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

        ScreeningResponse::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'skin' => false,
            'head' => false,
            'eyes' => false,
            'ears' => false,
            'nose' => false,
            'throat' => false,
            'chest_lungs' => false,
            'heart' => false,
            'abdomen' => false,
            'kidney_bladder' => false,
            'brain' => false,
            'mental_disorder' => false,
            'is_pregnant' => false,
            'last_menstrual_period' => null,
        ], $screening));

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
        // D-125: printing follows the release — these tests are about what prints.
        $this->releaseForPrint($visit);

        return $record;
    }

    // ── 1. Print right after Save (D-127) ────────────────────────────────────

    public function test_the_physicians_print_after_save_carries_their_own_name_and_license(): void
    {
        $visit = $this->makeVisit();
        $physician = User::factory()->physician('Ana B. Cruz', '123456')->create();

        // D-64: the physician block is stamped from the encoder.
        $this->actingAs($physician)
            ->post(route('nurse.visits.encode.store', $visit), EncodePayload::make())
            ->assertRedirect(route('nurse.visits.encode', $visit));

        $this->actingAs($physician)
            ->post(route('nurse.visits.print.reprint', $visit))
            ->assertOk()
            ->assertSee('ANA B. CRUZ, MD')
            ->assertSee('License No.')
            ->assertSee('123456');
    }

    public function test_the_print_after_save_shades_the_saved_result_and_physical_signs(): void
    {
        $visit = $this->makeVisit();
        $physician = $this->physician();

        $this->actingAs($physician)->post(route('nurse.visits.encode.store', $visit), [
            ...EncodePayload::vitals(),
            'result' => 'Unfit',
            'ps_chest_lungs' => '1',
        ]);

        $html = $this->actingAs($physician)
            ->post(route('nurse.visits.print.reprint', $visit))
            ->assertOk()
            ->getContent();

        // UNFIT bubble shaded, FIT blank.
        $this->assertMatchesRegularExpression(
            '~<span class="bb"></span> <b>FIT</b>\s*<span class="bb on"[^>]*></span> <b>UNFIT</b>~u',
            $html
        );
        // Saved '1' → CHEST/LUNGS YES box marked, NO box blank.
        $this->assertMatchesRegularExpression(
            '~<td class="sname">CHEST/LUNGS</td>\s*<td class="sbox"><span class="bx on"></span></td>\s*<td class="sbox"><span class="bx"></span></td>~u',
            $html
        );
    }

    // ── 1b. D-56: a kiosk YES detail reaches REMARKS through Nurse Notes ─────

    public function test_a_kiosk_yes_detail_reaches_remarks_through_nurse_notes(): void
    {
        $nurse = $this->physician();
        $visit = $this->makeVisit([
            'skin' => true,
            'details' => ['skin' => 'Itchy rash on left arm'],
        ]);

        // The encode screen pre-fills Nurse Notes with the detail line…
        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('SKIN: Itchy rash on left arm');

        // …the nurse saves it as pre-filled (the textarea posts it back)…
        $this->actingAs($nurse)
            ->post(route('nurse.visits.encode.store', $visit), [
                ...EncodePayload::vitals(),
                'result' => 'Fit',
                'nurse_notes' => 'SKIN: Itchy rash on left arm',
            ])
            ->assertRedirect(route('nurse.visits.encode', $visit));

        // …and the printed form carries it under REMARKS.
        $this->releaseForPrint($visit);   // D-125: printing follows the release
        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->getContent();

        // D-67: REMARKS is wrapped and sized server-side onto the form's two
        // ruled lines — a short note keeps the full 10pt.
        $this->assertMatchesRegularExpression(
            '~<div class="rline" style="font-size: 10pt;">SKIN: Itchy rash on left arm</div>~',
            $html
        );
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

    // ── 3. Save never stamps printed_at ──────────────────────────────────────

    public function test_save_leaves_printed_at_null_even_when_printed_is_posted(): void
    {
        // The pre-save print and its `printed` flag are gone (D-127): a posted
        // one is not validated, so it never reaches the record.
        $visit = $this->makeVisit();

        $this->actingAs($this->physician())
            ->post(route('nurse.visits.encode.store', $visit), [...EncodePayload::make(), 'printed' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNull($visit->fresh()->clearanceRecord->printed_at);
    }
}
