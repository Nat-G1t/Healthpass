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
            'service_type' => $this->faker->randomElement(['medical', 'dental']),
            'scheduled_date' => now()->addDays($this->faker->numberBetween(1, 30))->toDateString(),
            'status' => 'scheduled',
            'source' => 'self',
            'batch_request_id' => null,
            'created_by' => null,
        ];
    }

    public function medical(): static
    {
        return $this->state(['service_type' => 'medical']);
    }

    public function dental(): static
    {
        return $this->state(['service_type' => 'dental']);
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
}
