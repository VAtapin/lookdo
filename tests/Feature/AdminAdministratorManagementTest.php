<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAdministratorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_update_and_delete_another_administrator(): void
    {
        $actor = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

        $created = $this->actingAs($actor)->postJson('/api/control/administrators', [
            'name' => 'Second Admin',
            'email' => 'second@example.test',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'locale' => 'de',
            'is_active' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('email', 'second@example.test')
            ->assertJsonPath('is_super_admin', true);
        $administrator = User::where('email', 'second@example.test')->firstOrFail();

        $this->actingAs($actor)->putJson('/api/control/administrators/'.$administrator->id, [
            'name' => 'Updated Admin',
            'email' => 'updated@example.test',
            'password' => '',
            'password_confirmation' => '',
            'locale' => 'en',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Admin')
            ->assertJsonPath('is_active', false);

        $this->actingAs($actor)
            ->deleteJson('/api/control/administrators/'.$administrator->id)
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('users', ['id' => $administrator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.created', 'actor_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.updated', 'actor_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.deleted', 'actor_id' => $actor->id]);
    }

    public function test_administrator_cannot_disable_or_delete_own_account(): void
    {
        $actor = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

        $this->actingAs($actor)->putJson('/api/control/administrators/'.$actor->id, [
            'name' => $actor->name,
            'email' => $actor->email,
            'password' => '',
            'password_confirmation' => '',
            'locale' => 'de',
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->actingAs($actor)
            ->deleteJson('/api/control/administrators/'.$actor->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('administrator');
    }
}
