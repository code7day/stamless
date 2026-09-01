<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Configuración concreta de los campos de un formulario (sin
     * `tenant_id` propio: se resuelve vía `forms.tenant_id`).
     */
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignId('field_definition_id')->nullable()->constrained('form_field_definitions')->nullOnDelete();

            $table->string('label');
            $table->string('type');
            $table->string('name');
            $table->string('placeholder')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_encrypted')->default(false);
            $table->jsonb('options')->nullable();
            $table->jsonb('validation_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['form_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
