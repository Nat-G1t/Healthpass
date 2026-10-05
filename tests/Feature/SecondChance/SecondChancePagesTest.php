<?php

declare(strict_types=1);

namespace Tests\Feature\SecondChance;

use App\Models\Appointment;
use App\Models\BatchRequestStudent;
use App\Models\ClinicVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SecondChanceFixture;
use Tests\TestCase;

/**
 * D-110 — what the 2nd chance looks like: the student statuses on the Batch
 * Results popup, the student's Next Appointment card, and the kiosk on the
 * 2nd-chance day.
 */
class SecondChancePagesTest extends TestCase
{
    use RefreshDatabase;
    use SecondChanceFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecondChance();
    }

    // ── Batch Results popup (FR-ADM-12) ─────────────────────────────────────

    public function test_the_popup_shows_each_student_status_with_its_label(): void
    {
        $batch = $this->makeBatch();

        $this->addStudent($batch);                                     // scheduled
        $this->visit($this->addStudent($batch), 'resting');            // rest_retake
        $this->visit($this->addStudent($batch), 'captured');           // in_clinic
        $this->visit($this->addStudent($batch), 'encoded', 'Fit');     // completed

        // A student on a 2nd chance tomorrow, and one whose 2nd chance was
        // yesterday and missed — their roster rows point at those appointments.
        $this->onSecondChance($this->addStudent($batch), $this->day(1));   // rescheduled
        $this->onSecondChance($this->addStudent($batch), $this->day(-1));  // unattended

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 12:00:00'));
        $response = $this->actingAs($this->admin)->get('/admin/batches')->assertOk();

        $statuses = array_column($response->viewData('resultPopups')[$batch->id]['students'], 'status');
        $this->assertSame(
            ['scheduled', 'rest_retake', 'in_clinic', 'completed', 'rescheduled', 'unattended'],
            $statuses,
        );

        foreach (['Scheduled', 'Rescheduled', 'Rest / Retake', 'At the clinic', 'Completed', 'Unattended'] as $label) {
            $response->assertSee($label);
        }
        // Rescheduled is the red `danger` badge (D-107's variant).
        $response->assertSeeInOrder(['bg-red-100 text-red-700', 'rescheduled', 'Rescheduled'], false);

        foreach (['Not yet attended', 'Re-check', 'Did not finish', 'Absent'] as $oldLabel) {
            $response->assertDontSee($oldLabel);
        }
    }

    // ── Student dashboard (FR-STU-01) ───────────────────────────────────────

    public function test_on_the_evening_of_the_missed_day_the_card_shows_the_second_chance(): void
    {
        $original = $this->addStudent($this->makeBatch());

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();
        $secondChance = $this->secondChanceOf($original);

        // Still the missed day: today's appointment has been replaced.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:05:00'));
        $response = $this->actingAs($original->student)->get(route('student.dashboard'))->assertOk();

        $this->assertTrue($response->viewData('nextAppointment')->is($secondChance));
        $response->assertSee('2nd chance');
        $response->assertSee('You missed Oct 5, 2026. This is your last chance.');
        $response->assertSee('2nd chance scheduled');   // Recent Activity
    }

    public function test_a_first_appointment_has_no_second_chance_badge(): void
    {
        $original = $this->addStudent($this->makeBatch());

        $response = $this->actingAs($original->student)->get(route('student.dashboard'))->assertOk();

        $this->assertTrue($response->viewData('nextAppointment')->is($original));
        $response->assertDontSee('This is your last chance.');
        $response->assertDontSee('2nd chance');
    }

    // ── Kiosk on the 2nd-chance day ─────────────────────────────────────────

    public function test_a_student_who_rested_yesterday_gets_a_normal_first_pass_on_the_second_chance(): void
    {
        $batch = $this->makeBatch();
        $original = $this->addStudent($batch);
        $student = $original->student;

        // Day 1: sent to rest with a high blood pressure, never came back.
        $this->kiosk('kiosk.rest', $student->id, ['systolic' => 150, 'diastolic' => 95])->assertOk();
        $resting = ClinicVisit::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('resting', $resting->status);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();
        $secondChance = $this->secondChanceOf($original);

        // Day 2, at the kiosk: a fresh first pass — no re-check screen.
        $this->travelTo(Carbon::parse($this->day(1).' 07:05:00'));
        $identity = $this->postJson(route('kiosk.scan'), ['token' => $student->studentProfile->qr_token])
            ->assertOk()
            ->json('identity');

        $this->assertTrue($identity['hasAppointmentToday']);
        $this->assertArrayNotHasKey('recheck', $identity);
        $this->assertArrayNotHasKey('recheckWaitUntil', $identity);
        $this->assertNull(session('kiosk.resting_visit_id'));

        // Submit links the new visit to the 2nd-chance appointment.
        $this->kiosk('kiosk.submit', $student->id)->assertOk();

        $visit = ClinicVisit::where('student_id', $student->id)->whereKeyNot($resting->id)->firstOrFail();
        $this->assertSame('captured', $visit->status);
        $this->assertSame($secondChance->id, $visit->appointment_id);
        $this->assertSame('resting', $resting->fresh()->status);   // yesterday's row is left alone
        $this->assertSame('in_clinic', $this->rosterAppointment($batch, $student->id)->clearanceProgress());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Give $original a 2nd chance on $date and re-point its roster row, as the job does. */
    private function onSecondChance(Appointment $original, string $date): void
    {
        $secondChance = Appointment::factory()->create([
            'student_id' => $original->student_id,
            'scheduled_date' => $date,
            'scheduled_time' => '07:00:00',
            'status' => 'scheduled',
            'batch_request_id' => $original->batch_request_id,
            'rescheduled_from_id' => $original->id,
        ]);

        BatchRequestStudent::where('appointment_id', $original->id)->update(['appointment_id' => $secondChance->id]);
    }

    /** A kiosk POST as $studentId, bound in the session the way scan/login binds it. */
    private function kiosk(string $route, int $studentId, array $vitals = [])
    {
        return $this->withSession([
            'kiosk.student_id' => $studentId,
            'kiosk.login_method' => 'qr',
        ])->postJson(route($route), [
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['manual', 'manual', 'manual', 'manual'],
            'vitals' => array_replace([
                'height' => 170, 'weight' => 60, 'temperature' => 36.8,
                'systolic' => 118, 'diastolic' => 76, 'heartRate' => 72,
            ], $vitals),
            'screening' => [
                'skin' => true, 'head' => false, 'eyes' => false, 'ears' => false,
                'nose' => false, 'throat' => false, 'chest_lungs' => false, 'heart' => false,
                'abdomen' => false, 'kidney_bladder' => false, 'brain' => false,
                'mental_disorder' => false,
                'details' => ['skin' => 'itchy rash'],
                'isPregnant' => false, 'lastMenstrualPeriod' => null,
            ],
        ]);
    }
}
