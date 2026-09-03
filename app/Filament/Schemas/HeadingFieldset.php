<?php

namespace App\Filament\Schemas;

use App\Enums\HomepageNameEnum;
use App\Enums\PageTypeEnum;
use App\Models\Page;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Fieldset / Grupo reutilizable con los campos pretitle / title / subtitle / slug.
 */
final class HeadingFieldset
{
    public static function make(
        bool $required = false,
        string $label = 'Encabezado (Opcional)',
        ?Closure $afterTitleUpdated = null,
        bool $hasPretitle = true,
        bool $hasSubtitle = true,
        bool $hasSlug = false,
        bool $hasIsHome = false,
        ?string $pretitleLabel = null,
        ?string $subtitleLabel = null,
    ): Group {
        $fieldsetInputs = [];

        if ($hasPretitle) {
            $fieldsetInputs[] = TextInput::make('pretitle')
                ->label($pretitleLabel ?? 'Pre título')
                ->placeholder($pretitleLabel ?? 'Pre título')
                ->hiddenLabel()
                ->columnSpanFull()
                ->maxLength(255);
        }

        $titleInput = TextInput::make('title')
            ->label('Título')
            ->placeholder('Título')
            ->hiddenLabel()
            ->columnSpanFull()
            ->required($required)
            ->maxLength(255);

        if ($hasSlug) {
            $titleInput
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, Component $livewire, ?string $state) use ($afterTitleUpdated) {
                    $typeVal = $get('type');
                    if ($typeVal instanceof \BackedEnum) {
                        $typeVal = $typeVal->value;
                    }

                    $isNoPublicUrlType = in_array($typeVal, [
                        PageTypeEnum::Footer->value,
                        PageTypeEnum::Header->value,
                    ], true);

                    $slug = Str::slug($state ?? '');
                    if ($typeVal === PageTypeEnum::Footer->value) {
                        $slug = 'footer-'.$slug;
                    } elseif ($typeVal === PageTypeEnum::Header->value) {
                        $slug = 'header-'.$slug;
                    }

                    if ($isNoPublicUrlType || ! $get('custom_slug_active')) {
                        $recordId = null;
                        if (method_exists($livewire, 'getRecord') && $livewire->getRecord()) {
                            $recordId = $livewire->getRecord()->id;
                        } elseif (property_exists($livewire, 'record') && isset($livewire->record)) {
                            $recordId = is_object($livewire->record) ? $livewire->record->id : $livewire->record;
                        }
                        $set('slug', self::validSlug($slug, $recordId));
                    }

                    if ($afterTitleUpdated) {
                        $afterTitleUpdated($state, $set, $get);
                    }
                });
        } elseif ($afterTitleUpdated) {
            $titleInput
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Set $set, Get $get) => $afterTitleUpdated($state, $set, $get));
        }

        $fieldsetInputs[] = $titleInput;

        if ($hasSubtitle) {
            $fieldsetInputs[] = TextInput::make('subtitle')
                ->label($subtitleLabel ?? 'Subtítulo')
                ->placeholder($subtitleLabel ?? 'Subtítulo')
                ->hiddenLabel()
                ->columnSpanFull()
                ->maxLength(255);
        }

        $fieldset = Fieldset::make($label)
            ->columns(1)
            ->columnSpanFull()
            ->extraAttributes(['class' => 'title-header-groupped'])
            ->schema($fieldsetInputs);

        $groupComponents = [$fieldset];

        if ($hasSlug) {
            $groupComponents[] = Placeholder::make('url_preview')
                ->hiddenLabel()
                ->visible(function (Get $get) {
                    $typeVal = $get('type');
                    if ($typeVal instanceof \BackedEnum) {
                        $typeVal = $typeVal->value;
                    }

                    return ! in_array($typeVal, [
                        PageTypeEnum::Footer->value,
                        PageTypeEnum::Header->value,
                    ], true);
                })
                ->content(function (Get $get) {
                    $slug = $get('slug');
                    if (! empty($slug)) {
                        if (HomepageNameEnum::isHomepageKeyword($slug) || $get('is_home')) {
                            return 'URL permanente: /';
                        }

                        return 'URL permanente: /'.$slug;
                    }

                    return 'URL permanente: /';
                })
                ->extraAttributes(['class' => 'text-sm text-gray-500 dark:text-gray-400 mt-1 mb-2']);

            $groupComponents[] = Grid::make(2)
                ->schema([

                    Group::make()
                        ->schema([
                            Toggle::make('is_home')
                                ->label('Es página de inicio (Home)')
                                ->inline(false)
                                ->default(false)
                                ->live()
                                ->visible(function (Get $get) {
                                    $typeVal = $get('type');
                                    if ($typeVal instanceof \BackedEnum) {
                                        $typeVal = $typeVal->value;
                                    }

                                    return in_array($typeVal, [PageTypeEnum::Page->value, PageTypeEnum::Landing->value], true);
                                }),
                            Toggle::make('custom_slug_active')
                                ->label('Editar URL manualmente')
                                ->dehydrated(false)
                                ->live()
                                ->inline(false)
                                ->visible(function (Get $get) {
                                    $typeVal = $get('type');
                                    if ($typeVal instanceof \BackedEnum) {
                                        $typeVal = $typeVal->value;
                                    }

                                    return ! in_array($typeVal, [
                                        PageTypeEnum::Footer->value,
                                        PageTypeEnum::Header->value,
                                    ], true);
                                }),
                        ])
                        ->columns(2),

                    TextInput::make('slug')
                        ->label('URL (Slug)')
                        ->required()
                        ->unique(Page::class, 'slug', ignoreRecord: true)
                        ->visible(function (Get $get) {
                            $typeVal = $get('type');
                            if ($typeVal instanceof \BackedEnum) {
                                $typeVal = $typeVal->value;
                            }

                            return (bool) $get('custom_slug_active') && ! in_array($typeVal, [
                                PageTypeEnum::Footer->value,
                                PageTypeEnum::Header->value,
                            ], true);
                        }),

                    Hidden::make('slug')
                        ->visible(function (Get $get) {
                            $typeVal = $get('type');
                            if ($typeVal instanceof \BackedEnum) {
                                $typeVal = $typeVal->value;
                            }

                            return ! (bool) $get('custom_slug_active') || in_array($typeVal, [
                                PageTypeEnum::Footer->value,
                                PageTypeEnum::Header->value,
                            ], true);
                        }),
                ]);
        }

        return Group::make($groupComponents)
            ->columnSpanFull();
    }

    public static function validSlug(string $slug, ?int $recordId = null): string
    {
        if (empty($slug)) {
            return '';
        }

        $originalSlug = $slug;
        $count = 1;

        while (Page::where('slug', $slug)
            ->when($recordId, fn ($query) => $query->where('id', '!=', $recordId))
            ->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        return $slug;
    }
}
