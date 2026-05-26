<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\ValueObjects;

readonly class ActivationResult
{
    public function __construct(
        public bool $success,
        public ?string $offlineUntil = null,
        public ?string $message = null,
        public bool $deviceLimitReached = false,
    ) {}
}
