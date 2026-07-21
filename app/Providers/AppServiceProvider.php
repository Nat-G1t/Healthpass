<?php

namespace App\Providers;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // D-34, hosted internet deploy: tell Laravel which reverse proxy to trust,
        // so X-Forwarded-For / X-Forwarded-Proto are honoured and the app sees the
        // REAL visitor IP and the original https scheme. A "service provider" is
        // Laravel's startup hook — boot() runs after the config files are loaded,
        // which is why this lives here and not in bootstrap/app.php (see the note
        // there). Empty list on the Pi-local shape: no proxy, trust nothing.
        $proxies = TrustedProxies::parse(config('healthpass.trusted_proxies'));

        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }
    }
}
