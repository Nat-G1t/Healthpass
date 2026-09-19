<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FR-STU-14 (D-51) — My Appointments page.
 *
 * The bug this closes was a UI gap, not a rule gap: Appointment::isSelfCancellable()
 * has always allowed ANY self-booked future appointment to be cancelled, but the
 * only cancel button in the app hung off the dashboard's single Next Appointment
 * row. A student holding three appointments could reach exactly one of them.
 *
 * FR-STU-06 is unchanged and re-asserted here from the new surface: batch
 * appointments stay non-cancellable (D-39) and the day-before cutoff still bites.
 */
class MyAppointmentsPageTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    /**
     * A self-booked, scheduled appointment $daysAhead days out.
     */
    private function upcoming(User $student, int $daysAhead): Appointment
    {
        return Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->addDays($daysAhead)->toDateString(),
            'scheduled_time' => '09:00:00',
            'status' => 'scheduled',
            'source' => 'self',
        ]);
    }

    /**
     * A batch-generated appointment belonging to $college, which is what makes
     * scheduledByLabel() able to name the college.
     */
    private function batchAppointment(User $student, College $college, int $daysAhead = 5): Appointment
    {
        static $seq = 900;

        $date = now()->addDays($daysAhead)->toDateString();

        $director = User::factory()->create(['role' => 'director']);
        $admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $college->id,
        ]);

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => $date,
            'requested_time' => '09:00:00',
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor(1),
            'scheduled_date' => $date,
            'status' => 'approved',
            'reviewed_by' => $director->id,
            'reviewed_at' => now(),
        ]);

        return Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'scheduled_time' => '09:00:00',
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $director->id,
        ]);
    }

    /** The exact markup of one row's hidden cancel form, so ids can't collide as substrings. */
    private function cancelFormFor(Appointment $appointment): string
    {
        return 'id="cancel-appt-'.$appointment->id.'"';
    }

    // ── The gap this page closes ─────────────────────────────────────────────

    public function test_page_lists_every_upcoming_appointment_not_just_the_nearest(): void
    {
        $student = $this->student();

        $soon = $this->upcoming($student, 2);
        $middle = $this->upcoming($student, 9);
        $far = $this->upcoming($student, 20);

        $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->assertSee($soon->reference_no)
            ->assertSee($middle->reference_no)
            ->assertSee($far->reference_no);
    }

    /**
     * The actual defect. Every one of the three rows must carry its own cancel
     * form — before this page only the nearest appointment had one anywhere.
     */
    public function test_every_upcoming_self_booked_row_has_its_own_cancel_control(): void
    {
        $student = $this->student();

        $soon = $this->upcoming($student, 2);
        $far = $this->upcoming($student, 20);

        $response = $this->actingAs($student)->get(route('student.my-appointments'));

        $response->assertOk()
            ->assertSee($this->cancelFormFor($soon), false)
            ->assertSee($this->cancelFormFor($far), false);
    }

    /**
     * The regression guard: cancelling an appointment that is NOT the nearest
     * one must work and must leave the nearer one alone.
     */
    public function test_student_can_cancel_an_appointment_that_is_not_the_nearest(): void
    {
        $student = $this->student();

        $nearest = $this->upcoming($student, 2);
        $furthest = $this->upcoming($student, 20);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $furthest), ['from' => 'list'])
            ->assertRedirect(route('student.my-appointments'));

        $this->assertDatabaseHas('appointments', [
            'id' => $furthest->id,
            'status' => 'cancelled',
        ]);

        // The nearest one is untouched — cancelling is per-row, not "the next one".
        $this->assertDatabaseHas('appointments', [
            'id' => $nearest->id,
            'status' => 'scheduled',
        ]);
    }

    // ── FR-STU-06 / D-39 still holds from the new surface ────────────────────

    public function test_batch_appointment_is_listed_but_has_no_cancel_control(): void
    {
        $student = $this->student();
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $batch = $this->batchAppointment($student, $college);

        $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            // Listed, so the student can see their whole schedule…
            ->assertSee($batch->reference_no)
            // …but with no way to act on it, and told who to ask instead.
            ->assertDontSee($this->cancelFormFor($batch), false)
            ->assertSee('Contact your college');
    }

    public function test_cancelling_a_batch_appointment_from_the_list_is_still_forbidden(): void
    {
        $student = $this->student();
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $batch = $this->batchAppointment($student, $college);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $batch), ['from' => 'list'])
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', [
            'id' => $batch->id,
            'status' => 'scheduled',
        ]);
    }

    // ── Scope of the list ────────────────────────────────────────────────────

    public function test_another_students_appointments_are_never_listed(): void
    {
        $student = $this->student();
        $other = $this->student();

        $mine = $this->upcoming($student, 3);
        $theirs = $this->upcoming($other, 4);

        $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->assertSee($mine->reference_no)
            ->assertDontSee($theirs->reference_no);
    }

    /**
     * The list is strictly-future by design: an appointment dated today is past
     * the FR-STU-06 cancel cutoff anyway and belongs to the dashboard card.
     * Past and cancelled rows are history, which this page does not show.
     */
    public function test_today_past_and_cancelled_appointments_are_excluded(): void
    {
        // Pinned so the test cannot straddle midnight.
        Carbon::setTestNow('2026-09-06 10:00:00');

        $student = $this->student();

        $today = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
            'source' => 'self',
        ]);

        $past = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->subDays(4)->toDateString(),
            'status' => 'completed',
            'source' => 'self',
        ]);

        $cancelled = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->addDays(6)->toDateString(),
            'status' => 'cancelled',
            'source' => 'self',
        ]);

        $future = $this->upcoming($student, 6);

        $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->assertSee($future->reference_no)
            ->assertDontSee($today->reference_no)
            ->assertDontSee($past->reference_no)
            ->assertDontSee($cancelled->reference_no);

        Carbon::setTestNow();
    }

    public function test_empty_state_when_there_is_nothing_upcoming(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->assertSee('No upcoming appointments');
    }

    public function test_guest_cannot_reach_the_page(): void
    {
        $this->get(route('student.my-appointments'))->assertRedirect(route('login'));
    }

    // ── The source label (FR-STU-14) ─────────────────────────────────────────

    public function test_list_labels_a_self_booked_appointment_as_self_scheduled(): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->assertSee('Self-scheduled');
    }

    public function test_list_names_the_college_on_a_batch_appointment(): void
    {
        $student = $this->student();
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->batchAppointment($student, $college);

        $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->assertSee('Booked by College of Computing Studies');
    }

    public function test_dashboard_card_labels_a_self_booked_appointment(): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Self-scheduled');
    }

    /**
     * The case the old card could not express at all: the existing "Booked by
     * your college" note is gated on a FUTURE date, so a batch appointment
     * dated today said nothing about where it came from.
     */
    public function test_dashboard_card_names_the_college_on_a_batch_appointment_dated_today(): void
    {
        $student = $this->student();
        $college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->batchAppointment($student, $college, daysAhead: 0);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Booked by College of Computing Studies');
    }

    // ── Cancel confirm dialog ────────────────────────────────────────────────

    /**
     * Regression: `@js($var)` inside a Blade COMPONENT tag's attribute is not
     * compiled — it renders as the literal text "@js($cancelText)", which makes
     * the whole Alpine @click expression a JavaScript syntax error, so the
     * button silently does nothing. The label must come from a data attribute
     * read at runtime instead (same rule as the college-reassign dialog).
     */
    public function test_cancel_button_carries_a_working_click_handler(): void
    {
        $student = $this->student();
        $appointment = $this->upcoming($student, 4);

        $response = $this->actingAs($student)->get(route('student.my-appointments'));

        $response->assertOk()
            // An uncompiled Blade directive in the output = a broken handler.
            ->assertDontSee('@js(', false)
            ->assertSee('data-cancel-label=', false)
            ->assertSee('cancelText = $el.dataset.cancelLabel', false)
            ->assertSee('cancelId = '.$appointment->id.';', false);
    }

    /**
     * The dialog must be teleported to <body>: `position: fixed` resolves
     * against the nearest ancestor establishing a containing block, and the
     * animated card is one, so an unteleported dialog is laid out inside the
     * card instead of over the screen.
     */
    public function test_cancel_dialog_is_teleported_to_body_on_the_list(): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $html = $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->getContent();

        // Matched as a pair, not just the presence of x-teleport — the sidebar's
        // logout dialog teleports too, so a bare assertSee would pass trivially.
        $this->assertMatchesRegularExpression(
            '/<template x-teleport="body">\s*<div x-show="cancelOpen"/',
            $html,
            'The cancel dialog must be teleported to <body>, or it is laid out inside the card.'
        );
    }

    public function test_cancel_dialog_is_teleported_to_body_on_the_dashboard(): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $html = $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<template x-teleport="body">\s*<div x-show="cancelModal"/',
            $html,
            'The dashboard cancel dialog must be teleported to <body>, or it appears inside card 2.'
        );
    }

    /**
     * The X glyph reads as a close affordance, so it must actually dismiss the
     * dialog — and it must be type="button", or inside the dashboard's panel it
     * would submit the cancel form and destroy the appointment it was meant to
     * leave alone.
     */
    public function test_dialog_x_is_a_real_close_button_on_the_list(): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $html = $this->actingAs($student)
            ->get(route('student.my-appointments'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<button type="button"\s+@click="cancelOpen = false"\s+aria-label="Close"/',
            $html,
            'The dialog X must be a type="button" control that closes the dialog.'
        );
    }

    public function test_dialog_x_is_a_real_close_button_on_the_dashboard(): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $html = $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<button type="button"\s+@click="cancelModal = false"\s+aria-label="Close"/',
            $html,
            'The dialog X must be a type="button" control that closes the dialog.'
        );
    }

    /**
     * `items-end` pinned the panel to the bottom edge on phones, which read as
     * the dialog sliding off screen. Both dialogs centre at every breakpoint.
     */
    private function assertDialogIsCentred(string $routeName): void
    {
        $student = $this->student();
        $this->upcoming($student, 4);

        $html = $this->actingAs($student)->get(route($routeName))->assertOk()->getContent();

        // The dialog's own container class list, isolated from the rest of the page.
        preg_match('/class="fixed inset-0 z-\[60\] flex[^"]*"/', $html, $m);

        $this->assertNotEmpty($m, 'Could not find the dialog container.');
        $this->assertStringContainsString('items-center', $m[0]);
        $this->assertStringNotContainsString('items-end', $m[0]);
    }

    public function test_dialog_is_centred_on_mobile_on_the_list(): void
    {
        $this->assertDialogIsCentred('student.my-appointments');
    }

    public function test_dialog_is_centred_on_mobile_on_the_dashboard(): void
    {
        $this->assertDialogIsCentred('student.dashboard');
    }

    // ── Redirect allow-list ──────────────────────────────────────────────────

    public function test_cancel_without_a_from_value_still_redirects_to_the_dashboard(): void
    {
        $student = $this->student();
        $appointment = $this->upcoming($student, 5);

        $this->actingAs($student)
            ->delete(route('student.appointments.cancel', $appointment))
            ->assertRedirect(route('student.dashboard'));
    }

    /**
     * `from` is matched against a two-value allow-list, never used as a URL —
     * anything unrecognised falls back to the dashboard rather than becoming an
     * open redirect.
     */
    public function test_cancel_ignores_an_unrecognised_from_value(): void
    {
        $student = $this->student();
        $appointment = $this->upcoming($student, 5);

        $this->actingAs($student)
            ->delete(
                route('student.appointments.cancel', $appointment),
                ['from' => 'https://evil.example.com']
            )
            ->assertRedirect(route('student.dashboard'));
    }
}
