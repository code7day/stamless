<?php

namespace App\Filament\Resources;

use App\Enums\ContactActivityTypeEnum;
use App\Enums\ContactStatusEnum;
use App\Filament\Resources\ContactResource\Pages;
use App\Models\Contact;
use App\Models\Tenant;
use App\Services\ContactSubmissionService;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * 2026-09-13, "Nivel 2" del plan de Dashboard (autorizado por el Tech Lead
 * a pedido explícito: "aprovechar esto como oportunidad para habilitar el
 * Contacts Resource si el momento te lo permite") — hasta ahora `Contact`/
 * `ContactActivity`/`ContactPolicy`/`ContactSubmissionService` (ver
 * ADR-015) existían solo como dominio de backend consumido por el endpoint
 * público `POST forms/{slug}/submit`; no había ninguna pantalla en Studio
 * para que el tenant vea/gestione sus propios leads. Este Resource cierra
 * ese gap sin tocar el dominio ya probado.
 *
 * Decisiones de esta vuelta:
 * - **Sin `CreateAction`** (`canCreate(): false`): un Contact nace de un
 *   envío de formulario real (`ContactSubmissionService`), no de un alta
 *   manual — igual que `Testimonial`/`Page` sí tienen alta manual porque
 *   son contenido editorial, un Contact es un registro de evento. Si en el
 *   futuro se pide "cargar un lead a mano", es una subtarea propia.
 * - `email`/`phone`/`company` se muestran DESCIFRADOS (no enmascarados vía
 *   `DataMasker`) en Studio: el cast `encrypted` de `Contact` ya descifra
 *   de forma transparente al acceder al atributo, y el propósito completo
 *   de este Resource es que el tenant pueda contactar a su propio lead —
 *   enmascarar acá anularía el valor del feature. `DataMasker` sigue
 *   siendo la herramienta correcta para previews/logs, no para esta
 *   pantalla. `#[Hidden(['email','phone','company'])]` en `Contact` solo
 *   afecta `toArray()`/`toJson()` (serialización de API), no el acceso
 *   directo a atributos que usa Filament acá — sigue intacto para la API
 *   pública.
 * - Sin `ContactActivity` como Resource propio: se gestiona inline, con
 *   una línea de tiempo de solo lectura (`Placeholder`) dentro del modal
 *   de edición + una acción "Agregar nota" que crea una entrada `note` —
 *   evita un CRUD completo para un log que nunca se edita (`ContactActivity`
 *   ni siquiera tiene `updated_at`, ver el modelo).
 */
class ContactResource extends Resource
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-stack';

    /**
     * 2026-09-13, pedido del Tech Lead: agrupar bajo un término que
     * "englobe al futuro módulo de CRM" en vez de dejarlo suelto o
     * agruparlo como "Contactos" (muy acoplado al nombre de ESTE recurso
     * puntual). Primer intento: `'CRM'` — el propio Tech Lead lo bajó un
     * escalón el mismo día ("por ahora CRM creo que lo mantenemos de forma
     * sutil porque en esta primera etapa se reirán al ver solo un listado
     * de contactos"): nombrar un grupo entero "CRM" cuando adentro hoy hay
     * un solo listado de contactos suena a más de lo que hay. `'Clientes'`
     * es el término elegido — describe lo mismo sin la etiqueta de
     * categoría de producto, y sigue englobando con naturalidad lo que se
     * sume después (pipeline de deals, actividades, reportes) sin quedar
     * como una promesa prematura. Mismo patrón de `$navigationGroup` que ya
     * usan `ApiTokens`/`ApiPlayground`/`ApiDocumentation` ("Desarrolladores")
     * y `Preferences` ("Cuenta").
     */
    protected static string|UnitEnum|null $navigationGroup = 'Clientes';

    protected static ?string $model = Contact::class;

    protected static ?string $navigationLabel = 'Contactos';

    protected static ?string $modelLabel = 'Contacto';

    protected static ?string $pluralLabel = 'Contactos';

    protected static ?string $slug = 'contacts';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Badge de nav = "nuevos/total" (2026-09-13, corrección del Tech Lead
     * viendo el widget `LeadsOverviewWidget` del Escritorio: "falta en
     * contactos el contador, Contactos (Contactos nuevos/Total de
     * contactos)") — no es un "usado/límite" como el resto de los badges
     * de la app (Contacts no tiene tope de plan), sino la MISMA idea que ya
     * usa el widget de Leads: cuánto está pendiente de atender sobre
     * cuánto hay en total. Siempre visible, incluso en "0/0" — mismo
     * criterio ya establecido acá de no ocultar el badge en cero.
     * `danger` si hay pendientes (llama la atención), `gray` si no.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::pendingCount($tenant).'/'.self::totalCount($tenant);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::pendingCount($tenant) > 0 ? 'danger' : 'gray';
    }

    private static function pendingCount(Tenant $tenant): int
    {
        return Contact::where('tenant_id', $tenant->id)
            ->where('status', ContactStatusEnum::New->value)
            ->count();
    }

    private static function totalCount(Tenant $tenant): int
    {
        return Contact::where('tenant_id', $tenant->id)->count();
    }

    /**
     * Timeline de `ContactActivity` como HTML plano (mismo patrón que
     * `PageResource::renderTypeBadge()`: armar el markup de badge a mano
     * con las clases ya compiladas de Filament vía `FilamentColor`), en vez
     * de introducir `Infolists`/`RepeatableEntry` — este proyecto no usa
     * infolists en ningún otro Resource, y un `Placeholder` de solo lectura
     * alcanza para un log que nunca se edita inline.
     */
    private static function renderActivityTimeline(Contact $record): HtmlString
    {
        $activities = $record->activities()->with('user')->get();

        if ($activities->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Sin actividad registrada todavía.</p>');
        }

        $classes = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, 'gray'));

        $items = $activities->map(function ($activity) use ($classes): string {
            $label = e($activity->type->getLabel());
            $when = e(FriendlyDate::format($activity->created_at) ?? '');
            $who = $activity->user?->name ? ' · '.e($activity->user->name) : '';
            $description = $activity->description ? '<p class="mt-1 text-sm text-gray-700 dark:text-gray-300">'.e($activity->description).'</p>' : '';

            return <<<HTML
                <li class="py-2">
                    <span class="fi-badge fi-size-sm {$classes}">{$label}</span>
                    <span class="ms-2 text-xs text-gray-500 dark:text-gray-400">{$when}{$who}</span>
                    {$description}
                </li>
                HTML;
        })->implode('');

        return new HtmlString('<ul class="divide-y divide-gray-100 dark:divide-white/10">'.$items.'</ul>');
    }

    /**
     * Renderiza las respuestas del formulario como tabla HTML limpia y legible,
     * soportando textos largos (como el mensaje/consulta) con saltos de línea
     * y sin truncamiento visual.
     */
    private static function renderFormResponses(Contact $record): HtmlString
    {
        $data = app(ContactSubmissionService::class)->decryptData($record);

        if (empty($data)) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Sin datos de formulario.</p>');
        }

        $formFields = $record->form?->fields?->keyBy('name') ?? collect();

        $rows = '';
        foreach ($data as $key => $value) {
            $fieldDefinition = $formFields->get($key);
            $fieldLabel = $fieldDefinition?->label ? e($fieldDefinition->label) : null;
            $escapedKey = e($key);

            $keyCell = $fieldLabel
                ? "<div><span class=\"font-medium text-gray-900 dark:text-gray-100 text-sm\">{$fieldLabel}</span><div class=\"font-mono text-xs text-gray-500 dark:text-gray-400 mt-0.5\">{$escapedKey}</div></div>"
                : "<span class=\"font-mono text-xs text-gray-600 dark:text-gray-300\">{$escapedKey}</span>";

            if (is_array($value)) {
                $displayValue = e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $valueContent = "<pre class=\"font-mono text-xs whitespace-pre-wrap break-words text-gray-800 dark:text-gray-200\">{$displayValue}</pre>";
            } elseif (is_bool($value)) {
                $displayValue = $value ? 'Sí' : 'No';
                $valueContent = "<span class=\"text-sm text-gray-900 dark:text-gray-100\">{$displayValue}</span>";
            } elseif ($value === null || $value === '') {
                $valueContent = '<span class="text-sm text-gray-400 italic">—</span>';
            } else {
                $displayValue = e((string) $value);
                $valueContent = "<div class=\"text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap break-words leading-relaxed\">{$displayValue}</div>";
            }

            $rows .= <<<HTML
                <tr class="transition-colors hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-3 align-top w-1/3 min-w-[140px] border-b border-gray-100 dark:border-white/5">
                        {$keyCell}
                    </td>
                    <td class="px-4 py-3 align-top border-b border-gray-100 dark:border-white/5">
                        {$valueContent}
                    </td>
                </tr>
            HTML;
        }

        return new HtmlString(<<<HTML
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900/40 shadow-xs">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/[0.03]">
                            <th class="px-4 py-2.5 text-xs font-semibold text-gray-600 uppercase tracking-wider dark:text-gray-300 w-1/3">Campo</th>
                            <th class="px-4 py-2.5 text-xs font-semibold text-gray-600 uppercase tracking-wider dark:text-gray-300">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        {$rows}
                    </tbody>
                </table>
            </div>
        HTML);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Respuestas del formulario')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Placeholder::make('form_responses_table')
                            ->label('')
                            ->content(fn (?Contact $record): HtmlString => $record ? self::renderFormResponses($record) : new HtmlString(''))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?Contact $record): bool => filled($record?->data)),

                Section::make('Datos del contacto')
                    ->collapsible()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->maxLength(255)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(255)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('company')
                            ->label('Empresa')
                            ->maxLength(255)
                            ->columnSpan(1),

                        Forms\Components\Placeholder::make('source')
                            ->label('Origen')
                            ->content(fn (?Contact $record): string => $record?->source ?? '—'),

                        Forms\Components\Placeholder::make('form.name')
                            ->label('Formulario')
                            ->content(fn (?Contact $record): string => $record?->form?->name ?? '—'),
                    ]),

                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Gestión')
                            ->collapsible()
                            ->columnSpan(1)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Estado')
                                    ->options(ContactStatusEnum::class)
                                    ->required(),

                                Forms\Components\Select::make('assigned_to')
                                    ->label('Asignado a')
                                    ->relationship('assignedTo', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('Sin asignar'),

                                Forms\Components\DateTimePicker::make('last_contacted_at')
                                    ->label('Último contacto')
                                    ->native(false),

                                Forms\Components\Textarea::make('notes')
                                    ->label('Notas internas')
                                    ->rows(3),
                            ]),

                        Section::make('Actividad')
                            ->collapsible()
                            ->columnSpan(1)
                            ->schema([
                                Forms\Components\Placeholder::make('activities_timeline')
                                    ->label('')
                                    ->content(fn (?Contact $record): HtmlString => $record ? self::renderActivityTimeline($record) : new HtmlString('')),
                            ])
                            ->visible(fn (?Contact $record): bool => $record !== null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Contact $record): ?string => $record->email)
                    ->default('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value) {
                        'new' => 'info',
                        'in_progress' => 'warning',
                        'closed' => 'success',
                        'spam' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Origen')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('form.name')
                    ->label('Formulario')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label('Asignado a')
                    ->toggleable()
                    ->placeholder('Sin asignar'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recibido')
                    ->formatStateUsing(fn (Contact $record): ?string => FriendlyDate::format($record->created_at))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(ContactStatusEnum::class),

                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Asignado a')
                    ->relationship('assignedTo', 'name'),

                Tables\Filters\SelectFilter::make('form_id')
                    ->label('Formulario')
                    ->relationship('form', 'name'),
            ])
            ->actions([
                Actions\ActionGroup::make([
                    Actions\EditAction::make()
                        ->label('Ver / gestionar')
                        ->slideOver()
                        ->modalWidth('5xl'),

                    // Nota rápida (2026-09-13): registra un `ContactActivity`
                    // tipo `note` sin necesidad de abrir el modal completo de
                    // edición — pensado para el caso más común ("lo llamé,
                    // dejo constancia") sin fricción.
                    Actions\Action::make('addNote')
                        ->label('Agregar nota')
                        ->icon('heroicon-o-pencil-square')
                        ->modalHeading('Agregar nota')
                        ->modalSubmitActionLabel('Guardar nota')
                        ->schema([
                            Forms\Components\Textarea::make('description')
                                ->label('Nota')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function (array $data, Contact $record): void {
                            $record->activities()->create([
                                'type' => ContactActivityTypeEnum::Note,
                                'description' => $data['description'],
                                'user_id' => auth()->id(),
                            ]);
                        })
                        ->successNotificationTitle('Nota agregada'),

                    Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),

                    Actions\BulkAction::make('markInProgress')
                        ->label('Marcar en proceso')
                        ->icon('heroicon-o-clock')
                        ->action(fn ($records) => $records->each->update(['status' => ContactStatusEnum::InProgress]))
                        ->deselectRecordsAfterCompletion(),

                    Actions\BulkAction::make('markClosed')
                        ->label('Marcar cerrado')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) => $records->each->update(['status' => ContactStatusEnum::Closed]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Todavía no llegó ningún contacto')
            ->emptyStateDescription('Acá van a aparecer los leads que dejen sus datos en los formularios del sitio.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageContacts::route('/'),
        ];
    }
}
