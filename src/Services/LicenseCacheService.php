<?php

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Exceptions\CorruptedTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

final class LicenseCacheService
{
    const CACHE_KEY_TOKEN = 'licensing:token';

    const CACHE_KEY_META = 'licensing:meta';

    const CACHE_TTL = 3600;

    const TOKEN_VERSION = 1;

    public function __construct(
        private readonly ?string $cacheStore = null,
    ) {}

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
            now()->addDays(30)
        );

        Cache::store($this->cacheStore)->put(
            self::CACHE_KEY_META,
            [
                'cached_at' => $tokenData['cached_at'],
                'fingerprint' => $tokenData['fingerprint'] ?? null,
            ],
            now()->addDays(30)
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

    /**
     * @param  array<string, mixed>  $token
     */
    public function getOfflineUntil(array $token): ?string
    {
        return $token['offline_until'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function isWithinGracePeriod(array $token): bool
    {
        $offlineUntil = $this->getOfflineUntil($token);

        if ($offlineUntil === null) {
            return false;
        }

        return now()->lessThanOrEqualTo($offlineUntil);
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function graceDaysRemaining(array $token): int
    {
        $offlineUntil = $this->getOfflineUntil($token);

        if ($offlineUntil === null) {
            return 0;
        }

        try {
            $target = now()->parse($offlineUntil);
            $diff = (int) ceil(now()->diffInDays($target, true));

            return max(0, $diff);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function detectClockDrift(array $token): bool
    {
        try {
            $cachedAt = $token['cached_at'] ?? null;
            $serverTime = $token['server_time'] ?? null;

            if ($cachedAt === null || $serverTime === null) {
                return false;
            }

            $cachedAtCarbon = now()->parse($cachedAt);
            $serverTimeCarbon = now()->parse($serverTime);

            $elapsedSinceCached = now()->diffInSeconds($cachedAtCarbon);
            $elapsedSinceServer = $serverTimeCarbon->diffInSeconds($cachedAtCarbon);

            $clockDrift = abs($elapsedSinceCached - $elapsedSinceServer);

            return $clockDrift > 3600;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $tokenData
     */
    private function computeHmac(array $tokenData): string
    {
        $payload = ($tokenData['license_key'] ?? '')
            .($tokenData['fingerprint'] ?? '')
            .($tokenData['offline_until'] ?? '');

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
            .($token['offline_until'] ?? '');

        $computedHmac = hash_hmac('sha256', $payload, $this->getHmacSecret());

        return hash_equals($expectedHmac, $computedHmac);
    }

    private function getHmacSecret(): string
    {
        $appKey = config('app.key');

        if (! $appKey) {
            throw new \RuntimeException('APP_KEY tidak dikonfigurasi. Setel APP_KEY sebelum menggunakan lisensi offline.');
        }

        return $appKey;
    }
}
