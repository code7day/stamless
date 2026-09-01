<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * Cambio de contraseña del usuario autenticado, accesible únicamente desde
 * el menú del avatar (ver `PanelCmsProvider::panel()->userMenuItems()`) —
 * a propósito no se registra en el sidebar principal.
 *
 * `password` usa el cast `'hashed'` en `App\Models\User`, así que asignar
 * el valor en texto plano alcanza: Laravel lo hashea solo al guardar, sin
 * necesidad de `Hash::make()` manual acá.
 */
class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Cambiar contraseña';

    protected ?string $subheading = 'Actualizá la contraseña de tu cuenta de Console.';

    protected string $view = 'filament.pages.change-password';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\TextInput::make('current_password')
                    ->label('Contraseña actual')
                    ->password()
                    ->revealable()
                    ->required()
                    ->currentPassword(),

                Forms\Components\TextInput::make('password')
                    ->label('Contraseña nueva')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->confirmed(),

                Forms\Components\TextInput::make('password_confirmation')
                    ->label('Confirmar contraseña nueva')
                    ->password()
                    ->revealable()
                    ->required(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('save')
                ->label('Actualizar contraseña')
                ->icon('heroicon-o-lock-closed')
                ->action(function (): void {
                    $data = $this->form->getState();

                    /** @var User $user */
                    $user = auth()->user();

                    $user->update([
                        'password' => $data['password'],
                    ]);

                    $this->form->fill();

                    Notification::make()
                        ->title('Contraseña actualizada')
                        ->success()
                        ->send();
                }),
        ];
    }
}
