<?php

namespace App\Modules\Login\Services;

use App\Modules\Login\Models\UserSource;
use Illuminate\Http\Client\ConnectionException;

final class AccountDirectory
{
    public function __construct(private ProviderClient $provider) {}

    public function configurationHash(UserSource $source): string
    {
        return hash('sha256', json_encode($source->data, JSON_THROW_ON_ERROR));
    }

    private function credential(UserSource $source): string
    {
        foreach (['import_client_id', 'import_client_secret', 'admin_base_url'] as $key) {
            if (! is_string($source->data[$key] ?? null) || $source->data[$key] === '') {
                throw new LoginFailure('provider_directory_unconfigured', 503);
            }
        }
        try {
            $token = $this->provider->post($source, 'token', ['grant_type' => 'client_credentials'], true)['access_token'] ?? null;
        } catch (LoginFailure) {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }
        if (! is_string($token) || $token === '') {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }

        return $token;
    }

    private function read(UserSource $source, string $path, array $query = []): ?array
    {
        $token = $this->credential($source);
        try {
            $response = $this->provider->request($source)->withToken($token)
                ->get(rtrim($source->data['admin_base_url'], '/').'/users'.$path, $query);
        } catch (ConnectionException) {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }
        if ($response->status() === 404 && $path !== '') {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }

        return $response->json();
    }

    private function profile(array $remote): array
    {
        if (! is_string($remote['id'] ?? null) || $remote['id'] === '') {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }
        $text = fn (string $key): ?string => is_string($remote[$key] ?? null) ? mb_substr($remote[$key], 0, 255) : null;

        return [
            'uuid' => $remote['id'], 'username' => $text('username'),
            'first_name' => $text('firstName'), 'last_name' => $text('lastName'), 'email' => $text('email'),
            'enabled' => ($remote['enabled'] ?? false) === true,
            'is_service' => isset($remote['serviceAccountClientId']) || str_starts_with($remote['username'] ?? '', 'service-account-'),
        ];
    }

    public function search(UserSource $source, string $search, int $page, int $perPage): array
    {
        $rows = $this->read($source, '', [
            'search' => $search, 'first' => ($page - 1) * $perPage, 'max' => $perPage + 1,
            'briefRepresentation' => 'true',
        ]);
        if (! array_is_list($rows)) {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }
        $profiles = array_map(fn ($row) => is_array($row) ? $this->profile($row) : throw new LoginFailure('provider_directory_unavailable', 503), array_slice($rows, 0, $perPage));

        return ['data' => array_values(array_filter($profiles, fn ($p) => ! $p['is_service'])),
            'page' => $page, 'per_page' => $perPage, 'has_more' => count($rows) > $perPage];
    }

    public function account(UserSource $source, string $uuid): ?array
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,254}\z/D', $uuid)) {
            throw new LoginFailure('account_ineligible', 422);
        }
        $remote = $this->read($source, '/'.rawurlencode($uuid));
        if ($remote === null) {
            return null;
        }
        $profile = $this->profile($remote);
        if ($profile['uuid'] !== $uuid) {
            throw new LoginFailure('provider_directory_unavailable', 503);
        }

        return $profile;
    }
}
