<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-STU-11 — Kiosk Tutorial page (auth + student role required) and the
 * first-booking prompt on the booking confirmation screen.
 */
class KioskTutorialTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    // ── Tutorial page access ──────────────────────────────────────────────────

    public function test_guest_is_redirected_from_tutorial_page(): void
    {
        $this->get(route('student.tutorial'))->assertRedirect(route('login'));
    }

    public function test_non_student_is_redirected_from_tutorial_page(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->get(route('student.tutorial'))
            ->assertRedirect('/nurse/queue');
    }

    public function test_student_can_view_tutorial_page(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.tutorial'))
            ->assertOk()
            ->assertSee('Kiosk Tutorial')
            ->assertSee('Get Started');
    }

    // ── First-booking prompt on the confirmation screen ──────────────────────

    public function test_confirmed_page_flags_first_booking(): void
    {
        $student = $this->student();
        $appointment = Appointment::factory()->create(['student_id' => $student->id]);

        $this->actingAs($student)
            ->get(route('student.appointments.confirmed', $appointment))
            ->assertOk()
            ->assertViewHas('isFirstBooking', true)
            ->assertSee('View tutorial');
    }

    public function test_confirmed_page_does_not_flag_a_repeat_booking(): void
    {
        $student = $this->student();
        // A prior appointment (even a cancelled one) means this is not the first.
        Appointment::factory()->cancelled()->create(['student_id' => $student->id]);
        $appointment = Appointment::factory()->create(['student_id' => $student->id]);

        $this->actingAs($student)
            ->get(route('student.appointments.confirmed', $appointment))
            ->assertOk()
            ->assertViewHas('isFirstBooking', false)
            ->assertDontSee('View tutorial');
    }

    public function test_another_students_appointments_do_not_affect_the_flag(): void
    {
        $student = $this->student();
        Appointment::factory()->create(); // someone else's appointment
        $appointment = Appointment::factory()->create(['student_id' => $student->id]);

        $this->actingAs($student)
            ->get(route('student.appointments.confirmed', $appointment))
            ->assertOk()
            ->assertViewHas('isFirstBooking', true);
    }
}
