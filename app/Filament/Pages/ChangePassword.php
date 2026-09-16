<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
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
                Forms\Components\Placeholder::make('security_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString('
                        <div class="rounded-lg border border-amber-200 bg-amber-50/90 p-4 text-xs text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/50 dark:text-amber-300">
                            <div class="flex items-start gap-2.5">
                                <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                <div>
                                    <span class="font-bold text-sm">Actualización obligatoria de contraseña:</span>
                                    <p class="mt-1 leading-relaxed">
                                        Has iniciado sesión con una contraseña temporal. Por motivos de seguridad, debes ingresar tu contraseña actual y definir una nueva contraseña personalizada antes de continuar.
                                    </p>
                                </div>
                            </div>
                        </div>
                    '))
                    ->visible(fn (): bool => auth()->user()?->must_change_password ?? false),

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
                ->action(function () {
                    $data = $this->form->getState();

                    /** @var User $user */
                    $user = auth()->user();
                    $hadMustChange = (bool) ($user->must_change_password ?? false);

                    $user->update([
                        'password' => $data['password'],
                        'must_change_password' => false,
                    ]);

                    $this->form->fill();

                    Notification::make()
                        ->title('Contraseña actualizada correctamente')
                        ->body('Tu cuenta ha sido asegurada con la nueva contraseña.')
                        ->success()
                        ->send();

                    if ($hadMustChange) {
                        $panel = Filament::getCurrentOrDefaultPanel();
                        $tenant = Filament::getTenant() ?? $user->tenant;
                        $redirectUrl = $panel->hasTenancy() && $tenant
                            ? $panel->getUrl($tenant)
                            : $panel->getUrl();

                        return redirect()->to($redirectUrl);
                    }
                }),
        ];
    }
}
