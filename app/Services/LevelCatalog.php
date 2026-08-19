<?php

namespace App\Services;


class LevelCatalog
{
    private const LIMIARES = [1 => 0, 2 => 50, 3 => 120, 4 => 250, 5 => 450, 6 => 700];

    private const CUSTO_POR_NIVEL_ACIMA_DO_6 = 350;

    /**
     * @return array{nivel: int, xp: int, xp_proximo_nivel: int, progresso_percent: int}
     */
    public static function resolver(int $xp): array
    {
        $nivel = 1;
        foreach (self::LIMIARES as $candidato => $limiar) {
            if ($xp >= $limiar) {
                $nivel = $candidato;
            }
        }

        $limiarAtual = self::limiarDoNivel($nivel);
        $limiarProximo = self::limiarDoNivel($nivel + 1);
        $faixa = $limiarProximo - $limiarAtual;

        return [
            'nivel' => $nivel,
            'xp' => $xp,
            'xp_proximo_nivel' => $limiarProximo,
            'progresso_percent' => $faixa > 0 ? (int) round((($xp - $limiarAtual) / $faixa) * 100) : 100,
        ];
    }

    private static function limiarDoNivel(int $nivel): int
    {
        if (isset(self::LIMIARES[$nivel])) {
            return self::LIMIARES[$nivel];
        }

        return self::LIMIARES[6] + self::CUSTO_POR_NIVEL_ACIMA_DO_6 * ($nivel - 6);
    }
}
