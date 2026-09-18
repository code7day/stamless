<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\InvalidFieldFormatException;
use App\Exceptions\Api\MissingRequiredFieldsException;
use App\Models\Form;
use App\Services\ContactSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Endpoint público de envío de formularios. Reutiliza
 * `ContactSubmissionService` (dominio/cifrado/notificación ya resueltos en
 * ADR-015) — no duplica esa lógica acá.
 */
class FormSubmissionController extends Controller
{
    public function __construct(private readonly ContactSubmissionService $contactSubmissionService) {}

    public function store(Request $request, string $tenant_slug, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($tenant_slug);

        // 2026-09-18 (ADR-074): la resolución del `Form` sube ANTES del
        // chequeo de honeypot — la "Página de Agradecimiento" ahora vive
        // POR formulario (`Form::thank_you_*`, ya no `Setting` tenant-wide),
        // así que hasta la respuesta falsa-exitosa que ve un bot necesita
        // saber DE QUÉ formulario viene para mostrar el mensaje correcto.
        // Costo: 1 query extra en el camino de honeypot (antes no hacía
        // ninguna) — intrascendente frente al resto del request.
        $form = Form::where('tenant_id', $tenant->id)->where('slug', $slug)->where('is_active', true)->first();

        // Protección anti-bot Honeypot: si un bot completa campos trampa invisibles,
        // se responde 201 exitoso sin persistir spam ni disparar emails.
        if ($request->filled('honeypot') || $request->filled('_hp_check') || $request->filled('_gotcha')) {
            return $this->success(
                data: [
                    'uuid' => (string) Str::uuid(),
                    'thank_you' => $this->contactSubmissionService->resolveThankYouData($form),
                ],
                message: 'Formulario enviado correctamente.',
                status: 201,
            );
        }

        if (! $form) {
            return $this->error('Formulario no encontrado.', 404, ['code' => 'not_found']);
        }

        $source = $request->input('source')
            ?? $request->header('Referer')
            ?? $request->header('Origin')
            ?? ($request->header('X-Forwarded-Host') ? 'https://'.$request->header('X-Forwarded-Host') : null);

        $pageUrl = $request->input('page_url')
            ?? ($request->header('Referer') ? parse_url($request->header('Referer'), PHP_URL_PATH) : null);

        try {
            $contact = $this->contactSubmissionService->submit(
                $form,
                $request->except(['page_url', 'source', 'honeypot', '_hp_check', '_gotcha']),
                [
                    'source' => $source,
                    'page_url' => $pageUrl,
                    'ip_address' => $this->resolveClientIp($request),
                    'user_agent' => $request->userAgent(),
                    'geo_country_code' => $this->resolveOriginCountry($request),
                ],
            );
        } catch (MissingRequiredFieldsException|InvalidFieldFormatException $e) {
            // Catch específico ANTES del genérico de abajo (ambas
            // extienden `InvalidArgumentException`): acá sí tenemos
            // desglose por campo (ADR-024). `InvalidFieldFormatException`
            // sumada 2026-09-12 — mismo shape que la de campos faltantes,
            // solo cambia el motivo (formato inválido, no ausencia).
            return $this->error('Revisá los datos enviados.', 422, [
                'code' => 'validation',
                'fields' => $e->fields(),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['code' => 'validation']);
        }

        $submitterName = $request->input('name') ?? $request->input('nombre') ?? $request->input('first_name');

        return $this->success(
            data: [
                'uuid' => $contact->uuid,
                'thank_you' => $this->contactSubmissionService->resolveThankYouData($form, is_string($submitterName) ? $submitterName : null),
            ],
            message: 'Formulario enviado correctamente.',
            status: 201,
        );
    }

    /**
     * 2026-09-12 (ADR-004): CICA360 (y cualquier otro tenant que use el
     * mismo patrón de "proxy PHP server-side" en vez de pegarle directo al
     * API desde el navegador — ver ADR-002 de ese repo) hace esta llamada
     * server-to-server, así que `$request->ip()` sin más vería SIEMPRE la
     * IP saliente del hosting del proxy, nunca la del visitante real. Este
     * proyecto no tiene configurado `TrustProxies` (ningún proxy/CDN
     * intermedio de confianza declarado), así que `$request->ip()` no lee
     * `X-Forwarded-For` por su cuenta. Se prioriza el header
     * explícitamente cuando viene y contiene una IP válida — es un dato
     * puramente informativo (`Contact::ip_address` no se usa para
     * rate-limiting ni ninguna decisión de seguridad, el throttle real de
     * este endpoint es independiente y ya corre a nivel de middleware), así
     * que confiar en un header que en teoría cualquier cliente podría
     * setear no abre ninguna puerta nueva — en el peor caso, alguien falsea
     * qué IP queda guardada en su propio registro de contacto, no distinto
     * del riesgo ya aceptado de un `User-Agent` falso.
     */
    private function resolveClientIp(Request $request): ?string
    {
        $forwardedFor = $request->header('X-Forwarded-For');

        if ($forwardedFor) {
            $firstIp = trim(explode(',', $forwardedFor)[0]);

            if (filter_var($firstIp, FILTER_VALIDATE_IP) !== false) {
                return $firstIp;
            }
        }

        return $request->ip();
    }

    /**
     * 2026-09-12: "cabe la posibilidad abierta de que el cliente cambie de
     * pais en el formulario pero siempre el api debe recibir el IP y
     * country_code de origen, por favor asegurarse eso". El `country` que
     * el visitante puede elegir a mano en el `<select>` del formulario (si
     * el `Form` define ese campo) es un dato de NEGOCIO — el país que dice
     * tener — completamente aparte de este `geo_country_code`, que es la
     * geolocalización DERIVADA de la IP de origen y nunca cambia por nada
     * que el visitante haga en el formulario.
     *
     * Header `X-Origin-Country` — opcional, mismo criterio que
     * `X-Forwarded-For` en `resolveClientIp()`: CICA360 lo resuelve
     * server-side en cada submit (`contacto.php`, ver ADR-004 de ese repo)
     * a partir de la IP real, ANTES de reenviar acá — nunca a partir de
     * nada que el navegador le haya mandado. Se valida que sean
     * exactamente 2 letras (ISO 3166-1 alpha-2) antes de aceptarlo.
     *
     * Explícitamente OPCIONAL de este lado: ningún tenant está obligado a
     * mandarlo — si su proxy/frontend no lo implementa, el header
     * simplemente no llega y este método devuelve `null` sin que afecte en
     * nada al resto del submit (no es un campo de `FormField`, no participa
     * de `assertRequiredFieldsPresent()`/`assertFieldsAreValid()`).
     */
    private function resolveOriginCountry(Request $request): ?string
    {
        $originCountry = $request->header('X-Origin-Country');

        if (is_string($originCountry) && preg_match('/^[A-Za-z]{2}$/', $originCountry) === 1) {
            return strtoupper($originCountry);
        }

        return null;
    }

    // 2026-09-12: la validación de origen/dominio se retiró de acá — el
    // Tech Lead aclaró que la protección es a nivel de TOKEN de Stamless
    // (la plataforma), no del tenant/formulario puntual: "la proteccion es
    // para stamless, no para el sitio web, porque lo pueden usar solo con
    // node o react o app". Ahora vive en `App\Http\Middleware\
    // ValidateTokenOrigin`, aplicado a TODAS las rutas `v1/{tenant_slug}`
    // (no solo `forms/submit`) — ver ADR-059 en `docs/context/DECISIONS.md`.
}
