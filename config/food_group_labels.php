<?php

/**
 * Tradução do `label` amigável de categoria por locale, chaveada pelo `id`
 * estável (slug) que `FoodCatalogService::displayGroups()` calcula a partir
 * do `grupo_exibicao` canônico (sempre pt-BR, gravado no banco). Não
 * precisa de entrada 'pt-BR' aqui — o fallback é o próprio rótulo canônico
 * já em português. Só locales sem tradução aqui (ou sem fallback nenhum)
 * caem pro canônico, nunca ficam vazios.
 */
return [
    'bebidas' => ['en-US' => 'Beverages'],
    'carnes-e-aves' => ['en-US' => 'Meat and poultry'],
    'doces-e-sobremesas' => ['en-US' => 'Sweets and desserts'],
    'frutas' => ['en-US' => 'Fruits'],
    'graos-cereais-e-massas' => ['en-US' => 'Grains, cereals and pasta'],
    'leguminosas' => ['en-US' => 'Legumes and pulses'],
    'leites-e-derivados' => ['en-US' => 'Dairy products'],
    'oleaginosas-e-sementes' => ['en-US' => 'Nuts and seeds'],
    'oleos-e-gorduras' => ['en-US' => 'Oils and fats'],
    'ovos' => ['en-US' => 'Eggs'],
    'paes-e-preparacoes' => ['en-US' => 'Breads and preparations'],
    'peixes-e-frutos-do-mar' => ['en-US' => 'Fish and seafood'],
    'verduras-e-legumes' => ['en-US' => 'Vegetables'],
    'outros' => ['en-US' => 'Other'],
];
