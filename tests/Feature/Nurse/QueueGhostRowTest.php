<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live Queue "ghost row" (motion pass §6.1b — the reverse-stack flagship).
 *
 * Save & Close flashes `encoded_visit_id` for exactly one request; the queue
 * page then renders the just-encoded visit ONE last time as a `data-leaving`
 * ghost (static "Encoded ✓" chip, never the NEXT row) so the front-end can
 * animate it out. A plain reload must not show it again, and a bogus or
 * not-actually-encoded id in the flash slot must fail safe: no ghost, no error.
 */
class QueueGhostRowTest extends TestCase
{
    use RefreshDatabase;

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A captured visit with vitals + questionnaire, checked in $minutesAgo. */
    private function makeVisit(int $minutesAgo = 5, string $name = 'Ana Cruz'): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? 'Cruz',
            'sex' => 'F',
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('T###'),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'appointment_id' => null,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes($minutesAgo),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
            'last_menstrual_period' => null,
        ]);

        return $visit;
    }

    public function test_queue_after_encode_shows_ghost_row_exactly_once(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $encode = $this->actingAs($nurse)
            ->post(route('nurse.visits.encode.store', $visit), ['result' => 'Fit']);
        $encode->assertRedirect(route('nurse.queue'));
        $encode->assertSessionHas('encoded_visit_id', $visit->id);

        // The redirect target renders the ghost: data-leaving, the static chip,
        // and the visit's reference — even though the visit is now `encoded`
        // and out of the live-queue query.
        $page = $this->actingAs($nurse)->get(route('nurse.queue'));
        $page->assertOk();
        $page->assertSee('data-leaving', false);
        $page->assertSee('Encoded ✓');
        $page->assertSee($visit->reference_no);
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'data-leaving'),
            'The ghost row must render exactly once.',
        );
    }

    public function test_ghost_is_never_the_next_row_and_real_queue_keeps_next(): void
    {
        $nurse = $this->nurse();
        $oldest = $this->makeVisit(minutesAgo: 30, name: 'Bea Santos'); // encoded → ghost
        $waiting = $this->makeVisit(minutesAgo: 10, name: 'Carla Reyes');

        $this->actingAs($nurse)
            ->post(route('nurse.visits.encode.store', $oldest), ['result' => 'Fit'])
            ->assertRedirect(route('nurse.queue'));

        $page = $this->actingAs($nurse)->get(route('nurse.queue'));
        $page->assertOk();

        // The ghost row carries data-leaving but never data-next; the first
        // remaining REAL row is the one promoted to NEXT. (Row attributes are
        // matched per-tr — the page's <style> block also mentions data-next.)
        $content = $page->getContent();
        $this->assertSame(1, substr_count($content, 'data-leaving'));
        $this->assertMatchesRegularExpression(
            '/data-visit-id="'.$waiting->id.'"\s+data-next/',
            $content,
            'NEXT must sit on the first real (still-waiting) row.',
        );
        $this->assertMatchesRegularExpression(
            '/data-visit-id="'.$oldest->id.'"\s+data-leaving/',
            $content,
            'The ghost row must carry data-leaving and must not be NEXT.',
        );
    }

    public function test_plain_reload_does_not_show_the_ghost_again(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->actingAs($nurse)
            ->post(route('nurse.visits.encode.store', $visit), ['result' => 'Fit']);

        // First load consumes the one-request flash…
        $this->actingAs($nurse)->get(route('nurse.queue'))->assertSee('data-leaving', false);

        // …so a reload is a normal queue page: no ghost, no encoded reference.
        $reload = $this->actingAs($nurse)->get(route('nurse.queue'));
        $reload->assertOk();
        $reload->assertDontSee('data-leaving', false);
        $reload->assertDontSee($visit->reference_no);
    }

    public function test_bogus_or_unencoded_flash_id_fails_safe(): void
    {
        $nurse = $this->nurse();

        // Unknown id: page renders normally, no ghost, no error.
        $this->actingAs($nurse)
            ->withSession(['encoded_visit_id' => 999999])
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertDontSee('data-leaving', false);

        // A visit that is still `captured` (never encoded) must not become a
        // ghost either — it is a real queue row, nothing more.
        $visit = $this->makeVisit();
        $page = $this->actingAs($nurse)
            ->withSession(['encoded_visit_id' => $visit->id])
            ->get(route('nurse.queue'));
        $page->assertOk();
        $page->assertDontSee('data-leaving', false);
        $page->assertSee($visit->reference_no); // still a normal waiting row
    }
}
