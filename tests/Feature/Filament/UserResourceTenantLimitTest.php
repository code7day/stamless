<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRoleEnum;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserResourceTenantLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(string $plan = 'free', string $slug = 'tenant-a'): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_active' => true,
            'plan' => $plan,
        ]);

        $user = User::create([
            'name' => "Owner {$slug}",
            'email' => "owner@{$slug}.test",
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        return [$tenant, $user];
    }

    private function bootPanel(Tenant $tenant, User $user): void
    {
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);
    }

    public function test_user_resource_table_is_scoped_to_current_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser('free', 'tenant-a');
        [$tenantB, $userB] = $this->makeTenantWithUser('free', 'tenant-b');

        $this->bootPanel($tenantA, $userA);

        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$userA])
            ->assertCanNotSeeTableRecords([$userB]);
    }

    public function test_free_plan_reaches_limit_with_one_user_and_halts_creation(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('free', 'tenant-free');
        $this->bootPanel($tenant, $user);

        $this->assertTrue(UserResource::isUserLimitReached());

        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Second User',
                'email' => 'second@tenant-free.test',
                'password' => 'password123',
                'role' => UserRoleEnum::Editor->value,
            ]);

        $this->assertDatabaseMissing(User::class, [
            'email' => 'second@tenant-free.test',
        ]);
        $this->assertSame(1, User::where('tenant_id', $tenant->id)->count());
    }

    public function test_sponsorship_plan_allows_up_to_three_users(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('sponsorship', 'tenant-sponsor');
        $this->bootPanel($tenant, $user);

        $this->assertFalse(UserResource::isUserLimitReached());

        // Create Spatie roles in DB
        Role::firstOrCreate(['name' => UserRoleEnum::Editor->value, 'guard_name' => 'web']);

        // Create 2nd user
        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Second User',
                'email' => 'second@tenant-sponsor.test',
                'password' => 'password123',
                'role' => UserRoleEnum::Editor->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, User::where('tenant_id', $tenant->id)->count());
        $this->assertFalse(UserResource::isUserLimitReached());

        // Create 3rd user
        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Third User',
                'email' => 'third@tenant-sponsor.test',
                'password' => 'password123',
                'role' => UserRoleEnum::Editor->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(3, User::where('tenant_id', $tenant->id)->count());
        $this->assertTrue(UserResource::isUserLimitReached());

        // 4th user should be blocked
        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Fourth User',
                'email' => 'fourth@tenant-sponsor.test',
                'password' => 'password123',
                'role' => UserRoleEnum::Editor->value,
            ]);

        $this->assertSame(3, User::where('tenant_id', $tenant->id)->count());
    }

    public function test_spatie_roles_are_isolated_by_tenant_team(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser('free', 'tenant-a');
        [$tenantB, $userB] = $this->makeTenantWithUser('free', 'tenant-b');

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);

        // Assign 'admin' to userA under tenantA
        setPermissionsTeamId($tenantA->id);
        $userA->assignRole('admin');

        $this->assertTrue($userA->hasRole('admin'));

        // Switch team context to tenantB
        setPermissionsTeamId($tenantB->id);
        $userA->unsetRelation('roles');
        $this->assertFalse($userA->hasRole('admin'));

        // Assign 'editor' to userB under tenantB
        $userB->assignRole('editor');
        $this->assertTrue($userB->hasRole('editor'));

        // userB should not have 'editor' in tenantA
        setPermissionsTeamId($tenantA->id);
        $userB->unsetRelation('roles');
        $this->assertFalse($userB->hasRole('editor'));
    }

    public function test_super_admin_bypasses_all_gates(): void
    {
        $superAdmin = User::create([
            'name' => 'Platform Super Admin',
            'email' => 'super@platform.stamless.com',
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::allows('any-non-existent-permission'));
        $this->assertTrue(Gate::allows('manage-tenants'));
    }

    public function test_cannot_delete_currently_logged_in_user(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('sponsorship', 'tenant-del');
        $this->bootPanel($tenant, $user);

        Livewire::test(ManageUsers::class)
            ->callTableAction('delete', $user);

        $this->assertDatabaseHas(User::class, [
            'id' => $user->id,
        ]);
    }

    public function test_admin_can_change_password_from_table_action(): void
    {
        [$tenant, $admin] = $this->makeTenantWithUser('sponsorship', 'tenant-pwd');
        $subUser = User::create([
            'name' => 'Sub User',
            'email' => 'sub@tenant-pwd.test',
            'password' => 'old-password-123',
            'tenant_id' => $tenant->id,
        ]);

        $this->bootPanel($tenant, $admin);

        Livewire::test(ManageUsers::class)
            ->callTableAction('changePassword', $subUser, [
                'password' => 'new-brand-new-password-456',
                'password_confirmation' => 'new-brand-new-password-456',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Hash::check('new-brand-new-password-456', $subUser->fresh()->password));
    }

    public function test_editing_user_updates_details_without_touching_password(): void
    {
        [$tenant, $admin] = $this->makeTenantWithUser('sponsorship', 'tenant-edit');
        $subUser = User::create([
            'name' => 'Original Name',
            'email' => 'original@tenant-edit.test',
            'password' => 'original-secure-pwd',
            'tenant_id' => $tenant->id,
        ]);

        Role::firstOrCreate(['name' => UserRoleEnum::Author->value, 'guard_name' => 'web']);

        $this->bootPanel($tenant, $admin);

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $subUser, [
                'name' => 'Renamed Collaborator',
                'email' => 'renamed@tenant-edit.test',
                'role' => UserRoleEnum::Author->value,
            ])
            ->assertHasNoTableActionErrors();

        $freshSubUser = $subUser->fresh();
        $this->assertSame('Renamed Collaborator', $freshSubUser->name);
        $this->assertSame('renamed@tenant-edit.test', $freshSubUser->email);
        $this->assertTrue(Hash::check('original-secure-pwd', $freshSubUser->password));
    }
}
