<?php

namespace App\Modules\Login\Actions;

use App\Modules\Login\Models\PersonalAccessToken;
use App\Modules\Login\Models\UserSource;
use App\Modules\Login\Services\LoginFailure;
use App\Modules\Login\Services\SourceConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageLoginSources
{
    public function __construct(private SourceConfiguration $configuration) {}

    public function create(array $input): UserSource
    {
        return $this->atomic(function () use ($input): UserSource {
            $slug = $input['slug'];
            $data = array_replace(['timeout' => 10, 'page_size' => 100, 'allowed_algorithms' => ['RS256']], $input['data']);

            return $this->save(new UserSource, [
                'slug' => $slug, 'title' => $input['title'], 'is_active' => false,
                'data' => $this->redirects($slug, $data),
            ]);
        });
    }

    public function update(UserSource $source, array $input): UserSource
    {
        return $this->atomic(function () use ($source, $input): UserSource {
            $current = UserSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $data = array_replace($current->data, $input['data'] ?? []);
            foreach (['client_secret', 'import_client_secret'] as $key) {
                if (array_key_exists($key, $input['data'] ?? []) && blank($input['data'][$key])) {
                    if (isset($current->data[$key])) {
                        $data[$key] = $current->data[$key];
                    } else {
                        unset($data[$key]);
                    }
                }
            }
            if ($input['remove_directory'] ?? false) {
                foreach (['import_client_id', 'import_client_secret', 'admin_base_url'] as $key) {
                    unset($data[$key]);
                }
            }

            return $this->save($current, [
                'slug' => $current->slug, 'title' => $input['title'] ?? $current->title,
                'is_active' => $current->is_active, 'data' => $this->redirects($current->slug, $data),
            ]);
        });
    }

    public function upsert(array $input): UserSource
    {
        return $this->atomic(function () use ($input): UserSource {
            $current = UserSource::where('slug', $input['slug'])->lockForUpdate()->first() ?? new UserSource;

            return $this->save($current, $input);
        });
    }

    public function activity(UserSource $source, bool $active): UserSource
    {
        return $this->atomic(function () use ($source, $active): UserSource {
            $current = UserSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $current->update(['is_active' => $active]);
            if (! $active) {
                $this->revoke($current);
            }

            return $current;
        });
    }

    public function delete(UserSource $source): void
    {
        $this->atomic(function () use ($source): void {
            $current = UserSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($current->members()->exists()) {
                throw new LoginFailure('source_has_members', 409);
            }
            $current->delete();
        });
    }

    private function redirects(string $slug, array $data): array
    {
        $base = rtrim(config('app.url'), '/');

        return array_replace($data, ['callback_url' => $base.'/login/callback/'.$slug, 'post_logout_redirect_uri' => $base.'/login/logged-out']);
    }

    private function save(UserSource $current, array $values): UserSource
    {
        $values = $this->configuration->validate($values);
        $this->configuration->validateOrigins($values['data'], $current->exists ? $current->data : []);
        if ($current->exists && $current->data['issuer'] !== $values['data']['issuer'] && $current->members()->exists()) {
            throw ValidationException::withMessages(['data.issuer' => 'A source with connected accounts cannot change its issuer. Create a new source.']);
        }
        $previous = $current->exists ? $current->fingerprint() : null;
        $current->fill($values)->save();
        if ($previous && ($previous !== $current->fingerprint() || ! $current->is_active)) {
            $this->revoke($current);
        }

        return $current;
    }

    private function revoke(UserSource $source): void
    {
        PersonalAccessToken::whereHas('member', fn ($query) => $query->where('user_source_id', $source->id))->delete();
    }

    private function atomic(callable $change): mixed
    {
        try {
            return DB::transaction($change);
        } catch (QueryException $exception) {
            // Never serialize query bindings: source JSON can contain credentials.
            if (in_array($exception->errorInfo[0] ?? '', ['23503', '23505', '40001', '40P01'], true)) {
                throw new LoginFailure('source_conflict', 409);
            }
            throw new LoginFailure('source_save_failed', 503);
        }
    }
}
