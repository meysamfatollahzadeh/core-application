<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
            throw new RuntimeException('Tests require a separate database ending in _test.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);

        return $app;
    }
}
