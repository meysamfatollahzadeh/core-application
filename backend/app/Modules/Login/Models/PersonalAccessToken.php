<?php

namespace App\Modules\Login\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    protected $hidden = ['token', 'oidc_data', 'provider_config_hash'];

    protected function casts(): array
    {
        return ['oidc_data' => 'encrypted:array'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
