<?php

namespace App\Modules\Access\Console\Commands;

use App\Modules\Login\Models\Member;
use App\Modules\Organization\Actions\AtomicChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapAccess extends Command
{
    protected $signature = 'access:bootstrap {--source= : Existing UserSource slug} {--subject= : Exact imported external subject} {--dry-run}';

    protected $description = 'Explicitly grant global administration to one existing imported identity';

    public function handle(): int
    {
        if (! $this->option('source') || ! $this->option('subject')) {
            $this->error('Exact source slug and subject required.');

            return self::FAILURE;
        }
        $member = Member::whereHas('userSource', fn ($q) => $q->where('slug', $this->option('source')))->where('uuid', $this->option('subject'))->first();
        if (! $member) {
            $this->error('Imported identity not found.');

            return self::FAILURE;
        }
        $role = DB::table('roles')->where('key', 'system-administrator')->value('id');
        if (! $role) {
            $this->error('Run access:sync-permissions first.');

            return self::FAILURE;
        }
        if (! $this->option('dry-run')) {
            AtomicChange::run(fn () => DB::table('user_role')->insertOrIgnore(['user_id' => $member->user_id, 'role_id' => $role]));
        }
        $this->info(($this->option('dry-run') ? 'Dry run: ' : '').'global administrator user_id='.$member->user_id);

        return self::SUCCESS;
    }
}
