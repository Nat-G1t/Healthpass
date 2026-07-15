<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flagged Anomalies (FR-ANL-05) and its record-detail page:
 *  - sourced from vital_signs flag booleans, INCLUDING still-captured
 *    visits — flags surface from capture while case statistics stay
 *    encoded-only (FR-ANL-07). The PRD AC's cross-check lives here: a
 *    captured flagged visit is on this screen but in no analytics count;
 *  - three stat cards counting each flag type independently;
 *  - Category column: encoded case categories, or "Pending" until then;
 *  - College column = the capture-time snapshot (FR-STU-09, D-17).
 */
class AnomaliesPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    private User $nurse;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Juan Santos']);
    }

    /**
     * Persist one visit with vitals — unflagged and in-range unless
     * overridden. Flag booleans are set explicitly here exactly like the
     * kiosk submit does (stored at capture, never recomputed).
     */
    private function makeVisit(College $college, array $vitalOverrides = [], string $status = 'captured'): ClinicVisit
    {
        static $seq = 8000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $this->student->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => $status,
            'checked_in_at' => now(),
        ]);

        VitalSigns::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 170.0,
            'weight_kg' => 65.0,
            'bmi' => 22.5,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 120,
            'bp_diastolic' => 80,
            'entry_method' => 'manual',
            'is_bp_flagged' => false,
            'is_temp_flagged' => false,
            'is_bmi_flagged' => false,
        ], $vitalOverrides));

        return $visit;
    }

    /** Encode a visit: clearance record + status flip, like FR-NRS-04. */
    private function encode(ClinicVisit $visit, array $categories = []): ClearanceRecord
    {
        $visit->update(['status' => 'encoded']);

        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);

        foreach ($categories as $category) {
            $record->caseCategories()->create(['case_category' => $category]);
        }

        return $record;
    }

    public function test_guests_and_other_roles_cannot_open_anomalies(): void
    {
        $visit = $this->makeVisit($this->ccs, ['is_bp_flagged' => true, 'bp_systolic' => 145, 'bp_diastolic' => 93]);

        $this->get('/director/anomalies')->assertRedirect('/login');
        $this->get("/director/anomalies/{$visit->id}")->assertRedirect('/login');

        // The role middleware bounces other roles to their own home.
        $this->actingAs($this->nurse)->get('/director/anomalies')->assertRedirect();
        $this->actingAs($this->nurse)->get("/director/anomalies/{$visit->id}")->assertRedirect();
    }

    public function test_captured_flagged_visit_is_listed_but_enters_no_analytics_count(): void
    {
        // PRD §4.9 AC: freshly captured, temp-flagged, NOT yet encoded.
        $this->makeVisit($this->ccs, ['is_temp_flagged' => true, 'temperature_c' => 38.1]);

        // On Flagged Anomalies: listed, counted, Category = Pending.
        $response = $this->actingAs($this->director)
            ->get('/director/anomalies')
            ->assertOk()
            ->assertSee('Juan Santos')
            ->assertSee('Fever')
            ->assertSee('38.1')
            ->assertSee('Pending');

        $this->assertSame(1, $response->viewData('visits')->count());
        $this->assertSame(['bp' => 0, 'temp' => 1, 'bmi' => 0], $response->viewData('stats'));

        // On Analytics: invisible everywhere — matrix, donut, footnote.
        $analytics = $this->actingAs($this->director)->get('/director/analytics');
        $this->assertSame(0, $analytics->viewData('totalCases'));
        $this->assertSame(0, $analytics->viewData('totalScreened'));
        $this->assertSame(0, $analytics->viewData('uncategorizedCount'));
    }

    public function test_encoded_flagged_visit_shows_its_case_categories(): void
    {
        $visit = $this->makeVisit($this->ccs, ['is_bp_flagged' => true, 'bp_systolic' => 145, 'bp_diastolic' => 93]);
        $this->encode($visit, ['Cardiovascular System', 'Respiratory System']);

        $this->actingAs($this->director)
            ->get('/director/anomalies')
            ->assertOk()
            ->assertSee('High Blood Pressure')
            ->assertSee('145/93 mmHg')
            ->assertSee('Cardiovascular System, Respiratory System')
            ->assertDontSee('Pending');
    }

    public function test_unflagged_visits_are_not_listed(): void
    {
        $this->makeVisit($this->ccs); // all flags false
        $flagged = $this->makeVisit($this->cea, ['is_bmi_flagged' => true, 'bmi' => 31.4]);

        $response = $this->actingAs($this->director)->get('/director/anomalies');

        $visits = $response->viewData('visits');
        $this->assertSame([$flagged->id], $visits->pluck('id')->all());
    }

    public function test_stat_cards_count_each_flag_type_independently(): void
    {
        // One visit tripping BOTH bp and temp counts once in each card.
        $this->makeVisit($this->ccs, [
            'is_bp_flagged' => true, 'bp_systolic' => 150, 'bp_diastolic' => 95,
            'is_temp_flagged' => true, 'temperature_c' => 38.4,
        ]);
        $this->makeVisit($this->cea, ['is_bmi_flagged' => true, 'bmi' => 32.0]);

        $response = $this->actingAs($this->director)->get('/director/anomalies');

        $this->assertSame(['bp' => 1, 'temp' => 1, 'bmi' => 1], $response->viewData('stats'));
        // Two flagged visits — the double-flagged one is ONE table row.
        $this->assertSame(2, $response->viewData('visits')->count());
    }

    public function test_college_column_shows_the_capture_time_snapshot(): void
    {
        // D-17: flagged under CEA; the student has since moved to CCS.
        StudentProfile::factory()
            ->forCollege($this->ccs)
            ->create(['user_id' => $this->student->id]);

        $this->makeVisit($this->cea, ['is_bp_flagged' => true, 'bp_systolic' => 160, 'bp_diastolic' => 100]);

        $response = $this->actingAs($this->director)->get('/director/anomalies');

        $this->assertSame('CEA', $response->viewData('visits')->first()->college->code);
    }

    public function test_detail_page_shows_a_captured_visit_as_awaiting_encode(): void
    {
        $visit = $this->makeVisit($this->ccs, ['is_temp_flagged' => true, 'temperature_c' => 38.1]);

        $this->actingAs($this->director)
            ->get("/director/anomalies/{$visit->id}")
            ->assertOk()
            ->assertSee($visit->reference_no)
            ->assertSee('Juan Santos')
            ->assertSee('38.1')
            ->assertSee('Pending encode')
            ->assertSee('Awaiting nurse encode');
    }

    public function test_detail_page_shows_the_encoded_assessment(): void
    {
        $visit = $this->makeVisit($this->ccs, ['is_bp_flagged' => true, 'bp_systolic' => 145, 'bp_diastolic' => 93]);
        $this->encode($visit, ['Cardiovascular System']);

        $this->actingAs($this->director)
            ->get("/director/anomalies/{$visit->id}")
            ->assertOk()
            ->assertSee('145/93')
            ->assertSee('Fit')
            ->assertSee('Cardiovascular System')
            ->assertDontSee('Awaiting nurse encode');
    }
}
