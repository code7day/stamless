<?php

namespace App\Filament\Resources;

use App\Enums\UserRoleEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FriendlyDate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

class UserResource extends Resource
{
    use FormatsUsageBadge;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $slug = 'users';

    protected static ?int $navigationSort = 10;

    /**
     * Límite de usuarios por plan (solo usuarios activos del tenant).
     */
    public static function isUserLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxUsers();

        if ($limit === null) {
            return false;
        }

        return User::where('tenant_id', $tenant->id)->where('is_active', true)->count() >= $limit;
    }

    public static function userLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxUsers() : null;

        return "El plan actual permite hasta {$limit} usuarios activos. Para delegar acceso a más colaboradores, mejorá tu plan.";
    }

    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $activeCount = User::where('tenant_id', $tenant->id)->where('is_active', true)->count();
        $totalCount = User::where('tenant_id', $tenant->id)->count();

        return "{$activeCount}/{$totalCount}";
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $activeCount = User::where('tenant_id', $tenant->id)->where('is_active', true)->count();
        $totalCount = User::where('tenant_id', $tenant->id)->count();

        return $activeCount < $totalCount ? 'warning' : 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del colaborador')
                    ->description('Información personal, credenciales y rol asignado en este proyecto.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Nombre completo')
                                ->placeholder('Ej. María Gómez')
                                ->prefixIcon('heroicon-m-user')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('email')
                                ->label('Correo electrónico')
                                ->placeholder('maria@ejemplo.com')
                                ->prefixIcon('heroicon-m-envelope')
                                ->email()
                                ->required()
                                ->unique(User::class, 'email', ignoreRecord: true)
                                ->maxLength(255),
                        ]),

                        TextInput::make('password')
                            ->label('Contraseña de acceso')
                            ->placeholder('Mínimo 8 caracteres')
                            ->prefixIcon('heroicon-m-lock-closed')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->minLength(8)
                            ->maxLength(255)
                            ->helperText('El usuario podrá iniciar sesión inmediatamente con esta contraseña.')
                            ->columnSpanFull(),

                        Radio::make('role')
                            ->label('Rol asignado')
                            ->options(UserRoleEnum::class)
                            ->descriptions([
                                UserRoleEnum::Admin->value => 'Control total del panel Studio de este proyecto y sus configuraciones.',
                                UserRoleEnum::Editor->value => 'Puede crear, editar y publicar contenidos, blog, servicios y multimedia.',
                                UserRoleEnum::Author->value => 'Puede redactar contenidos y entradas de blog en modo borrador.',
                            ])
                            ->default(UserRoleEnum::Editor->value)
                            ->required()
                            ->formatStateUsing(function (?User $record): ?string {
                                if (! $record) {
                                    return UserRoleEnum::Editor->value;
                                }
                                $tenant = Filament::getTenant();
                                if ($tenant) {
                                    setPermissionsTeamId($tenant->id);
                                }

                                return $record->roles()->first()?->name ?? UserRoleEnum::Editor->value;
                            })
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Usuario activo')
                            ->helperText('Los usuarios inactivos no pueden acceder al panel Studio ni cuentan contra el límite del plan.')
                            ->default(true)
                            ->columnSpanFull(),

                        Toggle::make('must_change_password')
                            ->label('Obligar al usuario a cambiar su contraseña en el próximo inicio de sesión')
                            ->helperText('Se le solicitará actualizar su clave temporal de forma obligatoria apenas ingrese.')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->where('tenant_id', Filament::getTenant()?->id))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Admin' => 'success',
                        'Editor' => 'primary',
                        'Author' => 'warning',
                        default => 'gray',
                    })
                    ->state(function (User $record): string {
                        $tenant = Filament::getTenant();
                        if ($tenant) {
                            setPermissionsTeamId($tenant->id);
                        }

                        return $record->roles()->first()?->name ?? 'Admin';
                    }),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedCheckCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->action(function (User $record): void {
                        if ($record->id === auth()->id() && $record->is_active) {
                            Notification::make()
                                ->title('No podés desactivar tu propio usuario')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (! $record->is_active && self::isUserLimitReached()) {
                            Notification::make()
                                ->title('Límite del plan alcanzado')
                                ->body(self::userLimitMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()
                            ->title($record->is_active ? 'Usuario activado' : 'Usuario desactivado')
                            ->success()
                            ->send();
                    }),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->formatStateUsing(fn ($state) => FriendlyDate::format($state) ?? '—')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Estado de cuenta')
                    ->placeholder('Todos los usuarios')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),
            ])
            ->recordActions([
                Action::make('changePassword')
                    ->slideOver()
                    ->label('Cambiar contraseña')
                    ->icon('heroicon-m-key')
                    ->color('warning')
                    ->modalHeading(fn (User $record): string => "Cambiar contraseña de {$record->name}")
                    ->modalDescription('Establece una nueva contraseña de acceso para este usuario en el panel.')
                    ->modalIcon('heroicon-o-key')
                    ->modalWidth('lg')
                    ->form([
                        TextInput::make('password')
                            ->label('Nueva contraseña')
                            ->placeholder('Mínimo 8 caracteres')
                            ->prefixIcon('heroicon-m-lock-closed')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->same('password_confirmation'),

                        TextInput::make('password_confirmation')
                            ->label('Confirmar nueva contraseña')
                            ->placeholder('Repite la contraseña')
                            ->prefixIcon('heroicon-m-lock-closed')
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false),

                        Toggle::make('must_change_password')
                            ->label('Obligar al usuario a cambiar su contraseña en el próximo inicio de sesión')
                            ->helperText('Se le solicitará actualizar su clave temporal de forma obligatoria apenas ingrese.')
                            ->default(true),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->update([
                            'password' => $data['password'],
                            'must_change_password' => $data['must_change_password'] ?? false,
                        ]);

                        Notification::make()
                            ->title('Contraseña actualizada')
                            ->body("La contraseña de {$record->name} fue actualizada correctamente.")
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->slideOver()
                    ->modalHeading('Editar usuario')
                    ->modalWidth('2xl')
                    ->action(function (User $record, array $data): void {
                        $roleName = $data['role'] ?? UserRoleEnum::Editor->value;
                        unset($data['role']);

                        $record->update($data);

                        $tenant = Filament::getTenant();
                        if ($tenant) {
                            setPermissionsTeamId($tenant->id);
                            $role = Role::firstOrCreate([
                                'name' => $roleName,
                                'guard_name' => 'web',
                                'tenant_id' => $tenant->id,
                            ]);
                            $record->syncRoles([$role]);
                        }

                        Notification::make()
                            ->title('Usuario actualizado')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, User $record): void {
                        if ($record->id === auth()->id()) {
                            Notification::make()
                                ->title('No podés eliminar tu propio usuario')
                                ->danger()
                                ->send();
                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
