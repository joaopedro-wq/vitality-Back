<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app->environment('testing') && config('database.default') !== 'sqlite') {
            throw new \RuntimeException(
                'Testes só podem rodar com SQLite em memória. Verifique phpunit.xml/.env.testing; o banco de desenvolvimento nunca deve ser usado.'
            );
        }
    }
}
