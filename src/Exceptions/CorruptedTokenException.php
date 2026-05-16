<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

class CorruptedTokenException extends Exception
{
    public function __construct(string $message = 'Token lisensi rusak')
    {
        parent::__construct($message);
    }
}
