<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Exceptions\CorruptedTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

final class LicenseCacheService
{
    const CACHE_KEY_TOKEN = 'licensing:token';

    const CACHE_KEY_STATUS = 'licensing:status';

    const CACHE_KEY_META = 'licensing:meta';

    const TOKEN_VERSION = 1;

    public function __construct(
        private readonly ?string $cacheStore = null,
    ) {}

    public function storeStatus(string $status, bool $valid, string $offlineUntil, ?string $serverUpdatedAt = null): void
    {
        $data = [
            'valid' => $valid,
            'status' => $status,
            'offline_until' => $offlineUntil,
            'updated_at' => now()->toIso8601String(),
            'server_updated_at' => $serverUpdatedAt,
        ];

        $data['sig'] = $this->computeStatusHmac($data);

        Cache::store($this->cacheStore)->put(
            self::CACHE_KEY_STATUS,
            $data,
            now()->addDays(30),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function retrieveStatus(): ?array
    {
        $data = Cache::store($this->cacheStore)->get(self::CACHE_KEY_STATUS);

        if ($data === null) {
            return null;
        }

        if (! is_array($data)) {
            $this->clearStatus();

            return null;
        }

        $expectedSig = $data['sig'] ?? '';

        if (empty($expectedSig)) {
            $this->clearStatus();

            return null;
        }

        $computedSig = $this->computeStatusHmac($data);

        if (! hash_equals($expectedSig, $computedSig)) {
            $this->clearStatus();

            return null;
        }

        return $data;
    }

    public function hasStatus(): bool
    {
        return Cache::store($this->cacheStore)->has(self::CACHE_KEY_STATUS);
    }

    public function clearStatus(): void
    {
        Cache::store($this->cacheStore)->forget(self::CACHE_KEY_STATUS);
    }

    /**
     * @param  array<string, mixed>  $tokenData
     */
    public function storeToken(array $tokenData): void
    {
        $tokenData['version'] = self::TOKEN_VERSION;
        $tokenData['cached_at'] = now()->toIso8601String();
        $tokenData['hmac'] = $this->computeHmac($tokenData);

        $encrypted = Crypt::encryptString(json_encode($tokenData));

        Cache::store($this->cacheStore)->put(
            self::CACHE_KEY_TOKEN,
            $encrypted,
            now()->addDays(30),
        );

        Cache::store($this->cacheStore)->put(
            self::CACHE_KEY_META,
            [
                'cached_at' => $tokenData['cached_at'],
                'fingerprint' => $tokenData['fingerprint'] ?? null,
            ],
            now()->addDays(30),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function retrieveToken(): ?array
    {
        $encrypted = Cache::store($this->cacheStore)->get(self::CACHE_KEY_TOKEN);

        if ($encrypted === null) {
            return null;
        }

        try {
            $decrypted = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Throwable) {
            $this->clearToken();

            return null;
        }

        if (! is_array($decrypted)) {
            $this->clearToken();

            return null;
        }

        if (! $this->verifyIntegrity($decrypted)) {
            $this->clearToken();
            throw new CorruptedTokenException;
        }

        return $decrypted;
    }

    public function hasToken(): bool
    {
        return Cache::store($this->cacheStore)->has(self::CACHE_KEY_TOKEN);
    }

    public function clearToken(): void
    {
        Cache::store($this->cacheStore)->forget(self::CACHE_KEY_TOKEN);
        Cache::store($this->cacheStore)->forget(self::CACHE_KEY_META);
    }

    public function isWithinGracePeriod(string $offlineUntil): bool
    {
        return now()->lessThanOrEqualTo($offlineUntil);
    }

    public function graceDaysRemaining(string $offlineUntil): int
    {
        try {
            $diff = (int) ceil(now()->diffInDays(now()->parse($offlineUntil), true));

            return max(0, $diff);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array{valid: bool, status: string, offline_until: string, updated_at: string, server_updated_at?: string|null}  $data
     */
    private function computeStatusHmac(array $data): string
    {
        $payload = ($data['valid'] ? '1' : '0')
            .$data['status']
            .$data['offline_until']
            .$data['updated_at']
            .($data['server_updated_at'] ?? '');

        return hash_hmac('sha256', $payload, $this->getHmacSecret());
    }

    /**
     * @param  array<string, mixed>  $tokenData
     */
    private function computeHmac(array $tokenData): string
    {
        $payload = ($tokenData['license_key'] ?? '')
            .($tokenData['fingerprint'] ?? '')
            .($tokenData['offline_until'] ?? '')
            .($tokenData['max_devices'] ?? '')
            .($tokenData['server_updated_at'] ?? '');

        return hash_hmac('sha256', $payload, $this->getHmacSecret());
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private function verifyIntegrity(array $token): bool
    {
        $expectedHmac = $token['hmac'] ?? '';

        if (empty($expectedHmac)) {
            return false;
        }

        $payload = ($token['license_key'] ?? '')
            .($token['fingerprint'] ?? '')
            .($token['offline_until'] ?? '')
            .($token['max_devices'] ?? '')
            .($token['server_updated_at'] ?? '');

        $computedHmac = hash_hmac('sha256', $payload, $this->getHmacSecret());

        return hash_equals($expectedHmac, $computedHmac);
    }

    private function getHmacSecret(): string
    {
        $secret = config('licensing-client.hmac_secret') ?? config('app.key');

        if (! $secret) {
            throw new \RuntimeException('APP_KEY atau Licensing Secret tidak dikonfigurasi.');
        }

        return $secret;
    }
}
