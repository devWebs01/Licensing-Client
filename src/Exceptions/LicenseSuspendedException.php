<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

final class LicenseSuspendedException extends Exception
{
    public function __construct(string $message = 'Lisensi ditangguhkan, hubungi admin')
    {
        parent::__construct($message);
    }
}
