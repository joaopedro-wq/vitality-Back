<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFoodRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->is_admin === true; }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:255'],
            'grupo' => ['nullable', 'string', 'max:120'],
            'proteina' => ['required', 'numeric', 'min:0'],
            'gordura' => ['required', 'numeric', 'min:0'],
            'carbo' => ['required', 'numeric', 'min:0'],
            'caloria' => ['required', 'numeric', 'min:0'],
            'qtd' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
