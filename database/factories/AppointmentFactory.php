<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;
        $year = now()->year;

        return [
            'reference_no' => sprintf('APT-%d-%04d', $year, $seq),
            'student_id' => User::factory(),
            'service_type' => 'medical',   // D-60: the clinic's only service
            'scheduled_date' => now()->addDays($this->faker->numberBetween(1, 30))->toDateString(),
            'status' => 'scheduled',
            // D-61: every appointment comes from a college batch. A test that
            // needs a legacy pre-D-61 self-booking says 'source' => 'self'.
            'source' => 'batch',
            'batch_request_id' => null,
            'created_by' => null,
        ];
    }

    public function medical(): static
    {
        return $this->state(['service_type' => 'medical']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    public function past(): static
    {
        return $this->state(['scheduled_date' => now()->subDay()->toDateString()]);
    }

    public function onDate(string $date): static
    {
        return $this->state(['scheduled_date' => $date]);
    }

    /**
     * D-37: put the appointment in a one-hour clinic slot ('09:00:00').
     *
     * The default is deliberately NULL — that models the pre-D-37 appointments
     * that belong to no slot, so every test that doesn't opt in also exercises
     * the legacy path.
     */
    public function inSlot(string $slot): static
    {
        return $this->state(['scheduled_time' => $slot]);
    }
}
