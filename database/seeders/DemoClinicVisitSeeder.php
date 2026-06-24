<?php

namespace Database\Seeders;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * DEV/DEMO ONLY — synthetic kiosk data.
 *
 * Seeds 6 clinic visits across two demo students so that every UI state on the
 * My Records page is visible during development without needing a real kiosk:
 *
 *   • 3 encoded visits (with clearance records) → shows Fit/Unfit result + modal
 *       - HP-2026-9001  Juan Santos     Fit, no case category
 *       - HP-2026-9002  Juan Santos     Unfit, Cardiovascular System, BP flagged
 *       - HP-2026-9003  Maria Reyes     Fit, Alimentary System category
 *   • 3 captured visits (no clearance record) → shows Pending, gated View
 *       - HP-2026-9004  Juan Santos     normal vitals
 *       - HP-2026-9005  Maria Reyes     temperature flagged (verifies flag display)
 *       - HP-2026-9006  Maria Reyes     normal vitals
 *
 * Reference band HP-2026-9xxx is reserved for synthetic data and will not
 * collide with real sequences (which start from HP-2026-0001).
 *
 * DELETE this seeder (and its call in DatabaseSeeder) once the real kiosk
 * starts writing clinic_visits rows directly.
 */
class DemoClinicVisitSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        // Idempotent: skip if demo visits already exist.
        if (ClinicVisit::where('reference_no', 'like', 'HP-2026-9%')->exists()) {
            $this->command->info('DemoClinicVisitSeeder: demo visits already exist, skipping.');

            return;
        }

        $juan = User::where('email', 'juan.santos@psu.edu.ph')->firstOrFail();
        $maria = User::where('email', 'maria.reyes@psu.edu.ph')->firstOrFail();
        $nurse = User::where('email', 'nurse@healthpass.test')->firstOrFail();

        // ── Encoded visit 1 — Juan Santos, Fit, no case category ──────────────
        $v1 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9001',
            'student_id' => $juan->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => Carbon::parse('2026-01-10 08:55:00'),
            'checked_in_at' => Carbon::parse('2026-01-10 09:02:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v1->id,
            'height_cm' => 172.0,
            'weight_kg' => 68.5,
            'bmi' => 23.2,
            'temperature_c' => 36.6,
            'heart_rate_bpm' => 74,
            'bp_systolic' => 118,
            'bp_diastolic' => 76,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v1->id,
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
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v1->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'case_category' => null,
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-01-10 10:30:00'),
        ]);

        // ── Encoded visit 2 — Juan Santos, Unfit, Cardiovascular, BP flagged ──
        $v2 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9002',
            'student_id' => $juan->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => Carbon::parse('2026-03-05 09:10:00'),
            'checked_in_at' => Carbon::parse('2026-03-05 09:15:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v2->id,
            'height_cm' => 172.0,
            'weight_kg' => 71.0,
            'bmi' => 24.0,
            'temperature_c' => 36.8,
            'heart_rate_bpm' => 88,
            'bp_systolic' => 145,   // above 140 threshold → flagged
            'bp_diastolic' => 93,    // above 90 threshold → flagged
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => true,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v2->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => true,  // flagged questionnaire answer
            'heart' => true,  // flagged questionnaire answer
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v2->id,
            'encoded_by' => $nurse->id,
            'result' => 'Unfit',
            'case_category' => 'Cardiovascular System',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-03-05 10:45:00'),
        ]);

        // ── Encoded visit 3 — Maria Reyes, Fit, Alimentary System category ────
        $v3 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9003',
            'student_id' => $maria->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => Carbon::parse('2026-02-03 10:50:00'),
            'checked_in_at' => Carbon::parse('2026-02-03 11:00:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v3->id,
            'height_cm' => 158.5,
            'weight_kg' => 52.0,
            'bmi' => 20.7,
            'temperature_c' => 36.4,
            'heart_rate_bpm' => 79,
            'bp_systolic' => 112,
            'bp_diastolic' => 70,
            'entry_method' => 'sensor',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v3->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => true,  // flagged questionnaire answer
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v3->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'case_category' => 'Alimentary System',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-02-03 12:15:00'),
        ]);

        // ── Captured visit 4 — Juan Santos, Pending, normal vitals ────────────
        $v4 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9004',
            'student_id' => $juan->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse('2026-06-15 08:40:00'),
            'checked_in_at' => Carbon::parse('2026-06-15 08:45:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v4->id,
            'height_cm' => 172.0,
            'weight_kg' => 69.0,
            'bmi' => 23.3,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 76,
            'bp_systolic' => 120,
            'bp_diastolic' => 78,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v4->id,
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
        ]);

        // ── Captured visit 5 — Maria Reyes, Pending, temperature flagged ──────
        $v5 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9005',
            'student_id' => $maria->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse('2026-05-10 08:55:00'),
            'checked_in_at' => Carbon::parse('2026-05-10 09:00:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v5->id,
            'height_cm' => 158.5,
            'weight_kg' => 52.5,
            'bmi' => 20.9,
            'temperature_c' => 38.1,  // fever → flagged
            'heart_rate_bpm' => 95,
            'bp_systolic' => 125,
            'bp_diastolic' => 82,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => true,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v5->id,
            'vision' => false,
            'hearing' => false,
            'nose' => true,   // flagged answer
            'skin' => false,
            'respiratory' => true,   // flagged answer
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);

        // ── Captured visit 6 — Maria Reyes, Pending, normal vitals ───────────
        $v6 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9006',
            'student_id' => $maria->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse('2026-06-20 08:25:00'),
            'checked_in_at' => Carbon::parse('2026-06-20 08:30:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v6->id,
            'height_cm' => 158.5,
            'weight_kg' => 51.5,
            'bmi' => 20.5,
            'temperature_c' => 36.3,
            'heart_rate_bpm' => 77,
            'bp_systolic' => 110,
            'bp_diastolic' => 70,
            'entry_method' => 'sensor',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v6->id,
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
        ]);

        $this->command->info('DemoClinicVisitSeeder: 6 demo visits created (HP-2026-9001 – HP-2026-9006).');
    }
}
