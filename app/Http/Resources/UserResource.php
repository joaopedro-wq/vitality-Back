<?php

namespace App\Http\Resources;

use App\Services\UserAvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'data_nascimento' => $this->data_nascimento,
            'genero' => $this->genero,
            'peso' => $this->peso,
            'altura' => $this->altura,
            'avatar' => $this->avatarUrl(),
            'nivel_atividade' => $this->nivel_atividade,
            'objetivo' => $this->objetivo,
            'is_admin' => $this->is_admin,
        ];
    }

    private function avatarUrl(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        if (filter_var($this->avatar, FILTER_VALIDATE_URL)) {
            return $this->avatar;
        }

        return url(Storage::disk(UserAvatarService::DISK)->url($this->avatar));
    }
}
