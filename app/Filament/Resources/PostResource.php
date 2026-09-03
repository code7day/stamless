<?php

namespace App\Filament\Resources;

use App\Enums\PublishStatusEnum;
use App\Filament\Resources\PostResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Post;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Publicaciones';

    protected static ?string $pluralLabel = 'Publicaciones';

    protected static ?string $modelLabel = 'Publicación';

    protected static ?string $slug = 'posts';

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
                                    hasSlug: true
                                ),

                                Forms\Components\Textarea::make('excerpt')
                                    ->label('Extracto / Resumen')
                                    ->columnSpanFull()
                                    ->maxLength(500),

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
                                                Forms\Components\TextInput::make('meta.seo_title')
                                                    ->label('Título SEO')
                                                    ->maxLength(255),

                                                Forms\Components\TextInput::make('meta.seo_keywords')
                                                    ->label('Palabras Clave (Separadas por comas)')
                                                    ->maxLength(255),

                                                Forms\Components\Textarea::make('meta.seo_description')
                                                    ->label('Descripción SEO')
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),

                                Section::make('Open Graph (Redes Sociales)')
                                    ->description('Título, descripción e imágenes con las que se ve la publicación al compartirla en redes sociales o chats.')
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Forms\Components\TextInput::make('meta.og_title')
                                                    ->label('Título OG')
                                                    ->maxLength(255),

                                                Forms\Components\Textarea::make('meta.og_description')
                                                    ->label('Descripción OG')
                                                    ->maxLength(500)
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
                                        PropertiesSchema::make(['background_type', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color']),
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
                    ->sortable(),

                // Fecha "amigable" en todos los listados (2026-08-31,
                // pedido del Tech Lead) — relativo ("hace 2 días") si es
                // reciente, `d:m:Y h:i a` si no. Ver `FriendlyDate`/ADR-021.
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Fecha pub.')
                    ->formatStateUsing(fn (mixed $state): ?string => FriendlyDate::format($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PublishStatusEnum::class),

            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePosts::route('/'),
        ];
    }
}
