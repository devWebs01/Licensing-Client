<?php

namespace DevWebs01\LicensingClient\Exceptions;

use Exception;

final class ClockDriftDetectedException extends Exception
{
    public function __construct(string $message = 'Waktu server tidak sinkron')
    {
        parent::__construct($message);
    }
}
