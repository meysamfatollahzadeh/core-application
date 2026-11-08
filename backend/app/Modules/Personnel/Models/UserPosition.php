<?php

namespace App\Modules\Personnel\Models;

use Illuminate\Database\Eloquent\Model;

class UserPosition extends Model
{
    protected $fillable = ['user_id', 'position_id'];
}
