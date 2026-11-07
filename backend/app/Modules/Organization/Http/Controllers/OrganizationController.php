<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Organization\Actions\ManageOrganization;
use App\Modules\Organization\Http\Requests\TitleRequest;
use App\Modules\Organization\Http\Resources\DirectoryResource;
use App\Modules\Organization\Models\Building;
use App\Modules\Organization\Models\BuildingArea;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function __construct(private EffectivePermissions $access, private ManageOrganization $change) {}

    private function allow(Request $r, string $permission, ?int $company = null): void
    {
        $this->access->authorize($r->user()->id, $permission, $company);
    }

    private function page(Request $r, $query)
    {
        $data = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        if (! empty($data['search'])) {
            $query->where('title', 'ilike', '%'.$data['search'].'%');
        }

        return DirectoryResource::collection($query->orderBy('id')->paginate($data['per_page'] ?? 25)->withQueryString());
    }

    private function positionBelongs(Company $company, Position $position): void
    {
        abort_unless($position->company_id === $company->id, 404);
    }

    private function areaBelongs(Building $building, BuildingArea $area): void
    {
        abort_unless($area->building_id === $building->id, 404);
    }

    public function companies(Request $r)
    {
        $this->allow($r, 'companies.view');

        return $this->page($r, Company::query());
    }

    public function company(Request $r, Company $company)
    {
        $this->allow($r, 'companies.view');

        return new DirectoryResource($company);
    }

    public function createCompany(TitleRequest $r)
    {
        $this->allow($r, 'companies.create');

        return (new DirectoryResource($this->change->company(null, $r->safe()->only('title'))))->response()->setStatusCode(201);
    }

    public function updateCompany(TitleRequest $r, Company $company)
    {
        $this->allow($r, 'companies.update');

        return new DirectoryResource($this->change->company($company, $r->safe()->only('title')));
    }

    public function deleteCompany(Request $r, Company $company)
    {
        $this->allow($r, 'companies.delete');
        $this->change->delete($company);

        return response()->noContent();
    }

    public function positions(Request $r, Company $company)
    {
        $this->allow($r, 'positions.view', $company->id);

        return $this->page($r, Position::where('company_id', $company->id));
    }

    public function position(Request $r, Company $company, Position $position)
    {
        $this->allow($r, 'positions.view', $company->id);
        $this->positionBelongs($company, $position);

        return new DirectoryResource($position);
    }

    public function createPosition(TitleRequest $r, Company $company)
    {
        $this->allow($r, 'positions.create', $company->id);

        return (new DirectoryResource($this->change->position($company, null, $r->safe()->only(['title', 'parent_position_id']))))->response()->setStatusCode(201);
    }

    public function updatePosition(TitleRequest $r, Company $company, Position $position)
    {
        $this->allow($r, 'positions.update', $company->id);
        $this->positionBelongs($company, $position);

        return new DirectoryResource($this->change->position($company, $position, $r->safe()->only(['title', 'parent_position_id'])));
    }

    public function deletePosition(Request $r, Company $company, Position $position)
    {
        $this->allow($r, 'positions.delete', $company->id);
        $this->positionBelongs($company, $position);
        $this->change->delete($position);

        return response()->noContent();
    }

    public function buildings(Request $r)
    {
        $this->allow($r, 'buildings.view');

        return $this->page($r, Building::query());
    }

    public function building(Request $r, Building $building)
    {
        $this->allow($r, 'buildings.view');

        return new DirectoryResource($building);
    }

    public function createBuilding(TitleRequest $r)
    {
        $this->allow($r, 'buildings.create');

        return (new DirectoryResource($this->change->building(null, $r->safe()->only('title'))))->response()->setStatusCode(201);
    }

    public function updateBuilding(TitleRequest $r, Building $building)
    {
        $this->allow($r, 'buildings.update');

        return new DirectoryResource($this->change->building($building, $r->safe()->only('title')));
    }

    public function deleteBuilding(Request $r, Building $building)
    {
        $this->allow($r, 'buildings.delete');
        $this->change->delete($building);

        return response()->noContent();
    }

    public function areas(Request $r, Building $building)
    {
        $this->allow($r, 'buildings.view');

        return $this->page($r, BuildingArea::where('building_id', $building->id));
    }

    public function area(Request $r, Building $building, BuildingArea $area)
    {
        $this->allow($r, 'buildings.view');
        $this->areaBelongs($building, $area);

        return new DirectoryResource($area);
    }

    public function createArea(TitleRequest $r, Building $building)
    {
        $this->allow($r, 'buildings.create');

        return (new DirectoryResource($this->change->area($building, null, $r->safe()->only('title'))))->response()->setStatusCode(201);
    }

    public function updateArea(TitleRequest $r, Building $building, BuildingArea $area)
    {
        $this->allow($r, 'buildings.update');
        $this->areaBelongs($building, $area);

        return new DirectoryResource($this->change->area($building, $area, $r->safe()->only('title')));
    }

    public function deleteArea(Request $r, Building $building, BuildingArea $area)
    {
        $this->allow($r, 'buildings.delete');
        $this->areaBelongs($building, $area);
        $this->change->delete($area);

        return response()->noContent();
    }

    public function reportingOptions(Request $r)
    {
        $grants = $this->access->grants($r->user()->id);
        abort_unless(collect($grants)->contains(fn ($g) => in_array($g['key'], ['user_positions.create', 'user_positions.update'], true)), 403);
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = DB::table('positions')->join('companies', 'companies.id', '=', 'positions.company_id')->select('positions.id', 'positions.title', 'positions.company_id', 'companies.title as company_title');
        if (! empty($d['search'])) {
            $q->where('positions.title', 'ilike', '%'.$d['search'].'%');
        }

        return response()->json($q->orderBy('positions.id')->paginate(100));
    }

    public function locationOptions(Request $r)
    {
        $this->allow($r, 'personnel.update');
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = DB::table('building_areas')->join('buildings', 'buildings.id', '=', 'building_areas.building_id')->select('building_areas.id', 'building_areas.title', 'building_id', 'buildings.title as building_title');
        if (! empty($d['search'])) {
            $q->where(fn ($q) => $q->where('building_areas.title','ilike','%'.$d['search'].'%')->orWhere('buildings.title','ilike','%'.$d['search'].'%'));
        }

        return response()->json($q->orderBy('building_areas.id')->paginate(100));
    }
}
