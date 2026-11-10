<?php

namespace App\Modules\Login\Http\Requests;

use App\Modules\Access\Services\EffectivePermissions;
use Illuminate\Foundation\Http\FormRequest;

class ManageSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(EffectivePermissions::class)->allows($this->user()->id, $this->isMethod('POST') ? 'login_sources.create' : 'login_sources.update');
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'slug' => $create ? ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'] : ['prohibited'],
            'title' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['prohibited'],
            'remove_directory' => $create ? ['prohibited'] : ['sometimes', 'boolean'],
            'data' => [$create ? 'required' : 'sometimes', 'array:issuer,internal_issuer,client_id,client_secret,import_client_id,import_client_secret,admin_base_url,allowed_algorithms,timeout,page_size'],
        ];
    }
}
