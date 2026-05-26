<?php

namespace DevWebs01\LicensingClient\Tests\Feature;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use DevWebs01\LicensingClient\Tests\TestCase;
use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\ValidationResult;
use Illuminate\Support\Facades\Http;

class PayloadContractTest extends TestCase
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
            githubRawBase: 'https://raw.githubusercontent.com/devWebs01/license-sync-data/main',
            licenseKey: 'LICENSE-INTEGRATION-TEST',
            appName: 'Test App',
            graceDays: 7,
        );
    }

    /**
     * This payload matches the EXACT structure produced by
     * license-monitor/app/Services/GithubLicenseSyncService.php
     * Changes to one side MUST be reflected in the other.
     */
    private function serverPayload(array $overrides = []): array
    {
        return array_merge([
            'license_hash' => sha1('LICENSE-INTEGRATION-TEST'),
            'status' => 'active',
            'expires_at' => now()->addMonths(6)->toIso8601String(),
            'max_devices' => 5,
            'registered_devices_count' => 2,
            'product_name' => 'Kasir Pro',
            'plan_name' => 'Enterprise',
            'customer_name' => 'Toko Maju Jaya',
            'features' => ['unlimited_products', 'premium_reports', 'multi_warehouse'],
            'updated_at' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_activate_parses_full_server_payload(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload()),
        ]);

        $result = $this->service->activate('LICENSE-INTEGRATION-TEST');

        $this->assertInstanceOf(ActivationResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertNotNull($result->offlineUntil);
        $this->assertFalse($result->deviceLimitReached);
    }

    public function test_sync_parses_all_server_fields(): void
    {
        $payload = $this->serverPayload();

        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($payload),
        ]);

        $result = $this->service->sync();

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->valid);
        $this->assertSame(LicenseStatus::Active, $result->status);
        $this->assertSame(5, $result->maxDevices);
        $this->assertSame(2, $result->registeredDevicesCount);
        $this->assertFalse($result->deviceLimitReached);
        $this->assertSame($payload['features'], $result->features);
        $this->assertSame($payload['updated_at'], $result->serverUpdatedAt);
        $this->assertSame($payload['expires_at'], $result->expiresAt);
    }

    public function test_status_populates_enriched_fields_after_sync(): void
    {
        $payload = $this->serverPayload([
            'product_name' => 'Kasir Pro',
        ]);

        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($payload),
        ]);

        $this->service->sync();
        $info = $this->service->status();

        $this->assertTrue($info->isValid);
        $this->assertSame('Kasir Pro', $info->product);
        $this->assertSame(5, $info->maxDevices);
        $this->assertSame(2, $info->registeredDevicesCount);
        $this->assertFalse($info->deviceLimitReached);
        $this->assertSame($payload['features'], $info->features);
        $this->assertSame($payload['expires_at'], $info->expiresAt);
        $this->assertSame($payload['updated_at'], $info->serverUpdatedAt);
    }

    public function test_activate_rejects_when_device_limit_reached(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload([
                'max_devices' => 3,
                'registered_devices_count' => 3,
            ])),
        ]);

        $result = $this->service->activate('LICENSE-INTEGRATION-TEST');

        $this->assertFalse($result->success);
        $this->assertTrue($result->deviceLimitReached);
    }

    public function test_sync_reports_device_limit(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload([
                'max_devices' => 2,
                'registered_devices_count' => 2,
            ])),
        ]);

        $result = $this->service->sync();

        $this->assertTrue($result->valid);
        $this->assertTrue($result->deviceLimitReached);
        $this->assertSame(2, $result->maxDevices);
        $this->assertSame(2, $result->registeredDevicesCount);
    }

    public function test_status_reports_device_limit_reached(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload([
                'max_devices' => 1,
                'registered_devices_count' => 1,
            ])),
        ]);

        $this->service->sync();
        $info = $this->service->status();

        $this->assertTrue($info->isValid);
        $this->assertTrue($info->deviceLimitReached);
        $this->assertSame(1, $info->maxDevices);
        $this->assertSame(1, $info->registeredDevicesCount);
    }

    public function test_stale_cache_detection(): void
    {
        $serverTime = now()->subHours(2)->toIso8601String();

        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload([
                'updated_at' => $serverTime,
            ])),
        ]);

        $this->service->sync();
        $info = $this->service->status();

        $this->assertSame($serverTime, $info->serverUpdatedAt);
    }

    public function test_info_returns_same_as_status(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload([
                'product_name' => 'Kasir Pro',
                'features' => ['a', 'b'],
            ])),
        ]);

        $this->service->sync();

        $info = $this->service->info();
        $status = $this->service->status();

        $this->assertSame($status->product, $info->product);
        $this->assertSame($status->features, $info->features);
        $this->assertSame($status->maxDevices, $info->maxDevices);
        $this->assertSame($status->deviceLimitReached, $info->deviceLimitReached);
        $this->assertSame($status->serverUpdatedAt, $info->serverUpdatedAt);
    }

    public function test_activate_stores_features_in_cache(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response($this->serverPayload([
                'features' => ['unlimited_products', 'premium_reports'],
            ])),
        ]);

        $this->service->activate('LICENSE-INTEGRATION-TEST');

        $info = $this->service->status();

        $this->assertSame(['unlimited_products', 'premium_reports'], $info->features);
    }
}
