<?php

namespace App\Filament\Resources;

use App\Enums\PublishStatusEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Resources\PostResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Post;
use App\Models\Tenant;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Schmeits\FilamentCharacterCounter\Forms\Components\Textarea as CharacterTextarea;
use Schmeits\FilamentCharacterCounter\Forms\Components\TextInput as CharacterTextInput;

class PostResource extends Resource
{
    use FormatsUsageBadge;

    protected static ?string $model = Post::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-duplicate';

    // 2026-09-13, pedido del Tech Lead: en el menú principal "Blog" es más
    // reconocible para el usuario final que "Publicaciones" (jerga de CMS)
    // — además ya coincide con la URL pública real (`/blog/{slug}`, ver
    // `->description()` en `table()` más abajo), así que esto en realidad
    // ALINEA el label de navegación con la información que ya se muestra.
    // Alcance acotado a lo pedido ("del menu principal"): `$pluralLabel`/
    // `$modelLabel` quedan igual ("Publicaciones"/"Publicación") porque
    // esos alimentan botones/breadcrumbs internos ("Crear Publicación",
    // "Editar Publicación") que no se pidió tocar.
    protected static ?string $navigationLabel = 'Blog';

    protected static ?string $pluralLabel = 'Publicaciones';

    protected static ?string $modelLabel = 'Publicación';

    protected static ?string $slug = 'posts';

    /**
     * Límite de publicaciones activas por plan (2026-09-11, pedido del Tech
     * Lead: "para el free 10 publicaciones activas y para auspicio 20
     * publicaciones") — mismo patrón que
     * `PageResource::isContentLimitReached()`/`contentLimitMessage()`. Acá
     * no hay distinción por "tipo" (a diferencia de `Page`): un solo
     * contador total de `Post` del tenant contra `Tenant::maxPosts()`.
     */
    public static function isPostLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxPosts();

        if ($limit === null) {
            return false;
        }

