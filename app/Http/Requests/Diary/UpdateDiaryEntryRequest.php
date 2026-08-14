<?php

namespace App\Http\Requests\Diary;

use App\Models\Registro;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateDiaryEntryRequest extends StoreDiaryEntryRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof Registro && $entry->id_usuario === $this->user()?->id;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }
}
