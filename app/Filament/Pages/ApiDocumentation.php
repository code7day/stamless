<?php

namespace App\Filament\Pages;

use App\Enums\UserRoleEnum;
use App\Filament\Concerns\RestrictsPageToRoles;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Renderiza `docs/api/v1.md` como HTML dentro de Console — ver ADR-018.
 * Fuente de verdad única: este page NO duplica contenido, solo lo
 * muestra (con un índice navegable e IDs de anclaje inyectados); cualquier
 * cambio a la documentación se hace editando el archivo del repo.
 *
 * 2026-09-18: `readMarkdown()` reemplaza el tenant slug de ejemplo del
 * archivo fuente (`cica360`, en minúsculas, usado SOLO como segmento
 * `{tenant_slug}` en URLs/curl/fetch de ejemplo) por el slug del tenant
 * ACTUAL de Console — el Tech Lead notó que la doc mostraba "cica360" en
 * todos los ejemplos de URL sin importar desde qué tenant se abriera,
 * cuando la plataforma es multi-tenant y cualquier cliente puede terminar
 * viendo esta misma página. Las menciones en MAYÚSCULAS ("CICA360", marca)
 * dentro de prosa o de valores de ejemplo en JSON (`seo_title`, `quote`,
 * etc.) NO se tocan — son contenido ilustrativo de esa cuenta puntual, no
 * parte de la ruta del API, y `str_replace` es case-sensitive por diseño
 * acá (no requiere ninguna lógica extra para distinguirlas).
 */
class ApiDocumentation extends Page
{
    use RestrictsPageToRoles;

    protected static string|UnitEnum|null $navigationGroup = 'Desarrolladores';

    protected static ?string $navigationLabel = 'API Documentation';

    protected static ?string $title = 'API Documentation';

    protected ?string $subheading = 'Autenticación, endpoints y contrato de respuesta del API v1 — fuente: docs/api/v1.md.';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.api-documentation';

    /**
     * Grupo "Desarrolladores": `Admin`/`Editor` — no `Author`. Ver
     * `RestrictsPageToRoles` y ADR-078.
     */
    public static function canAccess(): bool
    {
        return static::userHasAnyRole([
            UserRoleEnum::Admin->value,
            UserRoleEnum::Editor->value,
        ]);
    }

    public function getMarkdownHtml(): string
    {
        $raw = $this->readMarkdown();

        if ($raw === null) {
            return '<p>No se encontró <code>docs/api/v1.md</code>.</p>';
        }

        $html = Str::markdown($raw, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // El markdown fuente usa `./openapi.v1.yaml` porque tiene sentido
        // como link relativo dentro del repo (GitHub, editor, etc.). Acá
        // se renderiza anidado bajo `/{tenant}/api-documentation`, así que
        // ese relativo resuelve mal en el browser (404) — se reescribe a
        // la ruta real que sirve el archivo (`routes/web.php`).
        $html = str_replace(
            'href="./openapi.v1.yaml"',
            'href="'.route('docs.openapi-yaml').'"',
            $html,
        );

        // Inyecta id="slug" en cada H2/H3 para poder linkear (anchors +
        // scrollspy del sidebar) sin depender de una extensión extra de
        // CommonMark.
        return preg_replace_callback(
            '/<(h[23])>(.*?)<\/\1>/s',
            fn (array $match) => sprintf(
                '<%1$s id="%2$s">%3$s</%1$s>',
                $match[1],
                Str::slug(strip_tags($match[2])),
                $match[2],
            ),
            $html,
        );
    }

    /**
     * Índice navegable (H2/H3) para el sidebar, calculado a partir del
     * Markdown crudo — mismos slugs que `getMarkdownHtml()` genera.
     *
     * @return array<int, array{level: int, text: string, id: string}>
     */
    public function getTableOfContents(): array
    {
        $raw = $this->readMarkdown();

        if ($raw === null) {
            return [];
        }

        $toc = [];

        foreach (preg_split('/\R/', $raw) as $line) {
            if (preg_match('/^(#{2,3})\s+(.+)$/', trim($line), $match)) {
                $text = trim($match[2]);

                $toc[] = [
                    'level' => strlen($match[1]),
                    'text' => $text,
                    'id' => Str::slug($text),
                ];
            }
        }

        return $toc;
    }

    private function readMarkdown(): ?string
    {
        $path = base_path('docs/api/v1.md');

        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $tenantSlug = Filament::getTenant()?->slug;

        if ($tenantSlug !== null && $tenantSlug !== 'cica360') {
            $raw = str_replace('cica360', $tenantSlug, $raw);
        }

        return $raw;
    }
}
