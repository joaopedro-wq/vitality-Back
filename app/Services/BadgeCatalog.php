<?php

namespace App\Services;

/**
 * Catálogo de badges de conquista do Painel. Não é uma tabela — as definições vivem em código
 * (poucas, fixas por enquanto); só o "quando cada usuário desbloqueou" persiste em
 * `user_badges` (ver `UserBadge`). Ver decisão de escopo no plano da Fase 6: badges avaliados de
 * forma síncrona a cada carregamento do resumo do Painel, não por evento — simples o bastante
 * pro volume de badges de hoje, mas o ponto certo pra mover pra evento (ex. depois de
 * `DiaryEntryService::create()`) se a lista crescer.
 */
class BadgeCatalog
{
    /**
     * @return array<int, array{codigo: string, icone: string, titulo: string}>
     */
    public static function definicoes(): array
    {
        return [
            ['codigo' => 'streak_4', 'icone' => '🔥', 'titulo' => __('messages.badge_streak_4')],
            ['codigo' => 'protein_5x', 'icone' => '💪', 'titulo' => __('messages.badge_protein_5x')],
            ['codigo' => 'trilha_7', 'icone' => '🏆', 'titulo' => __('messages.badge_trilha_7')],
        ];
    }

    /**
     * Avalia cada badge do catálogo contra as estatísticas já calculadas pelo DashboardService —
     * usado tanto pro selo conquistado quanto pro progresso do card de "próxima conquista".
     *
     * @param  array{streakDias: int, diasComProteinaBatida: int}  $stats
     * @return array<string, array{conquistado: bool, progresso: int}>
     */
    public static function avaliar(array $stats): array
    {
        return [
            'streak_4' => self::porContagem($stats['streakDias'], 4),
            'protein_5x' => self::porContagem($stats['diasComProteinaBatida'], 5),
            'trilha_7' => self::porContagem($stats['streakDias'], 7),
        ];
    }

    /**
     * @return array{conquistado: bool, progresso: int}
     */
    private static function porContagem(int $atual, int $alvo): array
    {
        return [
            'conquistado' => $atual >= $alvo,
            'progresso' => $alvo > 0 ? (int) round(min($atual / $alvo, 1) * 100) : 100,
        ];
    }
}
