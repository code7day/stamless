<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `email`/`phone`/`company` son `text` porque guardan el valor cifrado
     * con el cast `encrypted` de Laravel (no admiten `unique()` real: el
     * cifrado de Laravel es aleatorio por valor). `data` guarda el resto de
     * respuestas dinámicas del formulario como jsonb.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained('forms')->nullOnDelete();

            $table->string('name')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('company')->nullable();
            $table->jsonb('data');

            $table->string('source')->nullable();
            $table->string('page_url')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->string('status')->default('new');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('tags')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
