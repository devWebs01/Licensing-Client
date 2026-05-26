<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\ValueObjects;

use DevWebs01\LicensingClient\Enums\LicenseStatus;

readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public LicenseStatus $status = LicenseStatus::Unknown,
        public ?string $offlineUntil = null,
        public ?string $expiresAt = null,
        public ?string $message = null,
        public bool $deviceLimitReached = false,
        public ?int $maxDevices = null,
        public ?int $registeredDevicesCount = null,
        public ?string $serverUpdatedAt = null,
        public array $features = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            valid: ($data['status'] ?? '') === 'active',
            status: LicenseStatus::tryFrom($data['status'] ?? '') ?? LicenseStatus::Unknown,
            offlineUntil: $data['offline_until'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            message: null,
        );
    }
}
