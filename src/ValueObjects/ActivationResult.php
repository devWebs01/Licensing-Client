<?php

namespace DevWebs01\LicensingClient\ValueObjects;

readonly class ActivationResult
{
    public function __construct(
        public bool $success,
        public bool $requiresApproval = false,
        public ?string $activationCode = null,
        public ?string $offlineUntil = null,
        public ?string $message = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $payload = $data['data'] ?? [];

        return new self(
            success: (bool) ($data['success'] ?? false),
            requiresApproval: (bool) ($payload['requires_approval'] ?? false),
            activationCode: $payload['activation_code'] ?? null,
            offlineUntil: $payload['offline_until'] ?? null,
            message: $data['message'] ?? null,
        );
    }
}
