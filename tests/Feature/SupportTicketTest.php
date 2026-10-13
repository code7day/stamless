<?php

namespace Tests\Feature;

use App\Enums\SupportTicketCategoryEnum;
use App\Enums\SupportTicketPriorityEnum;
use App\Enums\SupportTicketStatusEnum;
use App\Filament\Widgets\PlanStatusWidget;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_can_create_support_ticket(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo',
            'plan' => 'sponsorship',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $ticket = SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subject' => 'Consulta sobre ampliación',
            'category' => SupportTicketCategoryEnum::PlanUpgrade,
            'priority' => SupportTicketPriorityEnum::High,
            'status' => SupportTicketStatusEnum::Open,
            'source' => 'widget_plan',
            'message' => 'Deseamos solicitar más cupo de publicaciones.',
        ]);

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'tenant_id' => $tenant->id,
            'subject' => 'Consulta sobre ampliación',
            'source' => 'widget_plan',
        ]);

        $this->assertStringStartsWith('ST-', $ticket->ticket_number);
    }

    public function test_support_ticket_messages_thread_and_replies(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Corp',
            'slug' => 'acme',
            'plan' => 'pro',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $staffUser = User::factory()->create([
            'is_super_admin' => true,
        ]);

        $ticket = SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subject' => 'Reporte de sugerencia',
            'category' => SupportTicketCategoryEnum::Improvement,
            'priority' => SupportTicketPriorityEnum::Medium,
            'status' => SupportTicketStatusEnum::Open,
            'source' => 'studio_inbox',
            'message' => 'Sería genial tener modo oscuro en el front.',
        ]);

        // Staff reply
        $reply = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $staffUser->id,
            'is_staff_reply' => true,
            'message' => '¡Hola! Tomamos nota de tu sugerencia y la evaluaremos.',
        ]);

        $ticket->update(['status' => SupportTicketStatusEnum::Answered]);

        $this->assertCount(1, $ticket->messages);
        $this->assertTrue($ticket->messages->first()->is_staff_reply);
        $this->assertEquals(SupportTicketStatusEnum::Answered, $ticket->fresh()->status);
    }

    public function test_tenant_isolation_scopes_tickets_correctly(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'plan' => 'free']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'plan' => 'pro']);

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        SupportTicket::create([
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'subject' => 'Ticket A',
            'category' => SupportTicketCategoryEnum::Inquiry,
            'priority' => SupportTicketPriorityEnum::Medium,
            'status' => SupportTicketStatusEnum::Open,
            'message' => 'Mensaje A',
        ]);

        SupportTicket::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'subject' => 'Ticket B',
            'category' => SupportTicketCategoryEnum::Inquiry,
            'priority' => SupportTicketPriorityEnum::Medium,
            'status' => SupportTicketStatusEnum::Open,
            'message' => 'Mensaje B',
        ]);

        $this->actingAs($userA);
        $this->assertEquals(1, SupportTicket::where('tenant_id', $tenantA->id)->count());
        $this->assertEquals(2, SupportTicket::withoutGlobalScopes()->count());
    }

    public function test_sponsorship_plan_label_is_auspicio(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'plan' => 'sponsorship']);

        $this->assertEquals(__('Sponsorship'), $tenant->planLabel());
    }

    public function test_support_ticket_auto_assigns_authenticated_user_id(): void
    {
        $tenant = Tenant::create(['name' => 'Test Corp', 'slug' => 'test-corp', 'plan' => 'pro']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user);

        $ticket = SupportTicket::create([
            'tenant_id' => $tenant->id,
            'subject' => 'Ticket sin user_id explícito',
            'category' => SupportTicketCategoryEnum::Inquiry,
            'priority' => SupportTicketPriorityEnum::Medium,
            'status' => SupportTicketStatusEnum::Open,
            'message' => 'Contenido de prueba',
        ]);

        $this->assertEquals($user->id, $ticket->user_id);
    }

    public function test_plan_status_widget_consult_action_creates_ticket(): void
    {
        $tenant = Tenant::create(['name' => 'Widget Corp', 'slug' => 'widget-corp', 'plan' => 'sponsorship']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(PlanStatusWidget::class)
            ->mountAction('consult')
            ->set('mountedActions.0.data', [
                'subject' => 'Consulta desde el widget modal',
                'message' => 'Necesitamos aumentar cupo de imágenes.',
            ])
            ->callMountedAction();

        $this->assertDatabaseHas('support_tickets', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subject' => 'Consulta desde el widget modal',
            'source' => 'widget_plan',
        ]);
    }
}
