<?php

namespace App\Modules\Login\Tests;

use App\Modules\Login\Models\Member;
use App\Modules\Login\Models\User;
use App\Modules\Login\Models\UserSource;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SourceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function race(UserSource $source, User $user, string $first, string $second): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'source-race-');
        unlink($barrier);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Modules\Login\Models\{UserSource, User};
use App\Modules\Login\Actions\{ManageLoginSources, ManageMemberConnections, CompleteLogin};
use App\Modules\Login\Services\{AccountDirectory, ProviderClient, LoginFailure};
use Illuminate\Support\Facades\{Cache, DB, Http};
$source = UserSource::findOrFail(getenv('SOURCE_ID')); $user = User::findOrFail(getenv('USER_ID'));
$action = getenv('ACTION');
if (getenv('FIRST') === 'yes') {
 DB::listen(function ($event) {
  if (str_contains($event->sql, 'user_sources') && str_contains($event->sql, 'for update')) {
   file_put_contents(getenv('BARRIER'), 'locked'); usleep(700000);
  }
 });
}
try {
 if ($action === 'deactivate') { app(ManageLoginSources::class)->activity($source, false); echo 'deactivated'; }
 if ($action === 'delete') { app(ManageLoginSources::class)->delete($source); echo 'deleted'; }
 if ($action === 'attach') {
  Http::preventStrayRequests();
  Http::fake(fn($request) => str_ends_with($request->url(), '/token') ? Http::response(['access_token'=>'test-only']) : Http::response(['id'=>'race-subject','username'=>'race-user','enabled'=>true]));
  $directory = app(AccountDirectory::class);
  (new ManageMemberConnections($directory))->attach($user, $source, 'race-subject'); echo 'attached';
 }
 if ($action === 'callback') {
  $provider = Mockery::mock(ProviderClient::class);
  $provider->shouldReceive('exchange')->andReturn(['id_token'=>'signed-test','access_token'=>'test-access','refresh_token'=>'test-refresh','expires_in'=>300]);
  $provider->shouldReceive('validateIdentity')->andReturn(['sub'=>'race-subject','sid'=>'test-session']);
  $binding = str_repeat('b',64); $state = str_repeat('s',64);
  Cache::put('login:transaction:'.$state, ['slug'=>$source->slug,'config'=>$source->fingerprint(),'binding_hash'=>hash('sha256',$binding),'created_at'=>time(),'verifier'=>'verifier','nonce'=>'nonce'], 300);
  (new CompleteLogin($provider))->execute($source, $state, 'code', $binding); echo 'issued';
 }
} catch (LoginFailure $e) { echo $e->errorCode; }
catch (Illuminate\Database\Eloquent\ModelNotFoundException) { echo 'missing'; }
PHP;
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array',
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'SOURCE_ID' => $source->id, 'USER_ID' => $user->id, 'BARRIER' => $barrier];
        $one = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'ACTION' => $first, 'FIRST' => 'yes']);
        $two = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'ACTION' => $second]);
        try {
            $one->start();
            $deadline = microtime(true) + 8;
            while (! file_exists($barrier) && $one->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier, $one->getErrorOutput());
            $two->start();
            $one->wait();
            $two->wait();
            $this->assertSame(0, $one->getExitCode(), $one->getErrorOutput());
            $this->assertSame(0, $two->getExitCode(), $two->getErrorOutput());

            return [$one->getOutput(), $two->getOutput()];
        } finally {
            $one->stop();
            $two->stop();
            @unlink($barrier);
        }
    }

    public function test_callback_and_deactivation_never_leave_a_usable_token_in_either_lock_order(): void
    {
        foreach ([['callback', 'deactivate'], ['deactivate', 'callback']] as [$first, $second]) {
            $source = UserSource::factory()->create();
            $member = Member::factory()->create(['user_source_id' => $source->id, 'uuid' => 'race-subject']);
            $result = $this->race($source, $member->user, $first, $second);
            $this->assertSame($first === 'callback' ? ['issued', 'deactivated'] : ['deactivated', 'source_changed'], $result);
            $this->assertFalse($source->fresh()->is_active);
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }
    }

    public function test_delete_and_attachment_preserve_source_integrity_in_either_lock_order(): void
    {
        foreach ([['attach', 'delete'], ['delete', 'attach']] as [$first, $second]) {
            $source = UserSource::factory()->create();
            $source->update(['data' => [...$source->data, 'import_client_id' => 'read-users', 'import_client_secret' => 'test-only', 'admin_base_url' => 'https://test.example/admin/realms/test']]);
            $user = User::factory()->create();
            $result = $this->race($source, $user, $first, $second);
            $this->assertSame($first === 'attach' ? ['attached', 'source_has_members'] : ['deleted', 'missing'], $result);
            $this->assertSame($first === 'attach', $source->fresh() !== null);
            $this->assertSame($first === 'attach', Member::where('user_source_id', $source->id)->exists());
        }
    }
}
