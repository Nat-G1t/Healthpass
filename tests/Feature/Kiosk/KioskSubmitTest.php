<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-KSK-12 — kiosk submit. ONE endpoint that, in a single transaction, creates
 * the clinic_visits + vital_signs + screening_responses trio, computes the §7.4
 * flag booleans server-side (BR-13/14), and links today's appointment or NULL
 * for a walk-in (BR-10).
 */
class KioskSubmitTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** An active student user; returns the User (clinic_visits.student_id = users.id). */
    private function student(): User
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create();

        return $profile->user;
    }

    /** A complete, valid submission payload; override any leaf via dot-free nesting. */
    private function payload(int $studentId, array $overrides = []): array
    {
        $base = [
            'studentUserId' => $studentId,
            'loginMethod' => 'qr',
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['sensor', 'sensor', 'sensor', 'sensor'],
            'vitals' => [
                'height' => 170,
                'weight' => 60,
                'bmi' => 20.8, // sent by client for display; server recomputes
                'temperature' => 36.8,
                'systolic' => 118,
                'diastolic' => 76,
                'heartRate' => 72,
            ],
            'screening' => [
                'vision' => false, 'hearing' => false, 'nose' => false,
                'skin' => false, 'respiratory' => false, 'heart' => false,
                'digestive' => false, 'bones' => false, 'nervous' => false,
                'isPregnant' => false, 'lastMenstrualPeriod' => null,
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    private function submit(int $studentId, array $overrides = [])
    {
        return $this->postJson(route('kiosk.submit'), $this->payload($studentId, $overrides));
    }

    // ── Three linked rows in one transaction ──────────────────────────────────

    public function test_submit_creates_exactly_three_linked_rows(): void
    {
        $student = $this->student();

        $response = $this->submit($student->id)->assertOk()->assertJson(['ok' => true]);

        // Exactly one of each row, no orphans.
        $this->assertSame(1, ClinicVisit::count());
        $this->assertSame(1, VitalSigns::count());
        $this->assertSame(1, ScreeningResponse::count());

        $visit = ClinicVisit::first();
        $this->assertSame($student->id, $visit->student_id);
        $this->assertSame('captured', $visit->status);
        $this->assertSame('qr', $visit->login_method);
        $this->assertNotNull($visit->privacy_consent_at);
        $this->assertNotNull($visit->checked_in_at);
        $this->assertMatchesRegularExpression('/^HP-\d{4}-\d{4}$/', $visit->reference_no);
        $this->assertSame($visit->reference_no, $response->json('reference'));

        // The 1:1 children point back at the visit.
        $this->assertSame($visit->id, $visit->vitalSigns->clinic_visit_id);
        $this->assertSame($visit->id, $visit->screeningResponse->clinic_visit_id);
        $this->assertSame('sensor', $visit->vitalSigns->entry_method);
    }

    public function test_invalid_payload_persists_nothing(): void
    {
        $student = $this->student();

        // Temperature above the plausibility bound → 422, no rows written.
        $this->submit($student->id, ['vitals' => ['temperature' => 99]])
            ->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
        $this->assertSame(0, VitalSigns::count());
        $this->assertSame(0, ScreeningResponse::count());
    }

    // ── Flag booleans at boundary values (§7.4, D-10) ─────────────────────────

    /** Temperature flag is "> 37.2": 37.2 is NOT flagged, 37.3 IS. */
    public function test_temperature_flag_boundary(): void
    {
        $this->submit($this->student()->id, ['vitals' => ['temperature' => 37.2]]);
        $this->assertFalse(VitalSigns::latest('id')->first()->is_temp_flagged);

        $this->submit($this->student()->id, ['vitals' => ['temperature' => 37.3]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_temp_flagged);
    }

    /** BP flag is "systolic >= 140 OR diastolic >= 90". */
    public function test_blood_pressure_flag_boundary(): void
    {
        // 139/89 — both below → not flagged.
        $this->submit($this->student()->id, ['vitals' => ['systolic' => 139, 'diastolic' => 89]]);
        $this->assertFalse(VitalSigns::latest('id')->first()->is_bp_flagged);

        // 140/90 — both at threshold → flagged.
        $this->submit($this->student()->id, ['vitals' => ['systolic' => 140, 'diastolic' => 90]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_bp_flagged);

        // 145/85 — systolic alone trips it → flagged.
        $this->submit($this->student()->id, ['vitals' => ['systolic' => 145, 'diastolic' => 85]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_bp_flagged);
    }

    /** BMI flag is ">= 30.0". height 100cm makes BMI == weight, so boundaries are exact. */
    public function test_bmi_flag_boundary(): void
    {
        $this->submit($this->student()->id, ['vitals' => ['height' => 100, 'weight' => 29.9]]);
        $row = VitalSigns::latest('id')->first();
        $this->assertEquals(29.9, (float) $row->bmi);
        $this->assertFalse($row->is_bmi_flagged);

        $this->submit($this->student()->id, ['vitals' => ['height' => 100, 'weight' => 30.0]]);
        $row = VitalSigns::latest('id')->first();
        $this->assertEquals(30.0, (float) $row->bmi);
        $this->assertTrue($row->is_bmi_flagged);
    }

    // ── Appointment linkage (BR-10) ───────────────────────────────────────────

    public function test_booked_student_links_todays_appointment(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->medical()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id)->assertOk();

        $this->assertSame($appointment->id, ClinicVisit::first()->appointment_id);
    }

    /**
     * Dental is scheduling-only (Decision D-3) and never enters the kiosk loop:
     * a student with only a dental appointment today is a WALK-IN, so the submit
     * linkage stays NULL — consistent with the Walk-in Check gate (FR-KSK-03a).
     */
    public function test_dental_only_same_day_appointment_is_a_walk_in(): void
    {
        $student = $this->student();
        Appointment::factory()->dental()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id)->assertOk();

        $this->assertNull(ClinicVisit::first()->appointment_id);
    }

    public function test_walk_in_gets_null_appointment(): void
    {
        $student = $this->student();

        // A cancelled appointment today, and a scheduled one on another day —
        // neither should link; this is a walk-in.
        Appointment::factory()->cancelled()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
        ]);
        Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id)->assertOk();

        $this->assertNull(ClinicVisit::first()->appointment_id);
    }

    // ── College snapshot, transfer-proof (FR-STU-09 / D-17) ───────────────────

    public function test_visit_snapshots_college_and_survives_a_transfer(): void
    {
        $ccs = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $profile = StudentProfile::factory()->forCollege($ccs)->create();
        $student = $profile->user;

        // Visit 1 captured while the student is still in CCS.
        $this->submit($student->id)->assertOk();
        $firstVisit = ClinicVisit::latest('id')->first();
        $this->assertSame($ccs->id, $firstVisit->college_id);

        // The student transfers to CEA (their LIVE college changes)…
        $profile->update(['college_id' => $cea->id]);

        // …Visit 2 snapshots the NEW college.
        $this->submit($student->id)->assertOk();
        $secondVisit = ClinicVisit::latest('id')->first();
        $this->assertSame($cea->id, $secondVisit->college_id);

        // The OLD visit is untouched — a past case is never re-attributed.
        $this->assertSame($ccs->id, $firstVisit->fresh()->college_id);

        // Grouping by the snapshot (the FR-ANL-05/08 source) counts the old visit
        // under CCS and the new one under CEA, even though the student's current
        // college is now CEA for their profile and all future data.
        $byCollege = ClinicVisit::query()
            ->selectRaw('college_id, COUNT(*) as total')
            ->groupBy('college_id')
            ->pluck('total', 'college_id');

        $this->assertSame(1, (int) $byCollege[$ccs->id]);
        $this->assertSame(1, (int) $byCollege[$cea->id]);
        $this->assertSame($cea->id, $profile->fresh()->college_id);
    }

    // ── entry_method roll-up (FR-KSK-06) ──────────────────────────────────────

    public function test_entry_method_rolls_up_mixed(): void
    {
        $this->submit($this->student()->id, ['vitalMethods' => ['sensor', 'manual', 'sensor', 'sensor']]);
        $this->assertSame('mixed', VitalSigns::latest('id')->first()->entry_method);

        $this->submit($this->student()->id, ['vitalMethods' => ['manual', 'manual', 'manual', 'manual']]);
        $this->assertSame('manual', VitalSigns::latest('id')->first()->entry_method);
    }
}
