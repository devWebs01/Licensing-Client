<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

final class LicenseNotActivatedException extends Exception
{
    public function __construct(string $message = 'Lisensi belum diaktivasi')
    {
        parent::__construct($message);
    }
}
