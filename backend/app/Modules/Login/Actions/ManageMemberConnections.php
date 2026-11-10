<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\AccountDirectory;
use App\Modules\Login\Services\LoginFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ManageMemberConnections
{
    public function __construct(private AccountDirectory $directory) {}

    public function assertAvailable(User $user, UserSource $source): void
    {
        if (! $source->is_active) {
            throw new LoginFailure('source_inactive', 409);
        }
        if ($user->members()->where('user_source_id', $source->id)->exists()) {
            throw new LoginFailure('user_source_occupied', 409);
        }
    }

    public function attach(User $user, UserSource $source, string $uuid): Member
    {
        $this->assertAvailable($user, $source);
        $configuration = $this->directory->configurationHash($source);
        $profile = $this->directory->account($source, $uuid);
        if (! $profile || ! $profile['enabled'] || $profile['is_service']) {
            throw new LoginFailure('account_ineligible', 422);
        }
        try {
            return DB::transaction(function () use ($user, $source, $uuid, $configuration): Member {
                $current = UserSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $this->assertAvailable($user, $current);
                if (! hash_equals($configuration, $this->directory->configurationHash($current))) {
                    throw new LoginFailure('source_changed', 409);
                }
                if (Member::where('uuid', $uuid)->where('user_source_id', $source->id)->exists()) {
                    throw new LoginFailure('identity_already_connected', 409);
                }

                return Member::create(['uuid' => $uuid, 'user_source_id' => $source->id, 'user_id' => $user->id]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new LoginFailure('membership_conflict', 409);
            }
            throw $e;
        }
    }

    public function detach(User $user, Member $member): void
    {
        abort_unless($member->user_id === $user->id, 404);
        DB::transaction(function () use ($user, $member): void {
            UserSource::whereKey($member->user_source_id)->lockForUpdate()->firstOrFail();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = Member::whereKey($member->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            PersonalAccessToken::where('member_id', $current->id)->delete();
            $current->delete();
        });
    }
}
