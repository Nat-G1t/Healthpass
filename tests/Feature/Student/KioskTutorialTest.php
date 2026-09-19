<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-STU-11 — Kiosk Tutorial page (auth + student role required). The
 * first-booking prompt lived on the booking confirmation screen and went with
 * it (D-61).
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
            ->assertRedirect('/nurse/dashboard');
    }

    public function test_student_can_view_tutorial_page(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.tutorial'))
            ->assertOk()
            ->assertSee('Kiosk Tutorial')
            ->assertSee('Get Started');
    }

    public function test_the_tutorial_no_longer_mentions_walking_in(): void
    {
        // D-61: a student reaches the kiosk only on the day their college scheduled.
        $this->actingAs($this->student())
            ->get(route('student.tutorial'))
            ->assertOk()
            ->assertDontSee('walk-in')
            ->assertSee('the day your college scheduled for you');
    }
}
