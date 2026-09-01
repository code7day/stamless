<?php

namespace App\Filament\Pages;

use App\Enums\LanguageEnum;
use App\Models\User;
use BackedEnum;
use DateTimeZone;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Preferencias de visualización del usuario admin (idioma + zona horaria)
 * — ver ADR-021. Afecta cómo `App\Support\FriendlyDate` formatea TODAS
 * las fechas de Console para este usuario. No tiene nada que ver con
 * `lang_iso` del contenido del tenant (eso sigue siendo siempre `es` en
 * el MVP, ver ADR-008/LanguageEnum) — son dos cosas distintas que
 * comparten el mismo catálogo de idiomas por conveniencia.
 */
class Preferences extends Page implements HasForms
{
    use InteractsWithForms;

    /**
     * Ya no vive en el sidebar (grupo "Cuenta"): desde que se agregó al
     * menú del avatar (`PanelCmsProvider::userMenuItems()`), mostrarla en
     * ambos lados era redundante — se pidió sacarla del sidebar.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Cuenta';

    protected static ?string $navigationLabel = 'Preferencias';

    protected static ?string $title = 'Preferencias';

    protected ?string $subheading = 'Cómo querés ver fechas y textos en Console. No afecta el idioma del contenido publicado del tenant.';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.preferences';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->form->fill([
            'locale' => $user->locale,
            'timezone' => $user->timezone,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\Select::make('locale')
                    ->label('Idioma')
                    ->options(collect(LanguageEnum::cases())->mapWithKeys(
                        fn (LanguageEnum $case): array => [$case->value => $case->getLabel()],
                    ))
                    ->required(),

                Forms\Components\Select::make('timezone')
                    ->label('Zona horaria')
                    ->helperText('Todas las fechas de Console (tokens, contenido, etc.) se muestran convertidas a esta zona.')
                    ->options(collect(DateTimeZone::listIdentifiers())->mapWithKeys(
                        fn (string $tz): array => [$tz => $tz],
                    ))
                    ->searchable()
                    ->required(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('save')
                ->label('Guardar')
                ->icon('heroicon-o-check')
                ->action(function (): void {
                    $data = $this->form->getState();

                    /** @var User $user */
                    $user = auth()->user();

                    $user->update([
                        'locale' => $data['locale'],
                        'timezone' => $data['timezone'],
                    ]);

                    Notification::make()
                        ->title('Preferencias guardadas')
                        ->success()
                        ->send();
                }),
        ];
    }
}
