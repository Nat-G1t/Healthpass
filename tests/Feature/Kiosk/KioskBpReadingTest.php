<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Http\Controllers\Kiosk\BpReadingController;
use App\Models\College;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * D-58 / FR-KSK-07a — Bluetooth BP readings.
 *
 * The Pi daemon POSTs a reading to /api/kiosk/bp-reading with a shared secret;
 * the kiosk polls /kiosk/bp-reading/latest and claims a new reading into its
 * session. The cache (the array store in tests) holds the reading in flight.
 */
class KioskBpReadingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-kiosk-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['healthpass.kiosk.device_key' => self::KEY]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The daemon's payload, shaped exactly like its fixed contract. */
    private function reading(array $overrides = []): array
    {
        return array_replace([
            'systolic' => 128,
            'diastolic' => 82,
            'mean_arterial' => 97,
            'pulse' => 72,
            'unit' => 'mmHg',
            'taken_at' => '2026-09-15T14:30:05',
            'user_id' => null,
            'raw' => '16800052006100ea07090f0e1e0548000400',
            'entry_method' => 'device_ble',
            'device_model' => 'A&D UA-651BLE',
            'flags' => [
                'body_movement' => false,
                'cuff_too_loose' => false,
                'irregular_pulse' => true,
                'pulse_out_of_range' => false,
                'improper_position' => false,
            ],
            'suspect' => false,
        ], $overrides);
    }

    /**
     * POST like the daemon: a JSON body and the key header, but NO
     * `Accept: application/json` — call() sends a browser-style Accept.
     */
    private function postReading(array $body, ?string $key = self::KEY)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($key !== null) {
            $server['HTTP_X_KIOSK_KEY'] = $key;
        }

        return $this->call('POST', '/api/kiosk/bp-reading', [], [], [], $server, json_encode($body));
    }

    /** Session keys as scan() binds them for a real, active student. */
    private function signedInStudent(): array
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create();

        return ['kiosk.student_id' => $profile->user_id, 'kiosk.login_method' => 'qr'];
    }

    // ── store: the daemon's POST ─────────────────────────────────────────────

    public function test_valid_reading_is_cached_with_a_received_at_stamp(): void
    {
        $this->postReading($this->reading())
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $cached = Cache::get(BpReadingController::CACHE_KEY);
        $this->assertSame(128, $cached['systolic']);
        $this->assertSame(82, $cached['diastolic']);
        $this->assertSame(72, $cached['pulse']);
        $this->assertTrue($cached['flags']['irregular_pulse']); // clinically meaningful: kept
        $this->assertSame('16800052006100ea07090f0e1e0548000400', $cached['raw']);
        $this->assertNotEmpty($cached['received_at']);
        // The device's identity and provenance fields are never trusted or kept.
        $this->assertArrayNotHasKey('user_id', $cached);
        $this->assertArrayNotHasKey('entry_method', $cached);
    }

    /** The api group has no session middleware — and therefore no CSRF check. */
    public function test_the_api_route_runs_without_a_session(): void
    {
        $this->postReading($this->reading())
            ->assertCreated()
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_wrong_key_is_refused_with_403(): void
    {
        $this->postReading($this->reading(), 'not-the-key')->assertForbidden();

        $this->assertNull(Cache::get(BpReadingController::CACHE_KEY));
    }

    public function test_missing_key_header_is_refused_with_403(): void
    {
        $this->postReading($this->reading(), null)->assertForbidden();
    }

    /** Fail closed: an unset server key must not let an empty header through. */
    public function test_unconfigured_key_refuses_everything(): void
    {
        config(['healthpass.kiosk.device_key' => null]);

        $this->postReading($this->reading(), '')->assertForbidden();

        $this->assertNull(Cache::get(BpReadingController::CACHE_KEY));
    }

    public function test_out_of_range_systolic_is_refused_with_a_json_422(): void
    {
        // Like the daemon, no JSON Accept header: still a JSON 422, never a redirect.
        $this->postReading($this->reading(['systolic' => 300]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('systolic');

        $this->assertNull(Cache::get(BpReadingController::CACHE_KEY));
    }

    public function test_systolic_not_above_diastolic_is_refused(): void
    {
        $this->postReading($this->reading(['systolic' => 80, 'diastolic' => 80]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('systolic');
    }

    public function test_optional_fields_may_be_null_and_flags_an_empty_object(): void
    {
        $this->postReading($this->reading([
            'pulse' => null,
            'mean_arterial' => null,
            'taken_at' => null,
            'flags' => new \stdClass,
        ]))->assertCreated();

        $cached = Cache::get(BpReadingController::CACHE_KEY);
        $this->assertNull($cached['pulse']);
        $this->assertSame([], $cached['flags']);
    }

    // ── latest: the kiosk's poll ─────────────────────────────────────────────

    public function test_latest_is_null_when_no_reading_waits(): void
    {
        $this->getJson(route('kiosk.bp-reading.latest'))
            ->assertOk()
            ->assertExactJson(['reading' => null]);
    }

    public function test_latest_returns_only_what_the_screen_needs(): void
    {
        $this->postReading($this->reading(['suspect' => true]))->assertCreated();

        $reading = $this->getJson(route('kiosk.bp-reading.latest'))->assertOk()->json('reading');

        // No flags, no raw bytes, no device model: those stay server-side.
        $this->assertSame(
            ['systolic' => 128, 'diastolic' => 82, 'pulse' => 72, 'suspect' => true],
            Arr::except($reading, 'received_at'),
        );
        $this->assertNotEmpty($reading['received_at']);
    }

    public function test_latest_is_null_once_the_ttl_lapses(): void
    {
        $this->postReading($this->reading())->assertCreated();

        $this->travel(config('healthpass.kiosk.bp_reading_ttl') + 1)->seconds();

        $this->getJson(route('kiosk.bp-reading.latest'))->assertExactJson(['reading' => null]);
    }

    /** A reading is health data: only an allowed kiosk terminal may read it (D-27 gate). */
    public function test_latest_is_refused_off_the_kiosk_network(): void
    {
        $this->postReading($this->reading())->assertCreated();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->getJson(route('kiosk.bp-reading.latest'))
            ->assertForbidden();
    }

    // ── claim: binding the reading to this kiosk session ─────────────────────

    public function test_claim_moves_the_reading_into_the_session(): void
    {
        $receivedAt = $this->postReading($this->reading())->json('received_at');

        $this->withSession($this->signedInStudent())
            ->postJson(route('kiosk.bp-reading.claim'), ['received_at' => $receivedAt])
            ->assertOk()
            ->assertJson(['ok' => true, 'reading' => ['systolic' => 128, 'diastolic' => 82, 'pulse' => 72]])
            ->assertSessionHas(BpReadingController::SESSION_KEY.'.raw', '16800052006100ea07090f0e1e0548000400');

        // Gone from the cache: nobody can poll or claim it again.
        $this->assertNull(Cache::get(BpReadingController::CACHE_KEY));
    }

    public function test_a_reading_can_only_be_claimed_once(): void
    {
        $receivedAt = $this->postReading($this->reading())->json('received_at');

        $this->withSession($this->signedInStudent())
            ->postJson(route('kiosk.bp-reading.claim'), ['received_at' => $receivedAt])
            ->assertOk();

        $this->postJson(route('kiosk.bp-reading.claim'), ['received_at' => $receivedAt])
            ->assertStatus(409);
    }

    public function test_claiming_a_replaced_reading_is_refused_and_keeps_the_newer_one(): void
    {
        $older = $this->postReading($this->reading())->json('received_at');
        $this->postReading($this->reading(['systolic' => 131]))->assertCreated();

        $this->withSession($this->signedInStudent())
            ->postJson(route('kiosk.bp-reading.claim'), ['received_at' => $older])
            ->assertStatus(409);

        $this->assertSame(131, Cache::get(BpReadingController::CACHE_KEY)['systolic']);
    }

    public function test_claim_needs_a_student_signed_in_at_the_kiosk(): void
    {
        $receivedAt = $this->postReading($this->reading())->json('received_at');

        $this->postJson(route('kiosk.bp-reading.claim'), ['received_at' => $receivedAt])
            ->assertStatus(422);

        $this->assertNotNull(Cache::get(BpReadingController::CACHE_KEY));
    }

    public function test_reset_forgets_a_claimed_reading(): void
    {
        $this->withSession([...$this->signedInStudent(), BpReadingController::SESSION_KEY => ['systolic' => 128]])
            ->postJson(route('kiosk.reset'))
            ->assertOk()
            ->assertSessionMissing(BpReadingController::SESSION_KEY);
    }

    /** A new student starts clean: an abandoned session's reading can't carry over. */
    public function test_scan_forgets_a_reading_left_by_an_abandoned_session(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        StudentProfile::factory()->forCollege($college)->create(['qr_token' => '2023123456']);

        $this->withSession([BpReadingController::SESSION_KEY => ['systolic' => 128]])
            ->postJson(route('kiosk.scan'), ['token' => '2023123456'])
            ->assertOk()
            ->assertSessionMissing(BpReadingController::SESSION_KEY);
    }
}
