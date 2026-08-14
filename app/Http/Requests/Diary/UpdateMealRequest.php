<?php

namespace App\Http\Requests\Diary;

use App\Models\Refeicao;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateMealRequest extends StoreMealRequest
{
    public function authorize(): bool
    {
        $meal = $this->route('meal');

        return $meal instanceof Refeicao && $meal->id_usuario === $this->user()?->id;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:80'],
            'horario' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
            'ordem' => ['sometimes', 'required', 'integer', 'min:0', 'max:999'],
        ];
    }
}
