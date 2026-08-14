<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class DiaryEntryItem extends Pivot
{
    protected $table = 'registro_alimentos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qtd' => 'decimal:3', 'nutrientes_snapshot' => 'array'];
    }
}
