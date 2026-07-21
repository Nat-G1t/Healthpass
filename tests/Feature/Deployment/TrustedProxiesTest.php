<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use App\Support\TrustedProxies;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * D-34, hosted internet deploy: the app sits behind a reverse proxy, so the real
 * client IP and the original https scheme only reach Laravel through
 * X-Forwarded-* headers — and only if TRUSTED_PROXIES names the proxy.
 *
 * This is wired in bootstrap/app.php, which runs once at boot and is easy to
 * break silently (a typo'd config key just means "no proxies trusted", with no
 * error anywhere). These tests prove the wiring actually takes effect.
 *
 * Why it matters: without it every visitor's IP reads as the proxy's, so all
 * per-IP throttles (OTP, kiosk POSTs) collapse into one shared bucket, and
 * generated links come out http:// on an https:// site.
 */
class TrustedProxiesTest extends TestCase
{
    /** A throwaway route reporting what the framework believes about a request. */
    private function defineProbeRoute(): void
    {
        Route::get('/_test/client-ip', fn () => [
            'ip' => request()->ip(),
            'secure' => request()->isSecure(),
        ]);
    }

    /** Set TRUSTED_PROXIES and rebuild the app so bootstrap/app.php re-reads it. */
    private function bootWithTrustedProxies(string $value): void
    {
        putenv('TRUSTED_PROXIES='.$value);
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;

        $this->refreshApplication();
        $this->defineProbeRoute();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTrustedProxiesEnv();
        $this->defineProbeRoute();
    }

    protected function tearDown(): void
    {
        $this->clearTrustedProxiesEnv();

        parent::tearDown();
    }

    private function clearTrustedProxiesEnv(): void
    {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }

    // ── The parser ──────────────────────────────────────────────────────────

    public function test_blank_value_trusts_nothing(): void
    {
        $this->assertSame([], TrustedProxies::parse(null));
        $this->assertSame([], TrustedProxies::parse('   '));
    }

    public function test_comma_separated_list_is_split_and_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2', '172.16.0.0/12'],
            TrustedProxies::parse(' 10.0.0.1, 10.0.0.2 ,,172.16.0.0/12 ')
        );
    }

    public function test_wildcard_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never "*"');

        TrustedProxies::parse('*');
    }

    // ── The wiring (bootstrap/app.php) ──────────────────────────────────────

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        // Default Pi-local shape: no proxy in front, so a forged X-Forwarded-For
        // must NOT change the IP the app sees.
        $response = $this->get('/_test/client-ip', [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertOk();
        $this->assertSame('127.0.0.1', $response->json('ip'));
        $this->assertFalse($response->json('secure'));
    }

    public function test_forwarded_headers_are_honoured_from_a_trusted_proxy(): void
    {
        // The test client's own REMOTE_ADDR is 127.0.0.1, so trusting it stands
        // in for the hosted shape's "nginx on the same box" setup.
        $this->bootWithTrustedProxies('127.0.0.1');

        $this->assertSame(['127.0.0.1'], TrustedProxies::parse(config('healthpass.trusted_proxies')));

        $response = $this->get('/_test/client-ip', [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertOk();
        $this->assertSame('203.0.113.9', $response->json('ip'), 'the real visitor IP must survive the proxy');
        $this->assertTrue($response->json('secure'), 'https must be detected so generated links are https');
    }
}
