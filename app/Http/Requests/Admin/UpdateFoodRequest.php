<?php

namespace App\Http\Requests\Admin;

class UpdateFoodRequest extends StoreFoodRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'in:ativo,pendente,arquivado'],
        ]);
    }
}
