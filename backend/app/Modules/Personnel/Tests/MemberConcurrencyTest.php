<?php

namespace App\Modules\Personnel\Tests;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MemberConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_attach_cannot_take_identity_or_duplicate_user_source(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($r) {
 if (str_ends_with($r->url(), '/token')) { return Illuminate\Support\Facades\Http::response(['access_token'=>'test-only']); }
 file_put_contents(getenv('READY'), 'ready');
 $deadline = microtime(true)+8;
 while (!file_exists(getenv('OTHER_READY')) && microtime(true)<$deadline) { usleep(10000); }
 if (!file_exists(getenv('OTHER_READY'))) { throw new RuntimeException('Barrier timeout'); }
 return Illuminate\Support\Facades\Http::response(['id'=>getenv('SUBJECT'), 'username'=>'human', 'enabled'=>true]);
});
try {
 app(App\Modules\Login\Actions\ManageMemberConnections::class)->attach(App\Modules\Login\Models\User::findOrFail(getenv('USER_ID')), App\Modules\Login\Models\UserSource::findOrFail(getenv('SOURCE_ID')), getenv('SUBJECT'));
 echo 'connected';
} catch (App\Modules\Login\Services\LoginFailure $e) { echo $e->errorCode; exit($e->httpStatus===409 ? 9 : 10); }
PHP;
        foreach (['identity', 'user-source'] as $scenario) {
            $source = UserSource::factory()->create();
            $source->update(['data' => [...$source->data, 'import_client_id' => 'reader', 'import_client_secret' => 'test-only', 'admin_base_url' => 'https://directory.example/admin/realms/test']]);
            $a = User::factory()->create();
            $b = $scenario === 'identity' ? User::factory()->create() : $a;
            $barrier = tempnam(sys_get_temp_dir(), 'member-race-');
            unlink($barrier);
            $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_DATABASE' => config('database.connections.pgsql.database'), 'SOURCE_ID' => $source->id];
            $first = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'USER_ID' => $a->id, 'SUBJECT' => 'subject-a', 'READY' => $barrier.'a', 'OTHER_READY' => $barrier.'b']);
            $second = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'USER_ID' => $b->id, 'SUBJECT' => $scenario === 'identity' ? 'subject-a' : 'subject-b', 'READY' => $barrier.'b', 'OTHER_READY' => $barrier.'a']);
            try {
                $first->start();
                $second->start();
                $first->wait();
                $second->wait();
                $codes = [$first->getExitCode(), $second->getExitCode()];
                sort($codes);
                $this->assertSame([0, 9], $codes, $first->getErrorOutput().$second->getErrorOutput());
                $this->assertSame(1, Member::where('user_source_id', $source->id)->count());
            } finally {
                $first->stop();
                $second->stop();
                @unlink($barrier.'a');
                @unlink($barrier.'b');
            }
        }
    }
}
