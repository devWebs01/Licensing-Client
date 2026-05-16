<?php

namespace DevWebs01\LicensingClient\Facades;

use DevWebs01\LicensingClient\ValueObjects\ActivationResult;
use DevWebs01\LicensingClient\ValueObjects\LicenseInfo;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isValid()
 * @method static LicenseInfo info()
 * @method static ActivationResult activate(string $key)
 * @method static bool verifyActivation(string $code)
 * @method static bool refresh()
 * @method static bool deactivate()
 * @method static bool hasFeature(string $feature)
 */
class LicenseClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'licensing-client';
    }
}
