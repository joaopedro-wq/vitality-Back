<?php

namespace Tests\Feature;

use App\Models\FoodCatalogVersion;
use App\Services\FoodCatalogService;
use Database\Seeders\TacoFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TacoFoodSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_reactivate_the_legacy_catalog_when_taco_four_is_active(): void
    {
        FoodCatalogVersion::create([
            'source' => 'taco',
            'version' => '4a-edicao',
            'checksum' => str_repeat('a', 64),
            'status' => 'active',
        ]);
        $this->mock(FoodCatalogService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('importTaco');
        });

        app(TacoFoodSeeder::class)->run();

        $this->assertTrue(true);
    }
}
