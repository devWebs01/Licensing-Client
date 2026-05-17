<?php

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Exceptions\ClockDriftDetectedException;
use DevWebs01\LicensingClient\Exceptions\LicenseNotActivatedException;
use DevWebs01\LicensingClient\Exceptions\ServerUnreachableException;
use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\LicenseInfo;
use DevWebs01\LicensingClient\ValueObjects\ValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class LicenseClientService
{
    private const REQUEST_TIMEOUT = 10;

    public function __construct(
        private readonly LicenseCacheService $cache,
        private readonly FingerprintCollector $fingerprint,
        private readonly string $serverUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $licenseKey,
        private readonly string $appName,
        private readonly int $timeout,
        private readonly int $graceDays,
    ) {}

    public function activate(string $licenseKey): ActivationResult
    {
        $fingerprint = $this->fingerprint->fingerprint();
        $deviceData = $this->fingerprint->collectData();

        try {
            $response = $this->signedPost('/api/v1/activate', [
                'license_key' => $licenseKey,
                'device' => [
                    'fingerprint' => $fingerprint,
                    'name' => $deviceData['hostname'],
                    'platform' => $deviceData['os'],
                    'platform_version' => $deviceData['kernel'],
                    'app_version' => config('app.version', '1.0.0'),
                ],
            ]);

            if ($response->status() === 401) {
                return new ActivationResult(
                    success: false,
                    message: 'Kredensial API tidak valid. Periksa LICENSING_API_KEY dan LICENSING_API_SECRET.',
                );
            }

            if ($response->failed()) {
                return new ActivationResult(
                    success: false,
                    message: $response->json('message', 'Gagal aktivasi'),
                );
            }

            $result = ActivationResult::fromArray($response->json());
            $serverTime = $response->header('Date');

            if ($result->success && ! $result->requiresApproval) {
                $this->storeTokenFromValidation($licenseKey, $fingerprint, $result->offlineUntil, $serverTime);
            }

            return $result;
        } catch (ConnectionException) {
            return new ActivationResult(
                success: false,
                message: 'Server lisensi tidak reachable',
            );
        }
    }

    public function verifyActivation(string $code): bool
    {
        $fingerprint = $this->fingerprint->fingerprint();
        $licenseKey = $this->resolveLicenseKey();

        try {
            $response = $this->signedGet("/api/v1/verify/{$licenseKey}/{$fingerprint}", [
                'code' => $code,
            ]);

            if ($response->failed()) {
                return false;
            }

            $data = $response->json('data', []);
            $serverTime = $response->header('Date');

            if ($data['valid'] ?? false) {
                $this->storeTokenFromValidation($licenseKey, $fingerprint, $data['offline_until'] ?? null, $serverTime);

                return true;
            }

            return false;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function validateOnline(): ValidationResult
    {
        $fingerprint = $this->fingerprint->fingerprint();
        $licenseKey = $this->resolveLicenseKey();

        try {
            $response = $this->signedPost('/api/v1/validate', [
                'license_key' => $licenseKey,
                'device' => [
                    'fingerprint' => $fingerprint,
                ],
            ]);

            if ($response->status() === 401) {
                throw new ServerUnreachableException('Kredensial API tidak valid');
            }

            if ($response->status() === 403) {
                $this->cache->clearToken();
                $message = $response->json('message', 'Lisensi tidak valid');
                $status = $response->json('data.status', 'unknown');

                return new ValidationResult(
                    valid: false,
                    status: LicenseStatus::tryFrom($status) ?? LicenseStatus::Unknown,
                    message: $message,
                );
            }

            if ($response->failed()) {
                throw new ServerUnreachableException;
            }

            $result = ValidationResult::fromArray($response->json());
            $serverTime = $response->header('Date');

            if ($result->valid) {
                $this->cacheTokenFromServer($licenseKey, $fingerprint, $result, $serverTime);
            }

            return $result;
        } catch (ConnectionException) {
            throw new ServerUnreachableException;
        }
    }

    public function validateOffline(): ValidationResult
    {
        $token = $this->cache->retrieveToken();

        if ($token === null) {
            throw new LicenseNotActivatedException;
        }

        if ($this->cache->detectClockDrift($token)) {
            throw new ClockDriftDetectedException;
        }

        $withinGrace = $this->cache->isWithinGracePeriod($token);

        if (! $withinGrace) {
            return new ValidationResult(
                valid: false,
                status: LicenseStatus::GraceWarning,
                offlineUntil: $token['offline_until'] ?? null,
                message: 'Grace period telah habis',
            );
        }

        return new ValidationResult(
            valid: true,
            status: LicenseStatus::Active,
            offlineUntil: $token['offline_until'] ?? null,
            product: $token['product'] ?? null,
            expiresAt: $token['expires_at'] ?? null,
            features: $token['features'] ?? [],
            message: 'Valid dari cache offline',
        );
    }

    public function status(): LicenseInfo
    {
        $token = $this->cache->retrieveToken();

        if ($token === null) {
            return new LicenseInfo(
                isValid: false,
                status: LicenseStatus::NotActivated,
                requiresOnlineRefresh: true,
            );
        }

        $withinGrace = $this->cache->isWithinGracePeriod($token);
        $daysRemaining = $this->cache->graceDaysRemaining($token);

        $status = LicenseStatus::tryFrom($token['status'] ?? '') ?? LicenseStatus::Unknown;

        if ($withinGrace && $daysRemaining <= 3 && $daysRemaining > 0) {
            $status = LicenseStatus::GraceWarning;
        }

        if (! $withinGrace) {
            $status = LicenseStatus::Locked;
        }

        return new LicenseInfo(
            isValid: $withinGrace,
            status: $status,
            offlineUntil: $token['offline_until'] ?? null,
            isWithinGracePeriod: $withinGrace,
            graceDaysRemaining: $daysRemaining,
            product: $token['product'] ?? null,
            cachedAt: $token['cached_at'] ?? null,
            requiresOnlineRefresh: ! $withinGrace,
        );
    }

    public function refresh(): bool
    {
        try {
            $result = $this->validateOnline();

            return $result->valid;
        } catch (ServerUnreachableException) {
            return false;
        }
    }

    public function deactivate(): bool
    {
        $fingerprint = $this->fingerprint->fingerprint();
        $licenseKey = $this->resolveLicenseKey();

        try {
            $response = $this->signedPost('/api/v1/deactivate', [
                'license_key' => $licenseKey,
                'device' => [
                    'fingerprint' => $fingerprint,
                ],
            ]);

            if ($response->successful()) {
                $this->cache->clearToken();

                return true;
            }

            return false;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function hasFeature(string $feature): bool
    {
        $token = $this->cache->retrieveToken();

        if ($token === null) {
            return false;
        }

        $features = $token['features'] ?? [];

        return in_array($feature, $features, true);
    }

    public function info(): LicenseInfo
    {
        return $this->status();
    }

    public function isValid(): bool
    {
        try {
            $result = $this->validateOffline();

            return $result->valid;
        } catch (LicenseNotActivatedException|ClockDriftDetectedException) {
            return false;
        }
    }

    private function signedPost(string $path, array $data): Response
    {
        return $this->signedRequest('POST', $path, $data);
    }

    private function signedGet(string $path, array $query = []): Response
    {
        return $this->signedRequest('GET', $path, $query);
    }

    private function signedRequest(string $method, string $path, array $data = []): Response
    {
        $timestamp = now()->toIso8601String();
        $nonce = Str::random(32);
        $body = '';
        $path = '/'.ltrim($path, '/');
        $url = $this->serverUrl.$path;
        $signPath = ltrim($path, '/');

        if ($method === 'GET') {
            $payload = "{$method}\n{$signPath}\n{$timestamp}\n{$nonce}\n";
            $url .= '?'.http_build_query($data);
        } else {
            $body = json_encode($data) ?: '';
            $payload = "{$method}\n{$signPath}\n{$timestamp}\n{$nonce}\n{$body}";
        }

        $signature = base64_encode(
            hash_hmac('sha256', $payload, $this->apiSecret, true)
        );

        $request = Http::timeout($this->timeout)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'X-Nonce' => $nonce,
                'Content-Type' => 'application/json',
            ]);

        if ($method === 'GET') {
            return $request->get($url);
        }

        return $request->post($url, $data);
    }

    private function storeTokenFromValidation(string $licenseKey, string $fingerprint, ?string $offlineUntil, ?string $serverTime = null): void
    {
        $offlineUntilDate = $offlineUntil
            ? now()->parse($offlineUntil)
            : now()->addDays($this->graceDays);

        $this->cache->storeToken([
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'status' => LicenseStatus::Active->value,
            'product' => $this->appName,
            'expires_at' => $offlineUntilDate->toDateString(),
            'offline_until' => $offlineUntilDate->toIso8601String(),
            'server_time' => $this->resolveServerTime($serverTime),
            'features' => [],
        ]);
    }

    private function cacheTokenFromServer(string $licenseKey, string $fingerprint, ValidationResult $result, ?string $serverTime = null): void
    {
        $offlineUntil = $result->offlineUntil
            ? now()->parse($result->offlineUntil)
            : now()->addDays($this->graceDays);

        $this->cache->storeToken([
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'status' => $result->status->value,
            'product' => $result->product ?? $this->appName,
            'expires_at' => $result->expiresAt,
            'offline_until' => $offlineUntil->toIso8601String(),
            'server_time' => $this->resolveServerTime($serverTime),
            'features' => $result->features,
        ]);
    }

    private function resolveServerTime(?string $httpDate): string
    {
        if ($httpDate === null) {
            return now()->toIso8601String();
        }

        try {
            return now()->parse($httpDate)->toIso8601String();
        } catch (\Throwable) {
            return now()->toIso8601String();
        }
    }

    private function resolveLicenseKey(): string
    {
        $token = $this->cache->retrieveToken();

        return $token['license_key'] ?? $this->licenseKey;
    }
}