        return Post::where('tenant_id', $tenant->id)->count() >= $limit;
    }

    /**
     * 2026-09-13, pedido del Tech Lead: "duplicar... facilitar a los
     * usuarios o clientes que puedan replicar paginas o publicaciones o
     * servicios para editarlos con sus properties definidos y asi heredar
     * lo configurado anteriormente" — slug único para el duplicado
     * ("-copia", "-copia-2"... si ya existe). `Post::where(...)` ya queda
     * scopeado al tenant actual por el global scope de `HasTenant`, así que
     * solo hace falta filtrar por `lang_iso` acá.
     */
    private static function duplicateSlug(Post $record): string
    {
        $base = $record->slug.'-copia';
        $candidate = $base;
        $suffix = 2;

        while (Post::where('tenant_id', $record->tenant_id)->where('slug', $candidate)->where('lang_iso', $record->lang_iso)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    public static function postLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxPosts() : null;

        return "El plan actual permite hasta {$limit} publicaciones activas. Para crear una nueva, eliminar primero alguna existente.";
    }

    /**
     * 2026-09-13, pedido del Tech Lead: badge "usado/límite" en la opción
     * de menú del sidebar (ver `FormatsUsageBadge`) — mismo conteo/límite
     * que `isPostLimitReached()`, sin el `>=` booleano.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::formatUsageBadge(Post::where('tenant_id', $tenant->id)->count(), $tenant->maxPosts());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::usageBadgeColor(Post::where('tenant_id', $tenant->id)->count(), $tenant->maxPosts());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Post Details')
                    ->tabs([
                        Tabs\Tab::make('Contenido')
                            ->schema([
                                HeadingFieldset::make(
                                    required: true,
                                    hasSlug: true,
                                    modelClass: Post::class,
                                ),

                                CharacterTextarea::make('excerpt')
                                    ->label('Extracto / Resumen')
                                    ->columnSpanFull()
                                    ->maxLength(500)
                                    ->characterLimit(300),

                                Forms\Components\RichEditor::make('content')
                                    ->label('Contenido')
                                    ->required()
                                    ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('Configuración e Imagen')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Forms\Components\Select::make('status')
                                            ->label('Estado')
                                            ->required()
                                            ->options(PublishStatusEnum::class)
                                            ->default(PublishStatusEnum::Draft->value),

                                        Forms\Components\DateTimePicker::make('published_at')
                                            ->label('Fecha de publicación')
                                            ->nullable(),

                                        Forms\Components\Hidden::make('lang_iso')
                                            ->default('es'),
                                    ]),

                                Section::make('Imágenes del Post')
                                    ->description('Sube o selecciona imágenes responsivas para el artículo')
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                MediaUpload::make('featured_image_id', 'Imagen Desktop'),
                                                MediaUpload::make('meta.featured_image_tablet_id', 'Imagen Tablet'),
                                                MediaUpload::make('meta.featured_image_mobile_id', 'Imagen Móvil'),
                                            ]),
                                    ]),
                            ]),

                        Tabs\Tab::make('SEO / Enlaces')
                            ->schema([
                                Section::make('Metadata SEO')
                                    ->description('Título, palabras clave y descripción que Google muestra en los resultados de búsqueda.')
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                CharacterTextInput::make('meta.seo_title')
                                                    ->label('Título SEO')
                                                    ->maxLength(255)
                                                    ->characterLimit(60),

                                                Forms\Components\TextInput::make('meta.seo_keywords')
                                                    ->label('Palabras Clave (Separadas por comas)')
                                                    ->maxLength(255),

                                                CharacterTextarea::make('meta.seo_description')
                                                    ->label('Descripción SEO')
                                                    ->maxLength(500)
                                                    ->characterLimit(160)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),

                                Section::make('Open Graph (Redes Sociales)')
                                    ->description('Título, descripción e imágenes con las que se ve la publicación al compartirla en redes sociales o chats.')
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                CharacterTextInput::make('meta.og_title')
                                                    ->label('Título OG')
                                                    ->maxLength(255)
                                                    ->characterLimit(60),

                                                CharacterTextarea::make('meta.og_description')
                                                    ->label('Descripción OG')
                                                    ->maxLength(500)
                                                    ->characterLimit(160)
                                                    ->columnSpanFull(),

                                                MediaUpload::make('meta.og_image_rect_id', 'Imagen OG Rectangular (1200x630)'),
                                                MediaUpload::make('meta.og_image_square_id', 'Imagen OG Cuadrada (600x600)'),
                                            ]),
                                    ]),

                                Section::make('Enlaces relacionados')
                                    ->description('Botones o enlaces adicionales asociados a esta publicación.')
                                    ->collapsed()
                                    ->schema([
                                        LinkSchema::make('links', 'Enlaces'),
                                    ]),

                                Section::make('Propiedades del post')
                                    ->description('Color de fondo (sólido o degradado) y color de texto de la publicación.')
                                    ->collapsed()
                                    ->schema([
                                        // 2026-09-13, pedido del Tech Lead: "recuerda todas las
                                        // properties a dos columnas o 3" — esta Section se había
                                        // quedado sin el `->columns(2)` que sí tiene el resto de
                                        // los `PropertiesSchema::make()` del proyecto (ver
                                        // `PageResource.php`, mismo patrón en cada bloque con
                                        // "Personalización de estilos").
                                        PropertiesSchema::make(['background_type', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color'])
                                            ->columns(2),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 2026-09-13, pedido del Tech Lead: miniatura circular como
                // primera columna — mismo patrón exacto ya usado en
                // `ServiceResource`/`TestimonialResource` (`ImageColumn`
                // sobre la relación BelongsTo a `Media`, `->disk()` dinámico
                // según el disco real del archivo, no asumido "public").
                Tables\Columns\ImageColumn::make('featuredImage.path')
                    ->label('')
                    ->disk(fn (Post $record) => $record->featuredImage?->disk?->value ?? 'public')
                    ->circular(),

                // Título+slug fusionados en 1 columna, 2 filas (2026-08-31,
                // pedido del Tech Lead) — mismo patrón title/subtitle ya
                // usado en ServiceResource/TestimonialResource. Primer
                // intento lo armó como link real (`->url()` a la URL
                // pública completa vía `Tenant::publicUrl()`) — el Tech
                // Lead se lo pensó de nuevo y prefirió texto plano, sin
                // hipervínculo: "URL: /blog/[slug]". `Tenant::publicUrl()`/
                // `primaryDomain()` quedan igual en el modelo (sin uso acá
                // por ahora) por si hace falta un link real en otro lugar.
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable(['title', 'slug'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Post $record): string => 'URL: /blog/'.$record->slug),

                // 2026-09-13 (ADR-059, addendum): "lo mismo en
                // publicaciones" — mismo patrón `description()` aplicado en
                // `PageResource::table()`. La columna suelta `published_at`
                // ("Fecha pub.") se saca por quedar duplicada.
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value) {
                        'published' => 'success',
                        'draft' => 'warning',
                        'scheduled' => 'info',
                        'archived' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Post $record): ?string => FriendlyDate::format($record->published_at))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PublishStatusEnum::class),

            ])
            ->actions([
                // 2026-09-13 (ADR-059, addendum): acciones de fila agrupadas
                // en un menú desplegable (mismo patrón que `ApiTokens::
                // table()`).
                Actions\ActionGroup::make([
                    Actions\EditAction::make()
                        ->slideOver(),
                    // 2026-09-13, pedido del Tech Lead: "duplicar... para
                    // editarlos con sus properties definidos y asi heredar
                    // lo configurado anteriormente" — clona el registro
                    // completo (excerpt/content/meta/links/properties/
                    // featured_image_id, etc., todo lo que ya no está en
                    // `excludeAttributes()`) como Borrador nuevo, para que
                    // el usuario edite a partir de una base ya armada en
                    // vez de empezar de cero. `uuid` se excluye para que
                    // `HasUuid` genere uno nuevo (si se copiara, dos filas
                    // compartirían el mismo UUID público); `slug` porque el
                    // original ya lo tiene tomado (columna única);
                    // `status`/`published_at` para que el duplicado nazca
                    // como borrador sin fecha de publicación, no como una
                    // copia ya "publicada" en paralelo al original.
                    Actions\ReplicateAction::make()
                        ->label('Duplicar')
                        // 2026-09-13 (ADR-061, addendum UX): mismo criterio
                        // que PageResource — modal de confirmación con
                        // copy propio en vez del genérico "Replicar :label"
                        // de Filament, consistente con el label "Duplicar".
                        ->modalHeading(fn (Post $record): string => "¿Duplicar \"{$record->title}\"?")
                        ->modalDescription('Se creará una copia con el mismo contenido, guardada como borrador para que puedas editarla antes de publicarla.')
                        ->modalSubmitActionLabel('Sí, duplicar')
                        ->modalFooterActionsAlignment('center')
                        ->excludeAttributes(['uuid', 'slug', 'status', 'published_at'])
                        ->beforeReplicaSaved(function (Post $record, Post $replica): void {
                            $replica->title = "{$record->title} (copia)";
                            $replica->slug = self::duplicateSlug($record);
                            $replica->status = PublishStatusEnum::Draft;
                            $replica->published_at = null;
                        })
                        // Mismo límite de plan que crear una publicación
                        // nueva (`Tenant::maxPosts()`) — duplicar no debe
                        // ser una forma de esquivarlo.
                        ->disabled(fn (): bool => self::isPostLimitReached())
                        ->tooltip(fn (): ?string => self::isPostLimitReached() ? self::postLimitMessage() : null)
                        ->before(function (Actions\ReplicateAction $action) {
                            if (! self::isPostLimitReached()) {
                                return;
                            }

                            Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::postLimitMessage())->send();
                            $action->halt();
                        })
                        ->successNotificationTitle('Publicación duplicada'),
                    Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->disabled(fn (): bool => self::isPostLimitReached())
                    ->tooltip(fn (): ?string => self::isPostLimitReached() ? self::postLimitMessage() : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isPostLimitReached()) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::postLimitMessage())->send();
                        $action->halt();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePosts::route('/'),
        ];
    }
}
