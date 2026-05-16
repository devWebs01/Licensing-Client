<?php

namespace DevWebs01\LicensingClient\Tests\Unit;

use DevWebs01\LicensingClient\Exceptions\CorruptedTokenException;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class LicenseCacheServiceTest extends TestCase
{
    private LicenseCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = new LicenseCacheService(
            graceDays: 7,
            cacheStore: 'array'
        );
    }

    public function test_store_and_retrieve_token(): void
    {
        $tokenData = [
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => 'abc123',
            'status' => 'active',
            'product' => 'Test App',
            'expires_at' => '2026-06-16',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'features' => ['pos', 'reports'],
        ];

        $this->cacheService->storeToken($tokenData);

        $retrieved = $this->cacheService->retrieveToken();

        $this->assertNotNull($retrieved);
        $this->assertSame($tokenData['license_key'], $retrieved['license_key']);
        $this->assertSame($tokenData['fingerprint'], $retrieved['fingerprint']);
        $this->assertSame($tokenData['status'], $retrieved['status']);
        $this->assertArrayHasKey('hmac', $retrieved);
        $this->assertArrayHasKey('version', $retrieved);
        $this->assertArrayHasKey('cached_at', $retrieved);
    }

    public function test_has_token_returns_true_when_token_exists(): void
    {
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => 'abc123',
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]);

        $this->assertTrue($this->cacheService->hasToken());
    }

    public function test_has_token_returns_false_when_no_token(): void
    {
        $this->assertFalse($this->cacheService->hasToken());
    }

    public function test_clear_token_removes_cache(): void
    {
        $this->cacheService->storeToken([
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => 'abc123',
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]);

        $this->assertTrue($this->cacheService->hasToken());

        $this->cacheService->clearToken();

        $this->assertFalse($this->cacheService->hasToken());
    }

    public function test_is_within_grace_period_returns_true_when_within(): void
    {
        $token = [
            'offline_until' => now()->addDays(5)->toIso8601String(),
        ];

        $this->assertTrue($this->cacheService->isWithinGracePeriod($token));
    }

    public function test_is_within_grace_period_returns_false_when_expired(): void
    {
        $token = [
            'offline_until' => now()->subDays(1)->toIso8601String(),
        ];

        $this->assertFalse($this->cacheService->isWithinGracePeriod($token));
    }

    public function test_grace_days_remaining_returns_correct_count(): void
    {
        $token = [
            'offline_until' => now()->addDays(3)->toIso8601String(),
        ];

        $this->assertSame(3, $this->cacheService->graceDaysRemaining($token));
    }

    public function test_detect_clock_drift_returns_false_when_no_drift(): void
    {
        $now = now();
        $token = [
            'cached_at' => $now->copy()->toIso8601String(),
            'server_time' => $now->copy()->toIso8601String(),
        ];

        $this->assertFalse($this->cacheService->detectClockDrift($token));
    }

    public function test_retrieve_token_returns_null_for_corrupted_cache(): void
    {
        Cache::store('array')->put(
            LicenseCacheService::CACHE_KEY_TOKEN,
            'invalid-encrypted-data',
            now()->addDays(30)
        );

        $this->assertNull($this->cacheService->retrieveToken());
    }

    public function test_retrieve_token_returns_null_for_manipulated_hmac(): void
    {
        $tokenData = [
            'license_key' => 'TEST-ABCD-EFGH-1234',
            'fingerprint' => 'abc123',
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];

        $this->cacheService->storeToken($tokenData);

        $this->expectException(CorruptedTokenException::class);
    }
}
