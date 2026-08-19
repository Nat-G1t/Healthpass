<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-AUTH-07 — an inactive account cannot use the app, including one that was
 * already signed in when it was deactivated.
 *
 * `LoginRequest` has always refused an inactive account at the door, but that
 * check runs once, at login. Before this middleware nothing re-read
 * `users.status`, so a session opened while the account was active stayed usable
 * until it expired on its own (up to two hours). D-47 turned deactivation into a
 * button the Director presses and the system's only form of removal, so it has
 * to bite on the very next request.
 */
class EnsureAccountIsActiveTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Your account is inactive.';

    private function user(string $role): User
    {
        $attributes = ['role' => $role];

        if ($role === 'college_admin') {
            $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
            $attributes['managed_college_id'] = $college->id;
        }

        return User::factory()->create($attributes);
    }

    // ── Active accounts are untouched ────────────────────────────────────────

    /**
     * The middleware sits on the whole web group, so the cost of getting it
     * wrong is every page for every role.
     */
    public function test_active_users_of_every_role_are_unaffected(): void
    {
        $dashboards = [
            'student' => '/student/dashboard',
            'college_admin' => '/admin/dashboard',
            'nurse' => '/nurse/dashboard',
            'director' => '/director/dashboard',
        ];

        foreach ($dashboards as $role => $url) {
            $this->actingAs($this->user($role))->get($url)->assertOk();
        }
    }

    public function test_a_guest_is_unaffected(): void
    {
        // No user on the request — the middleware must fall straight through
        // rather than redirecting the login page to itself.
        $this->get(route('login'))->assertOk();
    }

    // ── An open session is ended ─────────────────────────────────────────────

    public function test_an_already_signed_in_user_is_logged_out_when_deactivated(): void
    {
        $nurse = $this->user('nurse');

        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();

        // Deactivated mid-session, the way the Director's button does it.
        $nurse->update(['status' => 'inactive']);

        $this->get('/nurse/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_login_page_explains_why_they_were_signed_out(): void
    {
        $admin = $this->user('college_admin');
        $this->actingAs($admin);
        $admin->update(['status' => 'inactive']);

        $this->get('/admin/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, self::MESSAGE));

        $this->get(route('login'))->assertOk()->assertSee(self::MESSAGE);
    }

    public function test_a_deactivated_student_is_logged_out_too(): void
    {
        // Not a staff-only rule: FR-AUTH-07 is on `users`, and a student can be
        // deactivated directly in the database even though D-47's screen won't.
        $student = $this->user('student');
        $this->actingAs($student);
        $student->update(['status' => 'inactive']);

        $this->get('/student/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_reach_any_route_including_profile(): void
    {
        $nurse = $this->user('nurse');
        $this->actingAs($nurse);
        $nurse->update(['status' => 'inactive']);

        foreach (['/nurse/queue', '/profile', route('password.change')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    // ── Background polls get a status, not an HTML redirect ──────────────────

    public function test_json_requests_get_a_403_not_a_redirect(): void
    {
        $nurse = $this->user('nurse');
        $this->actingAs($nurse);
        $nurse->update(['status' => 'inactive']);

        // The Live Queue polls this every 4s; an HTML login page parsed as JSON
        // is the failure mode this branch exists to avoid.
        $this->getJson('/nurse/queue/feed')
            ->assertStatus(403)
            ->assertJson(['message' => 'Your account is inactive. Please contact the clinic for assistance.']);
    }

    // ── Ordering against the D-35 gate ───────────────────────────────────────

    public function test_inactive_beats_must_change_password(): void
    {
        // Both gates apply. The inactive one must win, otherwise a revoked
        // account is invited to set a new password instead of being turned away.
        $nurse = $this->user('nurse');
        $this->actingAs($nurse);
        $nurse->update(['status' => 'inactive', 'must_change_password' => true]);

        $this->get('/nurse/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
