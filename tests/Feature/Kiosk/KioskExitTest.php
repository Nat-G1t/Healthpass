<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FR-KSK-16 — discreet staff exit. The corner gesture opens a prompt; a valid
 * NURSE is authenticated and handed off to the queue. Every other case returns
 * the same generic 422 and leaves the request unauthenticated, so the kiosk is
 * never released by a student, wrong password, or non-nurse staff.
 */
class KioskExitTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $status = 'active', string $email = 'staff@healthpass.test'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    public function test_valid_nurse_is_authenticated_and_redirected_to_queue(): void
    {
        $nurse = $this->user('nurse', email: 'nurse@healthpass.test');

        $this->postJson(route('kiosk.exit'), [
            'email' => 'nurse@healthpass.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'redirect' => route('nurse.queue'),
            ]);

        $this->assertAuthenticatedAs($nurse);
    }

    public function test_wrong_password_is_rejected_and_stays_guest(): void
    {
        $this->user('nurse', email: 'nurse@healthpass.test');

        $this->postJson(route('kiosk.exit'), [
            'email' => 'nurse@healthpass.test',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertGuest();
    }

    public function test_non_nurse_staff_cannot_exit(): void
    {
        // A director with valid credentials must NOT unlock the kiosk (nurse-only).
        $this->user('director', email: 'director@healthpass.test');

        $this->postJson(route('kiosk.exit'), [
            'email' => 'director@healthpass.test',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertGuest();
    }

    public function test_student_cannot_exit(): void
    {
        $this->user('student', email: 'student@psu.edu.ph');

        $this->postJson(route('kiosk.exit'), [
            'email' => 'student@psu.edu.ph',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertGuest();
    }

    public function test_inactive_nurse_cannot_exit(): void
    {
        $this->user('nurse', status: 'inactive', email: 'nurse@healthpass.test');

        $this->postJson(route('kiosk.exit'), [
            'email' => 'nurse@healthpass.test',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertGuest();
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->postJson(route('kiosk.exit'), [
            'email' => 'ghost@healthpass.test',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertGuest();
    }
}
