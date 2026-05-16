<?php

namespace DevWebs01\LicensingClient\ValueObjects;

use DevWebs01\LicensingClient\Enums\LicenseStatus;

readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public LicenseStatus $status = LicenseStatus::Unknown,
        public ?string $offlineUntil = null,
        public ?string $product = null,
        public ?string $expiresAt = null,
        public int $maxDevices = 0,
        public int $devicesCount = 0,
        public ?string $message = null,
        public array $features = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $payload = $data['data'] ?? [];

        return new self(
            valid: (bool) ($payload['valid'] ?? false),
            status: LicenseStatus::tryFrom($payload['status'] ?? '') ?? LicenseStatus::Unknown,
            offlineUntil: $payload['offline_until'] ?? null,
            product: $payload['product'] ?? null,
            expiresAt: $payload['expires_at'] ?? null,
            maxDevices: (int) ($payload['max_devices'] ?? 0),
            devicesCount: (int) ($payload['devices_count'] ?? 0),
            message: $data['message'] ?? null,
            features: $payload['features'] ?? [],
        );
    }
}
