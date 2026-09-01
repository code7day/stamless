<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo global (no tenant-aware) de tipos de campo reutilizables
     * (email, teléfono, empresa, ...). `forms`/`form_fields` los consumen
     * como plantilla opcional vía `field_definition_id`.
     */
    public function up(): void
    {
        Schema::create('form_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('key')->unique();
            $table->string('label');
            $table->string('type');
            $table->boolean('is_system')->default(true);
            $table->boolean('default_required')->default(false);
            $table->boolean('default_encrypted')->default(false);
            $table->jsonb('options')->nullable();
            $table->jsonb('validation_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_field_definitions');
    }
};
