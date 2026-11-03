<?php

namespace Tests\Feature\Login;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SourceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_reference_source_slugs_and_secrets_remain_encrypted(): void
    {
        $source = UserSource::factory()->create(['slug' => 'primary']);
        $user = User::factory()->create();
        $member = Member::factory()->create(['user_source_slug' => 'primary', 'user_id' => $user->id]);
        $this->assertSame('primary', $member->userSource->slug);
        $this->assertSame($member->id, $source->members()->first()->id);
        $this->assertSame($user->id, $member->user->id);
        $this->assertSame('test-secret', $source->fresh()->data['client_secret']);
        $this->assertStringNotContainsString('test-secret', $source->getRawOriginal('data'));
        $this->assertArrayNotHasKey('data', $source->toArray());
        $this->assertArrayNotHasKey('id', $source->toArray());
        $this->assertSame('slug', $source->getRouteKeyName());
    }

    public function test_same_external_subject_in_different_sources_does_not_merge_users(): void
    {
        $a = UserSource::factory()->create();
        $b = UserSource::factory()->create();
        $one = Member::factory()->create(['user_source_slug' => $a->slug, 'uuid' => 'f:federation:subject']);
        $two = Member::factory()->create(['user_source_slug' => $b->slug, 'uuid' => 'f:federation:subject']);
        $this->assertNotSame($one->user_id, $two->user_id);
        $this->assertDatabaseCount('members', 2);
    }

    public function test_slug_cannot_be_renamed_after_creation(): void
    {
        $source = UserSource::factory()->create();
        $this->expectException(ValidationException::class);
        $source->update(['slug' => 'renamed']);
    }

    public function test_source_and_subject_are_unique_in_the_database(): void
    {
        $member = Member::factory()->create();
        $this->expectException(QueryException::class);
        Member::factory()->create(['user_source_slug' => $member->user_source_slug, 'uuid' => $member->uuid]);
    }
}
