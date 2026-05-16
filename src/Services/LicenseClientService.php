<?php

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Exceptions\ClockDriftDetectedException;
use DevWebs01\LicensingClient\Exceptions\LicenseExpiredException;
use DevWebs01\LicensingClient\Exceptions\LicenseNotActivatedException;
use DevWebs01\LicensingClient\Exceptions\LicenseSuspendedException;
use DevWebs01\LicensingClient\Exceptions\ServerUnreachableException;
use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\LicenseInfo;
use DevWebs01\LicensingClient\ValueObjects\ValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LicenseClientService
{
    public function __construct(
        private readonly LicenseCacheService $cache,
        private readonly FingerprintCollector $fingerprint,
        private readonly string $serverUrl,
        private readonly string $licenseKey,
        private readonly string $appName,
        private readonly int $timeout,
        private readonly int $graceDays,
    ) {
    }

    public function activate(string $licenseKey): ActivationResult
    {
        $fingerprint = $this->fingerprint->fingerprint();
        $deviceData = $this->fingerprint->collectData();

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->serverUrl}/api/v1/activate", [
                    'license_key' => $licenseKey,
                    'device' => [
                        'fingerprint' => $fingerprint,
                        'name' => $deviceData['hostname'],
                        'platform' => $deviceData['os'],
                        'platform_version' => $deviceData['kernel'],
                        'app_version' => config('app.version', '1.0.0'),
                    ],
                ]);

            if ($response->failed()) {
                return new ActivationResult(
                    success: false,
                    message: $response->json('message', 'Gagal aktivasi'),
                );
            }

            $result = ActivationResult::fromArray($response->json());

            if ($result->success && ! $result->requiresApproval) {
                $this->storeTokenFromValidation($licenseKey, $fingerprint, $result->offlineUntil);
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
            $response = Http::timeout($this->timeout)
                ->get("{$this->serverUrl}/api/v1/verify/{$licenseKey}/{$fingerprint}", [
                    'code' => $code,
                ]);

            if ($response->failed()) {
                return false;
            }

            $data = $response->json('data', []);

            if ($data['valid'] ?? false) {
                $this->storeTokenFromValidation($licenseKey, $fingerprint, $data['offline_until'] ?? null);

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
            $response = Http::timeout($this->timeout)
                ->post("{$this->serverUrl}/api/v1/validate", [
                    'license_key' => $licenseKey,
                    'device' => [
                        'fingerprint' => $fingerprint,
                    ],
                ]);

            $result = ValidationResult::fromArray($response->json());

            if ($result->valid) {
                $this->cacheTokenFromServer($licenseKey, $fingerprint, $result);
            }

            if ($response->status() === 403) {
                $this->cache->clearToken();
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

        try {
            if ($this->cache->detectClockDrift($token)) {
                throw new ClockDriftDetectedException;
            }
        } catch (ClockDriftDetectedException $e) {
            throw $e;
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
        $withinGrace = false;
        $daysRemaining = 0;

        if ($token !== null) {
            $withinGrace = $this->cache->isWithinGracePeriod($token);
            $daysRemaining = $this->cache->graceDaysRemaining($token);
        }

        if ($token === null) {
            return new LicenseInfo(
                isValid: false,
                status: LicenseStatus::NotActivated,
                requiresOnlineRefresh: true,
            );
        }

        $status = LicenseStatus::tryFrom($token['status'] ?? '') ?? LicenseStatus::Unknown;

        if ($token['offline_until'] !== null && now()->lessThanOrEqualTo($token['offline_until'])) {
            $status = LicenseStatus::Active;
        }

        if ($withinGrace && $daysRemaining <= 3 && $daysRemaining > 0) {
            $status = LicenseStatus::GraceWarning;
        }

        if (! $withinGrace && $status === LicenseStatus::Active) {
            $status = LicenseStatus::Locked;
        }

        return new LicenseInfo(
            isValid: $withinGrace && $status !== LicenseStatus::Locked,
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
            $response = Http::timeout($this->timeout)
                ->post("{$this->serverUrl}/api/v1/deactivate", [
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

    public function isValid(): bool
    {
        try {
            $result = $this->validateOffline();

            return $result->valid;
        } catch (LicenseNotActivatedException|ClockDriftDetectedException) {
            return false;
        }
    }

    private function storeTokenFromValidation(string $licenseKey, string $fingerprint, ?string $offlineUntil): void
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
            'server_time' => now()->toIso8601String(),
            'features' => [],
        ]);
    }

    private function cacheTokenFromServer(string $licenseKey, string $fingerprint, ValidationResult $result): void
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
            'server_time' => now()->toIso8601String(),
            'features' => $result->features,
        ]);
    }

    private function resolveLicenseKey(): string
    {
        $token = $this->cache->retrieveToken();

        return $token['license_key'] ?? $this->licenseKey;
    }
}
