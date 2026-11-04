<?php

namespace App\Modules\Login\Services;

use RuntimeException;

class LoginFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 401)
    {
        parent::__construct($errorCode);
    }
}
