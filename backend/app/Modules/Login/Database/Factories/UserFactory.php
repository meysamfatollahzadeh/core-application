<?php

namespace App\Modules\Login\Database\Factories;

use App\Modules\Login\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['name' => fake()->name()];
    }
}
