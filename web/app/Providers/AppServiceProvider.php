<?php

namespace App\Providers;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Dns\SystemResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Resolver::class, function () {
            // Local development only: answer DNS from a JSON zone file (see ArrayResolver for the format).
            $zone = env('DMARC_FAKE_DNS_ZONE');
            if ($zone && $this->app->environment('local') && is_readable($zone)) {
                return new ArrayResolver(json_decode((string) file_get_contents($zone), true) ?: []);
            }

            return new SystemResolver;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
