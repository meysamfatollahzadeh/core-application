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

    public function test_detach_waiting_for_callback_removes_the_just_issued_token(): void
    {
        $member = Member::factory()->create();
        $barrier = tempnam(sys_get_temp_dir(), 'member-callback-');
        unlink($barrier);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$member=App\Modules\Login\Models\Member::findOrFail(getenv('MEMBER_ID')); $source=$member->userSource;
if (getenv('ACTION')==='detach') {
 app(App\Modules\Login\Actions\ManageMemberConnections::class)->detach($member->user,$member); echo 'detached'; exit;
}
$provider=Mockery::mock(App\Modules\Login\Services\ProviderClient::class);
$provider->shouldReceive('exchange')->once()->andReturn(['id_token'=>'signed-test-identity','access_token'=>'test-access','refresh_token'=>'test-refresh','expires_in'=>300]);
$provider->shouldReceive('validateIdentity')->once()->andReturn(['sub'=>$member->uuid,'sid'=>'test-callback-session']);
$binding=str_repeat('b',64); $state=str_repeat('s',64);
Illuminate\Support\Facades\Cache::put('login:transaction:'.$state,['slug'=>$source->slug,'config'=>$source->fingerprint(),'binding_hash'=>hash('sha256',$binding),'created_at'=>time(),'verifier'=>'test-verifier','nonce'=>'test-nonce'],300);
Illuminate\Support\Facades\DB::listen(function($event) {
 if(str_contains($event->sql,'user_sources') && str_contains($event->sql,'for update')) { file_put_contents(getenv('BARRIER'),'locked'); usleep(1000000); }
});
$result=(new App\Modules\Login\Actions\CompleteLogin($provider))->execute($source,$state,'test-code',$binding);
echo isset($result['token'])?'issued':'failed';
PHP;
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'MEMBER_ID' => $member->id, 'BARRIER' => $barrier];
        $callback = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'ACTION' => 'callback']);
        $detach = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'ACTION' => 'detach']);
        try {
            $callback->start();
            $deadline = microtime(true) + 8;
            while (! file_exists($barrier) && $callback->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier, $callback->getErrorOutput());
            $detach->start();
            $callback->wait();
            $detach->wait();
            $this->assertSame(0, $callback->getExitCode(), $callback->getErrorOutput());
            $this->assertSame('issued', $callback->getOutput());
            $this->assertSame(0, $detach->getExitCode(), $detach->getErrorOutput());
            $this->assertDatabaseMissing('members', ['id' => $member->id]);
            $this->assertDatabaseCount('personal_access_tokens', 0);
        } finally {
            $callback->stop();
            $detach->stop();
            @unlink($barrier);
        }
    }

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
