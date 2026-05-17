<?php

namespace DevWebs01\LicensingClient\Tests\Unit;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Exceptions\ServerUnreachableException;
use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use DevWebs01\LicensingClient\Tests\TestCase;
use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\ValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LicenseClientServiceTest extends TestCase
{
    private LicenseClientService $service;

    private LicenseCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = new LicenseCacheService(
            cacheStore: 'array'
        );

        $this->service = new LicenseClientService(
            cache: $this->cacheService,
            fingerprint: new FingerprintCollector,
            serverUrl: 'https://monitor.test',
            apiKey: 'test-api-key',
            apiSecret: 'test-api-secret',
            licenseKey: 'TEST-ABCD-EFGH-1234',
            appName: 'Test App',
            timeout: 10,
            graceDays: 7,
        );
    }

    public function test_activate_returns_success_result(): void
    {
        Http::fake([
            'monitor.test/api/v1/activate' => Http::response([
                'success' => true,
                'message' => 'Perangkat berhasil diaktifkan',
                'data' => [
                    'device_id' => 1,
                    'offline_until' => now()->addDays(7)->toIso8601String(),
                ],
            ]),
        ]);

        $result = $this->service->activate('TEST-ABCD-EFGH-1234');

        $this->assertInstanceOf(ActivationResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertFalse($result->requiresApproval);
        $this->assertNotNull($result->offlineUntil);
    }

    public function test_activate_handles_approval_mode(): void
    {
        Http::fake([
            'monitor.test/api/v1/activate' => Http::response([
                'success' => true,
                'message' => 'Kode aktivasi dibuat',
                'data' => [
                    'requires_approval' => true,
                    'activation_code' => 'A7F3B2C1',
                    'expires_at' => now()->addHours(24)->toIso8601String(),
                ],
            ]),
        ]);

        $result = $this->service->activate('TEST-ABCD-EFGH-1234');

        $this->assertTrue($result->success);
        $this->assertTrue($result->requiresApproval);
        $this->assertSame('A7F3B2C1', $result->activationCode);
    }

    public function test_activate_handles_invalid_key(): void
    {
        Http::fake([
            'monitor.test/api/v1/activate' => Http::response([
                'success' => false,
                'message' => 'Kunci lisensi tidak valid',
            ], 404),
        ]);

        $result = $this->service->activate('INVALID-KEY');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->message);
    }

    public function test_sync_returns_valid(): void
    {
        Http::fake([
            'monitor.test/api/v1/validate' => Http::response([
                'success' => true,
                'message' => 'Lisensi valid',
                'data' => [
                    'valid' => true,
                    'status' => 'active',
                    'product' => 'Test App',
                    'expires_at' => now()->addMonth()->toDateString(),
                    'max_devices' => 3,
                    'devices_count' => 1,
                    'offline_until' => now()->addDays(7)->toIso8601String(),
                    'features' => ['pos', 'reports'],
                ],
            ]),
        ]);

        $result = $this->service->sync();

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->valid);
        $this->assertSame(LicenseStatus::Active, $result->status);
    }

    public function test_sync_throws_on_network_error(): void
    {
        Http::fake([
            'monitor.test/api/v1/validate' => function () {
                throw new ConnectionException('Connection timeout');
            },
        ]);

        $this->expectException(ServerUnreachableException::class);

        $this->service->sync();
    }

    public function test_status_uses_memory_cache_within_request(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(7)->toIso8601String());

        $info1 = $this->service->status();
        $info2 = $this->service->status();

        $this->assertTrue($info1->isValid);
        $this->assertTrue($info2->isValid);
    }

    public function test_deactivate_clears_cache(): void
    {
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => 'abc123',
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]);

        Http::fake([
            'monitor.test/api/v1/deactivate' => Http::response([
                'success' => true,
                'message' => 'Perangkat berhasil dideaktivasi',
            ]),
        ]);

        $result = $this->service->deactivate();

        $this->assertTrue($result);
        $this->assertFalse($this->cacheService->hasToken());
    }

    public function test_has_feature_returns_true_for_enabled_feature(): void
    {
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => (new FingerprintCollector)->fingerprint(),
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'features' => ['pos', 'reports'],
        ]);

        $this->assertTrue($this->service->hasFeature('pos'));
        $this->assertTrue($this->service->hasFeature('reports'));
        $this->assertFalse($this->service->hasFeature('inventory'));
    }

    public function test_status_returns_not_activated_when_no_cache(): void
    {
        $info = $this->service->status();

        $this->assertFalse($info->isValid);
        $this->assertSame(LicenseStatus::NotActivated, $info->status);
    }

    public function test_status_returns_active_when_status_cache_valid(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(7)->toIso8601String());

        $info = $this->service->status();

        $this->assertTrue($info->isValid);
    }

    public function test_is_valid_returns_false_when_no_status(): void
    {
        $this->assertFalse($this->service->isValid());
    }

    public function test_is_valid_returns_true_when_status_valid(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(7)->toIso8601String());

        $this->assertTrue($this->service->isValid());
    }
}
