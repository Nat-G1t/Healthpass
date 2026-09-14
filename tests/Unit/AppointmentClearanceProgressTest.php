<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Appointment::clearanceProgress() and the D-55 absent cutoff.
 *
 * A student with no clinic visit is "absent" once the SERVER clock reaches
 * `healthpass.absent_cutoff` (default 20:00) on the clinic date — before that,
 * on the day itself, they may still turn up and read as "awaiting".
 *
 * Nothing is persisted: setRelation() hands the model its clinic visit (or the
 * lack of one) directly, so each case is only the rule and the clock.
 */
class AppointmentClearanceProgressTest extends TestCase
{
    /** A Thursday. */
    private const CLINIC_DAY = '2026-09-10';

    private function appointment(string $status = 'scheduled', ?ClinicVisit $visit = null): Appointment
    {
        $appointment = new Appointment(['scheduled_date' => self::CLINIC_DAY, 'status' => $status]);
        $appointment->setRelation('clinicVisit', $visit);

        return $appointment;
    }

    private function visit(?string $result): ClinicVisit
    {
        $visit = new ClinicVisit;
        $visit->setRelation('clearanceRecord', $result === null ? null : new ClearanceRecord(['result' => $result]));

        return $visit;
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time));
    }

    public function test_no_visit_is_awaiting_the_day_before(): void
    {
        $this->at('2026-09-09 21:00:00');

        $this->assertSame('awaiting', $this->appointment()->clearanceProgress());
    }

    public function test_no_visit_is_still_awaiting_one_second_before_the_cutoff(): void
    {
        $this->at(self::CLINIC_DAY.' 19:59:59');

        $this->assertSame('awaiting', $this->appointment()->clearanceProgress());
    }

    public function test_no_visit_is_absent_from_the_cutoff_on_the_clinic_day(): void
    {
        $this->at(self::CLINIC_DAY.' 20:00:00');

        $this->assertSame('absent', $this->appointment()->clearanceProgress());
    }

    public function test_no_visit_is_absent_on_any_later_day(): void
    {
        $this->at('2026-09-11 07:00:00');

        $this->assertSame('absent', $this->appointment()->clearanceProgress());
    }

    public function test_the_cutoff_comes_from_config(): void
    {
        config(['healthpass.absent_cutoff' => '18:00']);

        $this->at(self::CLINIC_DAY.' 17:59:59');
        $this->assertSame('awaiting', $this->appointment()->clearanceProgress());

        $this->at(self::CLINIC_DAY.' 18:00:00');
        $this->assertSame('absent', $this->appointment()->clearanceProgress());
    }

    public function test_a_captured_visit_is_in_clinic_even_past_the_cutoff(): void
    {
        $this->at('2026-09-11 07:00:00');

        $this->assertSame('in_clinic', $this->appointment(visit: $this->visit(null))->clearanceProgress());
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
