<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Appointment;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the per-year sequence generator (§5.6 / BR-19). This is the feature
 * test CLAUDE.md requires for the raw SQL (orderByRaw LENGTH) the service uses —
 * it must behave identically on SQLite (this suite) and MySQL (dev/prod).
 */
class ReferenceNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReferenceNumberService
    {
        return app(ReferenceNumberService::class);
    }

    public function test_first_reference_of_the_year_starts_at_one(): void
    {
        $year = now()->year;

        $this->assertSame("APT-{$year}-0001", $this->service()->generateAppointmentRef());
    }

    public function test_next_reference_increments_the_highest_sequence(): void
    {
        $year = now()->year;
        Appointment::factory()->create(['reference_no' => "APT-{$year}-0007"]);

        $this->assertSame("APT-{$year}-0008", $this->service()->generateAppointmentRef());
    }

    /**
     * The regression this service was fixed for: once a year's counter crosses
     * the 4-digit pad width, a lexicographic max() ranks 'APT-YYYY-9999' above
     * 'APT-YYYY-10000' ('9' > '1'), recomputing seq 10000 forever → every mint
     * collides on the unique index. The LENGTH-first ordering must keep counting.
     */
    public function test_sequence_keeps_incrementing_past_the_pad_width(): void
    {
        $year = now()->year;
        Appointment::factory()->create(['reference_no' => "APT-{$year}-9999"]);
        Appointment::factory()->create(['reference_no' => "APT-{$year}-10000"]);

        $next = $this->service()->generateAppointmentRef();

        $this->assertSame("APT-{$year}-10001", $next);
        $this->assertSame(0, Appointment::where('reference_no', $next)->count());
    }

    public function test_sequence_is_scoped_per_year(): void
    {
        $year = now()->year;
        Appointment::factory()->create(['reference_no' => 'APT-'.($year - 1).'-0500']);

        // Last year's rows must not bleed into this year's counter.
        $this->assertSame("APT-{$year}-0001", $this->service()->generateAppointmentRef());
    }
}
