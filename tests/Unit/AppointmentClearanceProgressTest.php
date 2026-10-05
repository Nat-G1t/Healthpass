<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Appointment::clearanceProgress(), the D-55 absent cutoff and D-110's keys.
 *
 * A student with no clinic visit — or only a resting one — is "unattended"
 * once the SERVER clock reaches `healthpass.absent_cutoff` (default 20:00) on
 * the clinic date; before that, on the day itself, they may still turn up and
 * read as "scheduled" ("rescheduled" on a 2nd chance). A captured visit the
 * clinic hasn't encoded reads "in_clinic" whatever the clock says.
 *
 * Nothing is persisted: setRelation() hands the model its clinic visit (or the
 * lack of one) directly, so each case is only the rule and the clock.
 */
class AppointmentClearanceProgressTest extends TestCase
{
    /** A Thursday. */
    private const CLINIC_DAY = '2026-09-10';

    private function appointment(string $status = 'scheduled', ?ClinicVisit $visit = null, bool $secondChance = false): Appointment
    {
        $appointment = new Appointment([
            'scheduled_date' => self::CLINIC_DAY,
            'status' => $status,
            'rescheduled_from_id' => $secondChance ? 1 : null,
        ]);
        $appointment->setRelation('clinicVisit', $visit);

        return $appointment;
    }

    /** A kiosk visit: `captured` (or `resting`), encoded with $result when given. */
    private function visit(?string $result, string $status = 'captured'): ClinicVisit
    {
        $visit = new ClinicVisit(['status' => $result === null ? $status : 'encoded']);
        $visit->setRelation('clearanceRecord', $result === null ? null : new ClearanceRecord(['result' => $result]));

        return $visit;
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time));
    }

    public function test_no_visit_is_scheduled_the_day_before(): void
    {
        $this->at('2026-09-09 21:00:00');

        $this->assertSame('scheduled', $this->appointment()->clearanceProgress());
    }

    public function test_no_visit_is_still_scheduled_one_second_before_the_cutoff(): void
    {
        $this->at(self::CLINIC_DAY.' 19:59:59');

        $this->assertSame('scheduled', $this->appointment()->clearanceProgress());
    }

    public function test_no_visit_is_unattended_from_the_cutoff_on_the_clinic_day(): void
    {
        $this->at(self::CLINIC_DAY.' 20:00:00');

        $this->assertSame('unattended', $this->appointment()->clearanceProgress());
    }

    public function test_no_visit_is_unattended_on_any_later_day(): void
    {
        $this->at('2026-09-11 07:00:00');

        $this->assertSame('unattended', $this->appointment()->clearanceProgress());
    }

    public function test_the_cutoff_comes_from_config(): void
    {
        config(['healthpass.absent_cutoff' => '18:00']);

        $this->at(self::CLINIC_DAY.' 17:59:59');
        $this->assertSame('scheduled', $this->appointment()->clearanceProgress());

        $this->at(self::CLINIC_DAY.' 18:00:00');
        $this->assertSame('unattended', $this->appointment()->clearanceProgress());
    }

    public function test_a_second_chance_with_no_visit_is_rescheduled_before_its_cutoff(): void
    {
        $this->at(self::CLINIC_DAY.' 09:00:00');

        $this->assertSame('rescheduled', $this->appointment(secondChance: true)->clearanceProgress());
    }

    public function test_a_missed_second_chance_is_unattended(): void
    {
        // D-110 / Q6: no 3rd chance — missing the 2nd one is final.
        $this->at(self::CLINIC_DAY.' 20:00:00');

        $this->assertSame('unattended', $this->appointment(secondChance: true)->clearanceProgress());
    }

    public function test_a_resting_visit_is_rest_retake_before_the_cutoff(): void
    {
        $this->at(self::CLINIC_DAY.' 19:59:00');

        $this->assertSame('rest_retake', $this->appointment(visit: $this->visit(null, 'resting'))->clearanceProgress());
    }

    public function test_a_resting_visit_is_unattended_after_the_cutoff(): void
    {
        // D-110: sent to rest and never came back — missed, like a no-show.
        $this->at(self::CLINIC_DAY.' 20:00:00');

        $this->assertSame('unattended', $this->appointment(visit: $this->visit(null, 'resting'))->clearanceProgress());
    }

    public function test_a_captured_visit_is_in_clinic_before_the_cutoff(): void
    {
        $this->at(self::CLINIC_DAY.' 19:59:00');

        $this->assertSame('in_clinic', $this->appointment(visit: $this->visit(null))->clearanceProgress());
    }

    public function test_a_captured_visit_not_encoded_by_the_cutoff_is_still_in_clinic(): void
    {
        // D-110 (was D-86's "did not finish"): the clinic's delay, not the student's.
        $this->at(self::CLINIC_DAY.' 20:00:00');

        $this->assertSame('in_clinic', $this->appointment(visit: $this->visit(null))->clearanceProgress());
    }

    public function test_an_encode_after_the_cutoff_still_reads_completed(): void
    {
        // A student still at the clinic at 8 PM, encoded next morning.
        $this->at('2026-09-11 09:00:00');

        $this->assertSame('completed', $this->appointment(visit: $this->visit('Fit'))->clearanceProgress());
    }

    public function test_an_encoded_visit_is_completed(): void
    {
        $this->at(self::CLINIC_DAY.' 10:00:00');

        $this->assertSame('completed', $this->appointment(visit: $this->visit('Unfit'))->clearanceProgress());
    }

    public function test_a_withdrawn_seat_is_withdrawn_even_past_the_cutoff(): void
    {
        $this->at('2026-09-11 07:00:00');

        $this->assertSame('withdrawn', $this->appointment('cancelled')->clearanceProgress());
    }
}
