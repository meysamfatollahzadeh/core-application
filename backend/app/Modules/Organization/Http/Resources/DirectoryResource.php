<?php

namespace App\Modules\Organization\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DirectoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return $this->resource->toArray();
    }
}
