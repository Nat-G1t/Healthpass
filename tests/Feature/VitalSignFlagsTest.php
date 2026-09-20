<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use App\Support\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-66 — the heart-rate and respiratory-rate flag RULES, and the one scope
 * every "is this visit flagged?" screen is built on.
 *
 * The rules live as static helpers on VitalSigns so the kiosk submit, the
 * clinic's encode and the seeders share one implementation (BR-13: thresholds
 * come from config/healthpass.php and nowhere else). Widening
 * ClinicVisit::scopeFlagged() is what carries the two new flags to Flagged
 * Anomalies, the Director dashboard preview and the D-57 nav badge for free —
 * this file checks each of those three.
 */
class VitalSignFlagsTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private User $director;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Juan Santos']);
    }

    /** One captured visit with in-range, unflagged vitals unless overridden. */
    private function makeVisit(array $vitalOverrides = []): ClinicVisit
    {
        static $seq = 6000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $this->student->id,
            'college_id' => $this->college->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'checked_in_at' => now(),
        ]);

        VitalSigns::create([
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
            'is_hr_flagged' => false,
            'is_rr_flagged' => false,
            ...$vitalOverrides,
        ]);

        return $visit;
    }

    // ── The rules themselves (boundaries) ────────────────────────────────────

    /** "> 100 bpm": 100 is in range, 101 is not. */
    public function test_heart_rate_rule_boundary(): void
    {
        $this->assertFalse(VitalSigns::isHeartRateFlagged(100));
        $this->assertTrue(VitalSigns::isHeartRateFlagged(101));
    }

    /** No low-heart-rate flag: a fit student's resting 50 bpm is normal. */
    public function test_a_low_heart_rate_is_never_flagged(): void
    {
        $this->assertFalse(VitalSigns::isHeartRateFlagged(50));
        $this->assertFalse(VitalSigns::isHeartRateFlagged(30));
    }

    /** "< 12 or > 20 breaths/min": 12 and 20 are in range, 11 and 21 are not. */
    public function test_respiratory_rate_rule_boundary(): void
    {
        $this->assertTrue(VitalSigns::isRespiratoryRateFlagged(11));
        $this->assertFalse(VitalSigns::isRespiratoryRateFlagged(12));
        $this->assertFalse(VitalSigns::isRespiratoryRateFlagged(20));
        $this->assertTrue(VitalSigns::isRespiratoryRateFlagged(21));
    }

    /** Not measured yet (D-65) is not the same as abnormal. */
    public function test_an_unmeasured_respiratory_rate_is_not_flagged(): void
    {
        $this->assertFalse(VitalSigns::isRespiratoryRateFlagged(null));
        $this->assertFalse(VitalSigns::isHeartRateFlagged(null));
    }

    /** BR-13: the numbers come from config, so moving config moves the rule. */
    public function test_the_rules_read_their_thresholds_from_config(): void
    {
        config([
            'healthpass.thresholds.heart_rate_max' => 120,
            'healthpass.thresholds.respiratory_rate_min' => 10,
            'healthpass.thresholds.respiratory_rate_max' => 24,
        ]);

        $this->assertFalse(VitalSigns::isHeartRateFlagged(118));
        $this->assertTrue(VitalSigns::isHeartRateFlagged(121));
        $this->assertFalse(VitalSigns::isRespiratoryRateFlagged(23));
        $this->assertTrue(VitalSigns::isRespiratoryRateFlagged(25));
    }

    // ── scopeFlagged, and everything built on it ─────────────────────────────

    public function test_scope_flagged_returns_an_hr_only_visit(): void
    {
        $hrOnly = $this->makeVisit(['heart_rate_bpm' => 118, 'is_hr_flagged' => true]);
        $this->makeVisit(); // all clear

        $this->assertSame([$hrOnly->id], ClinicVisit::flagged()->pluck('id')->all());
    }

    public function test_scope_flagged_returns_an_rr_only_visit(): void
    {
        $rrOnly = $this->makeVisit(['respiratory_rate' => 26, 'is_rr_flagged' => true]);
        $this->makeVisit(); // all clear

        $this->assertSame([$rrOnly->id], ClinicVisit::flagged()->pluck('id')->all());
    }

    /** A visit tripping both new flags is still ONE row, not two. */
    public function test_a_visit_tripping_both_new_flags_is_listed_once(): void
    {
        $both = $this->makeVisit([
            'heart_rate_bpm' => 118, 'is_hr_flagged' => true,
            'respiratory_rate' => 26, 'is_rr_flagged' => true,
        ]);

        $this->assertSame([$both->id], ClinicVisit::flagged()->pluck('id')->all());
    }

    /**
     * FR-ANL-01 — the Director dashboard's count and preview are the same
     * scope, so both pick the new flags up without their own rule.
     */
    public function test_the_director_dashboard_preview_picks_up_the_new_flags(): void
    {
        $this->makeVisit(['heart_rate_bpm' => 118, 'is_hr_flagged' => true]);

        $response = $this->actingAs($this->director)->get('/director/dashboard')->assertOk();

        $this->assertSame(1, $response->viewData('stats')['flaggedVisits']);
        $this->assertSame(
            ['High Heart Rate — 118 bpm'],
            $response->viewData('flaggedVisits')->first()->vitalSigns->flagDescriptions(),
        );
    }

    /**
     * D-57 — the sidebar's Flagged Anomalies badge counts the same scope,
     * narrowed to what was captured since the Director last opened the page.
     */
    public function test_the_nav_badge_counts_the_new_flags(): void
    {
        $visit = $this->makeVisit(['respiratory_rate' => 26, 'is_rr_flagged' => true]);
        // The badge counts check-ins AFTER the Director's last visit to the
        // page, which for a fresh account is when it was created.
        $visit->update(['checked_in_at' => now()->addMinute()]);

        $this->assertSame(1, NavBadges::forUser($this->director)['director.anomalies']);
    }
}
