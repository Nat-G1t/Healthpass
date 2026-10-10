<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Http\Middleware\EnsureRole;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\ManualScreeningFixture;
use Tests\TestCase;

/**
 * D-133 — Manual Screening: who may reach it, and who the search finds.
 *
 * Nurse only. The search lists today's scheduled students who have no clinic
 * visit today in ANY status (Q12), matched on name or student ID.
 */
class ManualScreeningAccessTest extends TestCase
{
    use ManualScreeningFixture;
    use RefreshDatabase;

    /** @return Collection<int, int> The appointment ids the nurse's search returns. */
    private function searchIds(string $q): Collection
    {
        return $this->actingAs($this->nurse())
            ->get(route('nurse.manual.index', ['q' => $q]))
            ->assertOk()
            ->viewData('results')
            ->pluck('id');
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_the_nurse_sees_the_manual_screening_button_on_the_live_queue(): void
    {
        $this->actingAs($this->nurse())->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee('data-manual-screening', false)
            ->assertSee(route('nurse.manual.index'), false)
            ->assertSee('Manual Screening');
    }

    public function test_the_physician_has_no_button_and_is_sent_home_from_every_route(): void
    {
        $physician = $this->physician();
        $appointment = $this->scheduledStudent();

        $this->actingAs($physician)->get(route('nurse.queue'))
            ->assertOk()
            ->assertDontSee('data-manual-screening', false)
            ->assertDontSee(route('nurse.manual.index'), false);

        $home = EnsureRole::dashboardFor($physician);
        $this->actingAs($physician)->get(route('nurse.manual.index', ['q' => 'Manual']))->assertRedirect($home);
        $this->actingAs($physician)->get(route('nurse.manual.show', $appointment))->assertRedirect($home);
        $this->actingAs($physician)->post(route('nurse.manual.store', $appointment), $this->clearancePayload())
            ->assertRedirect($home);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_other_roles_are_refused_and_guests_must_sign_in(): void
    {
        $appointment = $this->scheduledStudent();

        foreach (['student', 'college_admin', 'director'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('nurse.manual.index'))->assertRedirect(EnsureRole::dashboardFor($user));
            $this->actingAs($user)->post(route('nurse.manual.store', $appointment), $this->clearancePayload())
                ->assertRedirect(EnsureRole::dashboardFor($user));
        }

        auth()->logout();
        $this->get(route('nurse.manual.index'))->assertRedirect(route('login'));
        $this->assertSame(0, ClinicVisit::count());
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function test_it_finds_a_student_by_name_and_by_student_id(): void
    {
        $target = $this->scheduledStudent(name: 'Andrea Lacson');
        $this->scheduledStudent(name: 'Benjamin Cruz');
        $studentNumber = $target->student->studentProfile->student_number;

        $this->assertEquals([$target->id], $this->searchIds('lacson')->all());
        $this->assertEquals([$target->id], $this->searchIds($studentNumber)->all());
    }

    public function test_it_lists_only_todays_scheduled_students_with_no_visit_today(): void
    {
        $waiting = $this->scheduledStudent(name: 'Pick Me');

        // Every kind of visit today takes the student off the list (Q12) —
        // resting (mid re-check at the kiosk) included.
        foreach (['resting', 'captured', 'encoded'] as $status) {
            $this->queueVisit('clearance', $status)->student->update(['name' => "Pick Me {$status}"]);
        }
        $this->queueVisit('assessment', 'interviewed')->student->update(['name' => 'Pick Me interviewed']);

        // Tomorrow's and a cancelled appointment are not today's open one.
        $this->scheduledStudent(name: 'Pick Me tomorrow', appointment: ['scheduled_date' => today()->addDay()->toDateString()]);
        $this->scheduledStudent(name: 'Pick Me cancelled', appointment: ['status' => 'cancelled']);

        $this->assertEquals([$waiting->id], $this->searchIds('Pick Me')->all());
    }

    public function test_it_includes_a_student_on_their_second_chance_day(): void
    {
        $missed = $this->scheduledStudent(name: 'Second Chance', appointment: ['scheduled_date' => today()->subDay()->toDateString()]);
        $secondChance = Appointment::factory()->medical()->create([
            'student_id' => $missed->student_id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '10:00:00',
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $missed->batch_request_id,
            'rescheduled_from_id' => $missed->id,
        ]);

        $this->assertEquals([$secondChance->id], $this->searchIds('Second Chance')->all());
    }

    public function test_a_search_under_two_characters_lists_nobody(): void
    {
        $this->scheduledStudent(name: 'Xavier');

        $this->assertCount(0, $this->searchIds('X'));
    }

    public function test_results_are_capped_at_twenty(): void
    {
        foreach (range(1, 21) as $n) {
            $this->scheduledStudent(name: "Capped {$n}");
        }

        $this->assertCount(20, $this->searchIds('Capped'));
    }

    public function test_the_results_show_the_batch_form_and_hour(): void
    {
        $appointment = $this->scheduledStudent('assessment', name: 'Row Details');

        $this->actingAs($this->nurse())->get(route('nurse.manual.index', ['q' => 'Row Details']))
            ->assertOk()
            ->assertSee($appointment->batchRequest->reference_no)
            ->assertSee('Medical Assessment')
            ->assertSee('9:00 AM – 10:00 AM')
            ->assertSee('Arrival 9:03 AM')
            ->assertSee(route('nurse.manual.show', $appointment), false);
    }

    // ── The form page ────────────────────────────────────────────────────────

    public function test_the_form_shows_the_cards_for_the_batchs_form(): void
    {
        $clearance = $this->scheduledStudent('clearance', name: 'Clearance Kid');
        $assessment = $this->scheduledStudent('assessment', name: 'Assessment Kid');

        $this->actingAs($this->nurse())->get(route('nurse.manual.show', $clearance))
            ->assertOk()
            ->assertSee('Clearance Kid')
            ->assertSee('Physical Signs Disorder of')
            ->assertSee('Send to physician')
            ->assertDontSee('Personal / Social History')
            ->assertDontSee('Pertinent Physical Examination')
            ->assertDontSee('name="respiratory_rate"', false);

        $this->actingAs($this->nurse())->get(route('nurse.manual.show', $assessment))
            ->assertOk()
            ->assertSee('Personal / Social History')
            ->assertSee('Pertinent Physical Examination')
            ->assertSee('name="respiratory_rate"', false)
            ->assertSee('Submit to physician');
    }

    public function test_the_form_never_offers_fit_or_unfit(): void
    {
        $this->actingAs($this->nurse())->get(route('nurse.manual.show', $this->scheduledStudent('assessment')))
            ->assertOk()
            ->assertDontSee('name="result"', false);
    }

    public function test_the_pregnancy_question_shows_for_a_female_student_only(): void
    {
        $this->actingAs($this->nurse())->get(route('nurse.manual.show', $this->scheduledStudent(sex: 'F')))
            ->assertSee('name="screening[isPregnant]"', false);
        $this->actingAs($this->nurse())->get(route('nurse.manual.show', $this->scheduledStudent(sex: 'M')))
            ->assertDontSee('name="screening[isPregnant]"', false);
    }

    public function test_a_student_already_screened_today_cannot_be_opened(): void
    {
        $appointment = $this->scheduledStudent(name: 'Already There');
        ClinicVisit::create([
            'reference_no' => 'HP-2026-M001',
            'student_id' => $appointment->student_id,
            'college_id' => $appointment->student->studentProfile->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'checked_in_at' => now(),
        ]);

        $this->actingAs($this->nurse())->get(route('nurse.manual.show', $appointment))
            ->assertRedirect(route('nurse.manual.index'))
            ->assertSessionHas('error', 'Already There has just been screened at the kiosk.');
    }

    public function test_an_unknown_appointment_lands_back_on_the_search(): void
    {
        $this->actingAs($this->nurse())->get(route('nurse.manual.show', 999999))
            ->assertRedirect(route('nurse.manual.index'))
            ->assertSessionHas('error', 'That appointment could not be found.');
    }
}
