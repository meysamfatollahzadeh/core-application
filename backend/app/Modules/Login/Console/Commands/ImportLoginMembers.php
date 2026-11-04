<?php

namespace App\Modules\Login\Console\Commands;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\ProviderClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportLoginMembers extends Command
{
    protected $signature = 'login:import-members {--source= : UserSource slug} {--dry-run} {--mapping-file= : Private subject-to-user-id JSON map}';

    protected $description = 'Import enabled Keycloak users into local membership records';

    public function handle(ProviderClient $provider): int
    {
        $source = UserSource::where('slug', (string) $this->option('source'))->where('is_active', true)->first();
        if (! $source) {
            $this->error('An active source slug is required.');

            return self::FAILURE;
        }
        try {
            $mapping = [];
            if ($path = $this->option('mapping-file')) {
                $mapping = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($mapping)) {
                    throw new RuntimeException;
                }
                foreach ($mapping as $subject => $userId) {
                    if ((! is_string($subject) && ! is_int($subject)) || ! is_int($userId) || ! User::whereKey($userId)->exists()) {
                        throw new RuntimeException;
                    }
                }
            }
            $token = $provider->post($source, 'token', ['grant_type' => 'client_credentials'], true)['access_token'];
            $created = 0;
            $skipped = 0;
            $seen = [];
            $dryRun = (bool) $this->option('dry-run');
            for ($first = 0; $first < 1000000; $first += $source->data['page_size']) {
                $page = $provider->adminUsers($source, $first, $token);
                foreach ($page as $remote) {
                    $subject = $remote['id'] ?? null;
                    if (! is_string($subject) || $subject === '' || ! ($remote['enabled'] ?? false)
                        || str_starts_with($remote['username'] ?? '', 'service-account-') || isset($seen[$subject])) {
                        $skipped++;

                        continue;
                    }
                    $seen[$subject] = true;
                    $created += DB::transaction(function () use ($source, $remote, $subject, $mapping, $dryRun): int {
                        UserSource::where('slug', $source->slug)->lockForUpdate()->firstOrFail();
                        $existing = Member::where('user_source_id', $source->id)->where('uuid', $subject)->first();
                        if ($existing) {
                            if (isset($mapping[$subject]) && $existing->user_id !== $mapping[$subject]) {
                                throw new RuntimeException('Existing associations cannot be silently changed.');
                            }

                            return 0;
                        }
                        if (! $dryRun) {
                            $name = trim(($remote['firstName'] ?? '').' '.($remote['lastName'] ?? '')) ?: ($remote['username'] ?? 'کاربر سازمان');
                            $user = isset($mapping[$subject]) ? User::findOrFail($mapping[$subject]) : User::create(['name' => mb_substr($name, 0, 255)]);
                            Member::create(['uuid' => $subject, 'user_source_id' => $source->id, 'user_id' => $user->id]);
                        }

                        return 1;
                    });
                }
                if (count($page) < $source->data['page_size']) {
                    $this->info(($dryRun ? 'Dry run: ' : '').'new='.$created.' skipped='.$skipped.' scanned='.count($seen));

                    return self::SUCCESS;
                }
            }
            throw new RuntimeException('Pagination limit exceeded.');
        } catch (Throwable) {
            $this->error('errors=1 Import stopped; completed imports and existing associations were preserved.');

            return self::FAILURE;
        }
    }
}
