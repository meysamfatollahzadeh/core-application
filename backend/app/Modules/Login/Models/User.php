<?php

namespace App\Modules\Login\Models;

use App\Modules\Login\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $attributes = ['performances' => '{"theme":"auto"}'];

    protected function casts(): array
    {
        return ['performances' => 'array'];
    }

    protected $fillable = ['name', 'building_area_id'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
