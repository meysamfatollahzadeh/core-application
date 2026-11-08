<?php

namespace App\Modules\Login\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $member = $this->currentAccessToken()->member;

        return [
            'user' => ['id' => $this->id, 'name' => $this->name, 'performances' => $this->performances],
            'source' => ['slug' => $member->userSource->slug, 'title' => $member->userSource->title],
            'member' => ['id' => $member->id, 'uuid' => $member->uuid],
        ];
    }
}
