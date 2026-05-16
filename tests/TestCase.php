<?php

namespace DevWebs01\LicensingClient\Tests;

use DevWebs01\LicensingClient\LicensingClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LicensingClientServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('licensing-client.server_url', 'https://monitor.test');
        $app['config']->set('licensing-client.license_key', 'TEST-ABCD-EFGH-1234');
        $app['config']->set('licensing-client.environment', 'production');
        $app['config']->set('licensing-client.grace_days', 7);
        $app['config']->set('licensing-client.timeout', 10);
        $app['config']->set('licensing-client.dev_bypass', false);
        $app['config']->set('cache.default', 'array');
    }
}
