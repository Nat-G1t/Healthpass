<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D-48 — `users.last_active_at`, the column behind the Director's "Last active"
 * column (FR-AUTH-10).
 *
 * `users.status` says whether an account is allowed in; this says whether anyone
 * is still using it. The interesting behaviour is the write throttling: the
 * stamp must be cheap enough to sit on every request, so it is only written when
 * the stored value is stale.
 */
class RecordLastActiveTest extends TestCase
{
    use RefreshDatabase;

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse', 'last_active_at' => null]);
    }

    public function test_a_request_stamps_last_active_at(): void
    {
        $nurse = $this->nurse();
        $this->assertNull($nurse->last_active_at);

        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();

        $this->assertNotNull($nurse->refresh()->last_active_at);
    }

    public function test_a_guest_request_stamps_nobody(): void
    {
        $this->get(route('login'))->assertOk();

        // Nothing to assert against but the absence of a crash and of writes —
        // the middleware must simply fall through when there is no user.
        $this->assertSame(0, User::whereNotNull('last_active_at')->count());
    }

    public function test_the_stamp_is_not_rewritten_on_every_request(): void
    {
        // The Live Queue polls every four seconds; a write per request would put
        // that load on the database for a column measured in days.
        $nurse = $this->nurse();

        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();
        $first = $nurse->refresh()->last_active_at;

        Carbon::setTestNow(now()->addSeconds(30));
        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();

        $this->assertTrue(
            $first->equalTo($nurse->refresh()->last_active_at),
            'A second request 30s later must not rewrite the stamp.',
        );

        Carbon::setTestNow();
    }

    public function test_the_stamp_moves_once_it_is_stale(): void
    {
        $nurse = $this->nurse();

        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();
        $first = $nurse->refresh()->last_active_at;

        Carbon::setTestNow(now()->addMinutes(5));
        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();

        $this->assertTrue(
            $nurse->refresh()->last_active_at->greaterThan($first),
            'Past the threshold the stamp must advance.',
        );

        Carbon::setTestNow();
    }

    public function test_stamping_does_not_touch_updated_at(): void
    {
        // Loading a page is not a change to the user record, so the write goes
        // through the query builder rather than save().
        $nurse = $this->nurse();
        $updatedAt = $nurse->updated_at;

        Carbon::setTestNow(now()->addMinutes(10));
        $this->actingAs($nurse)->get('/nurse/dashboard')->assertOk();

        $this->assertTrue($updatedAt->equalTo($nurse->refresh()->updated_at));

        Carbon::setTestNow();
    }

    // ── A turned-away request is not "activity" ──────────────────────────────

    public function test_a_deactivated_users_refused_request_does_not_count(): void
    {
        $nurse = $this->nurse();
        $this->actingAs($nurse);
        $nurse->update(['status' => 'inactive', 'last_active_at' => null]);

        $this->get('/nurse/dashboard')->assertRedirect(route('login'));

        // EnsureAccountIsActive runs first and never calls through, so being
        // bounced at the door must not read as the account being used.
        $this->assertNull($nurse->refresh()->last_active_at);
    }

    public function test_a_password_change_gated_request_does_not_count(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $college->id,
            'must_change_password' => true,
            'last_active_at' => null,
        ]);

        $this->actingAs($admin)->get('/admin/dashboard')->assertRedirect(route('password.change'));

        $this->assertNull($admin->refresh()->last_active_at);
    }
}
