<?php

namespace App\Services;

/**
 * Catálogo do "mini-jogo" de missões do Painel — substitui o antigo `BadgeCatalog`. Propósito
 * único: estimular o hábito de registrar refeição, nunca a nutrição em si (nenhuma missão fala
 * de calorias/macros — isso é assunto de Metas/Recomendação, sistemas separados de propósito).
 *
 * Três escopos, cada um com sua própria noção de "quando reseta":
 * - `diaria`: avaliada contra o dia de hoje, `period_key` = a data (`Y-m-d`).
 * - `semanal`: avaliada contra a semana atual (segunda a domingo), `period_key` = a segunda-feira
 *   daquela semana.
 * - `marco`: cumulativa, nunca reseta — `period_key` fixo em `'once'` (absorve o que antes eram
 *   os badges `streak_4`/`trilha_7`; `protein_5x` não migrou porque era sobre nutrição).
 *
 * Números de XP são um ponto de partida (v1) — ajustar depois de ver uso real, não uma ciência
 * exata.
 */
class MissionCatalog
{
    public const ESCOPO_DIARIA = 'diaria';

    public const ESCOPO_SEMANAL = 'semanal';

    public const ESCOPO_MARCO = 'marco';

    /**
     * @return array<int, array{codigo: string, escopo: string, icone: string, titulo: string, xp: int}>
     */
    public static function definicoes(): array
    {
        return [
            ['codigo' => 'log_primeira_refeicao', 'escopo' => self::ESCOPO_DIARIA, 'icone' => '🍽️', 'titulo' => __('messages.mission_log_primeira_refeicao'), 'xp' => 5],
            ['codigo' => 'log_dia_completo', 'escopo' => self::ESCOPO_DIARIA, 'icone' => '✅', 'titulo' => __('messages.mission_log_dia_completo'), 'xp' => 15],
            ['codigo' => 'log_3_dias_semana', 'escopo' => self::ESCOPO_SEMANAL, 'icone' => '📅', 'titulo' => __('messages.mission_log_3_dias_semana'), 'xp' => 20],
            ['codigo' => 'log_semana_completa', 'escopo' => self::ESCOPO_SEMANAL, 'icone' => '🌟', 'titulo' => __('messages.mission_log_semana_completa'), 'xp' => 50],
            ['codigo' => 'marco_streak_4', 'escopo' => self::ESCOPO_MARCO, 'icone' => '🔥', 'titulo' => __('messages.mission_marco_streak_4'), 'xp' => 30],
            ['codigo' => 'marco_streak_7', 'escopo' => self::ESCOPO_MARCO, 'icone' => '🏆', 'titulo' => __('messages.mission_marco_streak_7'), 'xp' => 60],
            ['codigo' => 'marco_streak_30', 'escopo' => self::ESCOPO_MARCO, 'icone' => '💎', 'titulo' => __('messages.mission_marco_streak_30'), 'xp' => 200],
        ];
    }

    /**
     * @return array<int, array{codigo: string, escopo: string, icone: string, titulo: string, xp: int}>
     */
    public static function porEscopo(string $escopo): array
    {
        return array_values(array_filter(self::definicoes(), fn (array $missao) => $missao['escopo'] === $escopo));
    }
}
