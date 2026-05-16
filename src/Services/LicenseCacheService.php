<?php

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Exceptions\ClockDriftDetectedException;
use DevWebs01\LicensingClient\Exceptions\CorruptedTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class LicenseCacheService
{
    const CACHE_KEY_TOKEN = 'licensing:token';
    const CACHE_KEY_META = 'licensing:meta';
    const CACHE_TTL = 3600;
    const TOKEN_VERSION = 1;

    public function __construct(
        private readonly int $graceDays,
        private readonly ?string $cacheStore = null,
    ) {
    }

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

    public function getOfflineUntil(array $token): ?string
    {
        return $token['offline_until'] ?? null;
    }

    public function isWithinGracePeriod(array $token): bool
    {
        $offlineUntil = $this->getOfflineUntil($token);

        if ($offlineUntil === null) {
            return false;
        }

        return now()->lessThanOrEqualTo($offlineUntil);
    }

    public function graceDaysRemaining(array $token): int
    {
        $offlineUntil = $this->getOfflineUntil($token);

        if ($offlineUntil === null) {
            return 0;
        }

        return max(0, now()->diffInDays($offlineUntil, false));
    }

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

    private function computeHmac(array $tokenData): string
    {
        $payload = ($tokenData['license_key'] ?? '')
            . ($tokenData['fingerprint'] ?? '')
            . ($tokenData['offline_until'] ?? '');

        return hash_hmac('sha256', $payload, $this->getHmacSecret());
    }

    private function verifyIntegrity(array $token): bool
    {
        $expectedHmac = $token['hmac'] ?? '';

        if (empty($expectedHmac)) {
            return false;
        }

        $payload = ($token['license_key'] ?? '')
            . ($token['fingerprint'] ?? '')
            . ($token['offline_until'] ?? '');

        $computedHmac = hash_hmac('sha256', $payload, $this->getHmacSecret());

        return hash_equals($expectedHmac, $computedHmac);
    }

    private function getHmacSecret(): string
    {
        $appKey = config('app.key');

        return $appKey ?: 'default-secret-do-not-use';
    }
}
