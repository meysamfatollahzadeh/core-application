<?php

namespace App\Modules\Login\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagedSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = [
            'slug' => $this->slug, 'title' => $this->title, 'is_active' => $this->is_active,
            'members_count' => $this->members_count ?? $this->members()->count(),
        ];
        if (! $request->routeIs('management.login-sources.show')) {
            return $summary;
        }
        $data = $this->data;

        return [...$summary,
            'data' => array_intersect_key($data, array_flip(['issuer', 'internal_issuer', 'client_id', 'callback_url', 'post_logout_redirect_uri', 'import_client_id', 'admin_base_url', 'allowed_algorithms', 'timeout', 'page_size'])),
            'client_secret_configured' => filled($data['client_secret'] ?? null),
            'import_client_secret_configured' => filled($data['import_client_secret'] ?? null),
        ];
    }
}
