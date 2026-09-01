<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('lang_iso', 5)->default('es');

            $table->string('pretitle')->nullable();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->text('content')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('featured_image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->jsonb('meta')->nullable();
            $table->jsonb('links')->nullable();
            $table->jsonb('properties')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lang_iso', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
