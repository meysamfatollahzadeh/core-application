<?php

namespace App\Modules\Organization\Actions;

use App\Modules\Organization\Models\Building;
use App\Modules\Organization\Models\BuildingArea;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Position;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

final class ManageOrganization
{
    public function company(?Company $company, array $data): Company
    {
        return AtomicChange::run(fn () => tap($company ?? new Company, fn ($model) => $model->fill($data)->save()));
    }

    public function building(?Building $building, array $data): Building
    {
        return AtomicChange::run(function () use ($building, $data) {
            $new = ! $building;
            $building = tap($building ?? new Building, fn ($m) => $m->fill($data)->save());
            if ($new) {
                BuildingArea::create(['building_id' => $building->id, 'title' => 'نامشخص', 'is_default' => true]);
            }

            return $building;
        });
    }

    public function area(Building $building, ?BuildingArea $area, array $data): BuildingArea
    {
        return AtomicChange::run(function () use ($building, $area, $data) {
            if ($area) {
                abort_if($area->is_default, 409, 'The default area is protected.');
            }

            return tap($area ?? new BuildingArea(['building_id' => $building->id]), fn ($m) => $m->fill(['title' => $data['title']])->save());
        });
    }

    public function position(Company $company, ?Position $position, array $data): Position
    {
        return AtomicChange::run(function () use ($company, $position, $data) {
            if (isset($data['parent_position_id'])) {
                abort_unless(Position::where('company_id', $company->id)->whereKey($data['parent_position_id'])->exists(), 422, 'Parent must belong to this company.');
                if ($position) {
                    abort_if((int) $data['parent_position_id'] === $position->id, 422, 'A position cannot parent itself.');
                    $missing = DB::table('user_positions')->where('position_id', $position->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('user_position_reports')->whereColumn('user_position_id', 'user_positions.id'))->pluck('id');
                    if ($missing->isNotEmpty()) {
                        throw new HttpResponseException(response()->json(['message' => 'Assignments require reporting targets before reparenting.', 'assignment_ids' => $missing], 409));
                    }
                }
            }

            return tap($position ?? new Position(['company_id' => $company->id]), fn ($m) => $m->fill($data)->save());
        });
    }

    public function delete(object $entity): void
    {
        AtomicChange::run(function () use ($entity) {
            if ($entity instanceof BuildingArea) {
                abort_if($entity->is_default, 409, 'The default area is protected.');
            }
            if ($entity instanceof Building) {
                $extra = BuildingArea::where('building_id', $entity->id)->where('is_default', false)->exists();
                abort_if($extra, 409, 'Remove nondefault areas before deleting the building.');
                BuildingArea::where('building_id', $entity->id)->delete();
            }
            $entity->delete();
        });
    }
}
