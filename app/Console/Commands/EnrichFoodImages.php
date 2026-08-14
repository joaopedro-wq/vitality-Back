<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use App\Services\FoodImageEnrichmentService;
use Illuminate\Console\Command;

class EnrichFoodImages extends Command
{
    protected $signature = 'foods:enrich-images {--dry-run : Consulta fontes sem salvar} {--limit= : Maximo de alimentos processados} {--reference= : source_reference TACO especifico} {--retry-failed : Tenta novamente falhas de rede anteriores}';

    protected $description = 'Enriquece o catalogo TACO com imagens publicas/CC0 da Wikimedia';

    public function handle(FoodImageEnrichmentService $enrichment): int
    {
        $query = Alimento::query()
            ->where('fonte', 'taco')
            ->where('status', 'ativo')
            ->whereDoesntHave('publishedImage')
            ->when($this->option('reference'), fn ($foods, $reference) => $foods->where('source_reference', $reference))
            ->orderBy('id');

        if ($this->option('retry-failed')) {
            $query->where(fn ($foods) => $foods
                ->whereDoesntHave('images')
                ->orWhereHas('images', fn ($images) => $images->where('status', 'failed')));
        } else {
            $query->whereDoesntHave('images');
        }
        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $counts = ['published' => 0, 'eligible' => 0, 'rejected' => 0, 'failed' => 0, 'skipped' => 0];
        $query->each(function (Alimento $food) use ($enrichment, &$counts): void {
            $result = $enrichment->enrich($food, (bool) $this->option('dry-run'));
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $this->line("[{$result['status']}] {$food->descricao}: {$result['message']}");
            usleep(250000);
        });

        $this->info('Imagens: '.collect($counts)->map(fn ($count, $status) => "$status=$count")->join(', '));

        return self::SUCCESS;
    }
}
