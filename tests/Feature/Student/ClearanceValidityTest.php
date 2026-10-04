<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * D-107 / BR-26 — the student dashboard's Clearance Status card shows how long
 * a Fit result stays valid: six months (config) from the day it was encoded,
 * through that date, "Expired" from the next day. Unfit shows no validity.
 * Display-only — nothing here blocks anything.
 */
class ClearanceValidityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => 'student']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /**
     * An encoded visit whose record was encoded on $encodedAt (NULL = the
     * column is empty, so the card falls back to the record's created_at).
     * The visit and record are created at the CURRENT test time, so travel
     * before calling this to order two visits.
     */
    private function encodedVisit(string $result, ?string $encodedAt, string $refNo = 'HP-2026-V001'): ClearanceRecord
    {
        $visit = ClinicVisit::create([
            'reference_no' => $refNo,
            'student_id' => $this->student->id,
            'college_id' => College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies'])->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);

        return ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => $result,
            'encoded_at' => $encodedAt,
        ]);
    }

    private function dashboard(): TestResponse
    {
        return $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk();
    }

    /** The Clearance Status badge reads exactly $label (Recent Activity never renders a bare "Fit"). */
    private function assertBadge(TestResponse $response, string $label): void
    {
        $this->assertMatchesRegularExpression('#>\s*'.$label.'\s*</span>#', $response->getContent());
    }

    public function test_a_fit_result_shows_the_date_it_is_valid_until(): void
    {
        $this->travelTo('2026-10-04 10:00:00');
        $this->encodedVisit('Fit', '2026-10-04 10:00:00');

        $response = $this->dashboard();

        $this->assertBadge($response, 'Fit');
        $response->assertSee('Valid for 6 months — until Apr 4, 2027');
        $response->assertDontSee('Expired');
    }

    public function test_it_is_still_valid_on_the_last_day(): void
    {
        $this->travelTo('2026-10-04 10:00:00');
        $this->encodedVisit('Fit', '2026-10-04 10:00:00');

        $this->travelTo('2027-04-04 23:30:00');
        $response = $this->dashboard();

        $this->assertBadge($response, 'Fit');
        $response->assertSee('Valid for 6 months — until Apr 4, 2027');
        $response->assertDontSee('Expired');
        $response->assertDontSee('bg-red-100', false);
    }

    public function test_it_is_expired_from_the_next_day(): void
    {
        $this->travelTo('2026-10-04 10:00:00');
        $this->encodedVisit('Fit', '2026-10-04 10:00:00');

        $this->travelTo('2027-04-05 00:05:00');
        $response = $this->dashboard();

        $this->assertBadge($response, 'Expired');
        $response->assertSee('bg-red-100 text-red-700', false);
        $response->assertSee('Expired — was valid until Apr 4, 2027');
        $response->assertDontSee('Valid for 6 months');
    }

    public function test_a_month_end_encode_is_clamped_not_overflowed(): void
    {
        $this->travelTo('2026-08-31 09:00:00');
        $this->encodedVisit('Fit', '2026-08-31 09:00:00');

        $this->dashboard()->assertSee('Valid for 6 months — until Feb 28, 2027');
    }

    public function test_an_unfit_result_shows_no_validity(): void
    {
        $this->travelTo('2026-10-04 10:00:00');
        $this->encodedVisit('Unfit', '2026-10-04 10:00:00');

        // Even a year later an Unfit card never says Valid or Expired.
        $this->travelTo('2027-10-04 10:00:00');
        $response = $this->dashboard();

        $this->assertBadge($response, 'Unfit');
        $response->assertDontSee('Valid for');
        $response->assertDontSee('Expired');
    }

    public function test_a_missing_encoded_at_falls_back_to_created_at(): void
    {
        $this->travelTo('2026-10-04 10:00:00');
        $this->encodedVisit('Fit', null);

        $this->travelTo('2026-12-01 10:00:00');

        $this->dashboard()->assertSee('Valid for 6 months — until Apr 4, 2027');
    }

    public function test_a_newer_fit_after_an_expired_one_decides(): void
    {
        $this->travelTo('2026-01-10 10:00:00');
        $this->encodedVisit('Fit', '2026-01-10 10:00:00', 'HP-2026-V001'); // valid until Jul 10, 2026

        $this->travelTo('2026-09-01 10:00:00');
        $this->encodedVisit('Fit', '2026-09-01 10:00:00', 'HP-2026-V002');

        $this->travelTo('2026-10-04 10:00:00');
        $response = $this->dashboard();

        $this->assertBadge($response, 'Fit');
        $response->assertSee('Valid for 6 months — until Mar 1, 2027');
        $response->assertDontSee('Expired');
    }

    public function test_the_month_count_comes_from_config(): void
    {
        config(['healthpass.clearance_validity_months' => 3]);

        $this->travelTo('2026-10-04 10:00:00');
        $this->encodedVisit('Fit', '2026-10-04 10:00:00');

        $this->dashboard()->assertSee('Valid for 3 months — until Jan 4, 2027');
    }
}
