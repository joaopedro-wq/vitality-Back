<?php

namespace App\Http\Requests\Diary;

use Illuminate\Foundation\Http\FormRequest;

class StoreMealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:80'],
            'horario' => ['required', 'date_format:H:i,H:i:s'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }
}
