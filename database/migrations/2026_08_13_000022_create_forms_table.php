<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Las llaves de reCAPTCHA viven en `settings` del tenant, no en esta
     * tabla (`enable_recaptcha` solo activa/desactiva el chequeo).
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('lang_iso', 5)->default('es');

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            $table->string('notification_email')->nullable();
            $table->string('notification_subject')->nullable();
            $table->boolean('send_copy_to_submitter')->default(false);
            $table->text('success_message')->nullable();
            $table->string('redirect_url')->nullable();
            $table->boolean('is_active')->default(true);

            $table->boolean('enable_honeypot')->default(true);
            $table->boolean('enable_recaptcha')->default(false);

            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lang_iso', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
