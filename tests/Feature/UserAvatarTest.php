<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserAvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(UserAvatarService::DISK);
    }

    public function test_authenticated_user_can_upload_and_replace_own_avatar(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/user/avatar', [
            'avatar' => UploadedFile::fake()->image('first.png')->size(512),
        ])->assertOk()->assertJsonPath('data.id', $user->id);

        $firstPath = $user->fresh()->avatar;
        Storage::disk(UserAvatarService::DISK)->assertExists($firstPath);

        $this->post('/api/user/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg')->size(512),
        ])->assertOk();

        $secondPath = $user->fresh()->avatar;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk(UserAvatarService::DISK)->assertMissing($firstPath);
        Storage::disk(UserAvatarService::DISK)->assertExists($secondPath);
    }

    public function test_authenticated_user_can_remove_own_avatar_idempotently(): void
    {
        Storage::disk(UserAvatarService::DISK)->put('avatars/1/avatar.png', 'avatar');
        $user = User::factory()->create(['avatar' => 'avatars/1/avatar.png']);
        Sanctum::actingAs($user);

        $this->delete('/api/user/avatar')->assertNoContent();
        $this->assertNull($user->fresh()->avatar);
        Storage::disk(UserAvatarService::DISK)->assertMissing('avatars/1/avatar.png');

        $this->delete('/api/user/avatar')->assertNoContent();
    }

    public function test_avatar_must_be_a_supported_image_up_to_two_megabytes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->post('/api/user/avatar', [
            'avatar' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('avatar');
    }
}
