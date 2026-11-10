<?php

namespace App\Modules\Login\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Actions\ManageLoginSources;
use App\Modules\Login\Http\Requests\ManageSourceRequest;
use App\Modules\Login\Http\Resources\ManagedSourceResource;
use App\Modules\Login\Models\UserSource;
use Illuminate\Http\Request;

class SourceManagementController extends Controller
{
    public function __construct(private EffectivePermissions $access, private ManageLoginSources $sources) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user()->id, 'login_sources.view');
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $query = UserSource::withCount('members')->orderBy('title')->orderBy('slug');
        if (filled($input['search'] ?? null)) {
            $query->where(fn ($q) => $q->where('title', 'ilike', '%'.$input['search'].'%')->orWhere('slug', 'ilike', '%'.$input['search'].'%'));
        }

        return ManagedSourceResource::collection($query->paginate($input['per_page'] ?? 25));
    }

    public function show(Request $request, UserSource $source)
    {
        $this->access->authorize($request->user()->id, 'login_sources.view');

        return response()->json(['data' => (new ManagedSourceResource($source->loadCount('members')))->resolve($request)]);
    }

    public function store(ManageSourceRequest $request)
    {
        $source = $this->sources->create($request->validated());

        return (new ManagedSourceResource($source))->response()->setStatusCode(201);
    }

    public function update(ManageSourceRequest $request, UserSource $source)
    {
        return new ManagedSourceResource($this->sources->update($source, $request->validated()));
    }

    public function activity(Request $request, UserSource $source)
    {
        $this->access->authorize($request->user()->id, 'login_sources.activate');
        $input = $request->validate(['is_active' => ['required', 'boolean']]);

        return new ManagedSourceResource($this->sources->activity($source, (bool) $input['is_active']));
    }

    public function destroy(Request $request, UserSource $source)
    {
        $this->access->authorize($request->user()->id, 'login_sources.delete');
        $this->sources->delete($source);

        return response()->noContent();
    }
}
