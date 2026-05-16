<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

class LicenseNotActivatedException extends Exception
{
    public function __construct(string $message = 'Lisensi belum diaktivasi')
    {
        parent::__construct($message);
    }
}
