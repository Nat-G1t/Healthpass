<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Jobs\SendStaffAccountCreatedMail;
use App\Jobs\SendStaffTransferredMail;
use App\Mail\StaffAccountCreatedMail;
use App\Mail\StaffTransferredMail;
use App\Models\College;
use App\Models\User;
use App\Support\TransferNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D-50 — the two staff notices (FR-AUTH-10): "your account is ready" when the
 * Director provisions an account, and "you now manage another college" when an
 * admin is transferred, plus the one-time dashboard notice that accompanies the
 * transfer.
 *
 * The security assertion in here is the one that matters most: the welcome
 * email must NEVER carry the one-time password. D-35 and D-47 both turn on that
 * credential existing in readable form exactly once, on the Director's screen.
 */
class StaffNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private College $ccs;

    private College $coe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);
        $this->director = User::factory()->create(['role' => 'director']);
    }

    private function admin(?College $college = null): User
    {
        return User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Maria Santos',
            'managed_college_id' => ($college ?? $this->ccs)->id,
        ]);
    }

    // ── Welcome notice ───────────────────────────────────────────────────────

    public function test_creating_an_account_queues_a_welcome_email(): void
    {
        Queue::fake();

        $this->actingAs($this->director)->post(route('director.staff.store'), [
            'role' => 'college_admin',
            'name' => 'Maria Santos',
            'email' => 'maria.santos@dhvsu.edu.ph',
            'managed_college_id' => $this->ccs->id,
        ])->assertRedirect(route('director.staff.index'));

        $created = User::where('email', 'maria.santos@dhvsu.edu.ph')->firstOrFail();

        Queue::assertPushed(
            SendStaffAccountCreatedMail::class,
            fn (SendStaffAccountCreatedMail $job): bool => $job->staff->is($created),
        );
    }

    public function test_a_nurse_is_welcomed_too(): void
    {
        Queue::fake();

        $this->actingAs($this->director)->post(route('director.staff.store'), [
            'role' => 'nurse',
            'name' => 'Ana Reyes',
            'email' => 'ana.reyes@dhvsu.edu.ph',
        ])->assertRedirect(route('director.staff.index'));

        Queue::assertPushed(SendStaffAccountCreatedMail::class, 1);
    }

    public function test_a_rejected_creation_emails_nobody(): void
    {
        Queue::fake();

        // The escalation attempt from D-47 — no account, so no welcome.
        $this->actingAs($this->director)->post(route('director.staff.store'), [
            'role' => 'director',
            'name' => 'Shadow Director',
            'email' => 'shadow@dhvsu.edu.ph',
        ])->assertSessionHasErrors('role');

        Queue::assertNothingPushed();
    }

    public function test_the_welcome_email_never_contains_the_one_time_password(): void
    {
        // The whole of D-35 and D-47 rests on that credential existing in
        // readable form exactly once, on the Director's screen.
        Mail::fake();

        $this->actingAs($this->director)->post(route('director.staff.store'), [
            'role' => 'college_admin',
            'name' => 'Maria Santos',
            'email' => 'maria.santos@dhvsu.edu.ph',
            'managed_college_id' => $this->ccs->id,
        ]);

        $password = session('new_staff_credential')['password'];
        $this->assertNotEmpty($password);

        $created = User::where('email', 'maria.santos@dhvsu.edu.ph')->firstOrFail();
        $rendered = (new StaffAccountCreatedMail($created))->render();

        $this->assertStringNotContainsString($password, $rendered);
        // And it says so, which is what makes a later "send us your password"
        // mail recognisable as a forgery.
        $this->assertStringContainsString('never sends passwords by email', $rendered);
    }

    public function test_the_welcome_email_carries_a_working_sign_in_link(): void
    {
        $created = $this->admin();

        $rendered = (new StaffAccountCreatedMail($created))->render();

        // Built from route('login'), so it follows APP_URL and needs no edit of
        // its own when the real domain is configured at deployment.
        $this->assertStringContainsString(route('login'), $rendered);
        $this->assertStringContainsString('Maria Santos', $rendered);
        $this->assertStringContainsString('College of Computing Studies', $rendered);
    }

    // ── Transfer notice ──────────────────────────────────────────────────────

    public function test_transferring_an_admin_queues_a_transfer_email(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $admin), ['managed_college_id' => $this->coe->id])
            ->assertRedirect(route('director.staff.index'));

        Queue::assertPushed(
            SendStaffTransferredMail::class,
            fn (SendStaffTransferredMail $job): bool => $job->staff->is($admin)
                && $job->from->is($this->ccs)
                && $job->to->is($this->coe),
        );
    }

    public function test_the_transfer_email_names_both_colleges(): void
    {
        $admin = $this->admin();

        $rendered = (new StaffTransferredMail($admin, $this->ccs, $this->coe))->render();

        $this->assertStringContainsString('College of Computing Studies', $rendered);
        $this->assertStringContainsString('College of Engineering', $rendered);
        $this->assertStringContainsString(route('login'), $rendered);
    }

    public function test_moving_an_admin_to_the_college_they_are_already_on_emails_nobody(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $admin), ['managed_college_id' => $this->ccs->id])
            ->assertRedirect(route('director.staff.index'));

        Queue::assertNothingPushed();
        $this->assertNull(TransferNotice::pull($admin));
    }

    public function test_a_refused_transfer_emails_nobody(): void
    {
        Queue::fake();

        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $nurse), ['managed_college_id' => $this->ccs->id])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    // ── The one-time dashboard notice ────────────────────────────────────────

    public function test_a_transferred_admin_sees_the_notice_once(): void
    {
        $admin = $this->admin();

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $admin), ['managed_college_id' => $this->coe->id]);

        // First visit after the move: the dialog is there.
        $this->actingAs($admin->refresh())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('You have been moved to COE')
            ->assertSee('College of Computing Studies');

        // Second visit: gone. pull() cleared it, so a refresh cannot revive it.
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('You have been moved to');
    }

    public function test_an_untransferred_admin_sees_no_notice(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('You have been moved to');
    }

    public function test_the_notice_is_addressed_to_the_moved_admin_only(): void
    {
        $moved = $this->admin();
        $bystander = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Other Administrator',
            'managed_college_id' => $this->coe->id,
        ]);

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $moved), ['managed_college_id' => $this->coe->id]);

        // Same destination college, but the notice belongs to one account.
        $this->actingAs($bystander)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('You have been moved to');

        $this->actingAs($moved->refresh())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('You have been moved to COE');
    }

    // ── Job behaviour ────────────────────────────────────────────────────────

    public function test_a_deactivated_account_is_not_emailed(): void
    {
        // The Director deactivated them in the minutes the job spent queued.
        // Telling someone their account is ready, when it is not, is worse than
        // saying nothing.
        Mail::fake();

        $staff = $this->admin();
        $staff->update(['status' => 'inactive']);

        (new SendStaffAccountCreatedMail($staff))->handle();

        Mail::assertNothingSent();
    }

    public function test_a_transfer_notice_is_not_sent_if_the_role_changed(): void
    {
        Mail::fake();

        $staff = $this->admin();
        $job = new SendStaffTransferredMail($staff, $this->ccs, $this->coe);

        // No longer a college admin: the message would describe a scope this
        // account no longer has.
        $staff->update(['role' => 'nurse', 'managed_college_id' => null]);

        $job->handle();

        Mail::assertNothingSent();
    }

    public function test_the_welcome_job_sends_to_the_address_on_the_account(): void
    {
        Mail::fake();

        $staff = $this->admin();

        (new SendStaffAccountCreatedMail($staff))->handle();

        Mail::assertSent(
            StaffAccountCreatedMail::class,
            fn (StaffAccountCreatedMail $mail): bool => $mail->hasTo($staff->email),
        );
    }
}
