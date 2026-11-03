<?php

use App\Modules\Login\Models\User;

return [
    'defaults' => ['guard' => 'sanctum', 'passwords' => null],
    'guards' => [],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
    'passwords' => [],
];
