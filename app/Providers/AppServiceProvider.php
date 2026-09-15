<?php

namespace App\Providers;

use App\Support\NavBadges;
use App\Support\TrustedProxies;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\View;
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

        // D-57: the sidebar's unread badges. A VIEW COMPOSER is a callback
        // Laravel runs every time the named view renders — here the sidebar
        // layout every signed-in page shares — so the counts reach it without
        // each controller passing them, and no query lives in Blade.
        View::composer('components.layout.sidebar', function (ViewContract $view): void {
            $user = auth()->user();

            $view->with('navBadges', $user === null ? [] : NavBadges::forUser($user));
        });
    }
}
