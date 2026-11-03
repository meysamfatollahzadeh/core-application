<?php

namespace App\Modules\Login\Database\Factories;

use App\Modules\Login\Models\UserSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSourceFactory extends Factory
{
    protected $model = UserSource::class;

    public function definition(): array
    {
        $slug = 'source-'.fake()->unique()->numerify('########');

        return [
            'slug' => $slug, 'title' => 'Test Source', 'is_active' => true,
            'data' => [
                'issuer' => 'https://'.$slug.'.example.org/realms/test',
                'internal_issuer' => 'https://'.$slug.'.example.org/realms/test',
                'client_id' => 'organization', 'client_secret' => 'test-secret',
                'callback_url' => 'http://localhost:8081/login/callback/'.$slug,
                'post_logout_redirect_uri' => 'http://localhost:8081/login/logged-out',
                'allowed_algorithms' => ['RS256'], 'timeout' => 10, 'page_size' => 100,
            ],
        ];
    }
}
