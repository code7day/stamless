<?php

namespace App\Filament\Platform\Resources;

use App\Enums\SupportTicketCategoryEnum;
use App\Enums\SupportTicketPriorityEnum;
use App\Enums\SupportTicketStatusEnum;
use App\Filament\Platform\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Support\FriendlyDate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Atención al cliente';

    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationLabel = 'Bandeja de Consultas';

    protected static ?string $modelLabel = 'Consulta / Ticket';

    protected static ?string $pluralLabel = 'Bandeja de Consultas';

    protected static ?string $slug = 'support-tickets';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // En Platform se ven TODOS los tickets de todos los tenants sin el scope HasTenant de Studio
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['tenant', 'user']);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = SupportTicket::withoutGlobalScopes()
            ->whereIn('status', [SupportTicketStatusEnum::Open->value, SupportTicketStatusEnum::InProgress->value])
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('Nº Ticket')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->label('Consulta / Requerimiento')
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
                                    <span class="fi-badge fi-size-sm {$categoryClasses}">{$categoryLabel}</span>
                                    <span class="fi-badge fi-size-sm {$priorityClasses}">{$priorityLabel}</span>
                                </div>
                            </div>
                        HTML);
                    }),

                TextColumn::make('user.name')
                    ->label('Usuario')
                    ->searchable(['name', 'email'])
                    ->html()
                    ->formatStateUsing(function (mixed $state, SupportTicket $record): HtmlString {
                        $userName = e($record->user?->name ?? 'Desconocido');
                        $tenantName = e($record->tenant?->name ?? 'Sin tenant');
                        $tenantBadgeClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, 'info'));

                        $sourceText = e(match ($record->source) {
                            'widget_plan' => 'desde Widget Plan',
                            'studio_inbox' => 'desde Bandeja Studio',
                            'dashboard' => 'desde Dashboard',
                            default => 'desde '.ucfirst(str_replace('_', ' ', $record->source ?? 'Studio')),
                        });

                        return new HtmlString(<<<HTML
                            <div class="py-1">
                                <div class="font-medium text-gray-950 dark:text-white text-sm">{$userName}</div>
                                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                    <span class="fi-badge fi-size-sm {$tenantBadgeClasses}">{$tenantName}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{$sourceText}</span>
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
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tenant_id')
                    ->label('Cliente / Tenant')
                    ->relationship('tenant', 'name'),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(SupportTicketStatusEnum::class),

                SelectFilter::make('priority')
                    ->label('Prioridad')
                    ->options(SupportTicketPriorityEnum::class),

                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options(SupportTicketCategoryEnum::class),

                SelectFilter::make('source')
                    ->label('Vía / Origen')
                    ->options([
                        'widget_plan' => 'Botón Consultar (Widget Plan)',
                        'studio_inbox' => 'Bandeja Studio',
                    ]),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('reply')
                        ->label('Atender / Responder')
                        ->icon('heroicon-m-chat-bubble-left-ellipsis')
                        ->color('primary')
                        ->modalHeading(fn (SupportTicket $record) => "Atención Ticket {$record->ticket_number} - {$record->tenant?->name}")
                        ->modalSubmitActionLabel('Enviar respuesta al cliente')
                        ->modalWidth('4xl')
                        ->form(fn (SupportTicket $record) => [
                            Section::make('Detalles del Requerimiento')
                                ->columnSpanFull()
                                ->schema([
                                    Placeholder::make('ticket_details')
                                        ->label('')
                                        ->content(fn () => self::renderPlatformTicketSummary($record)),
                                ]),

                            Section::make('Historial de la Conversación')
                                ->columnSpanFull()
                                ->schema([
                                    Placeholder::make('thread')
                                        ->label('')
                                        ->content(fn () => self::renderPlatformThread($record)),
                                ]),

                            Section::make('Responder al Cliente')
                                ->columnSpanFull()
                                ->schema([
                                    Grid::make(2)->schema([
                                        Select::make('new_status')
                                            ->label('Nuevo Estado')
                                            ->options(SupportTicketStatusEnum::class)
                                            ->default(SupportTicketStatusEnum::Answered->value)
                                            ->required(),

                                        Select::make('new_priority')
                                            ->label('Prioridad')
                                            ->options(SupportTicketPriorityEnum::class)
                                            ->default($record->priority->value)
                                            ->required(),
                                    ]),

                                    Textarea::make('staff_message')
                                        ->label('Mensaje de respuesta oficial')
                                        ->placeholder('Escribe la respuesta que verá el cliente en su bandeja de Studio...')
                                        ->rows(4)
                                        ->required(),

                                    FileUpload::make('staff_attachments')
                                        ->label('Archivos adjuntos (Opcional)')
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
                                'is_staff_reply' => true,
                                'message' => $data['staff_message'],
                                'attachments' => $data['staff_attachments'] ?? null,
                            ]);

                            $record->update([
                                'status' => $data['new_status'],
                                'priority' => $data['new_priority'],
                                'closed_at' => $data['new_status'] === SupportTicketStatusEnum::Closed->value ? now() : null,
                            ]);

                            Notification::make()
                                ->title('Respuesta enviada y ticket actualizado')
                                ->success()
                                ->send();
                        }),

                    Action::make('quick_status')
                        ->label('Cambiar Estado')
                        ->icon('heroicon-m-arrow-path')
                        ->color('gray')
                        ->form([
                            Select::make('status')
                                ->label('Cambiar Estado')
                                ->options(SupportTicketStatusEnum::class)
                                ->required(),
                        ])
                        ->fillForm(fn (SupportTicket $record) => ['status' => $record->status->value])
                        ->action(function (SupportTicket $record, array $data): void {
                            $record->update([
                                'status' => $data['status'],
                                'closed_at' => $data['status'] === SupportTicketStatusEnum::Closed->value ? now() : null,
                            ]);

                            Notification::make()
                                ->title('Estado actualizado')
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

    private static function renderPlatformTicketSummary(SupportTicket $ticket): HtmlString
    {
        $tenantName = e($ticket->tenant?->name ?? 'Sin tenant');
        $planLabel = e($ticket->tenant?->planLabel() ?? 'Plan no definido');
        $userName = e($ticket->user?->name ?? 'Desconocido');
        $userEmail = e($ticket->user?->email ?? '—');
        $date = e(FriendlyDate::format($ticket->created_at) ?? '');
        $source = e(match ($ticket->source) {
            'widget_plan' => 'Botón "Consultar" (Widget Plan)',
            'studio_inbox' => 'Bandeja de Consultas Studio',
            default => $ticket->source,
        });
        $sourceUrl = $ticket->source_url ? "<a href=\"{$ticket->source_url}\" target=\"_blank\" class=\"text-xs text-primary-600 underline\">{$ticket->source_url}</a>" : '—';

        $attachmentsHtml = '';
        if (! empty($ticket->attachments) && is_array($ticket->attachments)) {
            $links = [];
            foreach ($ticket->attachments as $path) {
                $url = Storage::disk('public')->url($path);
                $filename = basename($path);
                $isImg = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp'], true);
                if ($isImg) {
                    $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"inline-block p-1.5 border border-gray-200 dark:border-white/10 rounded-lg bg-white dark:bg-gray-800 hover:border-primary-500 transition-colors shadow-sm\"><img src=\"{$url}\" alt=\"{$filename}\" class=\"h-20 w-auto object-cover rounded max-w-[140px]\" /><span class=\"block text-[11px] text-gray-500 dark:text-gray-400 text-center mt-1 truncate max-w-[140px]\">{$filename}</span></a>";
                } else {
                    $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-800 text-xs font-mono text-primary-600 dark:text-primary-400 hover:underline\">📄 {$filename}</a>";
                }
            }
            $attachmentsHtml = '<div class="mt-3 pt-2.5 border-t border-gray-200/60 dark:border-white/10"><span class="text-xs font-medium text-gray-500 dark:text-gray-400 block mb-1.5">Archivos / Capturas adjuntos:</span><div class="flex flex-wrap gap-2.5">'.implode('', $links).'</div></div>';
        }

        $message = nl2br(e($ticket->message));

        return new HtmlString(<<<HTML
            <div class="space-y-3 text-sm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 p-3 bg-gray-50 dark:bg-white/5 rounded-lg border border-gray-200/50 dark:border-white/10 text-xs">
                    <div><strong>Cliente:</strong> {$tenantName} ({$planLabel})</div>
                    <div><strong>Usuario:</strong> {$userName} ({$userEmail})</div>
                    <div><strong>Vía / Origen:</strong> {$source}</div>
                    <div><strong>URL Referencia:</strong> {$sourceUrl}</div>
                    <div><strong>Fecha de Envío:</strong> {$date}</div>
                    <div><strong>Categoría:</strong> {$ticket->category->getLabel()}</div>
                </div>
                <div class="p-3 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800">
                    <span class="text-xs font-semibold text-gray-500 block mb-1">Mensaje Original del Cliente:</span>
                    <p class="text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{$message}</p>
                    {$attachmentsHtml}
                </div>
            </div>
        HTML);
    }

    private static function renderPlatformThread(SupportTicket $ticket): HtmlString
    {
        $messages = $ticket->messages()->withoutGlobalScopes()->with('user')->get();

        if ($messages->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Sin mensajes de respuesta todavía. Puedes escribir la primera respuesta abajo.</p>');
        }

        $items = $messages->map(function (SupportTicketMessage $msg): string {
            $isStaff = $msg->is_staff_reply;
            $sender = $isStaff ? 'Equipo Stamless (Tú / Staff)' : e($msg->user?->name ?? 'Cliente');
            $date = e(FriendlyDate::format($msg->created_at) ?? '');
            $message = nl2br(e($msg->message));

            $badgeColor = $isStaff ? 'primary' : 'info';
            $badgeClasses = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $badgeColor));
            $roleLabel = $isStaff ? 'Respuesta Staff' : 'Mensaje Cliente';

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
