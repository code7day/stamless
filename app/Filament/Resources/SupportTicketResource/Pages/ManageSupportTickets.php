<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Enums\SupportTicketCategoryEnum;
use App\Enums\SupportTicketPriorityEnum;
use App\Filament\Resources\SupportTicketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupportTickets extends ManageRecords
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva Consulta / Feedback')
                ->icon('heroicon-m-plus')
                ->modalHeading('Crear Nueva Consulta o Sugerencia')
                ->modalSubmitActionLabel('Enviar Consulta')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['user_id'] = auth()->id();

                    return $data;
                })
                ->fillForm(function (): array {
                    $category = request()->query('category');
                    $subject = request()->query('subject');
                    $source = request()->query('source', 'studio_inbox');

                    return [
                        'category' => $category ?? SupportTicketCategoryEnum::Inquiry->value,
                        'subject' => $subject ?? '',
                        'priority' => SupportTicketPriorityEnum::Medium->value,
                        'source' => $source,
                    ];
                }),
        ];
    }
}
