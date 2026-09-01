<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `description` queda reemplazado por el sistema de `properties` jsonb
     * (position_container, align_content, decorator_bottom, slide_background_*,
     * etc. — ver `App\Filament\Schemas\PropertiesSchema`). El contenido de
     * un slide ya cuenta con pretitle/title/subtitle; no hay necesidad de
     * un cuarto campo de texto libre.
     */
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
    }
};
