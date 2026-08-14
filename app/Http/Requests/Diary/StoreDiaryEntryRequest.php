<?php

namespace App\Http\Requests\Diary;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiaryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'meal_id' => ['required', 'integer'],
            'consumed_at' => ['required', 'date', 'before_or_equal:now'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.food_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ];
    }
}
