<?php

namespace App\Modules\Personnel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Actions\ManageMemberConnections;
use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\AccountDirectory;
use App\Modules\Login\Services\LoginFailure;
use Illuminate\Http\Request;

class MemberConnectionController extends Controller
{
    public function __construct(private EffectivePermissions $access, private AccountDirectory $directory, private ManageMemberConnections $change) {}

    private function allow(Request $request): void
    {
        $this->access->authorize($request->user()->id, 'personnel.members.manage');
    }

    private function connection(Member $member): array
    {
        $source = $member->userSource;

        return ['id' => $member->id, 'uuid' => $member->uuid,
            'source' => ['slug' => $source->slug, 'title' => $source->title, 'is_active' => $source->is_active]];
    }

    public function index(Request $request, User $user)
    {
        $this->allow($request);
        $rows = $user->members()->with('userSource')->orderBy('id')->get()->map(function (Member $member): array {
            $profile = null;
            $state = 'source_inactive';
            if ($member->userSource->is_active) {
                try {
                    $profile = $this->directory->account($member->userSource, $member->uuid);
                    $state = $profile ? 'available' : 'account_missing';
                    if ($profile) {
                        unset($profile['is_service']);
                    }
                } catch (LoginFailure) {
                    $state = 'unavailable';
                }
            }

            return [...$this->connection($member), 'profile' => $profile, 'profile_state' => $state];
        });

        return response()->json(['data' => $rows]);
    }

    public function sources(Request $request, User $user)
    {
        $this->allow($request);
        $occupied = $user->members()->pluck('user_source_id');

        return response()->json(['data' => UserSource::where('is_active', true)->orderBy('title')->get()->map(fn ($source) => [
            'slug' => $source->slug, 'title' => $source->title,
            'can_search' => ! $occupied->contains($source->id),
        ])]);
    }

    public function search(Request $request, User $user, UserSource $source)
    {
        $this->allow($request);
        $data = $request->validate(['search' => ['required', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $this->change->assertAvailable($user, $source);
        $configuration = $this->directory->configurationHash($source);
        $result = $this->directory->search($source, $data['search'], (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));
        $current = $source->fresh();
        $this->change->assertAvailable($user, $current);
        if (! hash_equals($configuration, $this->directory->configurationHash($current))) {
            throw new LoginFailure('source_changed', 409);
        }
        $owners = Member::with('user:id,name')->where('user_source_id', $source->id)->whereIn('uuid', array_column($result['data'], 'uuid'))->get()->keyBy('uuid');
        $result['data'] = array_map(function ($profile) use ($owners) {
            $owner = $owners->get($profile['uuid'])?->user;
            $reasons = [];
            if (! $profile['enabled']) {
                $reasons[] = 'account_disabled';
            }
            if ($owner) {
                $reasons[] = 'identity_already_connected';
            }
            unset($profile['is_service']);

            return [...$profile, 'can_connect' => $reasons === [], 'reasons' => $reasons,
                'owner' => $owner ? $owner->only(['id', 'name']) : null];
        }, $result['data']);

        return response()->json($result);
    }

    public function store(Request $request, User $user)
    {
        $this->allow($request);
        $data = $request->validate(['source_slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'], 'uuid' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/D']]);
        $source = UserSource::where('slug', $data['source_slug'])->firstOrFail();
        $member = $this->change->attach($user, $source, $data['uuid']);

        return response()->json(['data' => $this->connection($member)], 201);
    }

    public function destroy(Request $request, User $user, Member $member)
    {
        $this->allow($request);
        $this->change->detach($user, $member);

        return response()->noContent();
    }
}
