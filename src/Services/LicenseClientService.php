<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Exceptions\CorruptedTokenException;
use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\LicenseInfo;
use DevWebs01\LicensingClient\ValueObjects\ValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class LicenseClientService
{
    private ?LicenseInfo $resolvedStatus = null;

    public function __construct(
        private readonly LicenseCacheService $cache,
        private readonly FingerprintCollector $fingerprint,
        private readonly string $githubRawBase,
        private readonly string $licenseKey,
        private readonly string $appName,
        private readonly int $graceDays,
    ) {}

    public function activate(string $licenseKey): ActivationResult
    {
        $response = $this->fetchFromGithub($licenseKey);

        if ($response['status'] === 'error') {
            return new ActivationResult(
                success: false,
                message: 'Gagal menghubungi server lisensi. Silakan coba lagi nanti.',
            );
        }

        if ($response['status'] === 'not_found' || $response['data'] === null) {
            return new ActivationResult(
                success: false,
                message: 'Lisensi tidak ditemukan. Periksa license key Anda.',
            );
        }

        $data = $response['data'];
        $status = $data['status'] ?? 'unknown';

        if ($status !== 'active') {
            return new ActivationResult(
                success: false,
                message: 'Lisensi tidak aktif. Status: '.$status,
            );
        }

        $expiresAt = $data['expires_at'] ?? null;

        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return new ActivationResult(
                success: false,
                message: 'Lisensi sudah kedaluwarsa pada '.$expiresAt,
            );
        }

        $maxDevices = (int) ($data['max_devices'] ?? 0);
        $registeredDevices = (int) ($data['registered_devices_count'] ?? 0);

        if ($maxDevices > 0 && $registeredDevices >= $maxDevices) {
            return new ActivationResult(
                success: false,
                message: 'Batas perangkat sudah tercapai ('.$maxDevices.'). Hubungi administrator untuk menambah kapasitas.',
                deviceLimitReached: true,
            );
        }

        $fingerprint = $this->fingerprint->fingerprint();
        $offlineUntil = $this->calculateOfflineUntil($expiresAt);

        $this->storeLicenseData(
            licenseKey: $licenseKey,
            fingerprint: $fingerprint,
            status: LicenseStatus::Active->value,
            offlineUntil: $offlineUntil,
            expiresAt: $expiresAt,
            maxDevices: $maxDevices,
            registeredDevicesCount: $registeredDevices,
            product: $data['product_name'] ?? null,
            features: $data['features'] ?? [],
            serverUpdatedAt: $data['updated_at'] ?? null,
        );

        return new ActivationResult(
            success: true,
            offlineUntil: $offlineUntil,
        );
    }

    public function sync(): ValidationResult
    {
        $licenseKey = $this->resolveLicenseKey();

        $response = $this->fetchFromGithub($licenseKey);

        if ($response['status'] === 'error') {
            return new ValidationResult(
                valid: true,
                status: LicenseStatus::Unknown,
                message: 'Gagal menghubungi server lisensi. Menggunakan mode offline.',
            );
        }

        if ($response['status'] === 'not_found' || $response['data'] === null) {
            $this->cache->clearToken();
            $this->cache->clearStatus();
            $this->resolvedStatus = null;

            return new ValidationResult(
                valid: false,
                status: LicenseStatus::Unknown,
                message: 'Lisensi tidak ditemukan di GitHub',
            );
        }

        $data = $response['data'];
        $status = LicenseStatus::tryFrom($data['status'] ?? '') ?? LicenseStatus::Unknown;
        $expiresAt = $data['expires_at'] ?? null;
        $valid = $status === LicenseStatus::Active && (! $expiresAt || now()->lessThanOrEqualTo($expiresAt));

        $maxDevices = (int) ($data['max_devices'] ?? 0);
        $registeredDevices = (int) ($data['registered_devices_count'] ?? 0);
        $deviceLimitReached = $maxDevices > 0 && $registeredDevices >= $maxDevices;

        if ($valid) {
            $fingerprint = $this->fingerprint->fingerprint();
            $offlineUntil = $this->calculateOfflineUntil($expiresAt);

            $this->storeLicenseData(
                licenseKey: $licenseKey,
                fingerprint: $fingerprint,
                status: LicenseStatus::Active->value,
                offlineUntil: $offlineUntil,
                expiresAt: $expiresAt,
                maxDevices: $maxDevices,
                registeredDevicesCount: $registeredDevices,
                product: $data['product_name'] ?? null,
                features: $data['features'] ?? [],
                serverUpdatedAt: $data['updated_at'] ?? null,
            );
        } else {
            $this->cache->clearToken();
            $this->cache->clearStatus();
            $this->resolvedStatus = null;
        }

        return new ValidationResult(
            valid: $valid,
            status: $status,
            offlineUntil: $valid ? $this->calculateOfflineUntil($expiresAt) : null,
            expiresAt: $expiresAt,
            deviceLimitReached: $deviceLimitReached,
            maxDevices: $maxDevices,
            registeredDevicesCount: $registeredDevices,
            serverUpdatedAt: $data['updated_at'] ?? null,
            features: $data['features'] ?? [],
        );
    }

    public function status(): LicenseInfo
    {
        if ($this->resolvedStatus !== null) {
            return $this->resolvedStatus;
        }

        $statusData = $this->cache->retrieveStatus();

        if ($statusData === null) {
            $this->resolvedStatus = new LicenseInfo(
                isValid: false,
                status: LicenseStatus::NotActivated,
                requiresOnlineRefresh: true,
            );

            return $this->resolvedStatus;
        }

        $withinGrace = $this->cache->isWithinGracePeriod($statusData['offline_until']);
        $daysRemaining = $this->cache->graceDaysRemaining($statusData['offline_until']);

        $status = LicenseStatus::tryFrom($statusData['status'] ?? '') ?? LicenseStatus::Unknown;

        if ($withinGrace && $daysRemaining <= 3 && $daysRemaining > 0) {
            $status = LicenseStatus::GraceWarning;
        }

        if (! $withinGrace && ! in_array($status, [LicenseStatus::Suspended, LicenseStatus::Expired, LicenseStatus::Revoked, LicenseStatus::NotActivated], true)) {
            $status = LicenseStatus::Locked;
        }

        $product = null;
        $maxDevices = null;
        $registeredDevicesCount = null;
        $serverUpdatedAt = null;
        $expiresAt = null;
        $features = [];

        try {
            $token = $this->cache->retrieveToken();

            if ($token !== null) {
                $product = $token['product'] ?? null;
                $maxDevices = $token['max_devices'] ?? null;
                $registeredDevicesCount = $token['registered_devices_count'] ?? null;
                $serverUpdatedAt = $token['server_updated_at'] ?? null;
                $expiresAt = $token['expires_at'] ?? null;
                $features = $token['features'] ?? [];
            }
        } catch (CorruptedTokenException) {
        }

        $deviceLimitReached = $maxDevices !== null && $registeredDevicesCount !== null
            && $maxDevices > 0 && $registeredDevicesCount >= $maxDevices;

        $staleCache = $serverUpdatedAt !== null && $statusData['updated_at'] !== null
            && $statusData['updated_at'] < $serverUpdatedAt;

        $this->resolvedStatus = new LicenseInfo(
            isValid: $withinGrace,
            status: $status,
            offlineUntil: $statusData['offline_until'] ?? null,
            isWithinGracePeriod: $withinGrace,
            graceDaysRemaining: $daysRemaining,
            product: $product,
            cachedAt: $statusData['updated_at'] ?? null,
            requiresOnlineRefresh: ! $withinGrace || $staleCache,
            maxDevices: $maxDevices,
            registeredDevicesCount: $registeredDevicesCount,
            serverUpdatedAt: $serverUpdatedAt,
            expiresAt: $expiresAt,
            deviceLimitReached: $deviceLimitReached,
            features: $features,
        );

        return $this->resolvedStatus;
    }

    public function refresh(): bool
    {
        try {
            $result = $this->sync();

            return $result->valid;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isStatusCached(): bool
    {
        return $this->cache->hasStatus();
    }

    public function deactivate(): bool
    {
        $this->cache->clearToken();
        $this->cache->clearStatus();
        $this->resolvedStatus = null;

        return true;
    }

    public function info(): LicenseInfo
    {
        return $this->status();
    }

    public function isValid(): bool
    {
        return $this->status()->isValid;
    }

    /**
     * @return array{status: string, data: array<string, mixed>|null}
     */
    private function fetchFromGithub(string $licenseKey): array
    {
        $hash = sha1($licenseKey);
        $base = rtrim($this->githubRawBase, '/');
        $url = "{$base}/licenses/{$hash}.json";

        try {
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                return ['status' => 'success', 'data' => $response->json()];
            }

            if ($response->status() === 404) {
                return ['status' => 'not_found', 'data' => null];
            }

            return ['status' => 'error', 'data' => null];
        } catch (ConnectionException) {
            return ['status' => 'error', 'data' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function storeLicenseData(
        string $licenseKey,
        string $fingerprint,
        string $status,
        string $offlineUntil,
        ?string $expiresAt = null,
        ?int $maxDevices = null,
        ?int $registeredDevicesCount = null,
        ?string $product = null,
        array $features = [],
        ?string $serverUpdatedAt = null,
    ): void {
        $this->cache->storeStatus($status, true, $offlineUntil, $serverUpdatedAt);

        $this->cache->storeToken([
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'status' => $status,
            'product' => $product ?? $this->appName,
            'expires_at' => $expiresAt ?? now()->addDays($this->graceDays)->toDateString(),
            'offline_until' => $offlineUntil,
            'max_devices' => $maxDevices,
            'registered_devices_count' => $registeredDevicesCount,
            'server_updated_at' => $serverUpdatedAt,
            'features' => $features,
        ]);

        $this->resolvedStatus = null;
    }

    private function calculateOfflineUntil(?string $expiresAt): string
    {
        if ($expiresAt) {
            return now()->parse($expiresAt)->addDays($this->graceDays)->toIso8601String();
        }

        return now()->addDays($this->graceDays)->toIso8601String();
    }

    private function resolveLicenseKey(): string
    {
        try {
            $token = $this->cache->retrieveToken();
        } catch (CorruptedTokenException) {
            $token = null;
        }

        return $token['license_key'] ?? $this->licenseKey;
    }

    public function renderGracePeriodWarning(): string
    {
        $info = $this->status();

        if (! $info->isWithinGracePeriod || $info->graceDaysRemaining > 3 || $info->graceDaysRemaining <= 0) {
            return '';
        }

        $days = $info->graceDaysRemaining;
        $message = "Sistem belum mendeteksi koneksi internet. Silakan hubungkan komputer ke internet untuk memperpanjang lisensi otomatis. Sisa waktu offline: <strong>{$days} hari</strong>.";

        return <<<HTML
        <div id="license-grace-warning" style="position: fixed; bottom: 0; left: 0; right: 0; background-color: #ef4444; color: white; padding: 16px; text-align: center; font-family: system-ui, -apple-system, sans-serif; font-size: 14px; z-index: 999999; box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1); display: flex; justify-content: center; align-items: center; gap: 12px;">
            <div>
                <svg style="width: 20px; height: 20px; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <div>{$message}</div>
            <button onclick="document.getElementById('license-grace-warning').style.display='none'" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 4px 8px; border-radius: 4px; cursor: pointer; margin-left: 16px;">Tutup</button>
        </div>
        HTML;
    }
}
