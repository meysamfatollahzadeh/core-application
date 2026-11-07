<?php

namespace App\Modules\Personnel\Actions;

use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Models\User;
use App\Modules\Organization\Actions\AtomicChange;
use App\Modules\Organization\Models\Position;
use App\Modules\Personnel\Models\UserPosition;
use Illuminate\Support\Facades\DB;

final class ManagePersonnel
{
    public function user(User $user, array $data): User
    {
        return AtomicChange::run(fn () => tap($user, fn ($m) => $m->fill($data)->save()));
    }

    public function assignment(?UserPosition $assignment, User $user, Position $position, array $reports, ?int $actor = null): UserPosition
    {
        return AtomicChange::run(function () use ($assignment, $user, $position, $reports, $actor) {
            $position->refresh();
            if (! $assignment && $actor !== null) {
                app(EffectivePermissions::class)->authorizePositionOccupancy($actor, $position->id);
            }
            abort_if(in_array($position->id, $reports, true), 422, 'Cannot report to the assigned position.');
            abort_if($position->parent_position_id && count($reports) === 0, 422, 'A nonroot assignment requires reporting targets.');
            abort_unless(Position::whereIn('id', $reports)->count() === count($reports), 422, 'Unknown or duplicate reporting positions.');
            $assignment ??= UserPosition::create(['user_id' => $user->id, 'position_id' => $position->id]);
            DB::table('user_position_reports')->where('user_position_id', $assignment->id)->delete();
            foreach ($reports as $id) {
                DB::table('user_position_reports')->insert(['user_position_id' => $assignment->id, 'report_to_position_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $assignment;
        });
    }

    public function delete(UserPosition $assignment): void
    {
        AtomicChange::run(function () use ($assignment) {
            $before = app(EffectivePermissions::class)->hasAdministrator();
            $assignment->delete();
            app(EffectivePermissions::class)->protectAdministrator($before);
        });
    }
}
