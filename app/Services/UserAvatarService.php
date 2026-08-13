<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UserAvatarService
{
    public const DISK = 'public';

    public function replace(User $user, UploadedFile $avatar): User
    {
        $disk = Storage::disk(self::DISK);
        $previousPath = $this->storedPath($user->avatar);
        $newPath = $avatar->store("avatars/{$user->id}", self::DISK);

        try {
            $user->forceFill(['avatar' => $newPath])->save();
        } catch (Throwable $exception) {
            $disk->delete($newPath);

            throw $exception;
        }

        if ($previousPath && $previousPath !== $newPath) {
            $disk->delete($previousPath);
        }

        return $user->fresh();
    }

    public function remove(User $user): User
    {
        $previousPath = $this->storedPath($user->avatar);

        if (! $previousPath) {
            return $user;
        }

        $user->forceFill(['avatar' => null])->save();
        Storage::disk(self::DISK)->delete($previousPath);

        return $user->fresh();
    }

    private function storedPath(?string $avatar): ?string
    {
        if (! $avatar) {
            return null;
        }

        if (! filter_var($avatar, FILTER_VALIDATE_URL)) {
            return ltrim($avatar, '/');
        }

        $path = parse_url($avatar, PHP_URL_PATH) ?: '';
        $storageMarker = '/storage/';
        $markerPosition = strpos($path, $storageMarker);

        return $markerPosition === false ? null : substr($path, $markerPosition + strlen($storageMarker));
    }
}
