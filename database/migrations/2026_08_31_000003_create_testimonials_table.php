<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo propio de testimonios/casos de éxito (2026-08-31, ADR
     * pendiente en DECISIONS.md): antes vivían como un `Repeater` inline
     * dentro de `content.items` del bloque `testimonials` — el Tech Lead
     * pidió una tabla real gestionable desde su propio recurso Filament,
     * con el bloque reducido a encabezado + filtro (cuántos mostrar, en
     * qué orden) + link opcional. `is_visible` (no `visibility`, mismo
     * nombre que ya usa `blocks.is_visible`) para ocultar un testimonio
     * de la API pública sin borrarlo.
     */
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('role')->nullable();
            $table->text('quote');
            $table->foreignId('avatar_id')->nullable()->constrained('media')->nullOnDelete();

            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'is_visible', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
