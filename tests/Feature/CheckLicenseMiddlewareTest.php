<?php

namespace DevWebs01\LicensingClient\Tests\Feature;

use DevWebs01\LicensingClient\Http\Middleware\CheckLicenseMiddleware;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class CheckLicenseMiddlewareTest extends TestCase
{
    private LicenseCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = new LicenseCacheService(
            cacheStore: 'array'
        );

        $this->app->instance(LicenseCacheService::class, $this->cacheService);

        Route::middleware(CheckLicenseMiddleware::class)->get('/test-route', function () {
            return 'OK';
        });

        Route::get('/login', function () {
            return 'LOGIN';
        })->name('login');
    }

    public function test_valid_cache_allows_request(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(7)->toIso8601String());

        $response = $this->get('/test-route');

        $response->assertOk();
        $response->assertSee('OK');
    }

    public function test_no_cache_redirects_to_wizard(): void
    {
        $response = $this->get('/test-route');

        $response->assertRedirect(route('licensing.activate'));
    }

    public function test_expired_cache_within_grace_allows_request(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(2)->toIso8601String());

        $response = $this->get('/test-route');

        $response->assertOk();
    }

    public function test_expired_cache_no_grace_redirects_to_lock(): void
    {
        $this->cacheService->storeStatus('locked', false, now()->subDays(1)->toIso8601String());

        $response = $this->get('/test-route');

        $response->assertRedirect();
    }

    public function test_expired_status_redirects_to_lock(): void
    {
        $this->cacheService->storeStatus('expired', false, now()->subDays(1)->toIso8601String());

        $response = $this->get('/test-route');

        $response->assertRedirect();
    }

    public function test_excluded_routes_bypass_middleware(): void
    {
        config(['licensing-client.excluded_routes' => ['test-route']]);

        $response = $this->get('/test-route');

        $response->assertOk();
    }

    public function test_development_bypass_allows_request(): void
    {
        config(['licensing-client.environment' => 'local']);

        $response = $this->get('/test-route');

        $response->assertOk();
    }
}
