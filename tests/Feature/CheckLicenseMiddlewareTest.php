<?php

namespace DevWebs01\LicensingClient\Tests\Feature;

use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class CheckLicenseMiddlewareTest extends TestCase
{
    private LicenseCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = new LicenseCacheService(
            graceDays: 7,
            cacheStore: 'array'
        );

        Route::middleware('license')->get('/test-route', function () {
            return 'OK';
        });

        Route::get('/login', function () {
            return 'LOGIN';
        })->name('login');
    }

    public function test_valid_cache_allows_request(): void
    {
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => (new FingerprintCollector)->fingerprint(),
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'features' => [],
        ]);

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
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => (new FingerprintCollector)->fingerprint(),
            'status' => 'active',
            'offline_until' => now()->addDays(2)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'features' => [],
        ]);

        $response = $this->get('/test-route');

        $response->assertOk();
    }

    public function test_expired_cache_no_grace_redirects_to_lock(): void
    {
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => (new FingerprintCollector)->fingerprint(),
            'status' => 'active',
            'offline_until' => now()->subDays(1)->toIso8601String(),
            'server_time' => now()->subDays(8)->toIso8601String(),
            'features' => [],
        ]);

        $response = $this->get('/test-route');

        $response->assertRedirect(route('licensing.locked'));
    }

    public function test_server_returns_expired_redirects_to_lock(): void
    {
        $this->cacheService->clearToken();

        Http::fake([
            'monitor.test/api/v1/validate' => Http::response([
                'success' => false,
                'message' => 'Lisensi telah kedaluwarsa',
                'data' => [
                    'valid' => false,
                    'status' => 'expired',
                ],
            ], 403),
        ]);

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
