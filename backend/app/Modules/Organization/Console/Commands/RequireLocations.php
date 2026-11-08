<?php

namespace App\Modules\Organization\Console\Commands;

use Illuminate\Console\Command;

class RequireLocations extends Command
{
    protected $signature = 'organization:require-locations';

    protected $description = 'Enforce mandatory locations after explicit backfill';

    public function handle(): int
    {
        return $this->call('migrate', ['--path' => 'app/Modules/Organization/Database/ContractMigrations', '--force' => true]);
    }
}
