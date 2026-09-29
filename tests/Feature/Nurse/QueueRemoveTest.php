<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * D-93 — the clinic removes a student who left without being seen from the
 * Live Queue. The visit and its readings are DELETED, and the student's
 * appointment opens again so they can redo the kiosk if they come back.
 */
class QueueRemoveTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A student with an appointment today and a visit in the given status, with vitals + answers. */
    private function makeVisit(string $status = 'captured'): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Juan Dela Cruz']);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '09:00:00',
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('####'),
            'student_id' => $student->id,
            'college_id' => $this->college->id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $status,
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
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
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
            'is_hr_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    private function remove(ClinicVisit $visit, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->nurse)->delete(route('nurse.visits.remove', $visit));
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_nurse_removes_a_waiting_student_and_the_visit_is_deleted(): void
    {
        $visit = $this->makeVisit();

        $this->remove($visit)
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Juan Dela Cruz was removed'));

        $this->assertDatabaseMissing('clinic_visits', ['id' => $visit->id]);
        // Its readings go with it — "delete entirely", no trace.
        $this->assertDatabaseMissing('vital_signs', ['clinic_visit_id' => $visit->id]);
        $this->assertDatabaseMissing('screening_responses', ['clinic_visit_id' => $visit->id]);
    }

    public function test_the_removed_student_leaves_the_queue_feed(): void
    {
        $visit = $this->makeVisit();

        $this->remove($visit);

        $this->actingAs($this->nurse)->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJson(['count' => 0, 'visits' => []]);
    }

    public function test_the_student_can_redo_the_kiosk_afterwards(): void
    {
        $visit = $this->makeVisit();
        $appointmentId = $visit->appointment_id;

        // While the visit waits, the appointment is used up (FR-KSK-03b).
        $this->assertNull(Appointment::todayFor($visit->student_id));

        $this->remove($visit);

        // The appointment itself is untouched, and open again.
        $this->assertSame('scheduled', Appointment::find($appointmentId)->status);
        $this->assertSame($appointmentId, Appointment::todayFor($visit->student_id)?->id);
    }

    public function test_a_physician_can_remove_too(): void
    {
        $visit = $this->makeVisit();
        $physician = User::factory()->create(['role' => 'physician', 'license_number' => '0123456']);

        $this->remove($visit, as: $physician)->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseMissing('clinic_visits', ['id' => $visit->id]);
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_an_encoded_visit_is_never_removed(): void
    {
        $visit = $this->makeVisit('encoded');

        $this->remove($visit)
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('clinic_visits', ['id' => $visit->id]);
        $this->assertDatabaseHas('vital_signs', ['clinic_visit_id' => $visit->id]);
    }

    public function test_a_resting_visit_is_not_in_the_queue_so_it_is_not_removed(): void
    {
        $visit = $this->makeVisit('resting');

        $this->remove($visit)->assertSessionHas('error');

        $this->assertDatabaseHas('clinic_visits', ['id' => $visit->id]);
    }

    public function test_removing_twice_explains_instead_of_erroring(): void
    {
        $visit = $this->makeVisit();

        $this->remove($visit);

        $this->remove($visit)
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('error');
    }

    public function test_the_encode_page_of_a_removed_visit_sends_the_nurse_back_to_the_queue(): void
    {
        $visit = $this->makeVisit();
        $this->remove($visit);

        $this->actingAs($this->nurse)->get(route('nurse.visits.encode', $visit))
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'removed from the queue'));
    }

    public function test_only_clinic_staff_can_remove(): void
    {
        $visit = $this->makeVisit();
        $admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->college->id]);

        $this->remove($visit, as: $admin)->assertRedirect();
        $this->assertDatabaseHas('clinic_visits', ['id' => $visit->id]);

        $this->post('/logout');

        $this->delete(route('nurse.visits.remove', $visit))->assertRedirect('/login');
        $this->assertDatabaseHas('clinic_visits', ['id' => $visit->id]);
        $this->assertSame(0, ClearanceRecord::count());
    }

    // ── The page ─────────────────────────────────────────────────────────────

    public function test_each_queue_row_has_a_remove_button_and_one_confirm_popup(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse)->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('data-queue-remove', false)
            ->assertSee('data-remove-url="'.route('nurse.visits.remove', $visit).'"', false)
            ->assertSee('id="queue-remove-title"', false)
            ->assertSee('Keep in queue');
    }

    public function test_the_feed_carries_the_remove_url_for_poll_added_rows(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse)->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJsonPath('visits.0.remove_url', route('nurse.visits.remove', $visit));
    }
}
