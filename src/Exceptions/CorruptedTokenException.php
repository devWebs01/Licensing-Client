<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

final class CorruptedTokenException extends Exception
{
    public function __construct(string $message = 'Token lisensi rusak')
    {
        parent::__construct($message);
    }
}
