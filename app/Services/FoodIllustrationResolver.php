<?php

namespace App\Services;

class FoodIllustrationResolver
{
    /** @var array<string, list<string>> */
    private const FRUIT_KEYWORDS = [
        'fruit-avocado' => ['abacate'], 'fruit-pineapple' => ['abacaxi'], 'fruit-abiu' => ['abiu'],
        'fruit-acai' => ['açaí'], 'fruit-acerola' => ['acerola'], 'fruit-plum' => ['ameixa'],
        'fruit-atemoya' => ['atemóia'], 'banana' => ['banana'], 'fruit-cacao' => ['cacau'],
        'fruit-caja-manga' => ['cajá-manga'], 'fruit-caja' => ['cajá'], 'fruit-cashew' => ['caju'],
        'fruit-persimmon' => ['caqui'], 'fruit-starfruit' => ['carambola'], 'fruit-ciriguela' => ['ciriguela'],
        'fruit-cupuacu' => ['cupuaçu'], 'fruit-fig' => ['figo'], 'fruit-breadfruit' => ['fruta-pão'],
        'fruit-guava' => ['goiaba'], 'fruit-soursop' => ['graviola'], 'fruit-jabuticaba' => ['jabuticaba'],
        'fruit-jackfruit' => ['jaca'], 'fruit-jambo' => ['jambo'], 'fruit-jamelao' => ['jamelão'],
        'fruit-kiwi' => ['kiwi'], 'fruit-orange' => ['laranja'], 'fruit-lemon' => ['limão'],
        'fruit-apple' => ['maçã'], 'fruit-macauba' => ['macaúba'], 'fruit-papaya' => ['mamão'],
        'fruit-mango' => ['manga'], 'fruit-passionfruit' => ['maracujá'], 'fruit-watermelon' => ['melancia'],
        'fruit-melon' => ['melão'], 'fruit-mexerica' => ['mexerica'], 'fruit-strawberry' => ['morango'],
        'fruit-loquat' => ['nêspera'], 'fruit-pequi' => ['pequi'], 'fruit-pear' => ['pêra'],
        'fruit-peach' => ['pêssego'], 'fruit-sugar-apple' => ['pinha'], 'fruit-pitanga' => ['pitanga'],
        'fruit-pomegranate' => ['romã'], 'fruit-tamarind' => ['tamarindo'], 'fruit-tangerine' => ['tangerina'],
        'fruit-tucuma' => ['tucumã'], 'fruit-umbu' => ['umbu'], 'fruit-grape' => ['uva'],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const GROUP_KEYWORDS = [
        'Ovos e derivados' => [
        'egg-omelette' => ['omelete'],
        'egg-quail' => ['ovo, de codorna'],
        'egg-white' => ['ovo, de galinha, clara'],
        'egg-yolk' => ['ovo, de galinha, gema'],
        'egg-boiled' => ['ovo, de galinha, inteiro, cozido'],
        'egg-fried' => ['ovo, de galinha, inteiro, frito'],
        'egg-raw' => ['ovo, de galinha, inteiro, cru'],
        ],
        'Carnes e derivados' => [
        'meat-burger' => ['hambúrguer', 'hamburguer'],
        'meat-sausage' => ['linguiça', 'lingüiça', 'linguica'],
        'meat-ham' => ['presunto', 'apresuntado'],
        'meat-salami' => ['salame', 'mortadela'],
        'meat-kibbeh' => ['quibe'],
        'meat-meatball' => ['almôndega', 'almondega'],
        ],
        'Cereais e derivados' => [
        'cereal-breakfast' => ['cereal matinal', 'mingau'],
        'cereal-flour' => ['farinha', 'fubá', 'fuba'],
        'cereal-corn' => ['milho', 'curau', 'pamonha'],
        'cereal-cookie' => ['biscoito', 'wafer', 'cream cracker'],
        'cereal-cake' => ['bolo'],
        'cereal-popcorn' => ['pipoca'],
        ],
        'Alimentos preparados' => [
        'prepared-cuscuz' => ['cuscuz'],
        'prepared-feijoada' => ['feijoada'],
        'prepared-stroganoff' => ['estrogonofe'],
        'prepared-tapioca' => ['tapioca'],
        'prepared-carreteiro' => ['arroz carreteiro'],
        'prepared-tacaca' => ['tacacá', 'tacaca'],
        'prepared-salad' => ['salada'],
        'prepared-soup' => ['sopa'],
        'prepared-stew' => ['ensopado'],
        'prepared-yakisoba' => ['yakisoba'],
        'prepared-acaraje' => ['acarajé', 'acaraje'],
        'prepared-vatapa' => ['vatapá', 'vatapa'],
        ],
        'Produtos açucarados' => [
        'sweet-chocolate' => ['chocolate'],
        'sweet-sugar' => ['açúcar', 'acucar'],
        'sweet-cocada' => ['cocada'],
        'sweet-pumpkin-jam' => ['doce, de abóbora', 'doce, de abobora'],
        'sweet-dulce-de-leche' => ['doce, de leite'],
        'sweet-rapadura' => ['rapadura'],
        ],
        'Verduras, hortaliças e derivados' => [
        'vegetable-lettuce' => ['alface'],
        'vegetable-kale' => ['couve'],
        'vegetable-cabbage' => ['repolho'],
        'vegetable-eggplant' => ['berinjela'],
        'vegetable-pepper' => ['pimentão', 'pimentao'],
        'vegetable-spinach' => ['espinafre'],
        ],
        'Miscelâneas' => [
        'misc-coffee' => ['café, pó', 'cafe, po'],
        'misc-cappuccino' => ['capuccino'],
        'misc-baking-powder' => ['fermento em pó', 'fermento em po'],
        'misc-yeast' => ['fermento, biológico', 'fermento, biologico', 'levedura'],
        'misc-gelatin' => ['gelatina'],
        'misc-salt' => ['sal,', 'shoyu', 'tempero'],
        ],
        'Outros alimentos industrializados' => [
        'industrial-black-olive' => ['azeitona, preta'],
        'industrial-green-olive' => ['azeitona, verde'],
        'industrial-whipped-cream' => ['chantilly'],
        'industrial-coconut-milk' => ['leite, de coco'],
        'industrial-mayonnaise' => ['maionese'],
        ],
    ];

    /** @var array<string, list<string>> */
    private const KEYWORDS = [
        'dairy-requeijao' => ['requeijão', 'requeijao'],
        'dairy-ricotta' => ['ricota'],
        'dairy-yogurt' => ['iogurte'],
        'dairy-cream' => ['creme de leite'],
        'dairy-cheese' => ['queijo'],
        'dairy-milk' => ['leite', 'bebida láctea', 'bebida lactea'],
        'drink-water' => ['água', 'agua'],
        'drink-coffee' => ['café', 'cafe'],
        'drink-tea' => ['chá', 'cha'],
        'drink-juice' => ['suco', 'sumo'],
        'drink-soda' => ['refrigerante'],
        'drink-wine' => ['vinho'],
        'fat-olive-oil' => ['azeite'],
        'fat-oil' => ['óleo', 'oleo'],
        'fat-butter' => ['manteiga'],
        'fat-margarine' => ['margarina'],
        'fat-coconut' => ['coco'],
        'fat-peanut-butter' => ['pasta de amendoim', 'manteiga de amendoim'],
        'legume-beans' => ['feijão', 'feijao'],
        'legume-lentils' => ['lentilha'],
        'legume-chickpeas' => ['grão-de-bico', 'grao-de-bico'],
        'legume-peas' => ['ervilha'],
        'legume-soy' => ['soja'],
        'legume-peanuts' => ['amendoim'],
        'vegetable-carrot' => ['cenoura'],
        'vegetable-tomato' => ['tomate'],
        'vegetable-broccoli' => ['brócolis', 'brocolis'],
        'vegetable-cucumber' => ['pepino'],
        'vegetable-pumpkin' => ['abóbora', 'abobora'],
        'vegetable-beetroot' => ['beterraba'],
        'staple-rice' => ['arroz'],
        'staple-bread' => ['pão', 'pao', 'torrada'],
        'staple-pasta' => ['macarrão', 'macarrao', 'massa', 'nhoque', 'lasanha'],
        'staple-potato' => ['batata', 'inhame', 'cará', 'cara', 'mandioquinha'],
        'staple-cassava' => ['mandioca', 'aipim'],
        'staple-oats' => ['aveia'],
        'banana' => ['banana'],
        'bread' => ['pão', 'torrada', 'biscoito', 'bolo', 'pamonha', 'tapioca'],
        'rice' => ['arroz', 'canjica', 'risoto'],
        'beans' => ['feijão', 'lentilha', 'grão-de-bico', 'ervilha', 'soja', 'amendoim'],
        'pasta' => ['macarrão', 'massa', 'nhoque', 'lasanha', 'pizza'],
        'root' => ['batata', 'mandioca', 'aipim', 'inhame', 'cará', 'mandioquinha'],
        'leafy' => ['alface', 'rúcula', 'couve', 'acelga', 'agrião', 'repolho', 'espinafre'],
        'vegetable' => ['abóbora', 'abobrinha', 'berinjela', 'brócolis', 'cenoura', 'tomate', 'pepino', 'pimentão', 'beterraba', 'legume'],
        'fruit' => ['abacate', 'abacaxi', 'açaí', 'acerola', 'ameixa', 'maçã', 'manga', 'mamão', 'melão', 'morango', 'uva', 'laranja', 'tangerina', 'pêra', 'pêssego', 'fruta'],
        'beef' => ['carne bovina', 'bife', 'acém', 'contra-filé', 'patinho', 'músculo', 'fígado'],
        'chicken' => ['frango', 'galinha', 'peru'],
        'pork' => ['porco', 'lombo', 'bisteca', 'costela', 'presunto', 'bacon', 'toucinho'],
        'fish' => ['atum', 'salmão', 'sardinha', 'bacalhau', 'pescada', 'peixe', 'cação', 'abadejo', 'tucunaré'],
        'seafood' => ['camarão', 'caranguejo', 'lula', 'marisco'],
        'egg' => ['ovo'],
        'cheese' => ['queijo', 'ricota', 'requeijão'],
        'milk' => ['leite', 'iogurte', 'bebida láctea'],
        'oil' => ['azeite', 'óleo', 'manteiga', 'margarina'],
        'sweet' => ['açúcar', 'chocolate', 'doce', 'sorvete', 'rapadura', 'quindim', 'paçoca'],
        'drink' => ['café', 'chá', 'suco', 'refrigerante', 'água', 'bebida'],
        'meal' => ['salada', 'sopa', 'ensopado', 'yakisoba', 'acarajé', 'vatapá', 'tacacá'],
    ];

    /** @var array<string, string> */
    private const GROUP_FALLBACKS = [
        'Carnes e derivados' => 'beef',
        'Verduras, hortaliças e derivados' => 'vegetable',
        'Frutas e derivados' => 'fruit',
        'Cereais e derivados' => 'bread',
        'Pescados e frutos do mar' => 'fish',
        'Leguminosas e derivados' => 'beans',
        'Alimentos preparados' => 'meal',
        'Leite e derivados' => 'milk',
        'Produtos açucarados' => 'sweet',
        'Bebidas (alcoólicas e não alcoólicas)' => 'drink',
        'Gorduras e óleos' => 'oil',
        'Ovos e derivados' => 'egg',
        'Miscelâneas' => 'misc-salt',
        'Outros alimentos industrializados' => 'industrialized',
    ];

    public function resolve(string $description, ?string $group): string
    {
        $name = mb_strtolower($description);

        if ($group === 'Frutas e derivados') {
            foreach (self::FRUIT_KEYWORDS as $key => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($name, $keyword)) {
                        return $key;
                    }
                }
            }
        }

        foreach (self::GROUP_KEYWORDS[$group ?? ''] ?? [] as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $key;
                }
            }
        }

        foreach (self::KEYWORDS as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $key;
                }
            }
        }

        return self::GROUP_FALLBACKS[$group ?? ''] ?? 'meal';
    }
}
