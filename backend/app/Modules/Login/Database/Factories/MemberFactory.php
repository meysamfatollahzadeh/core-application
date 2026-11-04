<?php

namespace App\Modules\Login\Database\Factories;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return ['uuid' => fake()->uuid(), 'user_source_id' => UserSource::factory(), 'user_id' => User::factory()];
    }
}
