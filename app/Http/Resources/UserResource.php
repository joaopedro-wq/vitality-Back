<?php

namespace App\Http\Resources;

use App\Services\UserAvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'avatar' => UserAvatarService::url($this->avatar),
            'nivel_atividade' => $this->nivel_atividade,
            'objetivo' => $this->objetivo,
            'is_admin' => $this->is_admin,
            'onboarding_status' => $this->onboarding_status,
            'onboarding_finished_at' => $this->onboarding_finished_at?->toISOString(),
        ];
    }
}
