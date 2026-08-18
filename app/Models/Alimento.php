<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alimento extends Model
{
    use HasFactory;

    protected $fillable = [
        'descricao',
        'nome_exibicao',
        'detalhe_exibicao',
        'nome_exibicao_normalizado',
        'proteina',
        'gordura',
        'caloria',
        'carbo',
        'qtd',
        'id_usuario',
        'fonte',
        'source_reference',
        'grupo',
        'grupo_normalizado',
        'grupo_exibicao',
        'illustration_key',
        'nome_normalizado',
        'status',
        'created_by',
        'updated_by',
        'source_version',
        'source_checksum',

    ];

    /**
     * Nome amigável para exibição (ex. "Abacate"). Fonte única de verdade
     * usada por toda a API — busca, diário, plano alimentar, troca e
     * favoritos — em vez de cada tela montar seu próprio texto a partir de
     * `descricao` (o nome técnico original, ex. "Abacate, cru", preservado
     * intocado para rastreabilidade e matching interno). Cai para
     * `descricao` enquanto o backfill (`foods:generate-display-names` /
     * `foods:apply-display-names`) não roda para este alimento.
     */
    public function getNomeExibicaoAttribute(): string
    {
        return ($this->attributes['nome_exibicao'] ?? null) ?: $this->descricao;
    }

    /**
     * Complemento do nome amigável (preparo, corte, estado), ex. "cru",
     * "congelado, assado". Nulo quando o nome principal já é
     * autoexplicativo.
     */
    public function getDetalheExibicaoAttribute(): ?string
    {
        return ($this->attributes['detalhe_exibicao'] ?? null) ?: null;
    }

    /**
     * Categoria amigável e granular (ex. "Peixes e frutos do mar"). Cai
     * para `grupo_normalizado` (categoria mais ampla, já usada pelos
     * filtros existentes) enquanto o backfill não roda.
     */
    public function getGrupoExibicaoAttribute(): string
    {
        return ($this->attributes['grupo_exibicao'] ?? null) ?: ($this->grupo_normalizado ?: 'Outros');
    }



    public function dietas()
    {
        return $this->belongsToMany(Dieta::class, 'dieta_alimentos', 'alimento_id', 'dieta_id')->withPivot('qtd');
    }

    public function registros()
    {
        return $this->belongsToMany(Registro::class, 'registro_alimentos')->withPivot('qtd');
    }
    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function userPreferences()
    {
        return $this->hasMany(UserFood::class, 'food_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function nutrientes()
    {
        return $this->belongsToMany(Nutriente::class, 'alimento_nutrientes', 'alimento_id', 'nutriente_id')
            ->withPivot(['valor', 'tipo_dado']);
    }

    public function images()
    {
        return $this->hasMany(FoodImage::class, 'alimento_id');
    }

    public function publishedImage()
    {
        return $this->hasOne(FoodImage::class, 'alimento_id')->where('status', 'published');
    }

    public function planTags()
    {
        return $this->belongsToMany(FoodPlanTag::class, 'alimento_food_plan_tag');
    }

    public function restrictions()
    {
        return $this->belongsToMany(FoodRestriction::class, 'alimento_food_restriction');
    }

}
