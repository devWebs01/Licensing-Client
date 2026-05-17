<?php

namespace DevWebs01\LicensingClient\Services;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
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
    private ?LicenseInfo $resolvedStatus = null;

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
                $this->storeLicenseData($licenseKey, $fingerprint, $result->offlineUntil, LicenseStatus::Active->value, [], null, $serverTime);
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
                $this->storeLicenseData($licenseKey, $fingerprint, $data['offline_until'] ?? null, LicenseStatus::Active->value, [], null, $serverTime);

                return true;
            }

            return false;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function sync(): ValidationResult
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
                $this->cache->clearStatus();
                $this->resolvedStatus = null;

                return new ValidationResult(
                    valid: false,
                    status: LicenseStatus::tryFrom($response->json('data.status', 'unknown')) ?? LicenseStatus::Unknown,
                    message: $response->json('message', 'Lisensi tidak valid'),
                );
            }

            if ($response->failed()) {
                throw new ServerUnreachableException;
            }

            $result = ValidationResult::fromArray($response->json());
            $serverTime = $response->header('Date');

            if ($result->valid) {
                $offlineUntil = $result->offlineUntil
                    ? now()->parse($result->offlineUntil)
                    : now()->addDays($this->graceDays);

                $this->storeLicenseData(
                    $licenseKey,
                    $fingerprint,
                    $offlineUntil->toIso8601String(),
                    $result->status->value,
                    $result->features,
                    $result->product ?? $this->appName,
                    $serverTime,
                    $result->expiresAt,
                );
            }

            return $result;
        } catch (ConnectionException) {
            throw new ServerUnreachableException;
        }
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

        if (! $withinGrace) {
            $status = LicenseStatus::Locked;
        }

        $this->resolvedStatus = new LicenseInfo(
            isValid: $withinGrace,
            status: $status,
            offlineUntil: $statusData['offline_until'] ?? null,
            isWithinGracePeriod: $withinGrace,
            graceDaysRemaining: $daysRemaining,
            product: null,
            cachedAt: $statusData['updated_at'] ?? null,
            requiresOnlineRefresh: ! $withinGrace,
        );

        return $this->resolvedStatus;
    }

    public function refresh(): bool
    {
        try {
            $result = $this->sync();

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
                $this->cache->clearStatus();
                $this->resolvedStatus = null;

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
        $info = $this->status();

        $token = $this->cache->retrieveToken();

        if ($token !== null && $info->product === null) {
            return new LicenseInfo(
                isValid: $info->isValid,
                status: $info->status,
                offlineUntil: $info->offlineUntil,
                isWithinGracePeriod: $info->isWithinGracePeriod,
                graceDaysRemaining: $info->graceDaysRemaining,
                product: $token['product'] ?? null,
                cachedAt: $token['cached_at'] ?? null,
                requiresOnlineRefresh: $info->requiresOnlineRefresh,
            );
        }

        return $info;
    }

    public function isValid(): bool
    {
        return $this->status()->isValid;
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

    private function storeLicenseData(
        string $licenseKey,
        string $fingerprint,
        ?string $offlineUntil,
        string $status,
        array $features,
        ?string $product = null,
        ?string $serverTime = null,
        ?string $expiresAt = null,
    ): void {
        $offlineUntilDate = $offlineUntil
            ? now()->parse($offlineUntil)
            : now()->addDays($this->graceDays);

        $offlineUntilStr = $offlineUntilDate->toIso8601String();

        $this->cache->storeStatus($status, true, $offlineUntilStr);

        $this->cache->storeToken([
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'status' => $status,
            'product' => $product ?? $this->appName,
            'expires_at' => $expiresAt ?? $offlineUntilDate->toDateString(),
            'offline_until' => $offlineUntilStr,
            'server_time' => $this->resolveServerTime($serverTime),
            'features' => $features,
        ]);

        $this->resolvedStatus = null;
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
