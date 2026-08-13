<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Nutriente extends Model
{
    protected $table = 'nutrientes';
    protected $fillable = ['codigo', 'nome', 'unidade', 'categoria'];
}
