<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdatePerformances
{
    public function execute(User $user, string $theme): array
    {
        return DB::transaction(function () use ($user, $theme): array {
            DB::update("UPDATE users SET performances = jsonb_set(performances, '{theme}', to_jsonb(?::text), false), updated_at = ? WHERE id = ?", [$theme, now(), $user->id]);

            return $user->refresh()->performances;
        });
    }
}
