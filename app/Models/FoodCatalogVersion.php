<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodCatalogVersion extends Model
{
    protected $fillable = ['source', 'version', 'checksum', 'status', 'summary', 'activated_at'];
    protected function casts(): array { return ['summary' => 'array', 'activated_at' => 'datetime']; }
}
