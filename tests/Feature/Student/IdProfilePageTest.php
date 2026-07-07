<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Mail\OtpVerificationMail;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Tests\TestCase;

/**
 * FR-STU-09 — My ID & Profile: kiosk QR (Active badge), read-only fields, edit modal.
 * FR-STU-10 — Late ID linking via the Step 4 capture flow when never linked.
 *
 * Changing the email address is gated behind an OTP sent to the NEW address:
 * the email is only replaced once the student confirms the code; a failed
 * confirmation leaves the email untouched.
 *
 * AC: the QR rendered on My ID scans back to the same qr_token at the kiosk;
 *     student number and sex are not self-editable. College IS editable
 *     (students transfer, FR-STU-09 / D-17) and is validated against colleges.id.
 */
class IdProfilePageTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function college(): College
    {
        // Idempotent so it can be called both to seed the profile and to resolve
        // the default college_id in validPayload() within the same test.
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /**
     * A student whose profile carries the given qr_token. Editable fields use
     * production-shaped values (year_level '1'..'5') so the edit form round-trips.
     */
    private function studentWithProfile(string $qrToken, array $overrides = []): User
    {
        $college = $this->college();
        $user = User::factory()->create([
            'role' => 'student',
            'name' => 'Juan Cruz',
            'email' => 'juan@example.com',
        ]);

        StudentProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'college_id' => $college->id,
            'student_number' => '2023-12345',
            'first_name' => 'Juan',
            'middle_name' => 'Reyes',
            'last_name' => 'Cruz',
            'sex' => 'M',
            'course' => 'BS Information Technology',
            'year_level' => '3',
            'date_of_birth' => '2003-05-15',
            'place_of_birth' => 'Angeles City, Pampanga',
            'civil_status' => 'Single',
            'address' => '123 Rizal St., Angeles City',
            'qr_token' => $qrToken,
        ], $overrides));

        return $user->fresh();
    }

    /** A valid edit payload. Email defaults to the student's own (no email change). */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Reyes',
            'last_name' => 'Cruz',
            'email' => 'juan@example.com',
            'college_id' => $this->college()->id,
            'course' => 'BS Computer Science',
            'year_level' => '4',
            'date_of_birth' => '2003-05-15',
            'place_of_birth' => 'Mabalacat City, Pampanga',
            'civil_status' => 'Single',
            'address' => '456 Bonifacio Ave., Mabalacat City',
        ], $overrides);
    }

    /** Triggers an email change and returns the OTP captured from the faked mail. */
    private function startEmailChange(User $student, string $newEmail): string
    {
        $otp = '';
        Mail::assertSent(OtpVerificationMail::class, function (OtpVerificationMail $mail) use (&$otp, $newEmail) {
            if ($mail->hasTo($newEmail)) {
                $otp = $mail->otp;

                return true;
            }

            return false;
        });

        return $otp;
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student.id-profile'))->assertRedirect(route('login'));
    }

    public function test_non_student_role_is_redirected_to_own_dashboard(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)->get(route('student.id-profile'))->assertRedirect();
    }

    // ── FR-STU-09: linked student sees QR + Active badge ─────────────────────────

    public function test_linked_student_sees_active_badge_and_scannable_qr(): void
    {
        $student = $this->studentWithProfile('2023-12345');

        $response = $this->actingAs($student)->get(route('student.id-profile'));

        $response->assertOk();
        $response->assertSee('Active');
        $response->assertSee('2023-12345');

        // AC: the rendered QR encodes exactly the qr_token (scans back the same).
        $expectedQr = QrCode::format('svg')->size(220)->margin(0)->generate('2023-12345');
        $response->assertSee($expectedQr, false);
    }

    // ── FR-STU-10: unlinked student sees the capture flow ────────────────────────

    public function test_unlinked_student_sees_capture_flow_not_qr(): void
    {
        $student = $this->studentWithProfile(str_repeat('a', 64));

        $response = $this->actingAs($student)->get(route('student.id-profile'));

        $response->assertOk();
        $response->assertSee('Not linked');
        $response->assertSee('Use Camera');
        $response->assertSee('Upload ID Photo');
    }

    // ── FR-STU-09: edit updates only editable fields ─────────────────────────────

    public function test_update_changes_editable_fields_and_syncs_user_name(): void
    {
        $student = $this->studentWithProfile('2023-12345');

        // Email kept the same → no OTP, fields save immediately.
        $response = $this->actingAs($student)->patch(route('student.id-profile.update'), $this->validPayload([
            'first_name' => 'Juancho',
            'last_name' => 'Cruz',
            'course' => 'BS Computer Science',
            'year_level' => '4',
        ]));

        $response->assertRedirect(route('student.id-profile'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'first_name' => 'Juancho',
            'course' => 'BS Computer Science',
            'year_level' => '4',
        ]);

        // users.name kept in sync; email unchanged.
        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Juancho Cruz',
            'email' => 'juan@example.com',
        ]);
    }

    public function test_update_cannot_change_locked_fields(): void
    {
        $student = $this->studentWithProfile('2023-12345');

        // Valid request (college_id is the student's own, so it passes validation),
        // but student_number and sex are forged. They must be dropped, not saved —
        // proving they're locked regardless of what the POST contains.
        $this->actingAs($student)->patch(route('student.id-profile.update'), $this->validPayload([
            'student_number' => '9999-99999',
            'sex' => 'F',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'student_number' => '2023-12345',
            'sex' => 'M',
        ]);
    }

    // ── FR-STU-09: college is editable on transfer ───────────────────────────────

    public function test_update_changes_college_and_reflects_on_profile(): void
    {
        $student = $this->studentWithProfile('2023-12345');
        $newCollege = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $response = $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['college_id' => $newCollege->id])
        );

        $response->assertRedirect(route('student.id-profile'));
        $response->assertSessionHas('status');

        // Live college re-scoped on the profile…
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'college_id' => $newCollege->id,
        ]);

        // …and shown on the My ID page.
        $this->actingAs($student)->get(route('student.id-profile'))
            ->assertOk()
            ->assertSee('College of Engineering and Architecture');
    }

    public function test_update_rejects_invalid_college_id(): void
    {
        $student = $this->studentWithProfile('2023-12345');
        $originalCollegeId = $student->studentProfile->college_id;

        $response = $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['college_id' => 999]) // no such college
        );

        $response->assertSessionHasErrors('college_id');

        // College left untouched — a forged id can't re-scope the student.
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'college_id' => $originalCollegeId,
        ]);
    }

    public function test_update_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $student = $this->studentWithProfile('2023-12345');

        $response = $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'taken@example.com'])
        );

        $response->assertSessionHasErrors('email');
    }

    // ── Email change OTP flow ────────────────────────────────────────────────────

    public function test_changing_email_does_not_apply_immediately_and_sends_otp(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $response = $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com', 'course' => 'BS Data Science'])
        );

        // Redirected to the verify screen; OTP mailed to the NEW address.
        $response->assertRedirect(route('student.id-profile.verify-email'));
        Mail::assertSent(OtpVerificationMail::class, fn (OtpVerificationMail $m) => $m->hasTo('new@example.com'));

        // Email NOT yet changed; the other fields ARE saved.
        $this->assertDatabaseHas('users', ['id' => $student->id, 'email' => 'juan@example.com']);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id, 'course' => 'BS Data Science']);
    }

    public function test_correct_otp_applies_new_email(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com'])
        );
        $otp = $this->startEmailChange($student, 'new@example.com');

        $response = $this->actingAs($student)->post(route('student.id-profile.verify-email.submit'), ['otp' => $otp]);

        $response->assertRedirect(route('student.id-profile'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['id' => $student->id, 'email' => 'new@example.com']);
        $this->assertNotNull($student->fresh()->email_verified_at);
    }

    public function test_wrong_otp_keeps_email_and_reports_remaining_attempts(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com'])
        );

        $response = $this->actingAs($student)->post(route('student.id-profile.verify-email.submit'), ['otp' => '000000']);

        $response->assertSessionHasErrors('otp');
        // Email unchanged; change still pending.
        $this->assertDatabaseHas('users', ['id' => $student->id, 'email' => 'juan@example.com']);
        $this->actingAs($student)->get(route('student.id-profile.verify-email'))->assertOk();
    }

    public function test_email_change_fails_after_five_wrong_attempts(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com'])
        );
        $correctOtp = $this->startEmailChange($student, 'new@example.com');
        $wrongOtp = $correctOtp === '111111' ? '222222' : '111111';

        // Burn all five attempts with the wrong code.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($student)->post(
                route('student.id-profile.verify-email.submit'),
                ['otp' => $wrongOtp]
            );
        }

        // Final attempt surfaces a failure message and discards the pending change.
        $response->assertRedirect(route('student.id-profile'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $student->id, 'email' => 'juan@example.com']);

        // No pending change remains → verify screen redirects away.
        $this->actingAs($student)->get(route('student.id-profile.verify-email'))
            ->assertRedirect(route('student.id-profile'));
    }

    public function test_cancel_email_change_discards_pending(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com'])
        );

        $this->actingAs($student)->post(route('student.id-profile.verify-email.cancel'))
            ->assertRedirect(route('student.id-profile'));

        $this->assertDatabaseHas('users', ['id' => $student->id, 'email' => 'juan@example.com']);
        $this->actingAs($student)->get(route('student.id-profile.verify-email'))
            ->assertRedirect(route('student.id-profile'));
    }

    public function test_verify_screen_redirects_when_no_pending_change(): void
    {
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->get(route('student.id-profile.verify-email'))
            ->assertRedirect(route('student.id-profile'));
    }

    // ── Resend cooldown (Part C — 60s, server-enforced) ──────────────────────────

    public function test_early_resend_is_rejected_during_the_cooldown(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com'])
        );

        // Within 60s of the first send — rejected server-side, nothing re-sent.
        $this->actingAs($student)->post(route('student.id-profile.verify-email.resend'))
            ->assertSessionHasErrors('otp');

        $this->assertCount(1, Mail::sent(OtpVerificationMail::class));
    }

    public function test_resend_is_allowed_after_the_cooldown_elapses(): void
    {
        Mail::fake();
        $student = $this->studentWithProfile('2023-12345');

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->validPayload(['email' => 'new@example.com'])
        );

        $this->travel(61)->seconds();

        $this->actingAs($student)->post(route('student.id-profile.verify-email.resend'))
            ->assertRedirect(route('student.id-profile.verify-email'))
            ->assertSessionHas('status');

        $this->assertCount(2, Mail::sent(OtpVerificationMail::class));
    }

    // ── FR-STU-10: late linking ──────────────────────────────────────────────────

    public function test_link_id_with_matching_digits_binds_qr_token(): void
    {
        $student = $this->studentWithProfile(str_repeat('a', 64));

        $response = $this->actingAs($student)->post(route('student.id-profile.link-id'), [
            'id_number' => '2023-12345',
        ]);

        $response->assertRedirect(route('student.id-profile'));
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'qr_token' => '2023-12345',
        ]);
    }

    public function test_link_id_with_mismatched_digits_is_rejected(): void
    {
        $token = str_repeat('a', 64);
        $student = $this->studentWithProfile($token);

        $response = $this->actingAs($student)->post(route('student.id-profile.link-id'), [
            'id_number' => '2099-00000',
        ]);

        $response->assertSessionHasErrors('id_number');
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'qr_token' => $token,
        ]);
    }
}
