<?php

namespace App\Filament\Pages;

use App\Enums\ApiTokenPlatformEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FriendlyDate;
use BackedEnum;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\View\Components\Columns\TextColumnComponent\ItemComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
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
    use FormatsUsageBadge;
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
     * 2026-09-12, pedido del Tech Lead: "para plan free / auspiciador
     * deberia permitir un limite de tokens, para free 5 y para asupicio
     * 10" — mismo patrón que `PostResource::isPostLimitReached()` y el
     * resto de límites por plan (ver `Tenant::maxApiTokens()`).
     */
    private function isTokenLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxApiTokens();

        if ($limit === null) {
            return false;
        }

        return $this->activeTokensCount() >= $limit;
    }

    private function tokenLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxApiTokens() : null;

        return "El plan actual permite hasta {$limit} tokens activos. Para crear uno nuevo, revocar primero alguno existente (o esperar a que expire).";
    }

    /**
     * Solo tokens ACTIVOS (no expirados) cuentan contra el límite — uno
     * que ya expiró no se puede usar para nada, así que dejarlo ocupar un
     * cupo obligaría al admin a revocarlo a mano solo para poder crear uno
     * nuevo (fricción sin ningún beneficio real). `regenerate` no necesita
     * este chequeo por separado: borra el token viejo ANTES de crear el
     * nuevo, así que nunca empuja el conteo por encima del límite.
     */
    private function activeTokensCount(): int
    {
        return $this->getTableQuery()
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    /**
     * 2026-09-13, pedido del Tech Lead: badge "usado/límite" en la opción
     * de menú del sidebar (ver `FormatsUsageBadge`) — mismo conteo que
     * `activeTokensCount()`, pero replicado como método ESTÁTICO porque
     * `getNavigationBadge()` (de `Filament\Pages\Page`) es estático y no
     * tiene acceso a `$this->getTableQuery()`.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::formatUsageBadge(self::activeTokensCountForTenant($tenant), $tenant->maxApiTokens());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::usageBadgeColor(self::activeTokensCountForTenant($tenant), $tenant->maxApiTokens());
    }

    /**
     * 2026-09-13: pública (no privada) desde que `PlanUsageWidget` del
     * Dashboard también la necesita para su barra "API Tokens".
     */
    public static function activeTokensCountForTenant(Tenant $tenant): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::query()->where('tenant_id', $tenant->id)->pluck('id'))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    // 2026-09-13 (13va vuelta, addendum ADR-059): el puente
    // `triggerEditOriginAction()` (usado por `TextColumn::
    // action('triggerEditOriginAction')` en las columnas `platform`/
    // `allowed_origin`) se borró junto con esas columnas — ya no queda
    // ninguna columna clickeable que lo llame. Para editar plataforma/
    // origen de un token existente, usar la acción de fila "Editar
    // plataforma/origen" (ver `->actions([...])` en `table()`).

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
                ->disabled(fn (): bool => $this->isTokenLimitReached())
                ->tooltip(fn (): ?string => $this->isTokenLimitReached() ? $this->tokenLimitMessage() : null)
                ->before(function (Actions\Action $action) {
                    if (! $this->isTokenLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body($this->tokenLimitMessage())->send();
                    $action->halt();
                })
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

                    // 2026-09-13: Expiración y Plataforma van en la misma
                    // fila (2 columnas) — pedido explícito del Tech Lead
                    // sobre cómo se veía el form (una debajo de otra
                    // ocupando todo el ancho sin necesidad). `allowed_origin`
                    // queda FUERA del grid, a ancho completo — es el único
                    // campo con `->visible()` condicional, y dentro de un
                    // `Grid` de 2 columnas habría dejado un hueco vacío al
                    // lado cuando no se muestra.
                    Grid::make(2)->schema([
                        Forms\Components\Select::make('expiration')
                            ->label('Expiración')
                            ->options(self::EXPIRATION_OPTIONS)
                            ->default('never')
                            ->helperText('Después de esta fecha el token deja de funcionar automáticamente (401), sin necesidad de revocarlo a mano.')
                            ->required(),

                        // 2026-09-12 (ADR-059): protección de ORIGEN a nivel
                        // de token, no de tenant — "la proteccion es para
                        // stamless... por eso tambien un select si es una
                        // web o es app". Ambos campos son opcionales: dejar
                        // `platform` sin elegir = sin restricción
                        // (comportamiento de siempre, "no requeridos de
                        // lado de stamless, sera opcion de cada cliente si
                        // desea usar").
                        Forms\Components\Select::make('platform')
                            ->label('Plataforma')
                            ->options(ApiTokenPlatformEnum::class)
                            ->helperText('Opcional. "App" nunca valida origen (no hay Origin/Referer significativo en mobile). "Web" exige que el request declare el dominio de abajo.')
                            ->live()
                            ->native(false),
                    ]),

                    // 2026-09-13: `->visible()`/`->required()` comparan
                    // contra el CASE del enum (`ApiTokenPlatformEnum::Web`),
                    // NUNCA contra `->value` ('web') — bug real detectado
                    // por el Tech Lead ("solo me pide seleccionar si es WEB
                    // o api pero si eso ya lo lleno... no aparece el
                    // dominio"). Motivo: `Select::options(EnumClass::class)`
                    // registra internamente un `EnumStateCast`
                    // (`Select::getDefaultStateCasts()`), así que
                    // `$get('platform')` devuelve la INSTANCIA del enum, no
                    // el string — comparar contra `->value` (string) da
                    // `false` siempre, sin importar qué se seleccione.
                    Forms\Components\TextInput::make('allowed_origin')
                        ->label('Dominio permitido')
                        ->placeholder('tudominio.com')
                        ->helperText('Host sin esquema ni puerto (ej. "tudominio.com", no "https://tudominio.com/"). Se compara contra Origin / Referer / X-Forwarded-Host del request.')
                        ->visible(fn (Get $get): bool => $get('platform') === ApiTokenPlatformEnum::Web)
                        ->required(fn (Get $get): bool => $get('platform') === ApiTokenPlatformEnum::Web),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();

                    $newToken = $user->createToken(
                        $data['name'],
                        $data['abilities'],
                        self::resolveExpiration($data['expiration']),
                    );

                    // Mismo motivo que arriba: `$data['platform']` es una
                    // instancia de `ApiTokenPlatformEnum` (o `null`), nunca
                    // un string — se compara contra el CASE, y se extrae
                    // `->value` recién al persistir (la columna es un
                    // `string` plano).
                    $platform = $data['platform'] ?? null;

                    // forceFill(), no update(): `last_four` (y ahora
                    // `platform`/`allowed_origin`) no están en el
                    // $fillable de Laravel\Sanctum\PersonalAccessToken (son
                    // campos nuestros, agregados en migraciones aparte),
                    // así que un ->update() normal los descarta en silencio
                    // por protección de mass-assignment y quedan NULL para
                    // siempre.
                    $newToken->accessToken->forceFill([
                        'last_four' => substr($newToken->plainTextToken, -4),
                        'platform' => $platform?->value,
                        'allowed_origin' => $platform === ApiTokenPlatformEnum::Web
                            ? ($data['allowed_origin'] ?? null)
                            : null,
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
                // 2026-09-13 (11va vuelta, addendum ADR-059): "nada, no
                // sabes resolverlo aun, volvamos a table" — después de
                // varias vueltas intentando un layout de tarjetas con
                // `Layout\Stack`/`Layout\Split` (ver addenda anteriores),
                // el Tech Lead pidió volver a la tabla clásica. Se abandona
                // por completo el modo "lista de tarjetas": columnas
                // sueltas, sin ningún componente de layout — Filament
                // vuelve a renderizar un `<table>` con `<thead>` real, así
                // que cada columna usa su `->label()` normalmente (ya no
                // hace falta meter el label DENTRO del texto vía
                // `formatStateUsing`, ni depender de tooltips: el header de
                // la tabla ya lo deja claro). Plataforma se mantiene como
                // badge de color (pedido explícito, sin cambios) y Permisos
                // como badges múltiples (uno por ability).
                // 2026-09-13 (12va vuelta, addendum ADR-059): "poner una
                // segunda fila debajo de nombre conformado por: Creado +
                // Plataforma (como badge) + Dominio (dominio solo si
                // plataforma es web, si no dejará de mostrase)" — usa
                // `TextColumn::description()`, que SÍ funciona dentro de
                // una celda de tabla normal (a diferencia de
                // `Layout\Stack`/`Split`, ver addenda anteriores). Como
                // `description()` solo acepta texto o `Htmlable`, y acá
                // hace falta un badge de color REAL (no texto plano), se
                // arma el `<span>` a mano con
                // `FilamentColor::getComponentClasses(BadgeComponent::class,
                // ...)` — la MISMA utilidad que usa Filament puro y duro
                // adentro de `TextColumn::toOptimizedHtml()` para pintar
                // sus propios badges — así el resultado respeta el tema
                // claro/oscuro sin inventar clases Tailwind a mano. El
                // resultado se envuelve en `HtmlString` porque el render de
                // `description()` usa el helper `e()` de Laravel, que
                // devuelve el HTML tal cual (sin escapar) cuando el valor
                // implementa `Htmlable`.
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->description(function (PersonalAccessToken $record): HtmlString {
                        [$platformLabel, $platformColor] = match ($record->platform) {
                            ApiTokenPlatformEnum::Web->value => ['Web', 'success'],
                            ApiTokenPlatformEnum::App->value => ['App', 'info'],
                            default => ['Sin restricción', 'gray'],
                        };

                        $badgeClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $platformColor));
                        $platformBadgeHtml = '<span class="fi-badge fi-size-sm '.$badgeClasses.'">'.e($platformLabel).'</span>';

                        $creadoHtml = e(FriendlyDate::format($record->created_at) ?? '—');

                        $dominioHtml = '';

                        if ($record->platform === ApiTokenPlatformEnum::Web->value && filled($record->allowed_origin)) {
                            $dominioHtml = ' · '.e($record->allowed_origin);
                        }

                        return new HtmlString($creadoHtml.' · '.$platformBadgeHtml.$dominioHtml);
                    }),

                Tables\Columns\TextColumn::make('abilities')
                    ->label('Permisos')
                    ->badge()
                    ->color('gray'),

                // 2026-09-13 (13va vuelta, addendum ADR-059): "por que
                // sigue existiendo las columnas plataforma y dominio
                // permitido" — quedaban duplicadas con la descripción de
                // "Nombre" de arriba (12va vuelta), que ya muestra
                // Plataforma (badge) y Dominio. Se sacan las columnas
                // sueltas `platform`/`allowed_origin` de la tabla — para
                // editarlos sigue estando la acción "Editar
                // plataforma/origen" en la columna de acciones (no se
                // perdió ninguna funcionalidad, solo la columna clickeable
                // redundante).
                // 2026-09-13 (14va vuelta, addendum ADR-059): "poner la
                // fecha de expiración debajo del token" — mismo patrón que
                // la descripción de "Nombre" (12va vuelta). El `<p
                // class="fi-ta-text-description">` que Filament pone
                // alrededor de `description()` YA trae `text-sm
                // text-gray-500` (ver `text.css`), así que en el caso normal
                // (no vencido) alcanza con texto plano, sin envolver nada.
                //
                // 2026-09-13 (15va vuelta): "tiene mucho leading o altura la
                // fecha de expiracion" — la vuelta anterior envolvía el
                // texto en `<span class="fi-ta-text fi-ta-text-item
                // fi-size-sm ...">` para poder pintarlo de rojo si venció,
                // pero esas clases son las de un ITEM de lista de
                // `TextColumn` (pensadas para alinearse con badges/íconos),
                // no las de una descripción — `.fi-ta-text-item.fi-size-sm`
                // trae `leading-6` (24px de interlineado) en `text.css`, muy
                // por encima de lo que corresponde a un texto secundario
                // chico, y quedaba anidado DENTRO del `<p
                // class="fi-ta-text-description">` que ya lo estilaba bien.
                // Fix: solo se envuelve en un `<span>` cuando hace falta
                // pintar rojo (vencido), y solo con las clases de COLOR
                // (`FilamentColor::getComponentClasses(ItemComponent::class,
                // 'danger')`), sin `fi-ta-text-item`/`fi-size-sm`. Si no
                // venció, es texto plano y hereda el gris/tamaño correcto
                // del `<p>` padre sin envoltorio extra.
                Tables\Columns\TextColumn::make('last_four')
                    ->label('Token')
                    ->placeholder('—')
                    ->formatStateUsing(fn (string $state): string => '••••••••'.$state)
                    ->description(function (PersonalAccessToken $record): HtmlString {
                        $expiraText = 'Expira: '.(FriendlyDate::format($record->expires_at) ?? 'nunca');
                        $isPast = $record->expires_at && Carbon::parse($record->expires_at)->isPast();

                        if (! $isPast) {
                            return new HtmlString(e($expiraText));
                        }

                        $dangerClasses = implode(' ', FilamentColor::getComponentClasses(ItemComponent::class, 'danger'));

                        return new HtmlString('<span class="'.$dangerClasses.'">'.e($expiraText).'</span>');
                    }),

                // 2026-09-13 (13va vuelta): "la columna creado tambien" —
                // duplicada con la descripción de "Nombre" de arriba, que
                // ya muestra la fecha de creación. `->defaultSort('created_
                // at', 'desc')` sigue funcionando aunque la columna ya no
                // esté declarada acá (ordena directo contra la query, no
                // depende de que haya un `TextColumn` visible con ese
                // nombre).
                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Último acceso')
                    ->formatStateUsing(fn (mixed $state): string => FriendlyDate::format($state) ?? 'Nunca')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                // 2026-09-13 (16va vuelta, addendum ADR-059): "ahora
                // agrupar las acciones" — Regenerar/Editar plataforma-origen/
                // Revocar pasan de ser 3 enlaces sueltos (ocupando bastante
                // ancho en cada fila) a un único botón con menú desplegable
                // (`Actions\ActionGroup`), patrón estándar de Filament para
                // acciones de fila.
                Actions\ActionGroup::make([
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
                                    ->helperText('El token anterior ya había expirado — elegir una nueva expiración para el token regenerado.'),
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

                            // 2026-09-12 (ADR-059): `platform`/`allowed_origin`
                            // se preservan del token que se está regenerando —
                            // regenerar es "renovar la clave", no "reconfigurar
                            // desde cero"; si el admin quiere cambiar la
                            // plataforma/dominio de un token, revoca y crea uno
                            // nuevo con el formulario completo de arriba.
                            $platform = $record->platform;
                            $allowedOrigin = $record->allowed_origin;

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
                                'platform' => $platform,
                                'allowed_origin' => $allowedOrigin,
                            ])->save();

                            $this->plainTextToken = $newToken->plainTextToken;

                            Notification::make()
                                ->title('Token regenerado')
                                ->success()
                                ->body('Copiá el nuevo token ahora: no se va a volver a mostrar.')
                                ->send();
                        }),

                    // 2026-09-12 (ADR-059, addendum): "dominios desde donde se
                    // usara el cliente" — el form de arriba (`createToken`)
                    // solo permite SETEAR `platform`/`allowed_origin` al crear
                    // el token; hasta acá, no había forma de agregarlo o
                    // cambiarlo en un token YA existente sin revocarlo y
                    // perder el secreto (obligando a actualizar el cliente con
                    // un token nuevo). Esta acción edita solo esos dos campos
                    // — NUNCA toca `token`/`last_four` ni ningún dato del
                    // secreto — así que es segura de exponer sin el modal de
                    // confirmación "esto invalida la clave anterior" que sí
                    // tiene `regenerate`.
                    Actions\Action::make('editOrigin')
                        ->label('Editar plataforma/origen')
                        ->icon('heroicon-o-globe-alt')
                        ->color('gray')
                        ->modalHeading('Editar plataforma/origen')
                        ->modalDescription('No afecta el token en sí — solo desde dónde se acepta usarlo.')
                        ->form(function (PersonalAccessToken $record): array {
                            return [
                                Forms\Components\Select::make('platform')
                                    ->label('Plataforma')
                                    ->options(ApiTokenPlatformEnum::class)
                                    ->default($record->platform)
                                    ->helperText('Opcional. "App" nunca valida origen (no hay Origin/Referer significativo en mobile). "Web" exige que el request declare el dominio de abajo.')
                                    ->live()
                                    ->native(false),

                                // 2026-09-13: mismo bug que en `createToken` —
                                // `->visible()`/`->required()` deben comparar
                                // contra el CASE del enum, no `->value`, porque
                                // `Select::options(EnumClass::class)` cambia el
                                // estado del campo a la instancia del enum (ver
                                // nota completa en `createToken` de arriba).
                                Forms\Components\TextInput::make('allowed_origin')
                                    ->label('Dominio permitido')
                                    ->default($record->allowed_origin)
                                    ->placeholder('tudominio.com')
                                    ->helperText('Host sin esquema ni puerto (ej. "tudominio.com", no "https://tudominio.com/"). Se compara contra Origin / Referer / X-Forwarded-Host del request.')
                                    ->visible(fn (Get $get): bool => $get('platform') === ApiTokenPlatformEnum::Web)
                                    ->required(fn (Get $get): bool => $get('platform') === ApiTokenPlatformEnum::Web),
                            ];
                        })
                        ->action(function (PersonalAccessToken $record, array $data): void {
                            // Mismo scoping que `regenerate`/`revoke`: nadie
                            // edita el token de otro user, aunque compartan
                            // tenant.
                            abort_unless(
                                $record->tokenable_type === User::class && $record->tokenable_id === auth()->id(),
                                403,
                            );

                            // Mismo motivo que en `createToken`: instancia del
                            // enum (o `null`), no string.
                            $platform = $data['platform'] ?? null;

                            $record->forceFill([
                                'platform' => $platform?->value,
                                'allowed_origin' => $platform === ApiTokenPlatformEnum::Web
                                    ? ($data['allowed_origin'] ?? null)
                                    : null,
                            ])->save();

                            Notification::make()
                                ->title('Plataforma/origen actualizado')
                                ->success()
                                ->send();
                        }),

                    Actions\Action::make('revoke')
                        ->label('Revocar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('El token dejará de funcionar inmediatamente. Esta acción no se puede deshacer.')
                        ->action(function (PersonalAccessToken $record): void {
                            abort_unless(
                                $record->tokenable_type === User::class && $record->tokenable_id === auth()->id(),
                                403,
                            );

                            $record->delete();
                        }),
                ]),
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
