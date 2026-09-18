<?php

namespace App\Filament\Resources;

use App\Enums\FormFieldTypeEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Resources\FormResource\Pages;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldDefinition;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
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
 * UX del builder (2026-09-18, 2da vuelta — ver `catalogFieldBlocks()`): cada
 * campo se agrega eligiendo un tipo del picker nativo de `Builder` (icono +
 * búsqueda, mismo componente que "Agregar bloque" en Contenidos), un `Block`
 * por `FormFieldDefinition` del catálogo (autocompleta
 * label/type/name/is_required/is_encrypted, todo editable después) más
 * "Campo personalizado" para uno 100% a medida — mismo criterio "catálogo →
 * override por instancia" que ya usa el resto del esquema (ver
 * `docs/context/DECISIONS.md`).
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
     * 2026-09-18 (fix real de producción, reporte del Tech Lead: `TypeError
     * (field.options ?? []).map is not a function` en cica360, más "el
     * campo país está activo pero en el formulario se ocultó" tras el
     * primer intento de parche desde el lado del cliente). Causa raíz —
     * MISMO bug ya documentado y resuelto para `colophon` en
     * `PageResource::isUuidKeyedArray()`/ADR-071, nunca portado acá: `$data`
     * dentro de `saveRelationshipsUsing()` de "fields" (más abajo) es el
     * estado CRUDO de cada bloque del `Builder` — cualquier Repeater ANIDADO
     * dentro de ese bloque (`options`, `validation_rules`) sigue keyeado por
     * el ID interno de Livewire de cada ítem (string tipo UUID), no por
     * posición 0..n-1, porque solo el nivel superior del `Builder` se
     * reindexaba (`array_values($state)`, ver más abajo) — los Repeaters
     * anidados dentro de `data` nunca recibían el mismo tratamiento. Un
     * array PHP con keys no-secuenciales se serializa a JSON como OBJETO,
     * no como ARRAY — así que `options`/`validation_rules` se guardaban en
     * la columna `jsonb` como `{"93f2...": {...}, "a01c...": {...}}` en vez
     * de `[{...}, {...}]`. El cliente (`ContactForm.tsx`) hacía
     * `field.options.map(...)`, que revienta sobre un objeto — de ahí el
     * `TypeError` real en producción. `array_values()` sobre cada uno antes
     * de guardar reindexa a 0..n-1, forzando la serialización como ARRAY
     * — mismo fix, mismo síntoma, ahora también acá.
     *
     * @return array<int, mixed>|null
     */
    private static function reindexRepeaterState(mixed $value): ?array
    {
        return is_array($value) ? array_values($value) : null;
    }

    /**
     * 2026-09-18 (2da vuelta, pedido del Tech Lead: "el botón agregar campo
     * que sea similar a agregar bloque de contenido, que sea un dropdown
     * con opciones con icono, más coherente y amigable... y cada campo con
     * collapsed para aprovechar altura") — reemplaza el Select "Campo del
     * catálogo" de dentro de cada ítem (1ra vuelta) por un `Block` del
     * Builder POR cada `FormFieldDefinition`, más uno "Campo personalizado":
     * el picker nativo de `Builder` (mismo componente y mismo look que
     * `PageResource::blocks`, con su propio `block-picker.blade.php` ya
     * afinado en este proyecto) YA es exactamente el dropdown buscable con
     * ícono por opción que se pidió — elegir el tipo de campo pasa a ser el
     * PRIMER paso (clic en "Agregar campo") en vez de un campo más dentro
     * de un ítem en blanco.
     *
     * @return array<int, \Filament\Forms\Components\Builder\Block>
     */
    private static function catalogFieldBlocks(): array
    {
        $icons = [
            'name' => 'heroicon-o-user',
            'email' => 'heroicon-o-envelope',
            'phone' => 'heroicon-o-phone',
            'company' => 'heroicon-o-building-office',
            'subject' => 'heroicon-o-tag',
            'city' => 'heroicon-o-map-pin',
            'country' => 'heroicon-o-globe-americas',
            'area_of_interest' => 'heroicon-o-star',
            'message' => 'heroicon-o-chat-bubble-left-right',
        ];

        $blocks = FormFieldDefinition::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (FormFieldDefinition $definition) => Builder\Block::make($definition->key)
                ->label($definition->label)
                ->icon($icons[$definition->key] ?? 'heroicon-o-squares-plus')
                ->schema(self::fieldBlockSchema($definition)))
            ->all();

        $blocks[] = Builder\Block::make('custom')
            ->label('Campo personalizado')
            ->icon('heroicon-o-plus-circle')
            ->schema(self::fieldBlockSchema(null));

        return $blocks;
    }

    /**
     * Schema compartido por TODOS los bloques de `catalogFieldBlocks()`
     * (catálogo + "Campo personalizado") — la única diferencia real entre
     * bloques son los VALORES POR DEFECTO (`$definition`, null para el
     * personalizado), no la forma de los campos editables.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private static function fieldBlockSchema(?FormFieldDefinition $definition): array
    {
        // 2026-09-18 (3ra vuelta, pedido del Tech Lead viendo el Select de
        // tipo editable en un campo de catálogo ya elegido: "si elijo
        // Nombre y Apellido... los presets deberían incluir el tipo texto
        // predeterminado, por que si no el usuario podría cometer el error
        // de seleccionar tipo email o celular u otro, no es usable... a
        // menos que sea un custom field, ahí sí amerita"). Un campo de
        // catálogo YA sabe su tipo correcto — dejarlo editable es una
        // trampa de UX, no una libertad útil. `Hidden` en vez de `Select`
        // deshabilitado: dehydrata garantizado sin el riesgo ya documentado
        // en las guías de este proyecto de que un campo `->disabled()` deje
        // de mandar su valor. Solo el bloque "Campo personalizado"
        // (`$definition === null`) mantiene el Select real.
        $isCatalogField = $definition !== null;

        // 2026-09-18 (4ta vuelta, pedido del Tech Lead viendo el layout ya
        // en Studio: "etiqueta y nombre técnico, esa fila debería ser a 2
        // columnas ya, por que al ocultarse el tipo se quedó en base a 3
        // columnas"). `Hidden` no pinta nada pero seguía contando como
        // tercer hijo del `Grid`, dejando Etiqueta/Nombre técnico angostos
        // con un tercio de ancho fantasma. Para un campo de catálogo el
        // grid pasa a 2 columnas reales y el `Hidden::make('type')` sale
        // fuera de él (no necesita layout, es un campo sin UI); solo
        // "Campo personalizado" mantiene las 3 columnas con el Select real.
        return [
            Grid::make($isCatalogField ? 2 : 3)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label('Etiqueta')
                        ->helperText('Lo que ve el visitante.')
                        ->required()
                        ->maxLength(255)
                        ->default($definition?->label)
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
                        ->default($definition?->key)
                        ->distinct()
                        ->validationMessages(['distinct' => 'El nombre técnico debe ser único dentro del formulario.']),

                    ...($isCatalogField ? [] : [
                        Forms\Components\Select::make('type')
                            ->label('Tipo')
                            ->required()
                            ->options(FormFieldTypeEnum::class)
                            ->default(FormFieldTypeEnum::Text->value)
                            ->live(),
                    ]),
                ]),

            ...($isCatalogField ? [Forms\Components\Hidden::make('type')->default($definition->type->value)] : []),

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
                        ->default($definition?->default_required ?? false)
                        ->required(),

                    Forms\Components\Toggle::make('is_encrypted')
                        ->label('Cifrar valor guardado')
                        ->helperText('Recomendado para teléfono, email y otros datos sensibles.')
                        ->default($definition?->default_encrypted ?? false)
                        ->required(),
                ]),

            // Solo aplica a campos con opciones fijas — mismo formato
            // `{value, label}` que ya siembra `Cliente0ContentSeeder::
            // upsertContactForm()` para país/área de interés.
            //
            // 2026-09-18 (pedido del Tech Lead viendo el editor con "Área de
            // interés" — 9 opciones): `->itemLabel()`/`->collapsible()->
            // collapsed()` — mismo patrón ya usado en el resto de repeaters
            // de contenido (`ServiceResource`, `LinkSchema`, `PageResource`):
            // cada ítem colapsado muestra su "Etiqueta visible" como título
            // en vez de "Opción #1"/"Opción #2" genérico, y arrancan todos
            // colapsados — con 9 opciones como en este caso, expandidas
            // todas de entrada obligaba a scrollear de más para ver el
            // conjunto.
            //
            // Corrección sobre una 1ra pasada de este mismo cambio: acá NO
            // se agrega ninguna dependencia de `is_active` — este Repeater
            // sigue visible solo según el `type` (Select/Radio), sin
            // importar si el campo está activo o no; el Tech Lead aclaró
            // que "ocultar cuando Campo activo = false" es sobre EL CAMPO EN
            // EL SITIO PÚBLICO (cica360), no sobre este editor. Eso ya está
            // resuelto del lado del API: `FormController::show()` solo
            // carga `fields` con `is_active=true`, así que un `FormField`
            // desactivado nunca llega al `GET /forms/{slug}` que consume
            // `ContactForm.tsx` — nada que cambiar acá.
            Forms\Components\Repeater::make('options')
                ->label('Opciones')
                ->addActionLabel('Agregar opción')
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => in_array($get('type'), [FormFieldTypeEnum::Select->value, FormFieldTypeEnum::Radio->value], true))
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->collapsible()
                ->collapsed()
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

            // Avanzado: reglas de validación extra más allá de lo que ya
            // cubre `ContactSubmissionService::rulesForField()` por tipo.
            // `->simple()` guarda un array plano de strings (`jsonb` de
            // `validation_rules`), no de objetos — evita el problema de un
            // `TagsInput` con separador por coma partiendo regex que traen
            // comas (ej. la regla de "Consulta" de CICA360).
            //
            // 2026-09-18 (4ta vuelta, corrección del Tech Lead sobre la
            // pasada anterior: "ojo a validates cuando son campos del
            // catálogo predeterminados, entonces debería tener presets
            // fijos... a menos que sean necesarios, pero yo creo que no
            // necesita, internamente se podría setear") — un campo de
            // catálogo NO expone este Repeater: sus reglas quedan fijas
            // (`Hidden`, precargado con `$definition->validation_rules`),
            // mismo criterio que el bloqueo de `type` de más arriba, no
            // solo un default editable. Únicamente "Campo personalizado"
            // (sin preset posible) mantiene el Repeater real.
            $isCatalogField
                ? Forms\Components\Hidden::make('validation_rules')->default($definition?->validation_rules)
                : Forms\Components\Repeater::make('validation_rules')
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
        ];
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
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Notificaciones y respuesta')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        // 2026-09-18, pedido del Tech Lead viendo la sección
                        // en Studio: "email y asunto, esa fila debería ser a
                        // 2 columnas... 1 columna [internamente] así baja
                        // email y asunto debajo" — antes vivían como 2 de
                        // los 4 hijos directos del `Section` a `columns(2)`,
                        // uno al lado del otro. Un `Grid(1)` anidado que
                        // ocupa las 2 columnas del Section los apila,
                        // dejando lugar además para el nuevo
                        // `notification_intro` en el mismo bloque vertical.
                        Grid::make(1)
                            ->columnSpanFull()
                            ->schema([
                                Forms\Components\TextInput::make('notification_email')
                                    ->label('Email de notificación')
                                    ->helperText('A dónde avisar cuando llega un envío nuevo. Admite varios, separados por coma: el primero recibe el correo, el resto va en copia (CC).')
                                    ->maxLength(1000)
                                    // 2026-09-18, bug real en producción:
                                    // `BindingResolutionException: ...
                                    // [$attribute] was unresolvable`. Un
                                    // closure puesto DIRECTO dentro de
                                    // `->rules([...])` no llega tal cual al
                                    // validador de Laravel — Filament
                                    // primero intenta EVALUARLO como uno de
                                    // sus propios closures inyectables
                                    // (`Get $get`, `$record`, etc., ver
                                    // `EvaluatesClosures`), y como
                                    // `$attribute` no es un parámetro que
                                    // sepa resolver, explota antes de
                                    // siquiera validar nada. El patrón
                                    // correcto (documentado por Filament):
                                    // el closure de AFUERA es el que
                                    // Filament evalúa (acá sin parámetros,
                                    // no hace falta `Get`), y debe
                                    // DEVOLVER el closure real de
                                    // validación `(string $attribute,
                                    // mixed $value, Closure $fail)`.
                                    ->rules([
                                        fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                            if (blank($value)) {
                                                return;
                                            }

                                            foreach (array_map('trim', explode(',', (string) $value)) as $email) {
                                                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                                    $fail("\"{$email}\" no es un email válido.");
                                                }
                                            }
                                        },
                                    ]),

                                Forms\Components\TextInput::make('notification_subject')
                                    ->label('Asunto del email')
                                    ->maxLength(255),

                                Forms\Components\Textarea::make('notification_intro')
                                    ->label('Texto introductorio del email (Opcional)')
                                    ->helperText('Se muestra arriba de los datos del envío, en el email que recibe el admin. Si se deja vacío, se usa un texto genérico.')
                                    ->rows(2)
                                    ->maxLength(1000),
                            ]),

                        Forms\Components\Toggle::make('send_copy_to_submitter')
                            ->label('Enviar copia a quien completa el formulario')
                            ->helperText('Se le manda por email el mismo contenido de la "Página de Agradecimiento" (más abajo).')
                            ->default(false)
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('redirect_url')
                            ->label('URL de redirección (Opcional)')
                            ->helperText('Si se define, el visitante es redirigido acá tras enviar en vez de ver la Página de Agradecimiento (más abajo).')
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        // `success_message` (2026-09-18, pedido del Tech
                        // Lead viendo el campo: "este campo creo que ya no
                        // va o si es necesario?") — SUPERADO por la sección
                        // "Página de Agradecimiento" de más abajo
                        // (`thank_you_description` cubre el mismo rol con
                        // más contenido: título, cuerpo enriquecido, cuadro
                        // de alerta y botón). Confirmado que
                        // `ContactForm.tsx` (cica360) nunca leyó
                        // `success_message` — solo consume el objeto
                        // `thank_you` de la respuesta del submit. Se quita
                        // del formulario de Studio para no dejar 2 campos
                        // compitiendo por el mismo propósito; la columna
                        // sigue existiendo en `forms` (dato legado de
                        // CICA360 sin romper nada) por si algún consumidor
                        // externo del API la lee — no se borra en esta
                        // pasada, sin evidencia de que haga falta. El
                        // espacio que dejó libre en esta sección ahora lo
                        // ocupa `notification_intro` (arriba).
                    ]),

                // Fase 1 del plan (ver docblock de la clase), builder de
                // campos — 2da vuelta de UX (2026-09-18, pedido del Tech
                // Lead): "Agregar campo" abre el MISMO picker con ícono y
                // búsqueda que "Agregar bloque" en Contenidos, en vez de
                // agregar un ítem en blanco con un Select adentro. Elegir
                // "Nombre"/"Email"/etc. YA agrega el campo con sus valores
                // por defecto del catálogo; "Campo personalizado" agrega uno
                // en blanco. `loadStateFromRelationshipsUsing()`/
                // `saveRelationshipsUsing()` reemplazan el manejo automático
                // de `->relationship()` (igual que `PageResource::blocks`)
                // porque acá el "tipo de bloque" elegido (la clave del
                // catálogo) no es una columna real de `FormField` — se
                // resuelve contra `field_definition_id` (null = personalizado)
                // en el momento de guardar, sin pisar la columna `type` real
                // (el tipo de INPUT del campo: texto/email/tel/...).
                // `Builder` en esta versión de Filament NO tiene método
                // `->relationship()` (a diferencia de `Repeater`) — se
                // confirmó en vivo con un `BadMethodCallException` real al
                // probar en Studio. `PageResource::blocks` (mismo
                // componente) nunca lo llama tampoco: el par
                // `loadStateFromRelationshipsUsing()`/
                // `saveRelationshipsUsing()` de abajo es INDEPENDIENTE de
                // `->relationship()` — son hooks del ciclo de vida del
                // componente que se pueden usar solos, sin él.
                Builder::make('fields')
                    ->label('Campos del formulario')
                    ->addActionLabel('Agregar campo')
                    ->blocks(self::catalogFieldBlocks())
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                    ->loadStateFromRelationshipsUsing(static function (Builder $component) {
                        $record = $component->getRecord();

                        if (! $record) {
                            return;
                        }

                        $state = $record->fields()
                            ->with('fieldDefinition')
                            ->orderBy('sort_order')
                            ->get()
                            ->map(fn (FormField $field) => [
                                'type' => $field->fieldDefinition?->key ?? 'custom',
                                'data' => [
                                    'id' => $field->id,
                                    'label' => $field->label,
                                    'name' => $field->name,
                                    'type' => $field->type?->value ?? (string) $field->type,
                                    'placeholder' => $field->placeholder,
                                    'help_text' => $field->help_text,
                                    'is_required' => $field->is_required,
                                    'is_encrypted' => $field->is_encrypted,
                                    'options' => $field->options,
                                    'validation_rules' => $field->validation_rules,
                                    'is_active' => $field->is_active,
                                ],
                            ])
                            ->toArray();

                        $component->state($state);
                    })
                    ->saveRelationshipsUsing(static function (Builder $component, $state) {
                        $record = $component->getRecord();

                        if (! $record) {
                            return;
                        }

                        // Misma red de seguridad que `PageResource::blocks`
                        // (ADR-071 addendum): un estado vacío en un form que
                        // YA tiene campos guardados es casi seguro un glitch
                        // de hidratación, no una decisión real de vaciarlo.
                        if (empty($state) && $record->fields()->exists()) {
                            Log::warning('FormResource: saveRelationshipsUsing de "fields" recibió estado vacío en un formulario que ya tiene campos — se aborta el guardado para no borrarlos por error.', [
                                'form_id' => $record->id,
                            ]);

                            return;
                        }

                        $definitionsByKey = FormFieldDefinition::query()->get()->keyBy('key');
                        $existingIds = [];

                        // `array_values()`: mismo motivo que en
                        // `PageResource::blocks` — `$state` viene keyeado
                        // por el ID interno de Livewire de cada ítem, no por
                        // posición.
                        foreach (array_values($state) as $index => $blockData) {
                            $definition = $definitionsByKey->get($blockData['type'] ?? 'custom');
                            $data = $blockData['data'] ?? [];

                            $attributes = [
                                'field_definition_id' => $definition?->id,
                                'label' => $data['label'] ?? ($definition?->label ?? 'Campo'),
                                'name' => $data['name'] ?? ($definition?->key ?? 'campo'),
                                'type' => $data['type'] ?? ($definition?->type?->value ?? FormFieldTypeEnum::Text->value),
                                'placeholder' => $data['placeholder'] ?? null,
                                'help_text' => $data['help_text'] ?? null,
                                'is_required' => $data['is_required'] ?? false,
                                'is_encrypted' => $data['is_encrypted'] ?? false,
                                'options' => self::reindexRepeaterState($data['options'] ?? null),
                                'validation_rules' => self::reindexRepeaterState($data['validation_rules'] ?? null),
                                'sort_order' => $index,
                                'is_active' => $data['is_active'] ?? true,
                            ];

                            if (! empty($data['id'])) {
                                $field = $record->fields()->find($data['id']);

                                if ($field) {
                                    $field->update($attributes);
                                    $existingIds[] = $field->id;
                                }
                            } else {
                                $field = $record->fields()->create($attributes);
                                $existingIds[] = $field->id;
                            }
                        }

                        $record->fields()->whereNotIn('id', $existingIds)->delete();
                    }),

                // 2026-09-18 (ADR-074) — TRASLADADO acá desde `Preferences`
                // (era tenant-wide, `Setting` `thank_you.*`): con varios
                // formularios por tenant, un solo mensaje de gracias
                // tenant-wide ya no alcanza. Mismos 5 campos, mismos labels/
                // helpers — solo cambia dónde viven (por `Form`, no por
                // tenant). Vacío = usa los defaults hardcodeados de
                // `FormSubmissionController::resolveThankYouData()`.
                //
                // Posición AL FINAL del schema (2026-09-18, pedido del Tech
                // Lead: "los campos de la página de agradecimiento poner al
                // final de los campos del formulario") — es lo último que
                // se configura en el flujo natural de armar un formulario
                // (primero qué se pide, después qué se muestra al terminar).
                Section::make('Página de Agradecimiento')
                    ->description('Personaliza el mensaje y contenidos que ve el visitante luego de enviar ESTE formulario.')
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('thank_you_title')
                            ->label('Título de Agradecimiento')
                            ->helperText('Puedes usar {name} como comodín para el nombre del remitente (ej: ¡Muchas gracias, {name}!).')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\RichEditor::make('thank_you_description')
                            ->label('Descripción')
                            ->helperText('Mensaje principal. Solo se permite formato en negrita (strong).')
                            ->toolbarButtons(['bold'])
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('thank_you_alert_title')
                            ->label('Título del Cuadro Informativo / Alerta')
                            ->helperText('Ej: Tiempo de respuesta estimado:')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('thank_you_alert_description')
                            ->label('Descripción del Cuadro Informativo / Alerta')
                            ->helperText('Ej: Menos de 24 horas hábiles (Lunes a Viernes de 9:00 a 18:00).')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('thank_you_button_label')
                            ->label('Texto del Botón')
                            ->helperText('Etiqueta del botón para reiniciar o volver a consultar (ej: Enviar otra consulta).')
                            ->maxLength(100)
                            ->columnSpanFull(),
                    ]),

                // 2026-09-18, pedido del Tech Lead: "pasar la sección de
                // seguridad al final del modal, después de la página de
                // agradecimientos y spanfull, ancho completo" — antes vivía
                // entre "Notificaciones y respuesta" y el builder de campos,
                // sin `columnSpanFull()` (quedaba angosta en el layout de
                // 2 columnas del schema general). Anti-bot es configuración
                // de "cómo se protege" el formulario, no algo que el
                // usuario necesite ver antes de definir campos/mensajes —
                // tiene sentido al final del flujo, ancho completo como el
                // resto de las secciones grandes de este form.
                Section::make('Seguridad')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->columnSpanFull()
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
            ->emptyStateDescription('Crear un formulario y elegir sus campos del catálogo para usarlo en el bloque "Formulario de contacto" de cualquier página.')
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
