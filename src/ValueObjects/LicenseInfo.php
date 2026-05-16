<?php

namespace DevWebs01\LicensingClient\ValueObjects;

use DevWebs01\LicensingClient\Enums\LicenseStatus;

readonly class LicenseInfo
{
    public function __construct(
        public bool $isValid,
        public LicenseStatus $status = LicenseStatus::Unknown,
        public ?string $offlineUntil = null,
        public bool $isWithinGracePeriod = false,
        public int $graceDaysRemaining = 0,
        public ?string $product = null,
        public ?string $cachedAt = null,
        public bool $requiresOnlineRefresh = false,
    ) {
    }
}
