<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

class BuildingArea extends Model
{
    protected $fillable = ['title', 'building_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
