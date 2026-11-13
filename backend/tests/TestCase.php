<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits(): array
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Os testes de integração exigem APP_ENV=testing.');
        }

        if (config('database.default') !== 'mysql') {
            throw new RuntimeException('Os testes de integração exigem a conexão MySQL campusfix_test.');
        }

        $connection = DB::connection();
        $actualDatabase = $connection->selectOne('SELECT DATABASE() AS database_name')->database_name;
        if ($actualDatabase !== 'campusfix_test') {
            $connection->disconnect();

            throw new RuntimeException('Execução interrompida: o banco efetivamente conectado não é campusfix_test.');
        }

        return parent::setUpTraits();
    }
}
