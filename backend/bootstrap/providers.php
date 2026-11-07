<?php

use App\Modules\Login\Providers\LoginServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Modules\Organization\Providers\OrganizationServiceProvider::class,
    App\Modules\Personnel\Providers\PersonnelServiceProvider::class,
    App\Modules\Access\Providers\AccessServiceProvider::class,

    LoginServiceProvider::class,
];
