<?php

namespace App\Modules\Organization\Tests;

use App\Modules\Organization\Actions\ManageOrganization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_reparenting_cannot_commit_a_chart_cycle(): void
    {
        $org = new ManageOrganization;
        $company = $org->company(null, ['title' => 'Concurrent']);
        $a = $org->position($company, null, ['title' => 'A']);
        $b = $org->position($company, null, ['title' => 'B']);
        $barrier = tempnam(sys_get_temp_dir(), 'org-barrier-');
        unlink($barrier);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
 App\Modules\Organization\Actions\AtomicChange::run(function () {
  Illuminate\Support\Facades\DB::table('positions')->where('id',getenv('POSITION_ID'))->update(['parent_position_id'=>getenv('PARENT_ID')]);
  if (getenv('BARRIER')) { file_put_contents(getenv('BARRIER'),'locked'); usleep(500000); }
 });
 echo 'committed';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { echo 'conflict'; exit($e->getStatusCode()===409 ? 9 : 10); }
CODE;
        $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')];
        $first = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'POSITION_ID' => $a->id, 'PARENT_ID' => $b->id, 'BARRIER' => $barrier]);
        $second = new Process([PHP_BINARY, '-r', $code], base_path(), [...$env, 'POSITION_ID' => $b->id, 'PARENT_ID' => $a->id, 'BARRIER' => '']);
        try {
            $first->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($barrier) && $first->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier);
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(9, $second->getExitCode(), $second->getErrorOutput());
            $this->assertSame('conflict', $second->getOutput());
            $this->assertNull(DB::table('positions')->where('id', $b->id)->value('parent_position_id'));
        } finally {
            $first->stop();
            $second->stop();
            @unlink($barrier);
        }
    }
}
