<?php

use App\Modules\Access\Providers\AccessServiceProvider;
use App\Modules\Login\Providers\LoginServiceProvider;
use App\Modules\Organization\Providers\OrganizationServiceProvider;
use App\Modules\Personnel\Providers\PersonnelServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    OrganizationServiceProvider::class,
    PersonnelServiceProvider::class,
    AccessServiceProvider::class,

    LoginServiceProvider::class,
];
