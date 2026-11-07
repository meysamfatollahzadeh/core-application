<?php

namespace App\Modules\Access\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['key', 'title'];
}
