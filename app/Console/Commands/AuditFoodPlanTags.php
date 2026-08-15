<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditFoodPlanTags extends Command
{
    protected $signature = 'foods:plan-tag-audit {--json : Emite o relatório em JSON}';
    protected $description = 'Audita a cobertura das tags usadas pelo gerador de plano alimentar';

    public function handle(): int
    {
        $byTag = DB::table('food_plan_tags as tags')->leftJoin('alimento_food_plan_tag as pivot', 'pivot.food_plan_tag_id', '=', 'tags.id')
            ->selectRaw('tags.slug, tags.label, count(pivot.alimento_id) as total')->groupBy('tags.id', 'tags.slug', 'tags.label')->orderBy('tags.slug')->get();
        $untagged = DB::table('alimentos as foods')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('alimento_food_plan_tag as pivot')->whereColumn('pivot.alimento_id', 'foods.id'))->count();
        $complementOnly = DB::table('alimentos as foods')->whereExists(fn ($query) => $query->selectRaw('1')->from('alimento_food_plan_tag as pivot')->join('food_plan_tags as tags', 'tags.id', '=', 'pivot.food_plan_tag_id')->whereColumn('pivot.alimento_id', 'foods.id')->where('tags.slug', 'complemento'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('alimento_food_plan_tag as pivot')->join('food_plan_tags as tags', 'tags.id', '=', 'pivot.food_plan_tag_id')->whereColumn('pivot.alimento_id', 'foods.id')->where('tags.slug', '!=', 'complemento'))->count();
        $report = ['foods' => DB::table('alimentos')->count(), 'untagged' => $untagged, 'complement_only' => $complementOnly, 'tags' => $byTag];
        if ($this->option('json')) $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        else {
            $this->info("Alimentos: {$report['foods']} | sem tag: {$untagged} | somente complemento: {$complementOnly}");
            $this->table(['Tag', 'Rótulo', 'Alimentos'], $byTag->map(fn ($tag) => [$tag->slug, $tag->label, $tag->total]));
        }
        return $untagged === 0 ? self::SUCCESS : self::FAILURE;
    }
}
