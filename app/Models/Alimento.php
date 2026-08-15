<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alimento extends Model
{
    use HasFactory;

    protected $fillable = [
        'descricao',
        'proteina',
        'gordura',
        'caloria',
        'carbo',
        'qtd',
        'id_usuario',
        'fonte',
        'source_reference',
        'grupo',
        'illustration_key',
        'nome_normalizado',
        'status',
        'created_by',
        'updated_by',
        'source_version',
        'source_checksum',

    ];



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
