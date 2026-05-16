<?php

namespace DevWebs01\LicensingClient;

use DevWebs01\LicensingClient\Commands\LicenseActivateCommand;
use DevWebs01\LicensingClient\Commands\LicenseStatusCommand;
use DevWebs01\LicensingClient\Components\CountdownWarning;
use DevWebs01\LicensingClient\Components\LockedScreen;
use DevWebs01\LicensingClient\Components\StatusBadge;
use DevWebs01\LicensingClient\Http\Middleware\CheckLicenseMiddleware;
use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Support\ServiceProvider;

class LicensingClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/licensing-client.php',
            'licensing-client'
        );

        $this->app->singleton(FingerprintCollector::class);

        $this->app->singleton(LicenseCacheService::class, function () {
            return new LicenseCacheService(
                graceDays: (int) config('licensing-client.grace_days', 7),
                cacheStore: config('licensing-client.cache.store'),
            );
        });

        $this->app->singleton(LicenseClientService::class, function () {
            return new LicenseClientService(
                cache: $this->app->make(LicenseCacheService::class),
                fingerprint: $this->app->make(FingerprintCollector::class),
                serverUrl: (string) config('licensing-client.server_url'),
                licenseKey: (string) config('licensing-client.license_key'),
                appName: (string) config('licensing-client.app_name', 'App'),
                timeout: (int) config('licensing-client.timeout', 10),
                graceDays: (int) config('licensing-client.grace_days', 7),
            );
        });

        $this->app->alias(LicenseClientService::class, 'licensing-client');
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerComponents();
        $this->registerPublishing();
        $this->registerBladeDirectives();
    }

    private function registerMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('license', CheckLicenseMiddleware::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LicenseActivateCommand::class,
                LicenseStatusCommand::class,
            ]);
        }
    }

    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/licensing.php');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'licensing');
    }

    private function registerComponents(): void
    {
        $this->loadViewComponentsAs('licensing', [
            CountdownWarning::class,
            StatusBadge::class,
            LockedScreen::class,
        ]);
    }

    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/licensing-client.php' => config_path('licensing-client.php'),
            ], 'licensing-client-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/licensing-client'),
            ], 'licensing-client-views');
        }
    }

    private function registerBladeDirectives(): void
    {
        BladeDirectives::register();
    }
}
