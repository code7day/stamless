<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\FriendlyDate;
use BackedEnum;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

/**
 * Gestión de API Tokens (Sanctum) del tenant actual — ver ADR-018.
 *
 * El texto plano del token solo existe en memoria en el instante en que se
 * crea (`NewAccessToken::plainTextToken`); Sanctum solo persiste el hash
 * en `personal_access_tokens.token`. Por eso se guarda en la propiedad
 * pública de Livewire `$plainTextToken`, que vive solo durante esa
 * interacción y se muestra en un banner en la vista — nunca se puede
 * recuperar después (ni siquiera este mismo agente/admin puede volver a
 * verlo).
 */
class ApiTokens extends Page implements HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Desarrolladores';

    protected static ?string $navigationLabel = 'API Tokens';

    protected static ?string $title = 'API Tokens';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.api-tokens';

    /**
     * Abilities disponibles del MVP (ver routes/api.php / ADR-018).
     */
    public const array ABILITIES = [
        'content:read' => 'Leer contenido (content:read)',
        'forms:submit' => 'Enviar formularios (forms:submit)',
    ];

    /**
     * Ventanas de expiración ofrecidas al crear un token. `never` = sin
     * expiración (`expires_at = null`), el comportamiento de siempre.
     */
    public const array EXPIRATION_OPTIONS = [
        'never' => 'Nunca (recomendado solo para uso interno de confianza)',
        '1' => '1 día (tokens de prueba)',
        '30' => '30 días',
        '90' => '90 días',
        '365' => '1 año',
    ];

    public ?string $plainTextToken = null;

    public function clearPlainTextToken(): void
    {
        $this->plainTextToken = null;
    }

    /**
     * Sanctum rechaza automáticamente (401) cualquier token cuyo
     * `expires_at` ya pasó — no hace falta lógica extra de enforcement acá,
     * solo setear la columna al crear el token.
     */
    public static function resolveExpiration(string $option): ?\DateTimeInterface
    {
        return $option === 'never' ? null : now()->addDays((int) $option);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createToken')
                ->label('Crear token')
                ->icon('heroicon-o-plus')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->placeholder('frontend-produccion')
                        ->helperText('Un nombre descriptivo para identificar dónde se usa este token.')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\CheckboxList::make('abilities')
                        ->label('Abilities')
                        ->options(self::ABILITIES)
                        ->default(['content:read'])
                        ->helperText('Qué puede hacer este token contra la API v1.')
                        ->required(),

                    Forms\Components\Select::make('expiration')
                        ->label('Expiración')
                        ->options(self::EXPIRATION_OPTIONS)
                        ->default('never')
                        ->helperText('Después de esta fecha el token deja de funcionar automáticamente (401), sin necesidad de revocarlo a mano.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();

                    $newToken = $user->createToken(
                        $data['name'],
                        $data['abilities'],
                        self::resolveExpiration($data['expiration']),
                    );

                    // forceFill(), no update(): `last_four` no está en el
                    // $fillable de Laravel\Sanctum\PersonalAccessToken (es
                    // un campo nuestro, agregado en una migración aparte),
                    // así que un ->update() normal lo descarta en silencio
                    // por protección de mass-assignment y la columna queda
                    // NULL para siempre.
                    $newToken->accessToken->forceFill([
                        'last_four' => substr($newToken->plainTextToken, -4),
                    ])->save();

                    $this->plainTextToken = $newToken->plainTextToken;

                    Notification::make()
                        ->title('Token creado')
                        ->success()
                        ->body('Copiá el token ahora: no se va a volver a mostrar.')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),

                Tables\Columns\TextColumn::make('abilities')
                    ->label('Abilities')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode(', ', $state) : (string) $state),

                Tables\Columns\TextColumn::make('last_four')
                    ->label('Token')
                    ->placeholder('—')
                    ->formatStateUsing(fn (string $state): string => '••••••••'.$state),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Último uso')
                    ->placeholder('Nunca usado')
                    ->formatStateUsing(fn (mixed $state): ?string => FriendlyDate::format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expira')
                    ->placeholder('Nunca')
                    ->formatStateUsing(fn (mixed $state): ?string => FriendlyDate::format($state))
                    ->color(fn (mixed $state): string => ($state && Carbon::parse($state)->isPast()) ? 'danger' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->formatStateUsing(fn (mixed $state): ?string => FriendlyDate::format($state))
                    ->sortable(),
            ])
            ->actions([
                Actions\Action::make('regenerate')
                    ->label('Regenerar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Regenerar token')
                    ->modalDescription('La clave anterior dejará de funcionar de inmediato.')
                    ->modalSubmitActionLabel('Regenerar')
                    ->form(function (PersonalAccessToken $record): array {
                        // Si el token ya había expirado, no tiene sentido
                        // clonar su `expires_at` (quedaría vencido de
                        // nuevo al instante) — se le pide al admin que
                        // elija una nueva ventana, mismo selector que al
                        // crear.
                        if (! $record->expires_at?->isPast()) {
                            return [];
                        }

                        return [
                            Forms\Components\Select::make('expiration')
                                ->label('Nueva expiración')
                                ->options(self::EXPIRATION_OPTIONS)
                                ->default('never')
                                ->required()
                                ->helperText('El token anterior ya había expirado — elegí una nueva expiración para el token regenerado.'),
                        ];
                    })
                    ->action(function (PersonalAccessToken $record, array $data): void {
                        // Scoping estricto: la tabla ya filtra por tenant
                        // (`getTableQuery()`), pero un tenant puede tener
                        // más de un user — nadie regenera el token de otro
                        // user, aunque compartan tenant.
                        abort_unless(
                            $record->tokenable_type === User::class && $record->tokenable_id === auth()->id(),
                            403,
                        );

                        /** @var User $user */
                        $user = auth()->user();

                        $name = $record->name;
                        $abilities = $record->abilities ?? ['*'];

                        $wasExpired = $record->expires_at?->isPast() ?? false;

                        $expiresAt = $wasExpired
                            ? self::resolveExpiration($data['expiration'] ?? 'never')
                            : $record->expires_at;

                        // Se borra el token viejo ANTES de crear el nuevo:
                        // si algo falla al crear, preferimos que el admin
                        // se quede sin token (recuperable creando uno a
                        // mano) antes que con dos tokens simultáneos
                        // válidos para el mismo propósito.
                        $record->delete();

                        $newToken = $user->createToken($name, $abilities, $expiresAt);

                        // forceFill(), no update(): ver el mismo comentario
                        // en `createToken` header action de arriba.
                        $newToken->accessToken->forceFill([
                            'last_four' => substr($newToken->plainTextToken, -4),
                        ])->save();

                        $this->plainTextToken = $newToken->plainTextToken;

                        Notification::make()
                            ->title('Token regenerado')
                            ->success()
                            ->body('Copiá el nuevo token ahora: no se va a volver a mostrar.')
                            ->send();
                    }),

                Actions\Action::make('revoke')
                    ->label('Revocar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('El token dejará de funcionar inmediatamente. Esta acción no se puede deshacer.')
                    ->action(fn (PersonalAccessToken $record) => $record->delete()),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Todavía no creaste ningún token')
            ->emptyStateDescription('Creá un token para que tu frontend pueda consumir la API v1 de forma autenticada.')
            ->emptyStateIcon('heroicon-o-key');
    }

    /**
     * Solo tokens de users que pertenecen al tenant actual — nunca tokens
     * de otros tenants, aunque compartan la misma base de datos.
     */
    protected function getTableQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::query()->where('tenant_id', $tenant->id)->pluck('id'));
    }
}
