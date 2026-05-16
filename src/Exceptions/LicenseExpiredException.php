<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

final class LicenseExpiredException extends Exception
{
    public function __construct(string $message = 'Lisensi telah kedaluwarsa')
    {
        parent::__construct($message);
    }
}
