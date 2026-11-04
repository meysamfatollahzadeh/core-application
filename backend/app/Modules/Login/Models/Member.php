<?php

namespace App\Modules\Login\Models;

use App\Modules\Login\Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'user_source_id', 'user_id'];

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }

    public function userSource(): BelongsTo
    {
        return $this->belongsTo(UserSource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
