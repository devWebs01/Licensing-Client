<?php

namespace DevWebs01\LicensingClient\Tests\Feature;

use DevWebs01\LicensingClient\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LicenseActivateCommandTest extends TestCase
{
    public function test_activate_command_success(): void
    {
        Http::fake([
            'monitor.test/api/v1/activate' => Http::response([
                'success' => true,
                'message' => 'Perangkat berhasil diaktifkan',
                'data' => [
                    'device_id' => 1,
                    'offline_until' => now()->addDays(7)->toIso8601String(),
                ],
            ]),
        ]);

        $this->artisan('license:activate', ['key' => 'TEST-ABCD-EFGH-1234'])
            ->assertSuccessful()
            ->expectsOutputToContain('berhasil');
    }

    public function test_activate_command_failure(): void
    {
        Http::fake([
            'monitor.test/api/v1/activate' => Http::response([
                'success' => false,
                'message' => 'Kunci lisensi tidak valid',
            ], 404),
        ]);

        $this->artisan('license:activate', ['key' => 'INVALID-KEY'])
            ->assertFailed();
    }

    public function test_activate_command_approval_mode(): void
    {
        Http::fake([
            'monitor.test/api/v1/activate' => Http::response([
                'success' => true,
                'message' => 'Kode aktivasi dibuat',
                'data' => [
                    'requires_approval' => true,
                    'activation_code' => 'A7F3B2C1',
                    'expires_at' => now()->addHours(24)->toIso8601String(),
                ],
            ]),
        ]);

        $this->artisan('license:activate', ['key' => 'TEST-ABCD-EFGH-1234'])
            ->assertSuccessful()
            ->expectsOutputToContain('A7F3B2C1');
    }

    public function test_status_command_runs(): void
    {
        $this->artisan('license:status')
            ->assertSuccessful();
    }
}
