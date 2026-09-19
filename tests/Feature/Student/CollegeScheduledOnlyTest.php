<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D-61 — students are scheduled only through their college.
 *
 * Self-booking (Book Appointment, its confirmation, the availability JSON and
 * the cancel action) and the My Appointments page are gone: every one of their
 * URLs is a 404, and the dashboard keeps a READ-ONLY Next Appointment card with
 * no way to book or cancel.
 */
class CollegeScheduledOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => 'student']);
    }

    /** A future appointment the way a Director-approved CCS batch leaves it. */
    private function batchAppointment(string $date): Appointment
    {
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $college->id]);

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-001',
            'college_id' => $college->id,
            'requested_by' => $admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => $date,
            'scheduled_date' => $date,
            'requested_time' => '09:00:00',
            'requested_blocks' => 1,
            'status' => 'approved',
        ]);

        return Appointment::factory()->inSlot('09:00:00')->create([
            'student_id' => $this->student->id,
            'scheduled_date' => $date,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);
    }

    // ── The removed pages and endpoints ──────────────────────────────────────

    public function test_every_removed_booking_url_is_a_404_for_a_student(): void
    {
        $appointment = $this->batchAppointment(now()->addDays(3)->toDateString());

        $this->actingAs($this->student);

        $this->get('/student/appointments')->assertNotFound();
        $this->get('/student/appointments/availability?year=2026&month=9')->assertNotFound();
        $this->post('/student/appointments', ['date' => now()->addDay()->toDateString(), 'time' => '09:00:00'])->assertNotFound();
        $this->get("/student/appointments/{$appointment->id}/confirmed")->assertNotFound();
        $this->delete("/student/appointments/{$appointment->id}")->assertNotFound();
        $this->get('/student/my-appointments')->assertNotFound();

        // The DELETE above reached nothing: the appointment is untouched.
        $this->assertSame('scheduled', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_the_removed_route_names_no_longer_exist(): void
    {
        foreach ([
            'student.appointments',
            'student.appointments.availability',
            'student.appointments.store',
            'student.appointments.confirmed',
            'student.appointments.cancel',
            'student.my-appointments',
        ] as $name) {
            $this->assertFalse(Route::has($name), "{$name} should be gone.");
        }
    }

    // ── The dashboard ────────────────────────────────────────────────────────

    public function test_the_dashboard_has_no_book_button_and_no_cancel_form(): void
    {
        $appointment = $this->batchAppointment(now()->addDays(3)->toDateString());

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            // The Next Appointment card is still there, read-only…
            ->assertSee($appointment->reference_no)
            ->assertSee('Booked by College of Computing Studies')
            // …with the one-line notice where Book New Appointment used to be…
            ->assertSee('Your college requests your clinic schedule.')
            // …and no way to book or cancel anything.
            ->assertDontSee('Book New Appointment')
            ->assertDontSee('Book Appointment')
            ->assertDontSee('Cancel appointment')
            ->assertDontSee('/student/appointments', false)
            ->assertDontSee('name="_method" value="DELETE"', false)
            // The sidebar lost both items.
            ->assertDontSee('My Appointments');
    }

    public function test_the_dashboard_empty_state_offers_no_booking_either(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('No upcoming appointment')
            ->assertSee('Your college requests your clinic schedule.')
            ->assertDontSee('Book Appointment')
            ->assertDontSee('Book an appointment');
    }
}
