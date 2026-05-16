<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

class ServerUnreachableException extends Exception
{
    public function __construct(string $message = 'Server lisensi tidak reachable')
    {
        parent::__construct($message);
    }
}
