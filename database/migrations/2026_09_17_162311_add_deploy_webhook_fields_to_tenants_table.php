<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fase 6 (post-MVP) del front headless llega antes de tiempo (2026-09-17,
     * pedido explícito del Tech Lead tras confirmar en vivo que un cambio de
     * contenido en Studio no se reflejaba en la website de producción de
     * CICA360 — el sitio es SSG, ver `ARCHITECTURE.md` §6 de cica360). Ver
     * ADR nuevo en `DECISIONS.md` (genesis) + ADR-006 (cica360).
     *
     * `deploy_repo`/`deploy_token` son POR TENANT (no un único valor global
     * en `.env`) a propósito: cada tenant headless tiene su propio repo
     * frontend/hosting (ver ARCHITECTURE.md §5, "multi-tenant no aplica" del
     * lado del front — cada front es de un solo tenant), así que el disparo
     * del rebuild también tiene que serlo. `null` en cualquiera de los 2
     * desactiva el mecanismo para ese tenant sin tocar código — así es
     * seguro dejar esto vacío para todos los tenants que no tengan (todavía)
     * un pipeline de CI propio conectado.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Formato "owner/repo" (ej. "eduflores/cica360") — el mismo que
            // espera la API de GitHub para `POST /repos/{owner}/{repo}/dispatches`.
            $table->string('deploy_repo')->nullable()->after('slug_changes_allowed');

            // Personal Access Token de GitHub con permiso `repo` (o, más
            // acotado, `contents:read` + `actions:write` si es un Fine-grained
            // PAT) — SOLO sirve para disparar el `repository_dispatch` del
            // workflow de deploy del front, nunca se expone en ninguna
            // respuesta de la API pública. Cifrado at-rest con el cast
            // `encrypted` de Laravel (ver `Tenant::$casts`), mismo criterio
            // que `Contact::email/phone/company` (ver DECISIONS.md de este
            // proyecto sobre datos sensibles).
            $table->text('deploy_token')->nullable()->after('deploy_repo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['deploy_repo', 'deploy_token']);
        });
    }
};
