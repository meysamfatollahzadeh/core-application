<?php

namespace App\Modules\Login\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SourceConfiguration
{
    public function validate(array $input): array
    {
        $valid = Validator::make($input, [
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'title' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean'],
            'data' => ['required', 'array:issuer,internal_issuer,client_id,client_secret,callback_url,post_logout_redirect_uri,import_client_id,import_client_secret,admin_base_url,allowed_algorithms,timeout,page_size'],
            'data.issuer' => ['required', 'url:http,https'],
            'data.internal_issuer' => ['required', 'url:http,https'],
            'data.client_id' => ['required', 'string', 'max:255'],
            'data.client_secret' => ['required', 'string', 'max:2048'],
            'data.callback_url' => ['required', 'url:http,https'],
            'data.post_logout_redirect_uri' => ['required', 'url:http,https'],
            'data.import_client_id' => ['sometimes', 'required', 'string'],
            'data.import_client_secret' => ['required_with:data.import_client_id', 'string'],
            'data.admin_base_url' => ['required_with:data.import_client_id', 'url:http,https'],
            'data.allowed_algorithms' => ['required', 'array', 'size:1'],
            'data.allowed_algorithms.*' => [Rule::in(['RS256'])],
            'data.timeout' => ['required', 'integer', 'between:1,30'],
            'data.page_size' => ['required', 'integer', 'between:1,500'],
        ])->validate();
        $base = rtrim(config('app.url'), '/');
        if ($valid['data']['callback_url'] !== $base.'/login/callback/'.$valid['slug']
            || $valid['data']['post_logout_redirect_uri'] !== $base.'/login/logged-out') {
            throw ValidationException::withMessages(['data' => 'Callback and logout targets must match the fixed application gateway.']);
        }
        foreach (['issuer', 'internal_issuer', 'admin_base_url'] as $key) {
            $url = $valid['data'][$key] ?? null;
            if ($url && (parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT))) {
                throw ValidationException::withMessages(['data.'.$key => 'Provider URLs must not contain credentials, queries or fragments.']);
            }
            if ($url && app()->isProduction() && parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw ValidationException::withMessages(['data.'.$key => 'Production provider endpoints must use HTTPS.']);
            }
        }

        return $valid;
    }
}
