<?php

namespace App\Filament\Resources;

use App\Enums\FormFieldTypeEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Resources\FormResource\Pages;
use App\Models\Form;
use App\Models\FormFieldDefinition;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Fase 1 del plan "formularios por tenant" (2026-09-18, pedido del Tech
 * Lead: "el usuario debería poder crear el formulario a manera de build,
 * indicando de la lista de inventario de campos que campo va usar para su
 * formulario"). Hasta ahora `Form`/`FormField` existían como dominio 100%
 * de backend (sembrados por `Cliente0ContentSeeder::upsertContactForm()`,
 * consumidos por `forms/{slug}` y el Select de `content.form_id` del
 * bloque `contact_form` en `PageResource`), sin NINGUNA pantalla en Studio
 * para verlos o editarlos — de ahí la confusión reportada ("no sé dónde se
 * crea el nombre de formulario principal... que no lo encuentro donde
 * cambiar en el admin"): el registro existe en la base, pero no había UI.
 *
 * UX del builder: cada campo del Repeater se agrega opcionalmente eligiendo
 * una `FormFieldDefinition` del catálogo global (autocompleta
 * label/type/name/is_required/is_encrypted, todo editable después) o queda
 * en blanco para un campo 100% a medida de este form/tenant — mismo
 * criterio "catálogo → override por instancia" que ya usa el resto del
 * esquema (ver `docs/context/DECISIONS.md`).
 *
 * Fuera de alcance de esta fase (ver plan): el front (cica360) todavía no
 * lee `Form`/`FormField` en runtime (`ContactFormBlock.astro` sigue
 * ignorando `content.form_id`) — eso es Fase 2, deliberadamente NO
 * incluida acá.
 */
class FormResource extends Resource
{
    use FormatsUsageBadge;

    protected static ?string $model = Form::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-envelope-open';

    // Mismo grupo que `ContactResource` ("Clientes"): un Form es, en la
    // práctica, la fuente de los Contacts — tiene sentido que vivan juntos
    // en el sidebar en vez de mezclado con el contenido editorial (Páginas/
    // Publicaciones/Servicios).
    protected static string|UnitEnum|null $navigationGroup = 'Clientes';

    protected static ?string $navigationLabel = 'Formularios';

    protected static ?string $pluralLabel = 'Formularios';

    protected static ?string $modelLabel = 'Formulario';

    protected static ?string $slug = 'forms';

    /**
     * Límite de formularios por plan (ver `Tenant::maxForms()`). Mismo
     * patrón que el resto de recursos con tope (`isSliderLimitReached()`,
     * etc.).
     */
    public static function isFormLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxForms();

        if ($limit === null) {
            return false;
        }

        return Form::where('tenant_id', $tenant->id)->count() >= $limit;
    }

    public static function formLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxForms() : null;

        return "El plan actual permite hasta {$limit} formularios. Para crear uno nuevo, eliminar primero alguno existente.";
    }

    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::formatUsageBadge(Form::where('tenant_id', $tenant->id)->count(), $tenant->maxForms());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::usageBadgeColor(Form::where('tenant_id', $tenant->id)->count(), $tenant->maxForms());
    }

    /**
     * Catálogo global cacheado en request (no cambia entre campos del mismo
     * Repeater, evita re-consultar por cada item al construir el Select).
     *
     * @return array<int, string>
     */
    private static function fieldDefinitionOptions(): array
    {
        return FormFieldDefinition::query()
            ->orderBy('sort_order')
            ->pluck('label', 'id')
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre del formulario')
                            ->helperText('Uso interno (Studio/API) — no se muestra al público.')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, ?string $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state ?? '')) : null),

                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->helperText('Identifica el formulario en la API pública: forms/{slug}.')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(
                                model: Form::class,
                                column: 'slug',
                                ignoreRecord: true,
                                modifyQueryUsing: fn ($query) => $query->where('tenant_id', Filament::getTenant()?->id ?? auth()->user()?->tenant_id),
                            ),

                        Forms\Components\Hidden::make('lang_iso')
                            ->default('es'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción (Opcional)')
                            ->helperText('Nota interna para identificar este formulario entre varios — no se muestra al público.')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Activo')
                            ->helperText('Un formulario inactivo rechaza envíos nuevos (API responde error) aunque siga visible en el sitio.')
                            ->default(true)
                            ->required(),
                    ]),

                Section::make('Notificaciones y respuesta')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('notification_email')
                            ->label('Email de notificación')
                            ->helperText('A dónde avisar cuando llega un envío nuevo.')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('notification_subject')
                            ->label('Asunto del email')
                            ->maxLength(255),

                        Forms\Components\Toggle::make('send_copy_to_submitter')
                            ->label('Enviar copia a quien completa el formulario')
                            ->default(false)
                            ->required(),

                        Forms\Components\TextInput::make('redirect_url')
                            ->label('URL de redirección (Opcional)')
                            ->helperText('Si se define, el visitante es redirigido acá tras enviar en vez de ver el mensaje de éxito.')
                            ->url()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('success_message')
                            ->label('Mensaje de éxito')
                            ->helperText('Lo que ve el visitante tras enviar (si no hay URL de redirección).')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Seguridad')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('enable_honeypot')
                            ->label('Campo honeypot anti-bot')
                            ->helperText('Recomendado: rechaza envíos automatizados sin fricción para personas reales.')
                            ->default(true)
                            ->required(),

                        Forms\Components\Toggle::make('enable_recaptcha')
                            ->label('reCAPTCHA')
                            ->helperText('Requiere tener configuradas las claves de reCAPTCHA del tenant.')
                            ->default(false)
                            ->required(),
                    ]),

                // Fase 1 del plan (ver docblock de la clase): el builder en
                // sí. Cada campo puede nacer de una `FormFieldDefinition`
                // del catálogo (autocompleta y sigue siendo editable) o
                // quedar en blanco para un campo a medida — sin abrir un
                // CRUD propio del catálogo en esta fase (decisión del Tech
                // Lead: "fijo por ahora").
                Forms\Components\Repeater::make('fields')
                    ->relationship('fields')
                    ->orderColumn('sort_order')
                    ->label('Campos del formulario')
                    ->addActionLabel('Agregar campo')
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? 'Nuevo campo')
                    ->collapsible()
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('field_definition_id')
                            ->label('Campo del catálogo')
                            ->placeholder('— Campo personalizado —')
                            ->options(fn (): array => self::fieldDefinitionOptions())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! $state) {
                                    return;
                                }

                                $definition = FormFieldDefinition::find($state);

                                if (! $definition) {
                                    return;
                                }

                                $set('label', $definition->label);
                                $set('name', $definition->key);
                                $set('type', $definition->type->value);
                                $set('is_required', $definition->default_required);
                                $set('is_encrypted', $definition->default_encrypted);
                            })
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Etiqueta')
                                    ->helperText('Lo que ve el visitante.')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                        if (filled($get('name'))) {
                                            return;
                                        }

                                        $set('name', Str::snake(Str::ascii($state ?? '')));
                                    }),

                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre técnico')
                                    ->helperText('Identificador para la API (minúsculas, sin espacios).')
                                    ->required()
                                    ->maxLength(255)
                                    ->distinct()
                                    ->validationMessages(['distinct' => 'El nombre técnico debe ser único dentro del formulario.']),

                                Forms\Components\Select::make('type')
                                    ->label('Tipo')
                                    ->required()
                                    ->options(FormFieldTypeEnum::class)
                                    ->default(FormFieldTypeEnum::Text->value)
                                    ->live(),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('placeholder')
                                    ->label('Placeholder (Opcional)')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('help_text')
                                    ->label('Texto de ayuda (Opcional)')
                                    ->helperText('Se muestra debajo del campo.')
                                    ->maxLength(255),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('is_required')
                                    ->label('Obligatorio')
                                    ->default(false)
                                    ->required(),

                                Forms\Components\Toggle::make('is_encrypted')
                                    ->label('Cifrar valor guardado')
                                    ->helperText('Recomendado para teléfono, email y otros datos sensibles.')
                                    ->default(false)
                                    ->required(),
                            ]),

                        // Solo aplica a campos con opciones fijas — mismo
                        // formato `{value, label}` que ya siembra
                        // `Cliente0ContentSeeder::upsertContactForm()` para
                        // país/área de interés.
                        Forms\Components\Repeater::make('options')
                            ->label('Opciones')
                            ->addActionLabel('Agregar opción')
                            ->defaultItems(0)
                            ->visible(fn (Get $get): bool => in_array($get('type'), [FormFieldTypeEnum::Select->value, FormFieldTypeEnum::Radio->value], true))
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('value')
                                            ->label('Valor')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('label')
                                            ->label('Etiqueta visible')
                                            ->required()
                                            ->maxLength(255),
                                    ]),
                            ]),

                        // Avanzado (2026-09-18): reglas de validación extra
                        // más allá de lo que ya cubre `ContactSubmissionService
                        // ::rulesForField()` por tipo — mismo mecanismo que
                        // usa CICA360 hoy (`$nameRules`/`$cityRules`/etc. en
                        // el seeder), ahora editable desde Studio sin tocar
                        // código. `->simple()` guarda un array plano de
                        // strings (`jsonb` de `validation_rules`), no de
                        // objetos — evita el problema de un `TagsInput` con
                        // separador por coma partiendo regex que traen
                        // comas (ej. la regla de "Consulta" de CICA360).
                        Forms\Components\Repeater::make('validation_rules')
                            ->label('Reglas de validación adicionales (Avanzado)')
                            ->helperText('Reglas de Laravel Validator, una por línea (ej. min:3, max:40, regex:/.../). Se suman a la validación base por tipo de campo.')
                            ->addActionLabel('Agregar regla')
                            ->defaultItems(0)
                            ->simple(
                                Forms\Components\TextInput::make('rule')
                                    ->required()
                                    ->maxLength(255)
                            ),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Campo activo')
                            ->default(true)
                            ->required(),
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
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Form $record): string => $record->slug),

                Tables\Columns\TextColumn::make('fields_count')
                    ->label('Campos')
                    ->counts('fields')
                    ->badge(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->action(
                        Actions\Action::make('toggleIsActive')
                            ->action(fn (Form $record) => $record->update(['is_active' => ! $record->is_active]))
                    )
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->actions([
                Actions\ActionGroup::make([
                    Actions\EditAction::make()
                        ->slideOver()
                        ->modalWidth('4xl'),

                    Actions\DeleteAction::make()
                        ->modalDescription('Se eliminarán también todos los campos de este formulario. Los Contacts ya recibidos NO se borran.'),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay formularios cargados')
            ->emptyStateDescription('Creá un formulario y elegí sus campos del catálogo para usarlo en el bloque "Formulario de contacto" de cualquier página.')
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->disabled(fn (): bool => self::isFormLimitReached())
                    ->tooltip(fn (): ?string => self::isFormLimitReached() ? self::formLimitMessage() : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isFormLimitReached()) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::formLimitMessage())->send();
                        $action->halt();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageForms::route('/'),
        ];
    }
}
