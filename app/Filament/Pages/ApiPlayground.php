<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnitEnum;

/**
 * Playground tipo Swagger/Postman para el API v1, embebido en Console — ver
 * ADR-018. Le pega a la API real del entorno (`config('stamless.urls.api')` +
 * `/v1/{tenant}/{path}` — ver ADR-020, ADR-026 y `config/stamless.php` sobre por
 * qué no se usa `config('app.url')`, que es el dominio de la landing, no el
 * de la API), no a un mock. No persiste nada del request salvo si el admin
 * explícitamente genera un token de prueba (que queda como un token real,
 * visible/revocable en `ApiTokens`).
 */
class ApiPlayground extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Desarrolladores';

    protected static ?string $navigationLabel = 'API Playground';

    protected static ?string $title = 'API Playground';

    protected ?string $subheading = 'Armá y ejecutá requests reales contra el API v1 sin salir de Console.';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.api-playground';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public string $activePreset = 'pages.index';

    public string $responseTab = 'pretty';

    public ?int $responseStatus = null;

    public ?int $responseDurationMs = null;

    public ?string $responseBody = null;

    public ?string $responseBodyHighlighted = null;

    /**
     * @var array<string, string>
     */
    public array $responseHeaders = [];

    public ?string $responseError = null;

    public ?string $lastCurl = null;

    /**
     * Ejemplos precargados contra CICA360 (agrupados por recurso, como
     * pide el punto C del brief). `path` es relativo a
     * `/v1/{tenant_slug}/`.
     */
    private const array PRESETS = [
        'pages.index' => ['group' => 'Pages', 'label' => 'Listar pages', 'method' => 'GET', 'path' => 'pages', 'body' => ''],
        'pages.show' => ['group' => 'Pages', 'label' => 'Page por slug (home)', 'method' => 'GET', 'path' => 'pages/home', 'body' => ''],
        'posts.index' => ['group' => 'Posts', 'label' => 'Listar posts', 'method' => 'GET', 'path' => 'posts', 'body' => ''],
        'posts.show' => ['group' => 'Posts', 'label' => 'Post por slug', 'method' => 'GET', 'path' => 'posts/como-elegir-seguro-de-vida', 'body' => ''],
        'menus.show' => ['group' => 'Menus', 'label' => 'Menú principal', 'method' => 'GET', 'path' => 'menus/menu-principal', 'body' => ''],
        'sliders.show' => ['group' => 'Sliders', 'label' => 'Slider home', 'method' => 'GET', 'path' => 'sliders/home', 'body' => ''],
        'media.show' => ['group' => 'Media', 'label' => 'Media por uuid', 'method' => 'GET', 'path' => 'media/{uuid}', 'body' => ''],
        'forms.submit' => [
            'group' => 'Forms', 'label' => 'Enviar formulario de contacto', 'method' => 'POST', 'path' => 'forms/contacto/submit',
            'body' => "{\n    \"name\": \"Juan Pérez\",\n    \"email\": \"juan@example.com\",\n    \"phone\": \"099123456\",\n    \"message\": \"Quiero más información.\"\n}",
        ],
    ];

    public function mount(): void
    {
        $preset = self::PRESETS[$this->activePreset];

        $this->form->fill([
            'tenant_slug' => 'cica360',
            'method' => $preset['method'],
            'path' => $preset['path'],
            'token' => '',
            'headers' => ['Accept' => 'application/json'],
            'body' => $preset['body'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(4)
                    ->schema([
                        Forms\Components\Select::make('method')
                            ->label('Método')
                            ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'])
                            ->required(),

                        Forms\Components\TextInput::make('tenant_slug')
                            ->label('Tenant')
                            ->required(),

                        Forms\Components\TextInput::make('path')
                            ->label('Path')
                            ->prefix('/v1/{tenant}/')
                            ->helperText('Editable — reemplazá {slug}/{uuid} según el recurso.')
                            ->required()
                            ->columnSpan(2),
                    ]),

                Forms\Components\TextInput::make('token')
                    ->label('Bearer token')
                    ->password()
                    ->revealable()
                    ->placeholder('Pegá un token existente o generá uno de prueba con el botón de arriba')
                    ->helperText('Se envía como header Authorization: Bearer {token}. Nunca se guarda.'),

                Forms\Components\KeyValue::make('headers')
                    ->label('Headers adicionales')
                    ->keyLabel('Header')
                    ->valueLabel('Valor')
                    ->helperText('Authorization se agrega automáticamente si completás el token de arriba.'),

                Forms\Components\Textarea::make('body')
                    ->label('Body (JSON)')
                    ->rows(6)
                    ->visible(fn (Get $get) => in_array($get('method'), ['POST', 'PUT', 'PATCH']))
                    ->helperText('Solo se envía en POST/PUT/PATCH. Tiene que ser JSON válido.'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generateTestToken')
                ->label('Generar token de prueba')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();

                    // Token desechable de 24hs — se lista/revoca igual que
                    // cualquier otro en "API Tokens", pero no queda dando
                    // vueltas indefinidamente si alguien se olvida de borrarlo.
                    $token = $user->createToken(
                        'playground-test-'.now()->format('YmdHis'),
                        ['content:read', 'forms:submit'],
                        ApiTokens::resolveExpiration('1'),
                    );

                    // forceFill(), no update(): `last_four` no está en el
                    // $fillable de Sanctum\PersonalAccessToken — ver el
                    // mismo comentario en ApiTokens::getHeaderActions().
                    $token->accessToken->forceFill([
                        'last_four' => substr($token->plainTextToken, -4),
                    ])->save();

                    $this->data['token'] = $token->plainTextToken;
                }),

            Actions\Action::make('send')
                ->label('Send')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->action('sendRequest'),
        ];
    }

    /**
     * Endpoints agrupados por recurso para el sidebar del playground.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function groupedPresets(): array
    {
        $grouped = [];

        foreach (self::PRESETS as $key => $preset) {
            $grouped[$preset['group']][$key] = $preset;
        }

        return $grouped;
    }

    /**
     * Se dispara al clickear un endpoint del sidebar: precarga método/path/
     * body sin pisar el token ni los headers que el admin ya haya cargado.
     */
    public function selectPreset(string $key): void
    {
        $preset = self::PRESETS[$key] ?? null;

        if (! $preset) {
            return;
        }

        $this->activePreset = $key;
        $this->responseTab = 'pretty';

        $this->data['method'] = $preset['method'];
        $this->data['path'] = $preset['path'];
        $this->data['body'] = $preset['body'];
    }

    public function sendRequest(): void
    {
        $state = $this->form->getState();

        $tenantSlug = trim($state['tenant_slug'] ?? '');
        $path = ltrim(trim($state['path'] ?? ''), '/');
        $method = strtolower($state['method'] ?? 'get');
        $token = trim($state['token'] ?? '');
        $headers = collect($state['headers'] ?? [])
            ->filter(fn ($value, $key) => filled($key))
            ->all();

        if (filled($token)) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $headers['Accept'] ??= 'application/json';

        $url = rtrim((string) config('stamless.urls.api'), '/')."/v1/{$tenantSlug}/{$path}";

        $body = null;
        $rawBody = trim($state['body'] ?? '');

        $this->responseTab = 'pretty';
        $this->responseHeaders = [];

        if (in_array($method, ['post', 'put', 'patch']) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->responseError = 'El body no es JSON válido: '.json_last_error_msg();
                $this->responseStatus = null;
                $this->responseBody = null;
                $this->responseBodyHighlighted = null;
                $this->lastCurl = $this->buildCurl($url, $method, $headers, $rawBody);

                return;
            }

            $body = $decoded;
        }

        $this->lastCurl = $this->buildCurl($url, $method, $headers, $rawBody !== '' ? $rawBody : null);

        $start = microtime(true);

        try {
            $request = Http::withHeaders($headers)->timeout(15);

            // El Playground le pega a `APP_URL_API` desde el propio server
            // Laravel (self-request a otro subdominio del mismo monolito).
            // En local eso suele resolver contra un certificado autofirmado
            // (MAMP PRO/Herd/Valet) que el cURL de PHP no confía —
            // "SSL certificate problem: unable to get local issuer
            // certificate" — sin que eso implique nada inseguro sobre el
            // certificado real de producción. Se desactiva la verificación
            // TLS únicamente en entorno local; nunca en producción.
            if (app()->isLocal()) {
                $request = $request->withoutVerifying();
            }

            $response = match ($method) {
                'post' => $request->post($url, $body ?? []),
                'put' => $request->put($url, $body ?? []),
                'patch' => $request->patch($url, $body ?? []),
                'delete' => $request->delete($url),
                default => $request->get($url),
            };

            $this->responseStatus = $response->status();
            $this->responseError = null;
            $this->responseHeaders = collect($response->headers())
                ->map(fn (array $values) => implode(', ', $values))
                ->all();

            $json = $response->json();
            $this->responseBody = $json !== null
                ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $response->body();
            $this->responseBodyHighlighted = $this->highlightJson($this->responseBody);
        } catch (Throwable $e) {
            $this->responseStatus = null;
            $this->responseBody = null;
            $this->responseBodyHighlighted = null;
            $this->responseError = 'No se pudo conectar con la API: '.$e->getMessage();
        }

        $this->responseDurationMs = (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Syntax highlighting server-side (sin dependencias JS externas):
     * escapa el JSON primero y recién después envuelve tokens en spans, así
     * no hay riesgo de inyectar HTML desde una response arbitraria.
     */
    private function highlightJson(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        // ENT_NOQUOTES a propósito: esto va dentro de <pre><code>, nunca de
        // un atributo HTML, así que no hace falta escapar comillas — y si
        // las escapáramos (e() usa ENT_QUOTES por defecto) los regex de
        // abajo dejarían de encontrar los `"..."` del JSON.
        $escaped = htmlspecialchars($json, ENT_NOQUOTES, 'UTF-8');

        // Claves: "algo":
        $escaped = preg_replace(
            '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"(\s*:)/',
            '<span class="gnss-json-key">"$1"</span>$2',
            $escaped,
        );

        // Valores string: : "algo"
        $escaped = preg_replace(
            '/:(\s*)"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/',
            ':$1<span class="gnss-json-string">"$2"</span>',
            $escaped,
        );

        // Números
        $escaped = preg_replace(
            '/:(\s*)(-?\d+(\.\d+)?)/',
            ':$1<span class="gnss-json-number">$2</span>',
            $escaped,
        );

        // Booleanos y null
        $escaped = preg_replace(
            '/:(\s*)(true|false)\b/',
            ':$1<span class="gnss-json-bool">$2</span>',
            $escaped,
        );

        return preg_replace(
            '/:(\s*)(null)\b/',
            ':$1<span class="gnss-json-null">$2</span>',
            $escaped,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function buildCurl(string $url, string $method, array $headers, ?string $body): string
    {
        $parts = ['curl -X '.strtoupper($method)." '{$url}'"];

        foreach ($headers as $key => $value) {
            $parts[] = "-H '{$key}: {$value}'";
        }

        if ($body !== null) {
            $parts[] = "-d '".str_replace("'", "'\\''", $body)."'";
        }

        return implode(" \\\n  ", $parts);
    }
}
