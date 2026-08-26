<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Group extends Model
{
    public const TIPOS_DESAFIO = ['weekly', 'monthly', 'all_time', 'custom'];

    /** Nome fixo do grupo do sistema que todo usuário entra ao logar — ver `GroupService`. */
    public const NOME_GRUPO_GLOBAL = 'Vitality';

    protected $fillable = [
        'name',
        'invite_code',
        'owner_id',
        'challenge_type',
        'challenge_starts_at',
        'challenge_ends_at',
        'is_global',
    ];

    /** Nenhuma tela do front usa `created_at`/`updated_at` de grupo — achado revisando o
     * payload de `/groups` (trazia isso e o `pivot` de `group_members` sem necessidade). */
    protected $hidden = ['created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'challenge_starts_at' => 'immutable_datetime',
            'challenge_ends_at' => 'immutable_datetime',
            'is_global' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Group $group) {
            do {
                $code = strtoupper(Str::random(8));
            } while (self::where('invite_code', $code)->exists());
            $group->invite_code = $code;
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot('joined_at')
            ->withTimestamps();
    }
}
