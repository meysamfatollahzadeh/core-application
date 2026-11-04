<?php

namespace App\Modules\Login\Models;

use App\Modules\Login\Database\Factories\UserSourceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserSource extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'title', 'is_active', 'data'];

    protected $hidden = ['id', 'data'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $source): void {
            Validator::make($source->attributes, [
                'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
                'title' => ['required', 'string', 'max:255'],
            ])->validate();
            if ($source->exists && $source->isDirty('slug')) {
                throw ValidationException::withMessages(['slug' => 'A source slug cannot be renamed.']);
            }
        });
    }

    protected static function newFactory(): UserSourceFactory
    {
        return UserSourceFactory::new();
    }

    protected function data(): Attribute
    {
        return Attribute::make(
            get: function (string $value): array {
                $data = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                foreach (['client_secret', 'import_client_secret'] as $key) {
                    if (isset($data[$key])) {
                        $data[$key] = Crypt::decryptString($data[$key]);
                    }
                }

                return $data;
            },
            set: function (array $data): string {
                foreach (['client_secret', 'import_client_secret'] as $key) {
                    if (isset($data[$key])) {
                        $data[$key] = Crypt::encryptString($data[$key]);
                    }
                }

                return json_encode($data, JSON_THROW_ON_ERROR);
            },
        );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function fingerprint(): string
    {
        $keys = ['issuer', 'internal_issuer', 'client_id', 'client_secret', 'callback_url', 'post_logout_redirect_uri', 'allowed_algorithms'];
        $settings = array_intersect_key($this->data, array_flip($keys));
        ksort($settings);

        return hash('sha256', $this->slug.json_encode($settings, JSON_THROW_ON_ERROR));
    }
}
