<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\User;
use App\Support\BatchResults;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBatchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Guards the DEV batch seed on SQLite (the suite's engine) — the CCS / CIT
 * demo rewritten for the 2026-10-05 re-seed.
 *
 * Things this has to keep true, because each has bitten a seeder in this
 * project before:
 *
 *  1. a fresh seed must not crash, and a second `db:seed` must not double it;
 *  2. its reference bands stay clear of DemoClinicVisitSeeder's APT-2026-9xxx /
 *     HP-2026-90xx, whose idempotency guards would make it skip itself;
 *  3. no clinic day in the PAST is left holding a captured visit the clinic
 *     never encoded — that is what made old batches read "Still in progress"
 *     for ever (D-111). The one past batch that reads it does so honestly: its
 *     missed students are booked for a 2nd chance still to come (D-110).
 *
 * The clock is pinned to mid-morning, so today's batch is In progress.
 */
class DemoBatchSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 10:30:00'));
        $this->seed(DatabaseSeeder::class);
    }

    private function batch(string $seq): BatchRequest
    {
        return BatchRequest::where('reference_no', 'BR-2026-'.$seq)
            ->with(BatchResults::statusRelations())
            ->firstOrFail();
    }

    public function test_every_scenario_reads_the_status_it_was_built_for(): void
    {
        $expected = [
            '911' => 'completed',
            '912' => 'finished_incomplete',
            '913' => 'no_one_attended',
            '914' => 'still_in_progress',
            '915' => 'completed',
            '921' => 'in_progress',
            '922' => 'in_progress',
            '931' => 'scheduled',
            '934' => 'scheduled',
        ];

        foreach ($expected as $seq => $status) {
            // Numeric-string keys come back as ints, hence the cast.
            $this->assertSame($status, $this->batch((string) $seq)->resultsStatus(), "BR-2026-{$seq}");
        }

        $this->assertSame(4, BatchRequest::where('status', 'pending')->where('reference_no', 'like', 'BR-2026-94%')->count());
        $this->assertSame('rejected', $this->batch('951')->status);
        $this->assertSame('cancelled', $this->batch('962')->status);
    }

    public function test_no_past_clinic_day_holds_a_visit_the_clinic_never_encoded(): void
    {
        $this->assertSame(0, ClinicVisit::where('status', 'captured')->where('checked_in_at', '<', today())->count());
    }

    public function test_todays_batch_has_every_stage_of_a_clinic_day(): void
    {
        $progress = $this->batch('921')->batchRequestStudents
            ->map(fn ($row) => $row->appointment->clearanceProgress())
            ->countBy()->all();

        $this->assertSame(['completed' => 12, 'in_clinic' => 6, 'rest_retake' => 2, 'scheduled' => 10], collect($progress)->sortKeys()->all());
    }

    public function test_the_full_day_fills_every_hour_to_capacity(): void
    {
        $date = $this->batch('934')->scheduled_date->toDateString();
        $perHour = Appointment::whereDate('scheduled_date', $date)->where('status', '!=', 'cancelled')
            ->get()->countBy('scheduled_time');

        $this->assertCount(8, $perHour);   // D-122: eight hours, 8 AM–4 PM
        $this->assertTrue($perHour->every(fn (int $count): bool => $count === (int) config('healthpass.hourly_capacity')));
    }

    public function test_a_student_never_holds_two_schedules_on_one_day(): void
    {
        $clash = Appointment::where('status', '!=', 'cancelled')->get()
            ->groupBy(fn (Appointment $appointment): string => $appointment->student_id.'@'.$appointment->scheduled_date->toDateString())
            ->filter(fn ($group): bool => $group->count() > 1);

        $this->assertSame([], $clash->keys()->all());
    }

    public function test_the_release_requests_cover_pending_approved_and_rejected(): void
    {
        $this->assertSame('approved', $this->batch('911')->latestReleaseRequest->status);
        $this->assertSame('pending', $this->batch('912')->latestReleaseRequest->status);
        $this->assertSame('rejected', $this->batch('915')->latestReleaseRequest->status);
    }

    public function test_pending_batches_have_no_appointments(): void
    {
        $pendingIds = BatchRequest::where('status', 'pending')->pluck('id');

        $this->assertSame(0, Appointment::whereIn('batch_request_id', $pendingIds)->count());
    }

    public function test_the_reserved_bands_do_not_collide_with_the_clinic_visit_seeder(): void
    {
        $ownBatchIds = BatchRequest::where('reference_no', 'like', 'BR-2026-9%')->pluck('id');

        $this->assertSame(
            0,
            Appointment::where('reference_no', 'like', 'APT-2026-9%')->whereIn('batch_request_id', $ownBatchIds)->count(),
        );
        $this->assertSame(0, ClinicVisit::whereIn('appointment_id', Appointment::whereIn('batch_request_id', $ownBatchIds)->select('id'))
            ->where('reference_no', 'like', 'HP-2026-9%')->count());

        // And the other seeder still ran — its own bands are intact.
        $this->assertTrue(ClinicVisit::where('reference_no', 'like', 'HP-2026-90%')->exists());
    }

    public function test_seeding_twice_does_not_duplicate(): void
    {
        $before = BatchRequest::count();

        $this->seed(DemoBatchSeeder::class);

        $this->assertSame($before, BatchRequest::count());
    }

    public function test_the_admins_and_the_director_can_open_every_seeded_batch(): void
    {
        $director = User::where('role', 'director')->firstOrFail();

        foreach (BatchRequest::where('reference_no', 'like', 'BR-2026-9%')->with('college')->get() as $batch) {
            $admin = User::where('role', 'college_admin')->where('managed_college_id', $batch->college_id)->firstOrFail();
            $this->actingAs($admin)->get("/admin/batches/{$batch->id}")->assertOk();

            $directorPage = match ($batch->status) {
                'pending' => route('director.batches.review', $batch),
                'approved' => route('director.tracking.results', $batch),
                default => route('director.tracking.show', $batch),
            };
            $this->actingAs($director)->get($directorPage)->assertOk();
        }

        $this->actingAs($director)->get(route('director.tracking.index'))->assertOk();
    }
}
