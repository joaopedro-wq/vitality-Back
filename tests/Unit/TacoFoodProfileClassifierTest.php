<?php

namespace Tests\Unit;

use App\Services\TacoFoodProfileClassifier;
use Tests\TestCase;

class TacoFoodProfileClassifierTest extends TestCase
{
    public function test_pineapple_is_a_direct_vegan_snack_fruit(): void
    {
        $profile = app(TacoFoodProfileClassifier::class)->classify('Frutas e derivados', 'Abacaxi, cru', .9, 12.3, .1);

        $this->assertSame('fruta', $profile['family']);
        $this->assertTrue($profile['direct_consumption']);
        $this->assertContains('fruta_lanche', $profile['tags']);
        $this->assertSame('compativel', $profile['diets']['vegana']);
    }

    public function test_oats_are_vegan_but_not_assumed_gluten_free(): void
    {
        $profile = app(TacoFoodProfileClassifier::class)->classify('Cereais e derivados', 'Aveia, flocos, crua', 13.9, 66.6, 8.5);

        $this->assertSame('aveia_cereal', $profile['family']);
        $this->assertContains('cafe_base', $profile['tags']);
        $this->assertSame('compativel', $profile['diets']['vegana']);
        $this->assertSame('desconhecido', $profile['restrictions']['gluten']);
    }

    public function test_meat_and_dairy_are_not_vegan(): void
    {
        $classifier = app(TacoFoodProfileClassifier::class);
        $meat = $classifier->classify('Carnes e derivados', 'Frango, peito, cozido', 31, 0, 3.6);
        $milk = $classifier->classify('Leite e derivados', 'Leite, integral', 3.2, 4.7, 3.2);

        $this->assertSame('incompativel', $meat['diets']['vegetariana']);
        $this->assertSame('incompativel', $milk['diets']['vegana']);
        $this->assertSame('incompativel', $milk['restrictions']['lactose']);
    }

    public function test_does_not_classify_oil_or_popcorn_as_plant_protein(): void
    {
        $classifier = app(TacoFoodProfileClassifier::class);
        $oil = $classifier->classify('Óleos e gorduras', 'Óleo, de soja', 0, 0, 100);
        $popcorn = $classifier->classify('Cereais e derivados', 'Pipoca, com óleo de soja, sem sal', 9, 70, 15);

        $this->assertSame('gordura', $oil['family']);
        $this->assertNotContains('prato_proteina', $oil['tags']);
        $this->assertSame('cereal', $popcorn['family']);
        $this->assertNotContains('prato_proteina', $popcorn['tags']);
    }
}
