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
use Tests\TestCase;

/**
 * D-65 — the clinic confirms the vitals on the encode page, and measures the
 * respiratory rate there.
 *
 * The two halves of the decision that must never drift:
 *
 *  - what the clinic confirms is saved on the clearance record
 *    (`encoded_vitals`) and is what prints and what the student sees;
 *  - the kiosk's own reading in `vital_signs` is NEVER overwritten (BR-14), so
 *    the flags and the analytics keep describing what the screening measured.
 *    The one exception is `respiratory_rate`, which the kiosk cannot measure.
 */
class EncodeVitalsTest extends TestCase
{
    use RefreshDatabase;

    /** The kiosk's reading on every visit this test builds. */
    private const KIOSK_VITALS = [
        'height_cm' => 170.0,
        'weight_kg' => 65.0,
        'bmi' => 22.5,
        'temperature_c' => 36.6,
        'heart_rate_bpm' => 78,
        'bp_systolic' => 150,
        'bp_diastolic' => 95,
    ];

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    /** A captured visit whose kiosk BP tripped the §7.4 flag. */
    private function makeVisit(): ClinicVisit
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);
        $student->studentProfile()->create([
            'college_id' => $college->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Ana',
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
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('V###'),
            'student_id' => $student->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            ...self::KIOSK_VITALS,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => true,   // 150/95 is over the locked 140/90
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...collect(array_keys(ScreeningResponse::QUESTIONS))
                ->mapWithKeys(fn (string $key): array => [$key => false])
                ->all(),
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    /** @param array<string, mixed> $payload */
    private function save(User $encoder, ClinicVisit $visit, array $payload)
    {
        return $this->actingAs($encoder)
            ->from(route('nurse.visits.encode', $visit))
            ->post(route('nurse.visits.encode.store', $visit), $payload);
    }

    // ── 1. Validation ─────────────────────────────────────────────────────────

    public function test_respiratory_rate_is_required(): void
    {
        $visit = $this->makeVisit();
        $payload = EncodePayload::make();
        unset($payload['respiratory_rate']);

        $this->save($this->nurse(), $visit, $payload)
            ->assertSessionHasErrors('respiratory_rate');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_every_vital_is_required(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, ['result' => 'Fit'])
            ->assertSessionHasErrors(array_keys(ClearanceRecord::ENCODED_VITALS));

        $this->assertDatabaseCount('clearance_records', 0);
    }

    /** FR-KSK-08 bounds, read from config — the same ones the kiosk uses. */
    public function test_an_out_of_range_respiratory_rate_is_refused(): void
    {
        $visit = $this->makeVisit();
        $bounds = config('healthpass.validation.respiratory_rate');

        foreach ([$bounds['min'] - 1, $bounds['max'] + 1] as $outOfRange) {
            $this->save($this->nurse(), $visit, EncodePayload::make(['respiratory_rate' => $outOfRange]))
                ->assertSessionHasErrors('respiratory_rate');
        }

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_an_out_of_range_temperature_is_refused(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, EncodePayload::make(['temperature_c' => 12]))
            ->assertSessionHasErrors('temperature_c');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_systolic_must_be_higher_than_diastolic(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, EncodePayload::make([
            'bp_systolic' => 90,
            'bp_diastolic' => 110,
        ]))->assertSessionHasErrors('bp_systolic');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    // ── 2. What is stored ─────────────────────────────────────────────────────

    public function test_the_confirmed_vitals_are_stored_on_the_clearance_record(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        // The clinic re-took the BP and measured the respiratory rate.
        $this->save($nurse, $visit, EncodePayload::make([
            'height_cm' => 170.0,
            'weight_kg' => 65.0,
            'temperature_c' => 36.6,
            'bp_systolic' => 132,
            'bp_diastolic' => 84,
            'heart_rate_bpm' => 78,
            'respiratory_rate' => 18,
        ]))->assertRedirect(route('nurse.queue'));

        $record = ClearanceRecord::firstWhere('clinic_visit_id', $visit->id);

        // assertEquals, not assertSame: JSON stores a whole 170.0 as 170 and
        // reads it back as an int. The printed form formats it either way.
        $this->assertEquals([
            'height_cm' => 170.0,
            'weight_kg' => 65.0,
            'bmi' => 22.5,
            'temperature_c' => 36.6,
            'bp_systolic' => 132,
            'bp_diastolic' => 84,
            'heart_rate_bpm' => 78,
            'respiratory_rate' => 18,
        ], $record->encoded_vitals);
    }

    public function test_the_respiratory_rate_lands_on_the_vitals_row(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, EncodePayload::make(['respiratory_rate' => 19]));

        $this->assertSame(19, $visit->vitalSigns->fresh()->respiratory_rate);
    }

    /**
     * D-66 — the respiratory-rate flag is derived at encode, from the value
     * being written, through the same VitalSigns helper the kiosk submit uses.
     */
    public function test_the_respiratory_rate_flag_is_computed_at_encode(): void
    {
        $inRange = $this->makeVisit();
        $this->save($this->nurse(), $inRange, EncodePayload::make(['respiratory_rate' => 16]));
        $this->assertFalse($inRange->vitalSigns->fresh()->is_rr_flagged);

        $tooFast = $this->makeVisit();
        $this->save($this->nurse(), $tooFast, EncodePayload::make(['respiratory_rate' => 24]));
        $this->assertTrue($tooFast->vitalSigns->fresh()->is_rr_flagged);

        $tooSlow = $this->makeVisit();
        $this->save($this->nurse(), $tooSlow, EncodePayload::make(['respiratory_rate' => 9]));
        $this->assertTrue($tooSlow->vitalSigns->fresh()->is_rr_flagged);
    }

    /** D-66 boundary: 12 and 20 are IN range; 11 and 21 are not. */
    public function test_respiratory_rate_flag_boundary(): void
    {
        foreach ([11 => true, 12 => false, 20 => false, 21 => true] as $rate => $expected) {
            $visit = $this->makeVisit();
            $this->save($this->nurse(), $visit, EncodePayload::make(['respiratory_rate' => $rate]));

            $this->assertSame(
                $expected,
                $visit->vitalSigns->fresh()->is_rr_flagged,
                "{$rate} breaths/min flagged wrongly",
            );
        }
    }

    public function test_the_kiosk_reading_and_its_flags_are_never_overwritten(): void
    {
        $visit = $this->makeVisit();

        // Every measured value corrected, all seven at once.
        $this->save($this->nurse(), $visit, EncodePayload::make([
            'height_cm' => 155.5,
            'weight_kg' => 90.0,
            'temperature_c' => 38.4,
            'bp_systolic' => 118,
            'bp_diastolic' => 72,
            'heart_rate_bpm' => 61,
            'respiratory_rate' => 15,
        ]))->assertRedirect(route('nurse.queue'));

        $vitals = $visit->vitalSigns->fresh();

        foreach (self::KIOSK_VITALS as $column => $captured) {
            $this->assertEquals($captured, $vitals->{$column}, "vital_signs.{$column} was overwritten at encode");
        }

        // BR-14: the flags describe the SCREENING, so they cannot move either —
        // the kiosk's 150/95 is still the flagged anomaly the Director sees.
        $this->assertTrue($vitals->is_bp_flagged);
        $this->assertFalse($vitals->is_temp_flagged);
        $this->assertFalse($vitals->is_bmi_flagged);
        // D-66: the corrected 61 bpm does not clear the capture's own HR flag
        // either — 78 bpm was never flagged, and it stays that way.
        $this->assertFalse($vitals->is_hr_flagged);
    }

    public function test_bmi_is_recomputed_server_side_and_a_posted_bmi_is_ignored(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, EncodePayload::make([
            'height_cm' => 160.0,
            'weight_kg' => 64.0,
            'bmi' => 1.1,   // forged — never reaches validated()
        ]))->assertRedirect(route('nurse.queue'));

        // 64 ÷ 1.6² = 25.0
        $this->assertEquals(25.0, ClearanceRecord::firstWhere('clinic_visit_id', $visit->id)->encoded_vitals['bmi']);
    }

    // ── 3. What is shown and printed ──────────────────────────────────────────

    public function test_the_preview_prints_the_posted_vitals_not_the_kiosk_reading(): void
    {
        $visit = $this->makeVisit();

        $html = $this->actingAs($this->nurse())
            ->post(route('nurse.visits.print.preview', $visit), EncodePayload::make([
                'bp_systolic' => 132,
                'bp_diastolic' => 84,
                'respiratory_rate' => 18,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('132/84 mmHg', $html);
        $this->assertStringContainsString('18 breaths/min', $html);
        $this->assertStringNotContainsString('150/95 mmHg', $html);
    }

    public function test_the_printed_form_carries_the_confirmed_vitals_and_the_respiratory_rate(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->save($nurse, $visit, EncodePayload::make([
            'bp_systolic' => 132,
            'bp_diastolic' => 84,
            'respiratory_rate' => 17,
        ]));

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Respiratory Rate:', $html);
        $this->assertStringContainsString('17 breaths/min', $html);
        $this->assertStringContainsString('132/84 mmHg', $html);
        $this->assertStringNotContainsString('150/95 mmHg', $html);
    }

    public function test_the_read_only_view_shows_the_kiosk_hint_only_where_a_value_was_corrected(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        // BP corrected; height left exactly as the kiosk measured it.
        $this->save($nurse, $visit, EncodePayload::make([
            'height_cm' => 170.0,
            'weight_kg' => 65.0,
            'temperature_c' => 36.6,
            'bp_systolic' => 132,
            'bp_diastolic' => 84,
            'heart_rate_bpm' => 78,
            'respiratory_rate' => 17,
        ]));

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kiosk: 150/95 mmHg', $html);
        $this->assertStringNotContainsString('Kiosk: 170.0 cm', $html);
    }
}
