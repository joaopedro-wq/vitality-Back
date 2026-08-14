<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Services\FoodImageEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FoodImageEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_a_high_confidence_cc0_commons_image(): void
    {
        Storage::fake('public');
        $food = Alimento::create([
            'descricao' => 'Banana, crua', 'nome_normalizado' => 'banana crua', 'fonte' => 'taco', 'source_reference' => '1',
            'status' => 'ativo', 'grupo' => 'Frutas', 'proteina' => 1, 'gordura' => 0, 'carbo' => 20, 'caloria' => 90, 'qtd' => 100,
        ]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL2uAAAAABJRU5ErkJggg==');
        Http::fake([
            'https://www.wikidata.org/*' => $this->wikidataResponses(),
            'https://commons.wikimedia.org/*' => Http::response($this->commonsResponse('CC0')),
            'https://upload.wikimedia.org/thumb.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(FoodImageEnrichmentService::class)->enrich($food);

        $this->assertSame('published', $result['status']);
        $this->assertDatabaseHas('food_images', ['alimento_id' => $food->id, 'status' => 'published', 'source_license' => 'CC0']);
        Storage::disk('public')->assertExists($result['image']->path);
    }

    public function test_it_rejects_a_non_public_license_without_downloading(): void
    {
        $food = Alimento::create([
            'descricao' => 'Banana, crua', 'nome_normalizado' => 'banana crua', 'fonte' => 'taco', 'source_reference' => '1',
            'status' => 'ativo', 'grupo' => 'Frutas', 'proteina' => 1, 'gordura' => 0, 'carbo' => 20, 'caloria' => 90, 'qtd' => 100,
        ]);
        Http::fake([
            'https://www.wikidata.org/*' => $this->wikidataResponses(),
            'https://commons.wikimedia.org/*' => Http::response($this->commonsResponse('CC BY-SA 4.0')),
        ]);

        $result = app(FoodImageEnrichmentService::class)->enrich($food);

        $this->assertSame('rejected', $result['status']);
        $this->assertDatabaseHas('food_images', ['alimento_id' => $food->id, 'status' => 'rejected']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://upload.wikimedia.org/thumb.jpg');
    }

    private function wikidataResponses(): \Illuminate\Http\Client\ResponseSequence
    {
        return Http::sequence()
            ->push(['search' => [['id' => 'Q503', 'label' => 'Banana', 'aliases' => []]]])
            ->push([
                'entities' => [
                    'Q503' => [
                        'claims' => [
                            'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Banana.jpg']]]],
                        ],
                    ],
                ],
            ]);
    }

    private function commonsResponse(string $license): array
    {
        return [
            'query' => [
                'pages' => [
                    '1' => [
                        'imageinfo' => [[
                            'url' => 'https://upload.wikimedia.org/original.jpg',
                            'thumburl' => 'https://upload.wikimedia.org/thumb.jpg',
                            'extmetadata' => [
                                'LicenseShortName' => ['value' => $license],
                                'LicenseUrl' => ['value' => 'https://creativecommons.org/publicdomain/zero/1.0/'],
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
