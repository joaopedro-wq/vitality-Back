<?php

namespace Tests\Unit;

use App\Services\FoodPlanClassificationService;
use PHPUnit\Framework\TestCase;

class FoodPlanClassificationServiceTest extends TestCase
{
    private FoodPlanClassificationService $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new FoodPlanClassificationService();
    }

    public function test_it_classifies_basic_staples_with_roles_and_styles(): void
    {
        $arroz = $this->classifier->classify('Cereais e derivados', 'Arroz, tipo 1, cozido', 2.5, 28, .2);
        $frango = $this->classifier->classify('Carnes e derivados', 'Frango, peito, cozido', 31, 0, 4);

        $this->assertEqualsCanonicalizing(['carboidrato', 'base_alimentar', 'caseiro', 'economico'], $arroz);
        $this->assertEqualsCanonicalizing(['proteina', 'base_alimentar', 'caseiro', 'economico'], $frango);
    }

    public function test_it_keeps_sweets_and_alcohol_available_as_complements_not_foundations(): void
    {
        $chocolate = $this->classifier->classify('Produtos açucarados', 'Chocolate, ao leite', 7.2, 59.5, 30.2);
        $beer = $this->classifier->classify('Bebidas (alcoólicas e não alcoólicas)', 'Cerveja, pilsen', .5, 3.3, 0);

        $this->assertContains('complemento', $chocolate);
        $this->assertNotContains('base_alimentar', $chocolate);
        $this->assertSame(['complemento'], $beer);
    }

    public function test_it_marks_fruits_as_fast_foundation_options(): void
    {
        $banana = $this->classifier->classify('Frutas e derivados', 'Banana, prata, crua', 1.3, 26, 0);

        $this->assertEqualsCanonicalizing(['fruta', 'base_alimentar', 'caseiro', 'rapido'], $banana);
    }
}
