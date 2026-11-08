<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = ['title', 'company_id', 'parent_position_id'];
}
