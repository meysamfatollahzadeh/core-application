<?php

namespace App\Modules\Login\Console\Commands;

use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\SourceConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpsertLoginSource extends Command
{
    protected $signature = 'login:sources:upsert {--config-file= : Private JSON configuration path}';

    protected $description = 'Register or update a Keycloak user source by its stable slug';

    public function handle(SourceConfiguration $configuration): int
    {
        $path = $this->option('config-file');
        if (! is_string($path) || ! is_readable($path)) {
            $this->error('A readable private configuration file is required.');

            return self::FAILURE;
        }
        try {
            $values = $configuration->validate(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
            DB::transaction(function () use ($values): void {
                $source = UserSource::where('slug', $values['slug'])->lockForUpdate()->first() ?? new UserSource;
                $previous = $source->exists ? $source->fingerprint() : null;
                $source->fill($values)->save();
                if ($previous && ($previous !== $source->fingerprint() || ! $source->is_active)) {
                    PersonalAccessToken::whereHas('member', fn ($query) => $query->where('user_source_id', $source->id))->delete();
                }
            });
        } catch (Throwable) {
            $this->error('Source registration failed. Verify the JSON schema, slug and provider settings.');

            return self::FAILURE;
        }
        $this->info('Source '.$values['slug'].' registered. Secrets were not printed.');

        return self::SUCCESS;
    }
}
