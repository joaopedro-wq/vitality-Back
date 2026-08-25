<?php

namespace App\Console\Commands;

use App\Services\TacoCatalogImporter;
use Illuminate\Console\Command;

class ImportTacoCatalog extends Command
{
    protected $signature = 'foods:taco-import {path : Caminho da planilha TACO} {--dry-run : Apenas valida e exibe a cobertura} {--activate : Ativa a versão TACO após a importação}';

    protected $description = 'Importa a TACO 4ª edição como catálogo versionado e categorizado';

    public function handle(TacoCatalogImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if ($this->option('dry-run')) {
            $this->line(json_encode($importer->preview($path), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $version = $importer->import($path, (bool) $this->option('activate'));
        $this->info("TACO importada: versão {$version->id} ({$version->status}).");

        return self::SUCCESS;
    }
}
