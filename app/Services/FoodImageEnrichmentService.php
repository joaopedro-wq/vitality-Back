<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\FoodImage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FoodImageEnrichmentService
{
    public function __construct(private readonly FoodCatalogService $catalog) {}

    /** @return array{status:string,message:string,image?:FoodImage} */
    public function enrich(Alimento $food, bool $dryRun = false): array
    {
        try {
            $candidate = $this->findCandidate($food);
            if (! $candidate) {
                return $this->recordFailure($food, 'Nenhuma entidade Wikimedia compatível encontrada.', $dryRun);
            }

            if ($candidate['score'] < config('food-images.minimum_match_score')) {
                return $this->recordFailure($food, 'Correspondência abaixo do limiar de confiança.', $dryRun, $candidate);
            }

            $metadata = $this->imageMetadata($candidate['filename']);
            if (! $metadata || ! $this->hasAllowedLicense($metadata['license'])) {
                return $this->recordFailure($food, 'Imagem sem licença de domínio público ou CC0.', $dryRun, $candidate, $metadata);
            }

            if ($dryRun) {
                return ['status' => 'eligible', 'message' => "Imagem elegível: {$candidate['filename']}"];
            }

            $image = $this->downloadAndPublish($food, $candidate, $metadata);

            return ['status' => 'published', 'message' => "Imagem publicada: {$candidate['filename']}", 'image' => $image];
        } catch (RequestException $exception) {
            return $this->recordFailure($food, 'Falha de rede ao consultar Wikimedia: '.$exception->getMessage(), $dryRun, null, null, 'failed');
        } catch (RuntimeException $exception) {
            return $this->recordFailure($food, $exception->getMessage(), $dryRun);
        }
    }

    /** @return array{qid:string,filename:string,score:float}|null */
    private function findCandidate(Alimento $food): ?array
    {
        $query = $this->searchTerm($food->descricao);
        if ($query === '') {
            return null;
        }

        $results = Http::acceptJson()->withUserAgent(config('food-images.user_agent'))->retry(3, 250)->get(config('food-images.wikidata_api'), [
            'action' => 'wbsearchentities', 'search' => $query, 'language' => 'pt', 'format' => 'json', 'limit' => 8,
        ])->throw()->json('search', []);

        $best = null;
        foreach ($results as $result) {
            $score = $this->score($query, (string) ($result['label'] ?? ''), Arr::wrap($result['aliases'] ?? []));
            if (! $best || $score > $best['score']) {
                $best = ['qid' => $result['id'], 'score' => $score];
            }
        }
        if (! $best) {
            return null;
        }

        $entity = Http::acceptJson()->withUserAgent(config('food-images.user_agent'))->retry(3, 250)->get(config('food-images.wikidata_api'), [
            'action' => 'wbgetentities', 'ids' => $best['qid'], 'props' => 'claims', 'format' => 'json',
        ])->throw()->json("entities.{$best['qid']}");
        $filename = data_get($entity, 'claims.P18.0.mainsnak.datavalue.value');

        return is_string($filename) ? ['qid' => $best['qid'], 'filename' => $filename, 'score' => $best['score']] : null;
    }

    /** @return array{thumb_url:string,source_url:string,license:string,license_url:?string,author:?string}|null */
    private function imageMetadata(string $filename): ?array
    {
        $pages = Http::acceptJson()->withUserAgent(config('food-images.user_agent'))->retry(3, 250)->get(config('food-images.commons_api'), [
            'action' => 'query', 'prop' => 'imageinfo', 'titles' => 'File:'.$filename,
            'iiprop' => 'url|extmetadata', 'iiurlwidth' => config('food-images.thumbnail_width'), 'format' => 'json',
        ])->throw()->json('query.pages', []);
        $info = data_get(reset($pages), 'imageinfo.0');
        if (! is_array($info) || empty($info['thumburl']) || empty($info['url'])) {
            return null;
        }

        $meta = $info['extmetadata'] ?? [];

        return [
            'thumb_url' => $info['thumburl'],
            'source_url' => $info['descriptionurl'] ?? $info['url'],
            'license' => strip_tags((string) data_get($meta, 'LicenseShortName.value', '')),
            'license_url' => data_get($meta, 'LicenseUrl.value'),
            'author' => trim(strip_tags((string) data_get($meta, 'Artist.value', ''))) ?: null,
        ];
    }

    /** @param array{qid:string,filename:string,score:float} $candidate @param array{thumb_url:string,source_url:string,license:string,license_url:?string,author:?string} $metadata */
    private function downloadAndPublish(Alimento $food, array $candidate, array $metadata): FoodImage
    {
        $response = Http::withUserAgent(config('food-images.user_agent'))->retry(3, 250)->get($metadata['thumb_url'])->throw();
        $contents = $response->body();
        $dimensions = @getimagesizefromstring($contents);
        if (! $dimensions) {
            throw new RuntimeException('Thumbnail retornada não é uma imagem válida.');
        }

        $hash = hash('sha256', $contents);
        $extension = match ($response->header('Content-Type')) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };
        $path = "food-images/{$food->id}/{$hash}.{$extension}";
        Storage::disk(config('food-images.disk'))->put($path, $contents, 'public');

        return DB::transaction(function () use ($food, $candidate, $metadata, $hash, $path, $dimensions): FoodImage {
            FoodImage::where('alimento_id', $food->id)->where('status', 'published')
                ->update(['status' => 'superseded', 'rejection_reason' => 'Substituída por nova imagem publicada.']);

            return FoodImage::updateOrCreate(
                ['alimento_id' => $food->id, 'commons_filename' => $candidate['filename']],
                [
                    'wikidata_id' => $candidate['qid'], 'source_url' => $metadata['source_url'],
                    'source_license' => $metadata['license'], 'source_license_url' => $metadata['license_url'],
                    'source_author' => $metadata['author'], 'path' => $path, 'image_hash' => $hash,
                    'width' => $dimensions[0], 'height' => $dimensions[1], 'match_score' => $candidate['score'],
                    'status' => 'published', 'rejection_reason' => null,
                ],
            );
        });
    }

    /** @param array{qid:string,filename:string,score:float}|null $candidate @param array{source_url:string,license:string,license_url:?string,author:?string}|null $metadata */
    private function recordFailure(Alimento $food, string $reason, bool $dryRun, ?array $candidate = null, ?array $metadata = null, string $status = 'rejected'): array
    {
        if ($dryRun) {
            return ['status' => 'skipped', 'message' => $reason];
        }
        $image = FoodImage::updateOrCreate(
            ['alimento_id' => $food->id, 'commons_filename' => $candidate['filename'] ?? null],
            [
                'wikidata_id' => $candidate['qid'] ?? null, 'source_url' => $metadata['source_url'] ?? null,
                'source_license' => $metadata['license'] ?? null, 'source_license_url' => $metadata['license_url'] ?? null,
                'source_author' => $metadata['author'] ?? null, 'match_score' => $candidate['score'] ?? null,
                'status' => $status, 'rejection_reason' => $reason,
            ],
        );

        return ['status' => $status, 'message' => $reason, 'image' => $image];
    }

    private function searchTerm(string $description): string
    {
        $term = $this->catalog->normalizeName($description);
        $term = preg_replace('/\b(cru|crua|cozido|cozida|assado|assada|frito|frita|grelhado|grelhada|com|sem|sal|acucar)\b/', ' ', $term) ?? $term;

        return trim((string) preg_replace('/\s+/', ' ', $term));
    }

    /** @param array<int,string> $aliases */
    private function score(string $query, string $label, array $aliases): float
    {
        $query = $this->catalog->normalizeName($query);
        $names = array_merge([$label], $aliases);
        $best = 0;
        foreach ($names as $name) {
            $candidate = $this->catalog->normalizeName((string) $name);
            if ($candidate === $query) {
                $best = max($best, 100);
            } elseif ($candidate !== '' && (str_contains($candidate, $query) || str_contains($query, $candidate))) {
                $best = max($best, 92);
            } else {
                similar_text($query, $candidate, $percentage) && $best = max($best, $percentage);
            }
        }

        return round((float) $best, 2);
    }

    private function hasAllowedLicense(string $license): bool
    {
        $license = mb_strtolower(trim($license));

        return str_contains($license, 'public domain') || str_contains($license, 'cc0');
    }
}
