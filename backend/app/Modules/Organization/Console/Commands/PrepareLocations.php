<?php

namespace App\Modules\Organization\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareLocations extends Command
{
    protected $signature = 'organization:prepare-locations {--building= : Explicit building title to create or reuse} {--fallback-area= : Existing area for unassigned users} {--mapping-file= : Private JSON user-id to area-id object} {--dry-run}';

    protected $description = 'Explicitly prepare current user locations without changing identity associations';

    public function handle(): int
    {
        try {
            $count = DB::transaction(function (): int {
                DB::statement('SELECT pg_advisory_xact_lock(7261107)');
                DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $mapping = $this->option('mapping-file') ? json_decode(file_get_contents($this->option('mapping-file')), true, flags: JSON_THROW_ON_ERROR) : [];
                if (! is_array($mapping)) {
                    throw new \RuntimeException('Mapping must be an object');
                }
                $fallback = $this->option('fallback-area');
                if ($this->option('building')) {
                    if ($fallback) {
                        throw new \RuntimeException('Choose a building or fallback area, not both');
                    }
                    $title = trim($this->option('building'));
                    if ($title === '' || mb_strlen($title) > 255) {
                        throw new \RuntimeException('Invalid building title');
                    }
                    $matches = DB::table('buildings')->where('title', $title)->get();
                    if ($matches->count() > 1) {
                        throw new \RuntimeException('Ambiguous building; use --fallback-area');
                    }
                    if ($matches->isEmpty()) {
                        $building = DB::table('buildings')->insertGetId(['title' => $title, 'created_at' => now(), 'updated_at' => now()]);
                        $fallback = DB::table('building_areas')->insertGetId(['building_id' => $building, 'title' => 'نامشخص', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
                    } else {
                        $fallback = DB::table('building_areas')->where('building_id', $matches[0]->id)->where('is_default', true)->value('id');
                    }
                }
                if ($fallback && ! DB::table('building_areas')->where('id', $fallback)->exists()) {
                    throw new \RuntimeException('Fallback area does not exist');
                }
                foreach ($mapping as $user => $area) {
                    if (! ctype_digit((string) $user) || ! is_int($area) || ! DB::table('users')->where('id', $user)->exists() || ! DB::table('building_areas')->where('id', $area)->exists()) {
                        throw new \RuntimeException('Invalid mapping');
                    }
                }
                foreach ($mapping as $user => $area) {
                    DB::table('users')->where('id', $user)->whereNull('building_area_id')->update(['building_area_id' => $area]);
                }
                $remaining = DB::table('users')->whereNull('building_area_id')->count();
                if ($remaining && ! $fallback) {
                    throw new \RuntimeException('Unassigned users require a mapping or explicit fallback');
                }
                $updated = count($mapping);
                if ($fallback) {
                    $updated += DB::table('users')->whereNull('building_area_id')->update(['building_area_id' => $fallback]);
                }
                $this->info('area_id='.($fallback ?: 'mapped').' unresolved=0');
                if ($this->option('dry-run')) {
                    DB::rollBack();
                }

                return $updated;
            });
            $this->info(($this->option('dry-run') ? 'Dry run: ' : '').'prepared='.$count);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Preparation failed; no changes saved. '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
