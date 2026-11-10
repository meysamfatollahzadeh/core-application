<?php

namespace App\Modules\Login\Console\Commands;

use App\Modules\Login\Actions\ManageLoginSources;
use Illuminate\Console\Command;
use Throwable;

class UpsertLoginSource extends Command
{
    protected $signature = 'login:sources:upsert {--config-file= : Private JSON configuration path}';

    protected $description = 'Register or update a Keycloak user source by its stable slug';

    public function handle(ManageLoginSources $sources): int
    {
        $path = $this->option('config-file');
        if (! is_string($path) || ! is_readable($path)) {
            $this->error('A readable private configuration file is required.');

            return self::FAILURE;
        }
        try {
            $source = $sources->upsert(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $this->error('Source registration failed. Verify the JSON schema, slug and provider settings.');

            return self::FAILURE;
        }
        $this->info('Source '.$source->slug.' registered. Secrets were not printed.');

        return self::SUCCESS;
    }
}
