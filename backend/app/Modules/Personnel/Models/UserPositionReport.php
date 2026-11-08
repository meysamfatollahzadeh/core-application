<?php

namespace App\Modules\Personnel\Models;

use Illuminate\Database\Eloquent\Model;

class UserPositionReport extends Model
{
    protected $fillable = ['user_position_id', 'report_to_position_id'];
}
