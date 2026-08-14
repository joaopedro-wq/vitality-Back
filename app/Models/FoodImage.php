<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class FoodImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'alimento_id', 'wikidata_id', 'commons_filename', 'source_url', 'source_license',
        'source_license_url', 'source_author', 'path', 'image_hash', 'width', 'height',
        'match_score', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'match_score' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function alimento()
    {
        return $this->belongsTo(Alimento::class, 'alimento_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function url(): ?string
    {
        return $this->path ? Storage::disk(config('food-images.disk'))->url($this->path) : null;
    }
}
