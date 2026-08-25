<?php

namespace Tests\Feature\Admin;

use App\Models\FoodCatalogVersion;
use App\Models\User;
use App\Services\TacoCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class TacoSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_upload_and_activate_a_taco_spreadsheet(): void
    {
        Storage::fake();
        $summary = [
            'foods' => 597,
            'groups' => ['Frutas e derivados' => 92],
            'families' => ['fruta' => 92],
            'pending_review' => 0,
            'role_coverage' => ['fruta_lanche' => 92],
            'checksum' => str_repeat('a', 64),
        ];
        $version = new FoodCatalogVersion([
            'source' => 'taco',
            'version' => '4a-edicao',
            'checksum' => $summary['checksum'],
            'status' => 'active',
            'summary' => $summary,
            'activated_at' => now(),
        ]);
        $version->id = 42;
        $this->mock(TacoCatalogImporter::class, function (MockInterface $mock) use ($summary, $version): void {
            $mock->shouldReceive('preview')->once()->andReturn($summary);
            $mock->shouldReceive('import')
                ->once()
                ->withArgs(fn (string $path, bool $activate) => $activate && is_file($path))
                ->andReturn($version);
        });
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->post('/api/admin/foods/import-taco-spreadsheet', [
            'file' => UploadedFile::fake()->create('Taco-4a-Edicao.xlsx', 128, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version.id', 42)
            ->assertJsonPath('data.version.status', 'active')
            ->assertJsonPath('data.summary.foods', 597);

        $this->assertSame([], Storage::allFiles('imports/taco'));
    }

    public function test_spreadsheet_import_requires_an_xlsx_file(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->post('/api/admin/foods/import-taco-spreadsheet', [
            'file' => UploadedFile::fake()->create('taco.csv', 20, 'text/csv'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }
}
