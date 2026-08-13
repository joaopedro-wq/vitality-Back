<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to boot a test application unless its database is the isolated
     * SQLite in-memory database. This runs before RefreshDatabase can migrate
     * or clear anything.
     */
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:') {
            throw new RuntimeException(
                'Testes só podem rodar com SQLite em memória. Use php artisan test; nunca execute RefreshDatabase contra o ambiente de desenvolvimento.'
            );
        }

        if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException(
                'O cache de configuração está ativo. Execute php artisan config:clear antes dos testes; ele pode manter a conexão de desenvolvimento e é bloqueado por segurança.'
            );
        }

        return parent::createApplication();
    }
}
