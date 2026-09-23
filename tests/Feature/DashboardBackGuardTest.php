<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FR-UI-07 — Back on a dashboard asks "Log out?"; guest pages are never stale.
 *
 * The guard itself runs in the browser, so these tests cover the server side:
 * every dashboard renders <x-back-guard> (marker `data-back-guard`), other
 * pages don't, and the guest pages are sent `no-store` so Back re-asks the
 * server and a signed-in user is redirected instead of shown an expired form.
 */
class DashboardBackGuardTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $role): User
    {
        if ($role === 'college_admin') {
            $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

            return User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $college->id]);
        }

        if ($role === 'physician') {
            return User::factory()->physician()->create();
        }

        return User::factory()->create(['role' => $role]);
    }

    /** @return array<string, array{string, string}> */
    public static function dashboards(): array
    {
        return [
            'student' => ['student', 'student.dashboard'],
            'college admin' => ['college_admin', 'admin.dashboard'],
            'nurse' => ['nurse', 'nurse.dashboard'],
            'physician' => ['physician', 'nurse.dashboard'],
            'director' => ['director', 'director.dashboard'],
        ];
    }

    #[DataProvider('dashboards')]
    public function test_every_dashboard_renders_the_back_guard(string $role, string $route): void
    {
        $this->actingAs($this->userFor($role))
            ->get(route($route))
            ->assertOk()
            ->assertSee('data-back-guard', false)
            ->assertSee('open-logout-confirm', false);
    }

    public function test_the_dashboard_with_a_query_string_still_has_the_guard(): void
    {
        $this->actingAs($this->userFor('nurse'))
            ->get(route('nurse.dashboard', ['page' => 2]))
            ->assertOk()
            ->assertSee('data-back-guard', false);
    }

    public function test_a_non_dashboard_page_has_no_back_guard(): void
    {
        $this->actingAs($this->userFor('student'))
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('data-back-guard', false);
    }

    /** @return array<string, array{string}> */
    public static function guestPages(): array
    {
        return [
            'login' => ['/login'],
            'register' => ['/register'],
            'forgot password' => ['/forgot-password'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_guest_pages_are_sent_no_store(string $uri): void
    {
        $cacheControl = (string) $this->get($uri)->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_a_signed_in_user_reaching_login_through_history_is_sent_home(): void
    {
        $this->actingAs($this->userFor('student'))
            ->get('/login')
            ->assertRedirect('/');

        $this->actingAs($this->userFor('student'))
            ->get('/')
            ->assertRedirect(route('student.dashboard'));
    }
}
