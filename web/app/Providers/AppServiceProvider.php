<?php

namespace App\Providers;

use App\Audit\AuditObserver;
use App\Audit\AuthEventSubscriber;
use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Dns\SystemResolver;
use App\Intel\AsnDatabase;
use App\Intel\MmdbAsnDatabase;
use Illuminate\Support\Facades\Event;
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

        $this->app->singleton(AsnDatabase::class, fn () => new MmdbAsnDatabase(config('dmarc.asn_db_path')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(AuditObserver::config()) as $model) {
            $model::observe(AuditObserver::class);
        }
        Event::subscribe(AuthEventSubscriber::class);
    }
}
