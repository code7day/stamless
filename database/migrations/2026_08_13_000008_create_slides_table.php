<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo propio (no son `blocks`). Cinco FKs a `media` para servir
     * distintos breakpoints/formatos sin lógica de resize en request-time.
     */
    public function up(): void
    {
        Schema::create('slides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('slider_id')->constrained('sliders')->cascadeOnDelete();
            $table->string('lang_iso', 5)->default('es');

            $table->string('pretitle')->nullable();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();

            $table->string('background_type')->default('image');

            $table->foreignId('image_desktop_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('image_tablet_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('image_mobile_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('video_desktop_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('video_mobile_id')->nullable()->constrained('media')->nullOnDelete();

            $table->boolean('has_presentation_video')->default(false);
            $table->string('presentation_youtube_id')->nullable();

            $table->jsonb('links')->nullable();
            $table->jsonb('properties')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['slider_id', 'sort_order']);
            $table->index(['tenant_id', 'lang_iso']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slides');
    }
};
