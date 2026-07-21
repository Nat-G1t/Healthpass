<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * D-35: a seeded staff account arrives with a one-time password somebody else
 * generated, so it must not be able to use the app until the owner has replaced
 * it through the OTP-confirmed change-password flow (D-20).
 */
class RequirePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, bool $mustChange): User
    {
        $attributes = [
            'role' => $role,
            'status' => 'active',
            'must_change_password' => $mustChange,
        ];

        if ($role === 'college_admin') {
            $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
            $attributes['managed_college_id'] = $college->id;
        }

        return User::factory()->create($attributes);
    }

    public function test_flagged_staff_are_redirected_from_their_dashboard(): void
    {
        $nurse = $this->staff('nurse', mustChange: true);

        $this->actingAs($nurse)
            ->get('/nurse/queue')
            ->assertRedirect(route('password.change'));
    }

    public function test_flagged_staff_may_reach_the_change_password_page(): void
    {
        $nurse = $this->staff('nurse', mustChange: true);

        // Must NOT redirect — otherwise the gate is an infinite loop.
        $this->actingAs($nurse)
            ->get(route('password.change'))
            ->assertOk();
    }

    public function test_flagged_staff_may_still_log_out(): void
    {
        $nurse = $this->staff('nurse', mustChange: true);

        $this->actingAs($nurse)
            ->post('/logout')
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_unflagged_staff_are_unaffected(): void
    {
        $nurse = $this->staff('nurse', mustChange: false);

        $this->actingAs($nurse)
            ->get('/nurse/queue')
            ->assertOk();
    }

    public function test_students_are_never_gated_by_default(): void
    {
        // Students self-register and choose their own password, so the column
        // must default to false for them.
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->assertFalse($student->must_change_password);
    }

    public function test_json_requests_get_a_status_not_a_redirect(): void
    {
        // A background poll must not receive an HTML redirect it would try to
        // parse as JSON.
        $nurse = $this->staff('nurse', mustChange: true);

        $this->actingAs($nurse)
            ->getJson('/nurse/queue')
            ->assertStatus(403);
    }

    public function test_completing_a_password_change_clears_the_flag(): void
    {
        Mail::fake();

        $nurse = $this->staff('nurse', mustChange: true);

        // Drive the real D-20 flow: stage the new password, then confirm the OTP.
        $this->actingAs($nurse)->post(route('password.change.store'), [
            'current_password' => 'password', // factory default
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('password.change.verify'));

        $otp = Mail::sent(OtpVerificationMail::class)
            ->filter(fn (OtpVerificationMail $mail) => $mail->hasTo($nurse->email))
            ->map(fn (OtpVerificationMail $mail) => $mail->otp)
            ->first();

        $this->assertNotNull($otp, 'the change-password flow should have mailed an OTP');

        $this->actingAs($nurse)
            ->post(route('password.change.verify.submit'), ['otp' => $otp])
            ->assertRedirect(route('password.change'));

        $this->assertFalse($nurse->fresh()->must_change_password);

        // And the gate is genuinely open now.
        $this->actingAs($nurse->fresh())->get('/nurse/queue')->assertOk();
    }
}
