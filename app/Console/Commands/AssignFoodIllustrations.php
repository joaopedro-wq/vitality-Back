<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use App\Services\FoodIllustrationResolver;
use Illuminate\Console\Command;

class AssignFoodIllustrations extends Command
{
    protected $signature = 'foods:assign-illustrations {--dry-run : Apenas mostra a distribuicao, sem gravar}';
    protected $description = 'Atribui chaves de ilustracao a todos os alimentos ativos';

    public function handle(FoodIllustrationResolver $resolver): int
    {
        $counts = [];
        $changed = 0;

        Alimento::query()->where('status', 'ativo')->orderBy('id')->eachById(function (Alimento $food) use ($resolver, &$counts, &$changed): void {
            $key = $resolver->resolve($food->descricao, $food->grupo);
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            if ($food->illustration_key !== $key) {
                $changed++;
                if (! $this->option('dry-run')) {
                    $food->update(['illustration_key' => $key]);
                }
            }
        });

        $this->table(['Ilustracao', 'Alimentos'], collect($counts)->sortKeys()->map(fn (int $total, string $key) => [$key, $total])->values()->all());
        $this->info($this->option('dry-run') ? "Simulacao: {$changed} alimentos receberiam chave." : "Concluido: {$changed} alimentos atualizados.");

        return self::SUCCESS;
    }
}
