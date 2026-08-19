<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Staff Accounts (FR-AUTH-10, D-47) — the Director provisions College Admin and
 * Nurse accounts in-app instead of a developer running StaffSeeder on the server.
 *
 * The security half of this file is the point of it: the Director must never be
 * able to mint another director or a student, must never be able to lock
 * themselves out, and the one-time password must exist in readable form exactly
 * once — on the screen that issued it.
 */
class StaffAccountTest extends TestCase
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

        $this->director = User::factory()->create([
            'role' => 'director',
            'name' => 'Clinic Director',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function admin(?College $college = null, string $name = 'CCS Administrator'): User
    {
        return User::factory()->create([
            'role' => 'college_admin',
            'name' => $name,
            'managed_college_id' => ($college ?? $this->ccs)->id,
        ]);
    }

    private function nurse(string $name = 'Head Nurse'): User
    {
        return User::factory()->create(['role' => 'nurse', 'name' => $name]);
    }

    /**
     * Every endpoint on this screen, aimed at $target.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function endpoints(User $target): array
    {
        return [
            ['get', route('director.staff.index'), []],
            ['post', route('director.staff.store'), [
                'role' => 'nurse',
                'name' => 'Someone Else',
                'email' => 'someone.else@dhvsu.edu.ph',
            ]],
            ['patch', route('director.staff.status', $target), ['status' => 'inactive']],
            ['patch', route('director.staff.college', $target), ['managed_college_id' => $this->coe->id]],
        ];
    }

    /** Create an account through the endpoint and return [user, plaintext password]. */
    private function createAccount(array $payload): array
    {
        $response = $this->actingAs($this->director)
            ->post(route('director.staff.store'), $payload);

        $response->assertRedirect(route('director.staff.index'));
        $response->assertSessionHas('new_staff_credential');

        $credential = session('new_staff_credential');

        return [User::where('email', $payload['email'])->firstOrFail(), $credential['password']];
    }

    /** An encoded visit + clearance record, so history has something to render. */
    private function encodedVisitFor(User $encoder): ClearanceRecord
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Juan Dela Cruz']);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-9001',
            'student_id' => $student->id,
            'college_id' => $this->ccs->id,
            'course' => 'Bachelor of Science in Information Technology',
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->toDateTimeString(),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        return ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => 'Fit',
            'encoded_at' => now()->toDateTimeString(),
        ]);
    }

    // ── 1. Access control — every endpoint, every wrong role ─────────────────

    public function test_guest_is_redirected_to_login_on_every_endpoint(): void
    {
        $target = $this->admin();

        foreach ($this->endpoints($target) as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)->assertRedirect(route('login'));
        }
    }

    public function test_student_is_refused_on_every_endpoint(): void
    {
        $target = $this->admin();
        $student = User::factory()->create(['role' => 'student']);

        foreach ($this->endpoints($target) as [$method, $url, $payload]) {
            $this->actingAs($student)->{$method}($url, $payload)
                ->assertRedirect('/student/dashboard');
        }
    }

    public function test_nurse_is_refused_on_every_endpoint(): void
    {
        $target = $this->admin();
        $nurse = $this->nurse();

        foreach ($this->endpoints($target) as [$method, $url, $payload]) {
            $this->actingAs($nurse)->{$method}($url, $payload)
                ->assertRedirect('/nurse/dashboard');
        }
    }

    public function test_college_admin_is_refused_on_every_endpoint(): void
    {
        $target = $this->admin(name: 'Another Administrator');
        $admin = $this->admin();

        foreach ($this->endpoints($target) as [$method, $url, $payload]) {
            $this->actingAs($admin)->{$method}($url, $payload)
                ->assertRedirect('/admin/dashboard');
        }
    }

    // ── 2. Creating accounts ─────────────────────────────────────────────────

    public function test_director_can_create_a_college_admin_with_a_college(): void
    {
        [$created] = $this->createAccount([
            'role' => 'college_admin',
            'name' => 'Maria Santos',
            'email' => 'maria.santos@dhvsu.edu.ph',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->assertSame('college_admin', $created->role);
        $this->assertSame($this->ccs->id, $created->managed_college_id);
        $this->assertSame('active', $created->status);
        $this->assertTrue($created->must_change_password);
        // Staff do not verify by email — the Director hands the account over.
        $this->assertNotNull($created->email_verified_at);
    }

    public function test_director_can_create_a_nurse_without_a_college(): void
    {
        [$created] = $this->createAccount([
            'role' => 'nurse',
            'name' => 'Ana Reyes',
            'email' => 'ana.reyes@dhvsu.edu.ph',
        ]);

        $this->assertSame('nurse', $created->role);
        $this->assertNull($created->managed_college_id);
        $this->assertTrue($created->must_change_password);
    }

    public function test_the_new_account_appears_on_the_page(): void
    {
        $this->createAccount([
            'role' => 'nurse',
            'name' => 'Ana Reyes',
            'email' => 'ana.reyes@dhvsu.edu.ph',
        ]);

        $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->assertSee('Ana Reyes')
            ->assertSee('ana.reyes@dhvsu.edu.ph');
    }

    public function test_students_and_directors_are_never_listed(): void
    {
        User::factory()->create(['role' => 'student', 'name' => 'Student Person']);
        User::factory()->create(['role' => 'director', 'name' => 'Second Director']);
        $this->admin(name: 'Listed Administrator');

        $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->assertSee('Listed Administrator')
            ->assertDontSee('Student Person')
            ->assertDontSee('Second Director');
    }

    // ── 3. SECURITY — privilege escalation ───────────────────────────────────

    public function test_posting_role_director_is_rejected(): void
    {
        $this->actingAs($this->director)
            ->post(route('director.staff.store'), [
                'role' => 'director',
                'name' => 'Shadow Director',
                'email' => 'shadow@dhvsu.edu.ph',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'shadow@dhvsu.edu.ph']);
    }

    public function test_posting_role_student_is_rejected(): void
    {
        $this->actingAs($this->director)
            ->post(route('director.staff.store'), [
                'role' => 'student',
                'name' => 'Fake Student',
                'email' => 'fake.student@dhvsu.edu.ph',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'fake.student@dhvsu.edu.ph']);
    }

    public function test_a_director_cannot_be_targeted_by_the_write_endpoints(): void
    {
        $other = User::factory()->create(['role' => 'director', 'name' => 'Second Director']);

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $other), ['status' => 'inactive'])
            ->assertForbidden();

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $other), ['managed_college_id' => $this->ccs->id])
            ->assertForbidden();

        $this->assertSame('active', $other->refresh()->status);
    }

    public function test_a_student_cannot_be_targeted_by_the_write_endpoints(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $student), ['status' => 'inactive'])
            ->assertForbidden();

        $this->assertSame('active', $student->refresh()->status);
    }

    // ── 4. SECURITY — an admin without a college is a dead account ───────────

    public function test_creating_a_college_admin_without_a_college_is_rejected(): void
    {
        $this->actingAs($this->director)
            ->post(route('director.staff.store'), [
                'role' => 'college_admin',
                'name' => 'Homeless Admin',
                'email' => 'homeless.admin@dhvsu.edu.ph',
            ])
            ->assertSessionHasErrors('managed_college_id');

        $this->assertDatabaseMissing('users', ['email' => 'homeless.admin@dhvsu.edu.ph']);
    }

    public function test_a_nurse_cannot_be_given_a_managed_college(): void
    {
        $this->actingAs($this->director)
            ->post(route('director.staff.store'), [
                'role' => 'nurse',
                'name' => 'Scoped Nurse',
                'email' => 'scoped.nurse@dhvsu.edu.ph',
                'managed_college_id' => $this->ccs->id,
            ])
            ->assertSessionHasErrors('managed_college_id');

        $this->assertDatabaseMissing('users', ['email' => 'scoped.nurse@dhvsu.edu.ph']);
    }

    public function test_a_college_admins_college_cannot_be_cleared(): void
    {
        $admin = $this->admin();

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $admin), ['managed_college_id' => null])
            ->assertSessionHasErrors('managed_college_id');

        $this->assertSame($this->ccs->id, $admin->refresh()->managed_college_id);
    }

    // ── 5. Credentials (D-35 reuse) ──────────────────────────────────────────

    public function test_the_new_account_is_forced_to_change_its_password_at_first_login(): void
    {
        [$created, $password] = $this->createAccount([
            'role' => 'college_admin',
            'name' => 'Maria Santos',
            'email' => 'maria.santos@dhvsu.edu.ph',
            'managed_college_id' => $this->ccs->id,
        ]);

        $this->assertTrue($created->must_change_password);

        $this->post('/logout');

        // The one-time password works…
        $this->post('/login', [
            'email' => 'maria.santos@dhvsu.edu.ph',
            'password' => $password,
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($created);

        // …but RequirePasswordChange sends them straight to the change screen
        // instead of their dashboard (D-35, same behaviour as the seeded staff).
        $this->get('/admin/dashboard')->assertRedirect(route('password.change'));
    }

    public function test_the_plaintext_password_is_never_persisted_in_readable_form(): void
    {
        [$created, $password] = $this->createAccount([
            'role' => 'nurse',
            'name' => 'Ana Reyes',
            'email' => 'ana.reyes@dhvsu.edu.ph',
        ]);

        $this->assertNotSame($password, $created->password);
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertDatabaseMissing('users', ['password' => $password]);
    }

    public function test_the_director_has_no_way_to_reset_someone_elses_password(): void
    {
        // Removed deliberately: a Director who can set an account's password can
        // sign in as that person and read their records — the impersonation D-47
        // rules out. Recovery is the account owner's own forgot-password OTP.
        $this->assertFalse(
            Route::has('director.staff.password'),
            'A password-reissue route must not exist on the Staff Accounts screen.',
        );

        $nurse = $this->nurse();
        $originalHash = $nurse->password;

        $this->actingAs($this->director)
            ->post("/director/staff/{$nurse->id}/password")
            ->assertNotFound();

        $this->assertSame($originalHash, $nurse->refresh()->password);
    }

    public function test_the_page_offers_no_password_reset_control(): void
    {
        $this->admin();

        $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->assertDontSee('Reset password');
    }

    // ── 6. Activate / deactivate (FR-AUTH-07) ────────────────────────────────

    public function test_deactivating_a_user_blocks_their_login(): void
    {
        $admin = $this->admin();

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $admin), ['status' => 'inactive'])
            ->assertRedirect(route('director.staff.index'));

        $this->assertSame('inactive', $admin->refresh()->status);

        $this->post('/logout');

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivating_cuts_a_session_that_is_already_open(): void
    {
        $admin = $this->admin();

        // The admin is signed in and working when the Director revokes them.
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $admin), ['status' => 'inactive'])
            ->assertRedirect(route('director.staff.index'));

        // Their next request is refused — deactivation is not merely a block on
        // the NEXT login (EnsureAccountIsActive, FR-AUTH-07). Without this the
        // revoked admin kept full access for the rest of SESSION_LIFETIME.
        $this->actingAs($admin->refresh())
            ->get('/admin/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_deactivated_user_can_be_reactivated(): void
    {
        $admin = $this->admin();
        $admin->update(['status' => 'inactive']);

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $admin), ['status' => 'active'])
            ->assertRedirect(route('director.staff.index'));

        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_the_director_cannot_deactivate_their_own_account(): void
    {
        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $this->director), ['status' => 'inactive'])
            ->assertForbidden();

        $this->assertSame('active', $this->director->refresh()->status);
    }

    public function test_an_unknown_status_value_is_rejected(): void
    {
        $nurse = $this->nurse();

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $nurse), ['status' => 'superuser'])
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $nurse->refresh()->status);
    }

    public function test_the_college_picker_uses_the_app_dialog_not_a_browser_confirm(): void
    {
        // The picker must look like the rest of the app: the same teleported
        // Alpine modal the Log out button uses, never window.confirm().
        $this->admin();

        $response = $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk();

        $response->assertSee('Move to another college?');
        $response->assertSee('x-teleport="body"', escape: false);
        $response->assertSee('role="dialog"', escape: false);
        // A cancelled dialog has to put the dropdown back, or the control would
        // display a college the database does not agree with.
        $response->assertSee('cancel()', escape: false);
        $response->assertDontSee('window.confirm', escape: false);
    }

    public function test_a_nurse_row_has_no_college_picker(): void
    {
        // Nurses are clinic-wide, so there is nothing to reassign and no dialog.
        $this->nurse('Solo Nurse');

        $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->assertDontSee('Move to another college?');
    }

    // ── 7. Reassigning a college ─────────────────────────────────────────────

    public function test_reassigning_a_college_changes_what_the_admin_sees(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertSee('submitted for CCS');

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $admin), ['managed_college_id' => $this->coe->id])
            ->assertRedirect(route('director.staff.index'));

        $this->assertSame($this->coe->id, $admin->refresh()->managed_college_id);

        $this->actingAs($admin)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertSee('submitted for COE')
            ->assertDontSee('submitted for CCS');
    }

    public function test_a_nurse_cannot_be_reassigned_to_a_college(): void
    {
        $nurse = $this->nurse();

        $this->actingAs($this->director)
            ->patch(route('director.staff.college', $nurse), ['managed_college_id' => $this->ccs->id])
            ->assertForbidden();

        $this->assertNull($nurse->refresh()->managed_college_id);
    }

    // ── 8. Deactivation never touches history ────────────────────────────────

    public function test_a_deactivated_nurses_past_clearance_records_still_render(): void
    {
        $departed = $this->nurse('Departed Nurse');
        $record = $this->encodedVisitFor($departed);

        $this->actingAs($this->director)
            ->patch(route('director.staff.status', $departed), ['status' => 'inactive'])
            ->assertRedirect(route('director.staff.index'));

        // The clearance record is untouched — encoded_by still points at them.
        $this->assertDatabaseHas('clearance_records', [
            'id' => $record->id,
            'encoded_by' => $departed->id,
        ]);

        // And another nurse still sees the row, with their name on it.
        $this->actingAs($this->nurse('On Duty'))
            ->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSee('Departed Nurse')
            ->assertSee('Juan Dela Cruz');
    }

    // ── 9. Last active (D-48) ────────────────────────────────────────────────

    public function test_an_account_that_has_never_been_seen_reads_never(): void
    {
        // Not backfilled: an account from before the column existed has no
        // honest answer, so it says so rather than inventing a date.
        $this->admin(name: 'Dormant Administrator');

        $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->assertSee('Never');
    }

    public function test_the_page_shows_when_an_account_was_last_seen(): void
    {
        $admin = $this->admin(name: 'Busy Administrator');
        $seenAt = now()->subHours(3);
        $admin->update(['last_active_at' => $seenAt]);

        $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->assertSee($seenAt->format('M j, Y g:i A'));
    }

    // ── 10. Listing order (college admins first, then nurses, names A–Z) ─────

    public function test_accounts_are_ordered_by_role_then_name(): void
    {
        $this->nurse('Zena Nurse');
        $this->nurse('Abel Nurse');
        $this->admin(name: 'Yolanda Admin');
        $this->admin($this->coe, name: 'Bianca Admin');

        $content = $this->actingAs($this->director)
            ->get(route('director.staff.index'))
            ->assertOk()
            ->getContent();

        $positions = [
            'Bianca Admin' => strpos($content, 'Bianca Admin'),
            'Yolanda Admin' => strpos($content, 'Yolanda Admin'),
            'Abel Nurse' => strpos($content, 'Abel Nurse'),
            'Zena Nurse' => strpos($content, 'Zena Nurse'),
        ];

        $this->assertSame(
            ['Bianca Admin', 'Yolanda Admin', 'Abel Nurse', 'Zena Nurse'],
            array_keys($positions),
            'sanity: expected order is declared above',
        );
        $this->assertTrue($positions['Bianca Admin'] < $positions['Yolanda Admin']);
        $this->assertTrue($positions['Yolanda Admin'] < $positions['Abel Nurse']);
        $this->assertTrue($positions['Abel Nurse'] < $positions['Zena Nurse']);
    }
}
