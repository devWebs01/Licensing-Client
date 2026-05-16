<?php

namespace DevWebs01\LicensingClient\Tests\Unit;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Tests\TestCase;
use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\LicenseInfo;
use DevWebs01\LicensingClient\ValueObjects\ValidationResult;

class ValueObjectsTest extends TestCase
{
    public function test_activation_result_constructor(): void
    {
        $result = new ActivationResult(
            success: true,
            requiresApproval: false,
            offlineUntil: '2026-05-23T13:00:00Z',
            message: 'Sukses'
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->requiresApproval);
        $this->assertSame('2026-05-23T13:00:00Z', $result->offlineUntil);
        $this->assertSame('Sukses', $result->message);
        $this->assertNull($result->activationCode);
    }

    public function test_activation_result_from_array_auto_activated(): void
    {
        $result = ActivationResult::fromArray([
            'success' => true,
            'message' => 'Perangkat berhasil diaktifkan',
            'data' => [
                'device_id' => 1,
                'offline_until' => '2026-05-23T13:00:00Z',
            ],
        ]);

        $this->assertTrue($result->success);
        $this->assertFalse($result->requiresApproval);
        $this->assertSame('2026-05-23T13:00:00Z', $result->offlineUntil);
    }

    public function test_activation_result_from_array_approval_mode(): void
    {
        $result = ActivationResult::fromArray([
            'success' => true,
            'message' => 'Kode aktivasi dibuat',
            'data' => [
                'requires_approval' => true,
                'activation_code' => 'A7F3B2C1',
            ],
        ]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->requiresApproval);
        $this->assertSame('A7F3B2C1', $result->activationCode);
    }

    public function test_validation_result_constructor(): void
    {
        $result = new ValidationResult(
            valid: true,
            status: LicenseStatus::Active,
            product: 'Test App',
            expiresAt: '2026-06-16',
            maxDevices: 3,
            devicesCount: 1,
            features: ['pos', 'reports'],
        );

        $this->assertTrue($result->valid);
        $this->assertSame(LicenseStatus::Active, $result->status);
        $this->assertSame('Test App', $result->product);
        $this->assertCount(2, $result->features);
    }

    public function test_validation_result_from_array(): void
    {
        $result = ValidationResult::fromArray([
            'success' => true,
            'message' => 'Lisensi valid',
            'data' => [
                'valid' => true,
                'status' => 'active',
                'product' => 'Test App',
                'expires_at' => '2026-06-16',
                'max_devices' => 3,
                'devices_count' => 1,
                'offline_until' => '2026-05-23T13:00:00Z',
                'features' => ['pos', 'reports'],
            ],
        ]);

        $this->assertTrue($result->valid);
        $this->assertSame(LicenseStatus::Active, $result->status);
        $this->assertSame('Test App', $result->product);
        $this->assertSame(3, $result->maxDevices);
        $this->assertSame(1, $result->devicesCount);
    }

    public function test_license_info_constructor(): void
    {
        $info = new LicenseInfo(
            isValid: true,
            status: LicenseStatus::Active,
            offlineUntil: '2026-05-23T13:00:00Z',
            isWithinGracePeriod: true,
            graceDaysRemaining: 3,
            product: 'Test App',
        );

        $this->assertTrue($info->isValid);
        $this->assertSame(LicenseStatus::Active, $info->status);
        $this->assertSame(3, $info->graceDaysRemaining);
        $this->assertTrue($info->isWithinGracePeriod);
    }

    public function test_license_status_blocking_values(): void
    {
        $this->assertFalse(LicenseStatus::Active->isBlocking());
        $this->assertFalse(LicenseStatus::GraceWarning->isBlocking());
        $this->assertTrue(LicenseStatus::Suspended->isBlocking());
        $this->assertTrue(LicenseStatus::Expired->isBlocking());
        $this->assertTrue(LicenseStatus::Revoked->isBlocking());
        $this->assertTrue(LicenseStatus::Locked->isBlocking());
        $this->assertTrue(LicenseStatus::NotActivated->isBlocking());
        $this->assertFalse(LicenseStatus::PendingApproval->isBlocking());
    }

    public function test_license_status_labels(): void
    {
        $this->assertSame('Active', LicenseStatus::Active->label());
        $this->assertSame('Suspended', LicenseStatus::Suspended->label());
        $this->assertSame('Locked', LicenseStatus::Locked->label());
        $this->assertSame('Not Activated', LicenseStatus::NotActivated->label());
    }
}
