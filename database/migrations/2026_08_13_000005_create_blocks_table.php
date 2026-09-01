<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Los blocks pertenecen exclusivamente a `pages` (no tienen columna
     * `meta`: el SEO vive únicamente en la página contenedora).
     */
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('lang_iso', 5)->default('es');

            $table->string('type');
            $table->string('pretitle')->nullable();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->jsonb('content');

            $table->jsonb('links')->nullable();
            $table->jsonb('properties')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            $table->index(['page_id', 'sort_order']);
            $table->index(['tenant_id', 'lang_iso']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
