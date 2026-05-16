<?php

namespace DevWebs01\LicensingClient\Tests\Feature;

use DevWebs01\LicensingClient\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ActivationWizardTest extends TestCase
{
    public function test_activate_page_returns_welcome_screen(): void
    {
        $response = $this->get(route('licensing.activate'));

        $response->assertOk();
        $response->assertSee('Selamat Datang');
    }

    public function test_activate_post_with_valid_key_succeeds(): void
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

        $response = $this->post(route('licensing.activate'), [
            'license_key' => 'TEST-ABCD-EFGH-1234',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('success');
    }

    public function test_activate_post_with_approval_mode(): void
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

        $response = $this->post(route('licensing.activate'), [
            'license_key' => 'TEST-ABCD-EFGH-1234',
        ]);

        $response->assertOk();
        $response->assertSee('Menunggu Approval');
    }

    public function test_activate_post_with_empty_key_returns_error(): void
    {
        $response = $this->post(route('licensing.activate'), [
            'license_key' => '',
        ]);

        $response->assertSessionHasErrors(['license_key']);
    }

    public function test_locked_page_renders(): void
    {
        $response = $this->get(route('licensing.locked', ['reason' => 'expired']));

        $response->assertOk();
        $response->assertSee('Akses Diblokir');
    }

    public function test_status_page_renders(): void
    {
        $response = $this->get(route('licensing.status'));

        $response->assertOk();
    }

    public function test_retry_endpoint_works(): void
    {
        $response = $this->post(route('licensing.retry'));

        $response->assertRedirect();
    }
}
