<?php

namespace DevWebs01\LicensingClient;

use DevWebs01\LicensingClient\Facades\LicenseClient;
use Illuminate\Support\Facades\Blade;

class BladeDirectives
{
    public static function register(): void
    {
        Blade::if('licensed', function () {
            return LicenseClient::isValid();
        });

        Blade::if('feature', function (string $feature) {
            return LicenseClient::hasFeature($feature);
        });

        Blade::if('licenseWarning', function () {
            return session()->has('license_warning');
        });
    }
}
