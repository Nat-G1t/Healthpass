<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security tests for authentication and student registration.
 *
 * Coverage:
 *   FR-AUTH-03 — role isolation (4 roles × 3 forbidden routes)
 *   FR-REG-03  — duplicate student_number / email produce field-level errors
 *   FR-REG-05  — OTP brute-force lockout at 5 attempts; resend invalidates old code
 *   FR-REG-08  — abandoned wizard leaves no users row and cannot log in
 *   FR-AUTH-07 — inactive account refused with explicit message
 *   CSRF       — VerifyCsrfToken active on all web POST routes
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createCollege(): College
    {
        return College::create(['code' => 'CCS', 'name' => 'College of Computer Studies']);
    }

    /**
     * Returns a full, valid Step-2 registration payload.
     * Override individual keys to exercise specific validation paths.
     */
    private function registrationPayload(College $college, string $email = 'student@psu.edu.ph'): array
    {
        return [
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'student_number' => '2024-00001',
            'college_id' => $college->id,
            'sex' => 'M',
            'course' => 'BS Information Technology',
            'year_level' => '1',
            'date_of_birth' => '2000-06-15',
            'place_of_birth' => 'San Fernando, Pampanga',
            'civil_status' => 'Single',
            'address' => '123 Sample St., San Fernando, Pampanga',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];
    }

    // ── 1. Role Isolation (FR-AUTH-03) ───────────────────────────────────────
    // EnsureRole middleware redirects wrong-role users to their own dashboard.

    public function test_student_cannot_access_non_student_routes(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('student.dashboard'));

        $this->actingAs($student)
            ->get(route('nurse.queue'))
            ->assertRedirect(route('student.dashboard'));

        $this->actingAs($student)
            ->get(route('director.dashboard'))
            ->assertRedirect(route('student.dashboard'));
    }

    public function test_college_admin_cannot_access_non_admin_routes(): void
    {
        $admin = User::factory()->create(['role' => 'college_admin']);

        $this->actingAs($admin)
            ->get(route('student.dashboard'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->get(route('nurse.queue'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->get(route('director.dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_nurse_cannot_access_non_nurse_routes(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->get(route('student.dashboard'))
            ->assertRedirect(route('nurse.queue'));

        $this->actingAs($nurse)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('nurse.queue'));

        $this->actingAs($nurse)
            ->get(route('director.dashboard'))
            ->assertRedirect(route('nurse.queue'));
    }

    public function test_director_cannot_access_non_director_routes(): void
    {
        $director = User::factory()->create(['role' => 'director']);

        $this->actingAs($director)
            ->get(route('student.dashboard'))
            ->assertRedirect(route('director.dashboard'));

        $this->actingAs($director)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('director.dashboard'));

        $this->actingAs($director)
            ->get(route('nurse.queue'))
            ->assertRedirect(route('director.dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        foreach ([
            route('student.dashboard'),
            route('admin.dashboard'),
            route('nurse.queue'),
            route('director.dashboard'),
            route('admin.batches.create'),
            route('admin.batches.index'),
            route('admin.batches.confirmation', 1),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    /**
     * Day-51 extension: the batch-request routes joined the /admin group after
     * the original suite, so re-prove role isolation on each of them for the
     * three non-admin roles (FR-AUTH-03 / FR-ADM-06).
     */
    public function test_non_admin_roles_cannot_reach_admin_batch_routes(): void
    {
        $dashboards = [
            'student' => route('student.dashboard'),
            'nurse' => route('nurse.queue'),
            'director' => route('director.dashboard'),
        ];

        foreach ($dashboards as $role => $home) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('admin.batches.create'))->assertRedirect($home);
            $this->actingAs($user)->get(route('admin.batches.index'))->assertRedirect($home);
            $this->actingAs($user)->get(route('admin.batches.confirmation', 1))->assertRedirect($home);
            $this->actingAs($user)
                ->post(route('admin.batches.store'), ['students' => [1]])
                ->assertRedirect($home);
        }
    }

    // ── 2. Duplicate student_number / email (FR-REG-03) ──────────────────────
    // StoreRegistrationInfoRequest validates unique:student_profiles,student_number
    // and unique:users,email at step 2.

    public function test_duplicate_student_number_returns_field_error(): void
    {
        $college = $this->createCollege();

        // Pre-existing profile occupying '2024-00001'
        StudentProfile::create([
            'user_id' => User::factory()->create(['role' => 'student'])->id,
            'college_id' => $college->id,
            'student_number' => '2024-00001',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'sex' => 'F',
            'course' => 'BS CS',
            'year_level' => '1',
            'date_of_birth' => '2001-03-10',
            'place_of_birth' => 'Angeles City',
            'civil_status' => 'Single',
            'address' => '1 Test St.',
            'qr_token' => Str::random(64),
        ]);

        $this->withSession(['reg.consent_at' => now()->toIso8601String()])
            ->post(route('register.info.store'), $this->registrationPayload($college))
            ->assertSessionHasErrors('student_number');
    }

    public function test_duplicate_email_returns_field_error(): void
    {
        $college = $this->createCollege();
        User::factory()->create(['email' => 'taken@psu.edu.ph']);

        $this->withSession(['reg.consent_at' => now()->toIso8601String()])
            ->post(route('register.info.store'), array_merge(
                $this->registrationPayload($college),
                ['email' => 'taken@psu.edu.ph']
            ))
            ->assertSessionHasErrors('email');
    }

    // ── 3. Abandoned registration (FR-REG-08) ────────────────────────────────
    // No users row is created until OTP is verified in step 3.

    public function test_abandoned_registration_leaves_no_users_row(): void
    {
        $college = $this->createCollege();
        Mail::fake();

        $email = 'abandoned@example.com';

        // Steps 1 and 2 — session only, no DB write
        $this->post(route('register.consent'), ['consent' => '1']);
        $this->post(route('register.info.store'), $this->registrationPayload($college, $email));

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_abandoned_registration_credentials_cannot_log_in(): void
    {
        $college = $this->createCollege();
        Mail::fake();

        $email = 'abandoned2@example.com';
        $password = 'Password123!';

        // Steps 1 and 2 only
        $this->post(route('register.consent'), ['consent' => '1']);
        $this->post(route('register.info.store'), $this->registrationPayload($college, $email));

        // Attempt login — no matching users row exists
        $this->post(route('login'), ['email' => $email, 'password' => $password])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ── 4. OTP brute force / resend (FR-REG-05) ──────────────────────────────

    public function test_otp_locked_after_five_wrong_attempts(): void
    {
        $college = $this->createCollege();
        Mail::fake();

        // Reach step 3 through the full wizard flow
        $this->post(route('register.consent'), ['consent' => '1']);
        $this->post(route('register.info.store'), $this->registrationPayload($college));
        $this->get(route('register.verify')); // controller generates and caches OTP here

        // With the array session driver, each request generates a fresh session ID
        // because no session cookie is carried automatically.  We pin the ID here so
        // the OTP cache key stays consistent across all subsequent POST requests.
        $sessionId = $this->app['session.store']->getId();
        $this->withCookie(config('session.cookie'), $sessionId);

        $cacheKey = 'reg_otp_'.$sessionId;

        // Replace the random OTP with a known hash so we control what "wrong" means
        Cache::put($cacheKey, [
            'hash' => hash('sha256', '654321'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], now()->addMinutes(10));

        // Attempts 1–4: cache stays alive between each
        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('register.verify.submit'), ['otp' => '000000'])
                ->assertSessionHasErrors('otp');

            $this->assertNotNull(
                Cache::get($cacheKey),
                "OTP cache entry must still exist after attempt $i (lockout only at 5)"
            );
        }

        // Attempt 5: cache must be cleared
        $response = $this->post(route('register.verify.submit'), ['otp' => '000000']);
        $response->assertSessionHasErrors('otp');

        $this->assertNull(Cache::get($cacheKey), 'Cache must be cleared after 5 failed attempts');

        $errorMsg = $response->getSession()->get('errors')->first('otp');
        $this->assertStringContainsString('5', $errorMsg, 'Error must reference the 5-attempt limit');
    }

    public function test_otp_resend_invalidates_old_code_and_resets_attempts(): void
    {
        $college = $this->createCollege();
        Mail::fake();

        // Reach step 3
        $this->post(route('register.consent'), ['consent' => '1']);
        $this->post(route('register.info.store'), $this->registrationPayload($college));
        $this->get(route('register.verify'));

        // Pin session ID (same reason as the brute-force test above)
        $sessionId = $this->app['session.store']->getId();
        $this->withCookie(config('session.cookie'), $sessionId);

        $cacheKey = 'reg_otp_'.$sessionId;
        $oldOtp = '111111';
        $oldHash = hash('sha256', $oldOtp);

        Cache::put($cacheKey, [
            'hash' => $oldHash,
            'attempts' => 3, // simulate prior wrong guesses
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], now()->addMinutes(10));

        // Resend: controller calls Cache::forget() then issues a fresh OTP
        $this->post(route('register.verify.resend'))
            ->assertRedirect(route('register.verify'));

        $newEntry = Cache::get($cacheKey);

        $this->assertNotNull($newEntry, 'Resend must issue a new OTP cache entry');
        $this->assertNotSame($oldHash, $newEntry['hash'], 'New OTP hash must differ from old one');
        $this->assertSame(0, $newEntry['attempts'], 'Attempt counter must reset on resend');

        // Submitting the old plaintext now fails — wrong hash
        $this->post(route('register.verify.submit'), ['otp' => $oldOtp])
            ->assertSessionHasErrors('otp');
    }

    // ── 5. Inactive account (FR-AUTH-07) ─────────────────────────────────────

    public function test_inactive_account_is_refused_with_clear_message(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'Password123!',  // 'hashed' cast bcrypts this
            'status' => 'inactive',
        ]);

        $response = $this->post(route('login'), [
            'email' => 'inactive@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $errorMsg = $response->getSession()->get('errors')->first('email');
        $this->assertStringContainsStringIgnoringCase(
            'inactive',
            $errorMsg,
            'Login error must explicitly mention that the account is inactive'
        );
    }

    // ── 6. CSRF active on all web POSTs ──────────────────────────────────────
    //
    // APP_ENV=testing causes runningUnitTests() → true, skipping the CSRF check.
    // The actual class in the web middleware group is ValidateCsrfToken (an alias
    // of VerifyCsrfToken).  We bind it here with runningUnitTests() → false so
    // the real token comparison runs.  Requests sent without _token must get 419.

    public function test_csrf_is_enforced_on_all_post_routes(): void
    {
        $this->app->bind(
            ValidateCsrfToken::class,
            fn ($app) => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            }
        );

        foreach ([
            route('login'),
            route('register.consent'),
            route('register.info.store'),
            route('register.verify.submit'),
            route('register.verify.resend'),
            route('logout'),
        ] as $url) {
            $this->post($url)->assertStatus(419);
        }
    }
}
