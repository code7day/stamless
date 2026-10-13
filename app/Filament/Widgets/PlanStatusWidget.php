<?php

namespace App\Filament\Widgets;

use App\Enums\SupportTicketCategoryEnum;
use App\Enums\SupportTicketPriorityEnum;
use App\Enums\SupportTicketStatusEnum;
use App\Models\SupportTicket;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class PlanStatusWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.cms.widgets.plan-status-widget';

    /**
     * Columna 1 (comparte la primera fila con `WelcomeWidget`, también columna 1).
     */
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = -40;

    public function getTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * Devuelve el badge y color correspondiente a cada nivel de plan real.
     *
     * @return array{label: string, color: string}
     */
    public function getPlanBadge(): array
    {
        $plan = $this->getTenant()?->planEnum();

        return [
            'label' => $plan?->getBadgeLabel() ?? __('Active'),
            'color' => $plan?->getColor() ?? 'gray',
        ];
    }

    /**
     * Descripción honesta y específica según las capacidades del plan activo.
     */
    public function getPlanDescription(): string
    {
        return $this->getTenant()?->planEnum()?->getDescription() ?? __('Active platform plan.');
    }

    /**
     * Determina si el plan actual puede ser mejorado.
     */
    public function canUpgrade(): bool
    {
        return $this->getTenant()?->planEnum()?->canUpgrade() ?? true;
    }

    /**
     * Acción interactiva para abrir el modal de consulta directamente en el Dashboard.
     */
    public function consultAction(): Action
    {
        $tenant = $this->getTenant();
        $planLabel = $tenant?->planLabel() ?? 'Plan actual';
        $buttonLabel = $tenant?->planEnum()?->getActionButtonLabel() ?? __('Consult');

        return Action::make('consult')
            ->label($buttonLabel)
            ->icon('heroicon-m-arrow-trending-up')
            ->color('primary')
            ->size('sm')
            ->modalHeading("Consulta sobre ampliación de plan ({$planLabel})")
            ->modalDescription('Cuéntanos qué necesitas ampliar (cupos, almacenamiento, módulos adicionales o upgrade) y el equipo de soporte te responderá directamente.')
            ->modalSubmitActionLabel('Enviar Consulta')
            ->modalWidth('lg')
            ->form([
                TextInput::make('subject')
                    ->label('Asunto')
                    ->default("Consulta sobre ampliación de plan ({$planLabel})")
                    ->required()
                    ->maxLength(255),

                Textarea::make('message')
                    ->label('Detalle de tu solicitud o inquietud')
                    ->placeholder('Describe brevemente lo que tu empresa o proyecto necesita...')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000),

                FileUpload::make('attachments')
                    ->label('Capturas o adjuntos (Opcional)')
                    ->multiple()
                    ->disk('public')
                    ->directory('support-attachments')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize(5120)
                    ->helperText('PNG, JPG, WebP o PDF (máx. 5MB).'),
            ])
            ->action(function (array $data): void {
                $tenant = $this->getTenant();

                SupportTicket::create([
                    'tenant_id' => $tenant?->id,
                    'user_id' => Auth::id(),
                    'subject' => $data['subject'],
                    'category' => SupportTicketCategoryEnum::PlanUpgrade,
                    'priority' => SupportTicketPriorityEnum::Medium,
                    'status' => SupportTicketStatusEnum::Open,
                    'source' => 'widget_plan',
                    'source_url' => request()->fullUrl(),
                    'message' => $data['message'],
                    'attachments' => $data['attachments'] ?? null,
                ]);

                Notification::make()
                    ->title('Consulta enviada con éxito')
                    ->body('Hemos recibido tu solicitud. Podrás darle seguimiento desde "Consultas y Feedback".')
                    ->success()
                    ->send();
            });
    }
}
