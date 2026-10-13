<?php

namespace App\Filament\Resources;

use App\Enums\SupportTicketCategoryEnum;
use App\Enums\SupportTicketPriorityEnum;
use App\Enums\SupportTicketStatusEnum;
use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Support\FriendlyDate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use UnitEnum;

class SupportTicketResource extends Resource
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Atención al cliente';

    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationLabel = 'Consultas y Feedback';

    protected static ?string $modelLabel = 'Consulta';

    protected static ?string $pluralLabel = 'Consultas y Feedback';

    protected static ?string $slug = 'support-tickets';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $pending = SupportTicket::where('tenant_id', $tenant->id)
            ->whereIn('status', [SupportTicketStatusEnum::Open->value, SupportTicketStatusEnum::InProgress->value])
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Detalles de la Consulta')
                    ->description('Envía tus dudas, inquietudes, reportes, sugerencias o solicitudes de plan.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('subject')
                                ->label('Asunto')
                                ->placeholder('Ej: Consulta sobre ampliación de plan o reporte de sugerencia')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(2),

                            Select::make('category')
                                ->label('Categoría')
                                ->options(SupportTicketCategoryEnum::class)
                                ->default(SupportTicketCategoryEnum::Inquiry->value)
                                ->required()
                                ->columnSpan(1),

                            Select::make('priority')
                                ->label('Prioridad')
                                ->options(SupportTicketPriorityEnum::class)
                                ->default(SupportTicketPriorityEnum::Medium->value)
                                ->required()
                                ->columnSpan(1),
                        ]),

                        Textarea::make('message')
                            ->label('Mensaje / Detalle')
                            ->placeholder('Describe detalladamente tu consulta, reporte o solicitud...')
                            ->required()
                            ->rows(5)
                            ->maxLength(3000),

                        FileUpload::make('attachments')
                            ->label('Capturas de pantalla o documentos adjuntos (Opcional)')
                            ->multiple()
                            ->disk('public')
                            ->directory('support-attachments')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(5120)
                            ->imagePreviewHeight('150')
                            ->helperText('Formatos permitidos: PNG, JPG, WebP o PDF (máx. 5MB por archivo).'),

                        Hidden::make('source')
                            ->default(fn () => request()->query('source', 'studio_inbox')),

                        Hidden::make('source_url')
                            ->default(fn () => url()->previous() ?? request()->fullUrl()),

                        Hidden::make('user_id')
                            ->default(fn () => auth()->id()),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')
                    ->label('Consulta')
                    ->searchable(['subject', 'ticket_number', 'message'])
                    ->html()
                    ->formatStateUsing(function (string $state, SupportTicket $record): HtmlString {
                        $subject = e($state);
                        $ticketNumber = e($record->ticket_number);
                        $categoryLabel = e($record->category->getLabel());
                        $categoryColor = $record->category->getColor() ?? 'gray';
                        $categoryClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $categoryColor));
                        $priorityLabel = e($record->priority->getLabel());
                        $priorityColor = $record->priority->getColor() ?? 'gray';
                        $priorityClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $priorityColor));

                        return new HtmlString(<<<HTML
                            <div class="py-1">
                                <div class="font-medium text-gray-950 dark:text-white text-sm">{$subject}</div>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <div class="inline-flex items-center gap-1">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Nº Ticket:</span>
                                        <span class="font-mono text-xs font-semibold text-gray-700 dark:text-gray-300">{$ticketNumber}</span>
                                        <button
                                            type="button"
                                            x-data="{ copied: false }"
                                            x-on:click.stop="window.navigator.clipboard.writeText('{$ticketNumber}'); copied = true; setTimeout(() => copied = false, 2000);"
                                            class="inline-flex items-center justify-center text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 transition-colors p-0.5 rounded focus:outline-none cursor-pointer"
                                            title="Copiar Nº de Ticket"
                                        >
                                            <svg x-show="!copied" class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                                            </svg>
                                            <svg x-show="copied" x-cloak class="w-3.5 h-3.5 text-success-600 dark:text-success-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                        </button>
                                    </div>
                                    <span class="text-gray-300 dark:text-gray-600">·</span>
                                    <span class="fi-badge fi-size-sm {$categoryClasses}">{$categoryLabel}</span>
                                    <span class="fi-badge fi-size-sm {$priorityClasses}">{$priorityLabel}</span>
                                </div>
                            </div>
                        HTML);
                    }),

                TextColumn::make('status')
                    ->label('Estado / Fecha')
                    ->html()
                    ->formatStateUsing(function (SupportTicketStatusEnum $state, SupportTicket $record): HtmlString {
                        $statusLabel = e($state->getLabel());
                        $statusColor = $state->getColor() ?? 'gray';
                        $statusClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $statusColor));
                        $date = e(FriendlyDate::format($record->created_at) ?? '');

                        return new HtmlString(<<<HTML
                            <div class="py-1">
                                <div><span class="fi-badge fi-size-sm {$statusClasses}">{$statusLabel}</span></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{$date}</div>
                            </div>
                        HTML);
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('status', $direction)->orderBy('created_at', $direction)),

                TextColumn::make('source')
                    ->label('Vía / Origen')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'widget_plan' => 'Widget Plan',
                        'studio_inbox' => 'Bandeja Studio',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(SupportTicketStatusEnum::class),

                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options(SupportTicketCategoryEnum::class),

                SelectFilter::make('priority')
                    ->label('Prioridad')
                    ->options(SupportTicketPriorityEnum::class),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('view_thread')
                        ->label('Ver Conversación')
                        ->icon('heroicon-m-chat-bubble-bottom-center-text')
                        ->color('primary')
                        ->modalHeading(fn (SupportTicket $record) => "Ticket {$record->ticket_number}: {$record->subject}")
                        ->modalSubmitActionLabel('Enviar respuesta')
                        ->modalWidth('4xl')
                        ->form(fn (SupportTicket $record) => [
                            Section::make('Información del Ticket')
                                ->columnSpanFull()
                                ->schema([
                                    Placeholder::make('ticket_summary')
                                        ->label('')
                                        ->content(fn () => self::renderTicketSummary($record)),
                                ]),

                            Section::make('Historial de Mensajes')
                                ->columnSpanFull()
                                ->schema([
                                    Placeholder::make('messages_thread')
                                        ->label('')
                                        ->content(fn () => self::renderMessagesThread($record)),
                                ]),

                            Section::make('Agregar Respuesta')
                                ->columnSpanFull()
                                ->schema([
                                    Textarea::make('reply_message')
                                        ->label('Tu respuesta o mensaje adicional')
                                        ->placeholder('Escribe aquí tu mensaje...')
                                        ->rows(3)
                                        ->required(),

                                    FileUpload::make('reply_attachments')
                                        ->label('Adjuntar archivos/capturas')
                                        ->multiple()
                                        ->disk('public')
                                        ->directory('support-attachments')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                        ->maxSize(5120),
                                ]),
                        ])
                        ->action(function (SupportTicket $record, array $data): void {
                            SupportTicketMessage::create([
                                'support_ticket_id' => $record->id,
                                'user_id' => auth()->id(),
                                'is_staff_reply' => false,
                                'message' => $data['reply_message'],
                                'attachments' => $data['reply_attachments'] ?? null,
                            ]);

                            if ($record->status === SupportTicketStatusEnum::Answered) {
                                $record->update(['status' => SupportTicketStatusEnum::InProgress]);
                            }

                            Notification::make()
                                ->title('Respuesta enviada correctamente')
                                ->success()
                                ->send();
                        }),

                    Action::make('close_ticket')
                        ->label('Marcar Resuelto')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (SupportTicket $record) => $record->status !== SupportTicketStatusEnum::Closed)
                        ->requiresConfirmation()
                        ->modalHeading('¿Marcar consulta como resuelta?')
                        ->modalDescription('Esto indicará que tu duda o requerimiento fue atendido satisfactoriamente.')
                        ->action(function (SupportTicket $record): void {
                            $record->update([
                                'status' => SupportTicketStatusEnum::Closed,
                                'closed_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Consulta marcada como resuelta')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSupportTickets::route('/'),
        ];
    }

    private static function renderTicketSummary(SupportTicket $ticket): HtmlString
    {
        $statusBadgeClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $ticket->status->getColor() ?? 'gray'));
        $priorityBadgeClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $ticket->priority->getColor() ?? 'gray'));

        $statusLabel = e($ticket->status->getLabel());
        $priorityLabel = e($ticket->priority->getLabel());
        $categoryLabel = e($ticket->category->getLabel());
        $date = e(FriendlyDate::format($ticket->created_at) ?? '');
        $source = e(match ($ticket->source) {
            'widget_plan' => 'Botón Consultar (Widget Plan)',
            'studio_inbox' => 'Bandeja de Consultas',
            default => $ticket->source,
        });

        $attachmentsHtml = '';
        if (! empty($ticket->attachments) && is_array($ticket->attachments)) {
            $links = [];
            foreach ($ticket->attachments as $path) {
                $url = Storage::disk('public')->url($path);
                $filename = basename($path);
                $isImg = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp'], true);
                if ($isImg) {
                    $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"inline-block p-1.5 border border-gray-200 dark:border-white/10 rounded-lg bg-white dark:bg-gray-800 hover:border-primary-500 dark:hover:border-primary-400 transition-colors shadow-sm\"><img src=\"{$url}\" alt=\"{$filename}\" class=\"h-20 w-auto object-cover rounded max-w-[140px]\" /><span class=\"block text-[11px] text-gray-500 dark:text-gray-400 text-center mt-1 truncate max-w-[140px]\">{$filename}</span></a>";
                } else {
                    $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-800 text-xs font-mono text-primary-600 dark:text-primary-400 hover:underline\">📄 {$filename}</a>";
                }
            }
            $attachmentsHtml = '<div class="mt-3 pt-2.5 border-t border-gray-200/60 dark:border-white/10"><span class="text-xs font-medium text-gray-500 dark:text-gray-400 block mb-1.5">Adjuntos iniciales:</span><div class="flex flex-wrap gap-2.5">'.implode('', $links).'</div></div>';
        }

        $message = nl2br(e($ticket->message));

        return new HtmlString(<<<HTML
            <div class="space-y-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="fi-badge fi-size-sm {$statusBadgeClasses}">{$statusLabel}</span>
                    <span class="fi-badge fi-size-sm {$priorityBadgeClasses}">Prioridad: {$priorityLabel}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">· {$categoryLabel} · {$date}</span>
                    <span class="text-xs text-gray-400 font-mono">({$source})</span>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-white/5 rounded-lg border border-gray-200/50 dark:border-white/10">
                    <p class="text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{$message}</p>
                    {$attachmentsHtml}
                </div>
            </div>
        HTML);
    }

    private static function renderMessagesThread(SupportTicket $ticket): HtmlString
    {
        $messages = $ticket->messages()->with('user')->get();

        if ($messages->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Aún no hay respuestas en esta consulta. El equipo de atención responderá a la brevedad.</p>');
        }

        $items = $messages->map(function (SupportTicketMessage $msg): string {
            $isStaff = $msg->is_staff_reply;
            $sender = $isStaff ? 'Equipo Stamless (Soporte & Atención)' : e($msg->user?->name ?? 'Usuario');
            $date = e(FriendlyDate::format($msg->created_at) ?? '');
            $message = nl2br(e($msg->message));

            $badgeColor = $isStaff ? 'primary' : 'gray';
            $badgeClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $badgeColor));
            $roleLabel = $isStaff ? 'Atención Stamless' : 'Cliente';

            $attachmentsHtml = '';
            if (! empty($msg->attachments) && is_array($msg->attachments)) {
                $links = [];
                foreach ($msg->attachments as $path) {
                    $url = Storage::disk('public')->url($path);
                    $filename = basename($path);
                    $isImg = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp'], true);
                    if ($isImg) {
                        $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"inline-block p-1.5 border border-gray-200 dark:border-white/10 rounded-lg bg-white dark:bg-gray-800 hover:border-primary-500 transition-colors shadow-sm\"><img src=\"{$url}\" alt=\"{$filename}\" class=\"h-16 w-auto object-cover rounded max-w-[120px]\" /></a>";
                    } else {
                        $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"inline-flex items-center gap-1.5 px-2 py-1 rounded border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-800 text-xs text-primary-600 dark:text-primary-400 font-mono hover:underline\">📄 {$filename}</a>";
                    }
                }
                $attachmentsHtml = '<div class="mt-2 flex flex-wrap gap-2">'.implode('', $links).'</div>';
            }

            $bgClass = $isStaff
                ? 'bg-primary-50 dark:bg-primary-950/20 border-primary-200 dark:border-primary-800/40'
                : 'bg-gray-50 dark:bg-white/5 border-gray-200/50 dark:border-white/10';

            return <<<HTML
                <div class="p-3 rounded-lg border {$bgClass} space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-sm text-gray-900 dark:text-gray-100">{$sender}</span>
                            <span class="fi-badge fi-size-sm {$badgeClasses}">{$roleLabel}</span>
                        </div>
                        <span class="text-xs text-gray-400">{$date}</span>
                    </div>
                    <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{$message}</p>
                    {$attachmentsHtml}
                </div>
            HTML;
        })->implode('');

        return new HtmlString('<div class="space-y-3">'.$items.'</div>');
    }
}
