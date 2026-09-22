<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\MedicalAssessment;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use App\Support\AssessmentDocument;
use App\Support\ClearanceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EncodePayload;
use Tests\TestCase;

/**
 * FR-PRT-07 / D-71 — the printed Medical Assessment Form
 * (PSU-QSP-OSS-004-FO010-R00): two US Legal pages printed back-to-back on one
 * sheet.
 *
 * The clinic's printers rarely duplex, so the encode screen prints the two
 * sides separately (`side=front` / `side=back`) while the PDF carries both
 * pages in one file for a printer that can.
 */
class AssessmentPrintTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse', 'name' => 'Jane Dela Cruz']);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A captured visit on an approved batch that chose the given form (D-62). */
    private function makeVisit(string $formType = 'assessment', string $sex = 'F'): ClinicVisit
    {
        static $seq = 1;

        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);
        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
            'last_name' => 'Cruz',
            'sex' => $sex,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq),
            'college_id' => $this->college()->id,
            'requested_by' => $this->nurse()->id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $appointment = Appointment::factory()->medical()->create([
            'student_id' => $student->id,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-P%03d', $seq++),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'respiratory_rate' => 16,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'smoking' => 'no',
            'alcohol' => 'quit',
            'illicit_drugs' => 'no',
            'sexually_active' => false,
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    /** Flip a visit to encoded, with its clearance record and (D-69) sections. */
    private function encode(
        ClinicVisit $visit,
        User $encoder,
        array $overrides = [],
        array $sections = [],
    ): ClearanceRecord {
        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => 'Fit',
            'purpose' => 'On-the-job Training',
            'encoded_vitals' => [
                'height_cm' => 165.0,
                'weight_kg' => 60.0,
                'bmi' => 22.0,
                'temperature_c' => 36.5,
                'bp_systolic' => 115,
                'bp_diastolic' => 75,
                'heart_rate_bpm' => 75,
                'respiratory_rate' => 16,
            ],
            'encoded_at' => now(),
            ...$overrides,
        ]);

        if ($visit->formType() === 'assessment') {
            $record->medicalAssessment()->create([
                'medical_history' => ['patient' => [], 'family' => [], 'specify' => []],
                'immunizations' => ['given' => [], 'others' => null],
                'surgical_history' => ['procedures' => null, 'date_done' => null],
                ...$sections,
            ]);
        }

        $visit->update(['status' => 'encoded']);

        return $record;
    }

    /** The printed document as HTML, optionally just one side. */
    private function print(User $staff, ClinicVisit $visit, ?string $side = null): string
    {
        $url = route('nurse.visits.print', $visit).($side ? "?side={$side}" : '');

        return $this->actingAs($staff)->get($url)->assertOk()->getContent();
    }

    // ── 1. Two pages, one sheet ──────────────────────────────────────────────

    /** Each page carries the form code at its foot, so it appears twice. */
    public function test_the_document_prints_the_form_code_once_per_page(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit);

        $this->assertSame(2, substr_count($html, AssessmentDocument::FORM_CODE));
    }

    public function test_side_front_renders_only_the_front_page(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'front');

        $this->assertStringContainsString('MEDICAL ASSESSMENT FORM', $html);
        $this->assertStringContainsString('PAST MEDICAL HISTORY &amp; FAMILY HISTORY', $html);
        $this->assertStringNotContainsString('PERTINENT PHYSICAL EXAMINATION', $html);
        $this->assertStringNotContainsString('IMMUNIZATION PROFILE', $html);
        $this->assertSame(1, substr_count($html, AssessmentDocument::FORM_CODE));
    }

    public function test_side_back_renders_only_the_back_page(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'back');

        $this->assertStringContainsString('PERTINENT PHYSICAL EXAMINATION', $html);
        $this->assertStringContainsString('IMMUNIZATION PROFILE', $html);
        $this->assertStringNotContainsString('PAST MEDICAL HISTORY &amp; FAMILY HISTORY', $html);
        $this->assertSame(1, substr_count($html, AssessmentDocument::FORM_CODE));
    }

    /** An unknown side is ignored rather than trusted — the whole form prints. */
    public function test_an_unknown_side_prints_both_pages(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'sideways');

        $this->assertSame(2, substr_count($html, AssessmentDocument::FORM_CODE));
    }

    // ── 2. The PDF ───────────────────────────────────────────────────────────

    /**
     * Two pages, Legal. dompdf writes one `/Type /Page` object per page (the
     * page TREE is `/Type /Pages`, which the lookahead excludes) and the
     * paper size as a MediaBox in points — Legal is 8.5 × 14in = 612 × 1008pt.
     */
    public function test_the_pdf_is_two_legal_pages(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $pdf = $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, preg_match_all('~/Type\s*/Page(?![s])~', $pdf));
        $this->assertMatchesRegularExpression(
            '~/MediaBox\s*\[\s*0[\d.]*\s+0[\d.]*\s+612(\.\d+)?\s+1008(\.\d+)?~',
            $pdf
        );
    }

    /** Legal is 612pt wide; anything drawn past this is off the sheet. */
    private const LEGAL_WIDTH_PT = 612.0;

    /**
     * The back page's RIGHT column must land ON the paper.
     *
     * This is not a paranoid assertion. dompdf lays the two halves out very
     * differently from Chrome: a left column that cannot line-break — and
     * dompdf will not break a line between inline-block boxes — grows to its
     * widest line and pushes sections IV, V and VI off the right edge. The
     * HTML is perfect in that state, the text is still in the PDF (drawn past
     * x=612, where nothing prints), and every other test here passes. The
     * only thing that catches it is where the ink actually lands, so this
     * reads the x co-ordinate dompdf drew each heading at.
     */
    public function test_the_pdf_back_page_carries_the_right_hand_column(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse, [], [
            'menstrual_history' => ['menarche_age' => 13],
        ]);

        $pdf = $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->getContent();

        foreach ([
            'IV. PERTINENT PHYSICAL EXAM',
            'V. MENSTRUAL HISTORY',
            'VI. OB/PREGNANCY HISTORY',
        ] as $heading) {
            $x = $this->drawnAtX($pdf, $heading);

            $this->assertNotNull($x, "The PDF never draws \"{$heading}\".");
            $this->assertLessThan(
                self::LEGAL_WIDTH_PT,
                $x,
                "\"{$heading}\" is drawn at x={$x}pt, past the ".self::LEGAL_WIDTH_PT
                    ."pt edge of the sheet — the back page's right column fell off the paper."
            );
        }
    }

    /**
     * The x co-ordinate, in points from the left edge, at which the PDF draws
     * a piece of text. dompdf emits `BT <x> <y> Td /F<n> <size> Tf [(text)] TJ`
     * into a deflated content stream, so this inflates the streams and reads
     * that number back.
     */
    private function drawnAtX(string $pdf, string $text): ?float
    {
        preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $streams);

        $content = '';

        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);

            if ($inflated !== false) {
                $content .= $inflated;
            }
        }

        $found = preg_match(
            '~BT\s+([\d.]+)\s+[\d.]+\s+Td[^\)]*\('.preg_quote($text, '~').'\)~',
            $content,
            $match
        );

        return $found === 1 ? (float) $match[1] : null;
    }

    public function test_the_assessment_pdf_is_named_after_its_form(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $response = $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString(
            "attachment; filename={$visit->reference_no}-medical-assessment.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    /** A Medical Clearance visit is untouched: one Letter page, R04 (D-67). */
    public function test_a_clearance_visit_still_gets_the_letter_one_page_document(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('clearance');
        $this->encode($visit, $nurse, ['purpose' => 'Field Trip/Educational Tour']);

        $html = $this->print($nurse, $visit);
        $this->assertStringContainsString(ClearanceDocument::FORM_CODE, $html);
        $this->assertStringNotContainsString(AssessmentDocument::FORM_CODE, $html);

        $pdf = $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match_all('~/Type\s*/Page(?![s])~', $pdf));
        // Letter is 8.5 × 11in = 612 × 792pt.
        $this->assertMatchesRegularExpression(
            '~/MediaBox\s*\[\s*0[\d.]*\s+0[\d.]*\s+612(\.\d+)?\s+792(\.\d+)?~',
            $pdf
        );
    }

    // ── 3. Front page ────────────────────────────────────────────────────────

    /**
     * The front table is headed "(Self Assessment)" and the student signs
     * under it, so it prints the KIOSK's answers — never the clinic's ps_*
     * exam findings, even when the two disagree.
     */
    public function test_the_self_assessment_prints_the_kiosk_answers_not_the_exam(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        // The student said SKIN yes and left KIDNEY/BLADDER unanswered; the
        // clinic's exam found the opposite on both.
        $visit->screeningResponse->update(['skin' => true, 'kidney_bladder' => null]);
        $this->encode($visit, $nurse, ['ps_skin' => false, 'ps_kidney_bladder' => true]);

        $html = $this->print($nurse, $visit, 'front');

        // SKIN: the kiosk's YES, not the exam's NO.
        $this->assertMatchesRegularExpression(
            '~<td class="sname">SKIN</td>\s*<td class="sbox"><span class="bx on"></span></td>\s*<td class="sbox"><span class="bx"></span></td>~u',
            $html
        );
        // KIDNEY/BLADDER: unanswered at the kiosk → both boxes blank.
        $this->assertMatchesRegularExpression(
            '~<td class="sname">KIDNEY/BLADDER</td>\s*<td class="sbox"><span class="bx"></span></td>\s*<td class="sbox"><span class="bx"></span></td>~u',
            $html
        );
    }

    /** The specify text prints inside the row's own blank, as the paper reads. */
    public function test_the_history_table_prints_the_specify_texts_in_their_blanks(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse, [], [
            'medical_history' => [
                'patient' => ['allergy', 'hypertension'],
                'family' => ['hypertension'],
                'specify' => ['allergy' => 'seafood', 'hypertension' => '150/100'],
            ],
        ]);

        $html = $this->print($nurse, $visit, 'front');

        $this->assertStringContainsString('Allergy (Specify: seafood)', $html);
        $this->assertStringContainsString('Hypertension (Highest BP: 150/100 mmHg)', $html);
    }

    // ── 4. Back page ─────────────────────────────────────────────────────────

    /** Section IV asks for metres, unlike the Medical Clearance's centimetres. */
    public function test_height_prints_in_metres(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'back');

        $this->assertMatchesRegularExpression(
            '~Height:\s*<span class="fill-inline"[^>]*>1\.65</span>\s*m~u',
            $html
        );
        $this->assertStringNotContainsString('165.0', $html);
    }

    public function test_sections_five_and_six_print_for_a_female_student(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('assessment', 'F');
        $this->encode($visit, $nurse, [], [
            'menstrual_history' => [
                'menarche_age' => 13,
                'lmp' => '2026-09-01',
                'contraceptive' => 'None',
                'menopause' => false,
            ],
            'ob_history' => ['gravida' => 1, 'para' => 1, 'pih' => false],
        ]);

        $html = $this->print($nurse, $visit, 'back');

        $this->assertMatchesRegularExpression(
            '~Menarche:\s*<span class="fill-inline"[^>]*>13</span>~u',
            $html
        );
        $this->assertStringContainsString('September 1, 2026', $html);
        $this->assertMatchesRegularExpression(
            '~Gravida:\s*<span class="fill-inline"[^>]*>1</span>~u',
            $html
        );
    }

    /** A male student's V and VI print BLANK — never "N/A" (D-70/D-71). */
    public function test_sections_five_and_six_print_blank_for_a_male_student(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('assessment', 'M');
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'back');

        // The headings are still there — the paper has them for everyone.
        $this->assertStringContainsString('V. MENSTRUAL HISTORY', $html);
        $this->assertStringContainsString('VI. OB/PREGNANCY HISTORY', $html);

        $this->assertMatchesRegularExpression(
            '~Menarche:\s*<span class="fill-inline"[^>]*></span>~u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~Gravida:\s*<span class="fill-inline"[^>]*></span>~u',
            $html
        );
        $this->assertStringNotContainsString('N/A', $html);
    }

    /** The kiosk's Personal / Social History answers (D-68) print in I. */
    public function test_the_personal_social_history_prints_the_kiosk_answers(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'back');

        // Alcohol was answered "quit" at the kiosk.
        $this->assertMatchesRegularExpression(
            '~Alcohol:\s*<span class="bx"></span> Yes\s*<span class="bx"[^>]*></span> No\s*<span class="bx on"[^>]*></span> Quit~u',
            $html
        );
    }

    // ── 5. Who signs it (D-64) ───────────────────────────────────────────────

    public function test_a_nurse_record_names_the_nurse_and_leaves_the_physician_blank(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $html = $this->print($nurse, $visit, 'back');

        $this->assertMatchesRegularExpression(
            '~<div class="name">Jane Dela Cruz</div>\s*<div class="center">Interviewed/Assessed by:</div>~u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~<div class="name"></div>\s*<div class="center">University Physician</div>~u',
            $html
        );
    }

    public function test_a_physician_record_prints_the_name_and_licence(): void
    {
        $physician = User::factory()->physician()->create();
        $visit = $this->makeVisit();
        $this->encode($visit, $physician, ClearanceRecord::physicianBlockFor($physician));

        $html = $this->print($physician, $visit, 'back');

        $this->assertMatchesRegularExpression(
            '~<div class="name">'.preg_quote($physician->name, '~').'</div>\s*<div class="center">Interviewed/Assessed by:</div>~u',
            $html
        );
        $this->assertStringContainsString(mb_strtoupper($physician->name).', MD', $html);
        $this->assertStringContainsString('60252', $html);
    }

    // ── 6. The encode screen's buttons (FR-NRS-05) ───────────────────────────

    public function test_an_encoded_assessment_offers_print_front_back_and_pdf(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Print front')
            ->assertSee('Print back')
            ->assertSee('Save as PDF')
            ->assertDontSee('Reprint')
            ->assertSee('Put the printed sheet back in the tray');
    }

    public function test_a_captured_assessment_offers_the_two_pre_save_prints(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Print front')
            ->assertSee('Print back')
            ->assertDontSee('Preview &amp; Print', false);
    }

    /** A Medical Clearance keeps its D-67 buttons untouched. */
    public function test_a_clearance_visit_keeps_preview_and_print(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('clearance');

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Preview &amp; Print', false)
            ->assertDontSee('Print front');
    }

    // ── 7. The pre-save print (FR-NRS-05) ────────────────────────────────────

    /**
     * Print front / Print back before Save & Close post the UNSAVED encode
     * form, so the sections have no row yet — they are rebuilt as a transient
     * MedicalAssessment and must still print.
     */
    public function test_the_pre_save_print_renders_the_posted_sections(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $html = $this->actingAs($nurse)
            ->post(route('nurse.visits.print.preview', $visit), [
                'result' => 'Fit',
                'side' => 'front',
                ...EncodePayload::vitals(),
                'medical_history' => [
                    'patient' => ['asthma'],
                    'specify' => ['allergy' => 'peanuts'],
                ],
            ])
            ->assertOk()
            ->getContent();

        // Front only, with the posted specify text in its blank.
        $this->assertSame(1, substr_count($html, AssessmentDocument::FORM_CODE));
        $this->assertStringContainsString('Allergy (Specify: peanuts)', $html);
        // "Interviewed/Assessed by" is the signed-in encoder, pre-save too.
        $this->assertStringContainsString('MEDICAL ASSESSMENT FORM', $html);
    }

    public function test_the_pre_save_back_print_names_the_signed_in_encoder(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->actingAs($nurse)
            ->post(route('nurse.visits.print.preview', $visit), [
                'result' => 'Fit',
                'side' => 'back',
                ...EncodePayload::vitals(),
                'physical_exam' => ['heent' => ['findings' => ['normal'], 'others' => null]],
            ])
            ->assertOk()
            ->assertSee('Jane Dela Cruz');
    }

    /** Every print re-stamps printed_at, front or back (FR-NRS-05). */
    public function test_a_side_print_still_stamps_printed_at(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $record = $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->post(route('nurse.visits.print.reprint', $visit), ['side' => 'back'])
            ->assertOk();

        $this->assertNotNull($record->fresh()->printed_at);
    }

    /** Nothing in the request body may change WHICH form prints (D-62). */
    public function test_the_form_type_comes_from_the_batch_not_the_request(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('clearance');
        $this->encode($visit, $nurse, ['purpose' => 'Field Trip/Educational Tour']);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', [$visit, 'formType' => 'assessment', 'side' => 'front']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(ClearanceDocument::FORM_CODE, $html);
        $this->assertStringNotContainsString(MedicalAssessment::class, $html);
        $this->assertStringNotContainsString(AssessmentDocument::FORM_CODE, $html);
    }

    // ── Blank paper (New Batch Request card 1) ──────────────────────────────

    public function test_the_blank_documents_carry_exactly_the_keys_a_real_one_does(): void
    {
        // The form templates cannot tell a blank from a real visit — so the
        // New Batch tile's preview needs no preview-only branch in them.
        $nurse = $this->nurse();

        $clearance = $this->makeVisit('clearance');
        $this->encode($clearance, $nurse, ['purpose' => 'Field Trip/Educational Tour']);
        $assessment = $this->makeVisit('assessment');
        $this->encode($assessment, $nurse);

        $keys = fn (array $document): array => collect($document)->keys()->sort()->values()->all();

        $this->assertSame($keys(ClearanceDocument::for($clearance)), $keys(ClearanceDocument::blank()));
        $this->assertSame($keys(AssessmentDocument::for($assessment, side: 'front')), $keys(AssessmentDocument::blank('front')));
    }
}
