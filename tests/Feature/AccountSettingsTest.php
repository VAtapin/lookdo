<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ConfirmAccountEmailChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_change_name_email_and_password(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.test',
            'password' => 'OldPassword123',
        ]);

        $this->actingAs($user)->putJson('/api/account', [
            'name' => 'New Name',
            'email' => 'new@example.test',
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', 'old@example.test')
            ->assertJsonPath('user.pending_email', 'new@example.test')
            ->assertJsonPath('email_pending', true);

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('old@example.test', $user->email);
        $this->assertSame('new@example.test', $user->pending_email);
        $this->assertTrue(Hash::check('NewPassword123', $user->password));
        Notification::assertSentOnDemand(ConfirmAccountEmailChange::class);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);

        $this->actingAs($user)->putJson('/api/account', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'wrong-password',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }
}
