<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\FoodAlias;
use App\Models\FoodImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function food(array $attributes = []): Alimento
    {
        return Alimento::create(array_merge([
            'descricao' => 'Arroz integral cozido', 'nome_normalizado' => 'arroz integral cozido',
            'fonte' => 'taco', 'source_reference' => 'test-1', 'status' => 'ativo', 'grupo' => 'Cereais',
            'proteina' => 2.6, 'gordura' => 1, 'carbo' => 25.8, 'caloria' => 123.5, 'qtd' => 100,
        ], $attributes));
    }

    public function test_user_can_search_active_catalog_and_manage_own_favorites(): void
    {
        $food = $this->food();
        $archived = $this->food(['descricao' => 'Item arquivado', 'nome_normalizado' => 'item arquivado', 'source_reference' => 'test-2', 'status' => 'arquivado']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/foods?search=arroz')->assertOk()->assertJsonPath('data.0.id', $food->id);
        $this->postJson("/api/foods/{$food->id}/favorite")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->getJson('/api/foods?tab=favorites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $food->id);
        $this->deleteJson("/api/foods/{$food->id}/favorite")->assertNoContent();
        $this->getJson('/api/foods?tab=favorites')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/foods/{$archived->id}")->assertNotFound();
    }

    public function test_only_admin_can_manage_catalog(): void
    {
        $payload = ['descricao' => 'Iogurte natural', 'grupo' => 'Laticínios', 'proteina' => 4, 'gordura' => 3, 'carbo' => 5, 'caloria' => 65, 'qtd' => 100];
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/admin/foods', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/admin/foods', $payload)->assertCreated()->assertJsonPath('data.fonte', 'manual');
        $this->assertDatabaseHas('alimentos', ['descricao' => 'Iogurte natural', 'fonte' => 'manual', 'status' => 'ativo']);
    }

    public function test_catalog_exposes_a_published_food_image_url(): void
    {
        $food = $this->food();
        FoodImage::create([
            'alimento_id' => $food->id, 'commons_filename' => 'Rice.jpg', 'path' => 'food-images/test/rice.jpg',
            'source_license' => 'CC0', 'status' => 'published', 'match_score' => 100,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/foods')->assertOk()->assertJsonPath('data.0.image_url', '/storage/food-images/test/rice.jpg');
    }

    public function test_catalog_search_finds_aliases_and_returns_the_food_detail(): void
    {
        $food = $this->food([
            'descricao' => 'Leite, de vaca, integral',
            'nome_normalizado' => 'leite de vaca integral',
            'nome_exibicao' => 'Leite',
            'detalhe_exibicao' => 'de vaca, integral',
        ]);
        FoodAlias::create([
            'alimento_id' => $food->id,
            'alias' => 'Leite integral',
            'normalized' => 'leite integral',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/foods?search=leite%20integral')
            ->assertOk()
            ->assertJsonPath('data.0.id', $food->id)
            ->assertJsonPath('data.0.descricao', 'Leite')
            ->assertJsonPath('data.0.detalhe_exibicao', 'de vaca, integral');
    }
}
