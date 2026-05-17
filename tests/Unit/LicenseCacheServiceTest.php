<?php

namespace DevWebs01\LicensingClient\Tests\Unit;

use DevWebs01\LicensingClient\Exceptions\CorruptedTokenException;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class LicenseCacheServiceTest extends TestCase
{
    private LicenseCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = new LicenseCacheService(
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

    public function test_store_and_retrieve_status(): void
    {
        $offlineUntil = now()->addDays(7)->toIso8601String();

        $this->cacheService->storeStatus('active', true, $offlineUntil);

        $status = $this->cacheService->retrieveStatus();

        $this->assertNotNull($status);
        $this->assertTrue($status['valid']);
        $this->assertSame('active', $status['status']);
        $this->assertSame($offlineUntil, $status['offline_until']);
        $this->assertArrayHasKey('sig', $status);
    }

    public function test_retrieve_status_returns_null_when_tampered(): void
    {
        $offlineUntil = now()->addDays(7)->toIso8601String();

        $this->cacheService->storeStatus('active', true, $offlineUntil);

        $data = Cache::store('array')->get(LicenseCacheService::CACHE_KEY_STATUS);
        $data['status'] = 'expired';
        Cache::store('array')->put(LicenseCacheService::CACHE_KEY_STATUS, $data, now()->addDays(30));

        $this->assertNull($this->cacheService->retrieveStatus());
    }

    public function test_has_status_returns_true_when_exists(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(7)->toIso8601String());

        $this->assertTrue($this->cacheService->hasStatus());
    }

    public function test_has_status_returns_false_when_not_exists(): void
    {
        $this->assertFalse($this->cacheService->hasStatus());
    }

    public function test_clear_status_removes_cache(): void
    {
        $this->cacheService->storeStatus('active', true, now()->addDays(7)->toIso8601String());
        $this->assertTrue($this->cacheService->hasStatus());

        $this->cacheService->clearStatus();

        $this->assertFalse($this->cacheService->hasStatus());
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
        $offlineUntil = now()->addDays(5)->toIso8601String();

        $this->assertTrue($this->cacheService->isWithinGracePeriod($offlineUntil));
    }

    public function test_is_within_grace_period_returns_false_when_expired(): void
    {
        $offlineUntil = now()->subDays(1)->toIso8601String();

        $this->assertFalse($this->cacheService->isWithinGracePeriod($offlineUntil));
    }

    public function test_grace_days_remaining_returns_correct_count(): void
    {
        $offlineUntil = now()->addDays(3)->addHour()->toIso8601String();

        $remaining = $this->cacheService->graceDaysRemaining($offlineUntil);
        $this->assertGreaterThanOrEqual(3, $remaining);
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

        $encrypted = Cache::store('array')->get(LicenseCacheService::CACHE_KEY_TOKEN);
        $decrypted = json_decode(Crypt::decryptString($encrypted), true);
        $decrypted['hmac'] = str_repeat('a', 64);
        Cache::store('array')->put(
            LicenseCacheService::CACHE_KEY_TOKEN,
            Crypt::encryptString(json_encode($decrypted)),
            now()->addDays(30)
        );

        $this->expectException(CorruptedTokenException::class);

        $this->cacheService->retrieveToken();
    }
}
