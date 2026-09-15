<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserRoleEnum;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Spatie\Permission\Models\Role;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalHeading('Crear nuevo colaborador')
                ->modalDescription('Asigna un nuevo usuario al proyecto con sus respectivas credenciales y rol.')
                ->modalIcon('heroicon-o-user-plus')
                ->modalWidth('lg')
                ->disabled(fn (): bool => UserResource::isUserLimitReached())
                ->tooltip(fn (): ?string => UserResource::isUserLimitReached() ? UserResource::userLimitMessage() : null)
                ->before(function (CreateAction $action) {
                    if (! UserResource::isUserLimitReached()) {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('Límite del plan alcanzado')
                        ->body(UserResource::userLimitMessage())
                        ->send();

                    $action->halt();
                })
                ->using(function (array $data): User {
                    $tenant = Filament::getTenant();
                    $roleName = $data['role'] ?? UserRoleEnum::Editor->value;
                    unset($data['role']);

                    $data['tenant_id'] = $tenant?->id;

                    $user = User::create($data);

                    if ($tenant) {
                        setPermissionsTeamId($tenant->id);
                        $role = Role::firstOrCreate([
                            'name' => $roleName,
                            'guard_name' => 'web',
                            'tenant_id' => $tenant->id,
                        ]);
                        $user->assignRole($role);
                    }

                    return $user;
                }),
        ];
    }
}
