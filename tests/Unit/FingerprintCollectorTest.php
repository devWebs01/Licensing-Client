<?php

namespace DevWebs01\LicensingClient\Tests\Unit;

use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Tests\TestCase;

class FingerprintCollectorTest extends TestCase
{
    private FingerprintCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collector = new FingerprintCollector;
    }

    public function test_fingerprint_returns_64_char_hex_string(): void
    {
        $fingerprint = $this->collector->fingerprint();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
    }

    public function test_fingerprint_is_consistent(): void
    {
        $first = $this->collector->fingerprint();
        $second = $this->collector->fingerprint();

        $this->assertSame($first, $second);
    }

    public function test_fingerprint_returns_same_as_collect(): void
    {
        $this->assertSame(
            $this->collector->fingerprint(),
            $this->collector->collect()
        );
    }

    public function test_collect_data_returns_expected_keys(): void
    {
        $data = $this->collector->collectData();

        $this->assertArrayHasKey('hostname', $data);
        $this->assertArrayHasKey('os', $data);
        $this->assertArrayHasKey('kernel', $data);
        $this->assertArrayHasKey('app_path', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('php_version', $data);
    }

    public function test_collect_data_contains_php_version(): void
    {
        $data = $this->collector->collectData();

        $this->assertSame(PHP_VERSION, $data['php_version']);
    }
}
