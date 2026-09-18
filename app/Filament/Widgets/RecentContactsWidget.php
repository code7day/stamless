<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Form;
use App\Models\Tenant;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 2026-09-13, "Nivel 2" del plan de Dashboard — pedido original del Tech
 * Lead: "que ahorre tiempo ubicar las cosas". Preview de los últimos leads
 * sin salir del Escritorio; "Ver todos" lleva a `ContactResource` completo
 * (con filtros/edición real, que este widget no duplica).
 */
class RecentContactsWidget extends TableWidget
{
    /**
     * Columna 1 (default de `Widget`, explícita acá para que quede
     * documentado): comparte fila con `PlanUsageWidget` (también columna
     * 1) en la grilla de 2 columnas del Escritorio — ver su docblock.
     */
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = -10;

    /**
     * 2026-09-18, mismo fix que `LeadsOverviewWidget` (ver su docblock
     * completo) — este widget mostraba una previsualización real de
     * `Contact` sin chequear ninguna Policy, visible a cualquier rol con
     * acceso al Dashboard. Exige acceso a Contactos Y a Formularios
     * (pedido explícito del Tech Lead) — con la matriz de 5 roles, esto
     * excluye a `Editor` de este resumen del Dashboard además de a
     * `Author`, aunque `Editor` sí conserve su acceso normal a
     * `ContactResource`.
     */
    public static function canView(): bool
    {
        if (! Filament::getTenant() instanceof Tenant) {
            return false;
        }

        $user = auth()->user();

        return $user
            && $user->can('viewAny', Contact::class)
            && $user->can('viewAny', Form::class);
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->heading('Últimos contactos')
            ->query(
                Contact::query()
                    ->when($tenant instanceof Tenant, fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->latest('created_at')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
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
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recibido')
                    ->formatStateUsing(fn (Contact $record): ?string => FriendlyDate::format($record->created_at)),
            ])
            ->headerActions([
                Actions\Action::make('viewAll')
                    ->label('Ver todos')
                    ->icon('heroicon-m-arrow-right')
                    ->url(ContactResource::getUrl()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('Todavía no llegó ningún contacto')
            ->emptyStateDescription('Acá van a aparecer los últimos leads que dejen sus datos en los formularios del sitio.');
    }
}
