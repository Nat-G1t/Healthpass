<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-NRS-09 (D-44) — Nurse Dashboard.
 *
 * The nurse's landing page: four stat tiles plus the CLINIC-WIDE encode
 * history (every nurse's encodes, newest first), filterable by month and
 * result, searchable by student name or reference number, paginated.
 *
 * Encoding is what puts a row here: a still-`captured` visit belongs to the
 * Live Queue, not the history.
 */
class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    /** Rows per page — must match DashboardController::PER_PAGE. */
    private const PER_PAGE = 15;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nurse(string $name = 'Nurse On Duty'): User
    {
        return User::factory()->create(['role' => 'nurse', 'name' => $name]);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /**
     * A visit still waiting in the Live Queue — captured, never encoded.
     *
     * @param  array<string, mixed>  $vitals
     */
    private function capturedVisit(string $studentName, ?string $checkedInAt = null, array $vitals = []): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $studentName]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('T###'),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'course' => 'Bachelor of Science in Information Technology',
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => $checkedInAt ?? now()->toDateTimeString(),
        ]);

        VitalSigns::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ], $vitals));

        return $visit;
    }

    /**
     * An ENCODED visit + its clearance record — one row of the history.
     * `encodedAt` doubles as the check-in time so the month a record is
     * encoded in is also a month VisitMonths offers in the picker.
     */
    private function encodedVisit(
        string $studentName,
        User $encoder,
        string $result = 'Fit',
        ?string $encodedAt = null,
        ?string $course = 'Bachelor of Science in Information Technology',
        ?string $printedAt = null,
    ): ClearanceRecord {
        $encodedAt ??= now()->toDateTimeString();

        $visit = $this->capturedVisit($studentName, $encodedAt);
        $visit->update(['status' => 'encoded', 'course' => $course]);

        return ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => $result,
            'encoded_at' => $encodedAt,
            'printed_at' => $printedAt,
        ]);
    }

    // ── 1. Access control (role gate) ─────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('nurse.dashboard'))->assertRedirect(route('login'));
    }

    public function test_student_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->get(route('nurse.dashboard'))
            ->assertRedirect('/student/dashboard');
    }

    public function test_college_admin_is_refused(): void
    {
        $admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->college()->id,
        ]);

        $this->actingAs($admin)
            ->get(route('nurse.dashboard'))
            ->assertRedirect('/admin/dashboard');
    }

    public function test_director_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'director']))
            ->get(route('nurse.dashboard'))
            ->assertRedirect('/director/dashboard');
    }

    public function test_nurse_can_open_the_dashboard(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.dashboard'))
            ->assertOk();
    }

    // ── 2. The dashboard is the nurse's home ──────────────────────────────────

    public function test_dashboard_is_the_nurse_home(): void
    {
        // EnsureRole bounces a wrong-role request to the user's OWN home —
        // for a nurse that is now the dashboard, not the queue.
        $this->actingAs($this->nurse())
            ->get('/student/dashboard')
            ->assertRedirect('/nurse/dashboard');
    }

    // ── 3. History contents ───────────────────────────────────────────────────

    public function test_encoded_visits_are_listed_newest_first(): void
    {
        $nurse = $this->nurse();
        $this->encodedVisit('Oldest Encode', $nurse, encodedAt: now()->subHours(3)->toDateTimeString());
        $this->encodedVisit('Newest Encode', $nurse, encodedAt: now()->subMinutes(5)->toDateTimeString());
        $this->encodedVisit('Middle Encode', $nurse, encodedAt: now()->subHour()->toDateTimeString());

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Newest Encode', 'Middle Encode', 'Oldest Encode']);
    }

    public function test_captured_visits_do_not_appear(): void
    {
        $nurse = $this->nurse();
        $this->capturedVisit('Still Waiting');
        $this->encodedVisit('Already Encoded', $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSee('Already Encoded')
            ->assertDontSee('Still Waiting');
    }

    public function test_empty_history_shows_an_empty_state(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSee('No encoded results yet');
    }

    public function test_program_snapshot_is_shown_and_falls_back_to_a_dash(): void
    {
        $nurse = $this->nurse();
        $this->encodedVisit('With Program', $nurse, course: 'Bachelor of Science in Computer Science');
        // Pre-D-43 visits carry no program snapshot and are never backfilled.
        $this->encodedVisit('No Program', $nurse, course: null);

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSee('Bachelor of Science in Computer Science')
            ->assertSeeInOrder(['No Program', '—']);
    }

    // ── 4. Clinic-wide, with an Encoded-by column ─────────────────────────────

    public function test_history_is_clinic_wide_and_names_the_encoder(): void
    {
        $onShift = $this->nurse('Nurse Alpha');
        $otherNurse = $this->nurse('Nurse Bravo');

        $this->encodedVisit('Bravo Patient', $otherNurse);

        // Nurse Alpha sees Nurse Bravo's encode, attributed to Bravo.
        $this->actingAs($onShift)
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Bravo Patient', 'Nurse Bravo']);
    }

    // ── 5. Filters ────────────────────────────────────────────────────────────

    public function test_month_filter_narrows_the_history(): void
    {
        $nurse = $this->nurse();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(9);

        $this->encodedVisit('This Month Student', $nurse);
        $this->encodedVisit('Last Month Student', $nurse, encodedAt: $lastMonth->toDateTimeString());

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard', ['month' => $lastMonth->format('Y-m')]))
            ->assertOk()
            ->assertSee('Last Month Student')
            ->assertDontSee('This Month Student');
    }

    public function test_result_filter_narrows_the_history(): void
    {
        $nurse = $this->nurse();
        $this->encodedVisit('Fit Student', $nurse, result: 'Fit');
        $this->encodedVisit('Unfit Student', $nurse, result: 'Unfit');

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard', ['result' => 'Unfit']))
            ->assertOk()
            ->assertSee('Unfit Student')
            ->assertDontSee('Fit Student');
    }

    public function test_search_matches_the_student_name(): void
    {
        $nurse = $this->nurse();
        $this->encodedVisit('Maricel Santos', $nurse);
        $this->encodedVisit('Juan Dela Cruz', $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard', ['q' => 'maricel']))
            ->assertOk()
            ->assertSee('Maricel Santos')
            ->assertDontSee('Juan Dela Cruz');
    }

    public function test_search_matches_the_reference_number(): void
    {
        $nurse = $this->nurse();
        $wanted = $this->encodedVisit('Reference Match', $nurse);
        $this->encodedVisit('Other Student', $nurse);

        $reference = $wanted->clinicVisit->reference_no;

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard', ['q' => $reference]))
            ->assertOk()
            ->assertSee($reference)
            ->assertDontSee('Other Student');
    }

    // ── 6. Pagination ─────────────────────────────────────────────────────────

    public function test_history_is_paginated_and_keeps_active_filters(): void
    {
        $nurse = $this->nurse();

        // One full page of Fit results plus one overflow row, and an Unfit row
        // that the filter must keep out of BOTH pages.
        for ($i = 1; $i <= self::PER_PAGE + 1; $i++) {
            $this->encodedVisit("Fit Student {$i}", $nurse, encodedAt: now()->subMinutes($i)->toDateTimeString());
        }
        $this->encodedVisit('Unfit Student', $nurse, result: 'Unfit');

        $response = $this->actingAs($nurse)
            ->get(route('nurse.dashboard', ['result' => 'Fit', 'page' => 2]));

        $response->assertOk()
            // Page 2 holds only the oldest Fit row — the Unfit one is filtered out.
            ->assertSee('Fit Student '.(self::PER_PAGE + 1))
            ->assertDontSee('Unfit Student')
            // withQueryString(): the pager's own links carry the filter along.
            ->assertSee('result=Fit', false);

        $response->assertViewHas('records', function ($records): bool {
            return $records->currentPage() === 2
                && $records->total() === self::PER_PAGE + 1
                && $records->count() === 1;
        });
    }

    // ── 7. Stat tiles ─────────────────────────────────────────────────────────

    public function test_stat_tiles_count_today_this_month_queue_and_flags(): void
    {
        $nurse = $this->nurse();

        $this->encodedVisit('Today One', $nurse, encodedAt: now()->subMinutes(10)->toDateTimeString());
        $this->encodedVisit('Today Two', $nurse, encodedAt: now()->subMinutes(20)->toDateTimeString());
        // Earlier this month, but not today — counts for the month tile only.
        $earlierThisMonth = now()->startOfMonth()->equalTo(now()->startOfDay())
            ? now()->subMonthNoOverflow()->startOfMonth()   // today IS the 1st: no earlier day exists
            : now()->startOfMonth();
        $this->encodedVisit('Earlier Encode', $nurse, encodedAt: $earlierThisMonth->toDateTimeString());

        // Awaiting encode = the Live Queue's captured count. One of them is
        // flagged, so it also counts on the flag tile (flags surface from
        // CAPTURE, FR-ANL-07 — encoding is not required).
        $this->capturedVisit('Waiting One');
        $this->capturedVisit('Waiting Two', vitals: ['bp_systolic' => 150, 'bp_diastolic' => 95, 'is_bp_flagged' => true]);

        $expectedMonth = $earlierThisMonth->isSameMonth(now()) ? 3 : 2;

        $this->actingAs($nurse)
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', function (array $stats) use ($expectedMonth): bool {
                return $stats['encodedToday'] === 2
                    && $stats['encodedMonth'] === $expectedMonth
                    && $stats['awaitingEncode'] === 2
                    && $stats['flaggedMonth'] === 1;
            });
    }
}
