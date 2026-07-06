<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_name_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame('Test User', $user->refresh()->name);
    }

    public function test_email_cannot_be_changed_via_profile_update(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'hacker@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        // Name updated, but the email field is ignored — no self-service email change.
        $this->assertSame('Test User', $user->name);
        $this->assertSame('original@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_account_deletion_route_is_removed(): void
    {
        $user = User::factory()->create();

        // HealthPass has no self-service account deletion. /profile still exists for
        // GET/PATCH, so DELETE is method-not-allowed rather than 404.
        $this
            ->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }
}
