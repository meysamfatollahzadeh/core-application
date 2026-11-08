<?php

namespace App\Modules\Login\Database\Factories;

use App\Modules\Login\Models\User;
use App\Modules\Organization\Models\Building;
use App\Modules\Organization\Models\BuildingArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $area = DB::transaction(function () {
            $area = BuildingArea::first();
            if ($area) {
                return $area;
            }
            $building = Building::create(['title' => 'Test building']);

            return BuildingArea::create(['building_id' => $building->id, 'title' => 'نامشخص', 'is_default' => true]);
        });

        return ['name' => fake()->name(), 'building_area_id' => $area->id];
    }
}
