<?php

namespace DevWebs01\LicensingClient\Enums;

enum LicenseStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case GraceWarning = 'grace_warning';
    case Locked = 'locked';
    case NotActivated = 'not_activated';
    case PendingApproval = 'pending_approval';
    case Unknown = 'unknown';

    public function isBlocking(): bool
    {
        return match ($this) {
            self::Active, self::GraceWarning => false,
            self::Suspended, self::Expired, self::Revoked, self::Locked, self::NotActivated => true,
            self::PendingApproval, self::Unknown => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::GraceWarning => 'Grace Warning',
            self::Locked => 'Locked',
            self::NotActivated => 'Not Activated',
            self::PendingApproval => 'Pending Approval',
            self::Unknown => 'Unknown',
        };
    }
}
