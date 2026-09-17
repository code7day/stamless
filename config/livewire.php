<?php

/**
 * Config publicada manualmente (2026-09-17, ver DECISIONS.md/PROGRESS.md) —
 * copia del `vendor/livewire/livewire/config/livewire.php` original, con UN
 * solo cambio real: `payload.max_nesting_depth` sube de 10 (default) a 24.
 *
 * Motivo: el usuario reportó un `Livewire\Exceptions\MaxNestingDepthExceededException`
 * real al agregar un 4to ítem en "Redes sociales" del bloque `colophon`
 * (página tipo Footer). La property path real del payload de Livewire tenía
 * 14 niveles:
 *
 *   mountedActions.0.data.blocks.{uuid}.data.content.columns.{uuid}.blocks.{uuid}.data.items.{uuid}
 *
 * Esto es estructural, no un bug de datos: el Builder de bloques de página
 * (`blocks`) contiene el bloque `colophon`, que tiene un Repeater de
 * `content.columns`, cada columna tiene su propio Builder anidado de
 * sub-bloques (`link_list`/`social_links`/`image_link`), y `social_links`
 * trae a su vez un Repeater de `data.items` (cada red social) — 4 niveles
 * de Builder/Repeater anidados es exactamente el patrón que este esquema
 * necesita para el pie de página multi-columna con sub-bloques mixtos (ver
 * `Colophon.astro`/`PageResource.php`, comentario de `ColophonColumn`).
 *
 * 24 (más del doble del default de 10) da margen para esta estructura y
 * para otras combinaciones de anidamiento ya existentes en el proyecto
 * (ej. menús de hasta 3 niveles vía `MenuTreeBuilder`, ADR-045) sin abrir
 * la puerta a payloads maliciosamente profundos — la guarda de Livewire
 * sigue activa, solo con un techo más realista para este dominio.
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Component Locations
    |---------------------------------------------------------------------------
    |
    | This value sets the root directories that'll be used to resolve view-based
    | components like single and multi-file components. The make command will
    | use the first directory in this array to add new component files to.
    |
    */

    'component_locations' => [
        resource_path('views/components'),
        resource_path('views/livewire'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Component Namespaces
    |---------------------------------------------------------------------------
    |
    | This value sets default namespaces that will be used to resolve view-based
    | components like single-file and multi-file components. These folders'll
    | also be referenced when creating new components via the make command.
    |
    */

    'component_namespaces' => [
        'layouts' => resource_path('views/layouts'),
        'pages' => resource_path('views/pages'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Page Layout
    |---------------------------------------------------------------------------
    | The view that will be used as the layout when rendering a single component as
    | an entire page via `Route::livewire('/post/create', 'pages::create-post')`.
    | In this case, the content of pages::create-post will render into $slot.
    |
    */

    'component_layout' => 'layouts::app',

    /*
    |---------------------------------------------------------------------------
    | Lazy Loading Placeholder
    |---------------------------------------------------------------------------
    | Livewire allows you to lazy load components that would otherwise slow down
    | the initial page load. Every component can have a custom placeholder or
    | you can define the default placeholder view for all components below.
    |
    */

    'component_placeholder' => null, // Example: 'placeholders::skeleton'

    /*
    |---------------------------------------------------------------------------
    | Make Command
    |---------------------------------------------------------------------------
    | This value determines the default configuration for the artisan make command
    | You can configure the component type (sfc, mfc, class) and whether to use
    | the high-voltage (⚡) emoji as a prefix in the sfc|mfc component names.
    |
    */

    'make_command' => [
        'type' => 'sfc', // Options: 'sfc', 'mfc', 'class'
        'emoji' => true, // Options: true, false
        'with' => [
            'js' => false,
            'css' => false,
            'test' => false,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Class Namespace
    |---------------------------------------------------------------------------
    |
    | This value sets the root class namespace for Livewire component classes in
    | your application. This value will change where component auto-discovery
    | finds components. It's also referenced by the file creation commands.
    |
    */

    'class_namespace' => 'App\\Livewire',

    /*
    |---------------------------------------------------------------------------
    | Class Path
    |---------------------------------------------------------------------------
    |
    | This value is used to specify the path where Livewire component class files
    | are created when running creation commands like `artisan make:livewire`.
    | This path is customizable to match your projects directory structure.
    |
    */

    'class_path' => app_path('Livewire'),

    /*
    |---------------------------------------------------------------------------
    | View Path
    |---------------------------------------------------------------------------
    |
    | This value is used to specify where Livewire component Blade templates are
    | stored when running file creation commands like `artisan make:livewire`.
    | It is also used if you choose to omit a component's render() method.
    |
    */

    'view_path' => resource_path('views/livewire'),

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | Livewire handles file uploads by storing uploads in a temporary directory
    | before the file is stored permanently. All file uploads are directed to
    | a global endpoint for temporary storage. You may configure this below:
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'), // Example: 'local', 's3'             | Default: 'default'
        'rules' => null,                                      // Example: ['file', 'mimes:png,jpg'] | Default: ['required', 'file', 'max:12288'] (12MB)
        'directory' => null,                                  // Example: 'tmp'                     | Default: 'livewire-tmp'
        'middleware' => null,                                 // Example: 'throttle:5,1'            | Default: 'throttle:60,1'
        'preview_mimes' => [                                  // Supported file types for temporary pre-signed file URLs...
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5, // Max duration (in minutes) before an upload is invalidated...
        'cleanup' => true, // Should cleanup temporary uploads older than 24 hrs...
    ],

    /*
    |---------------------------------------------------------------------------
    | Render On Redirect
    |---------------------------------------------------------------------------
    |
    | This value determines if Livewire will run a component's `render()` method
    | after a redirect has been triggered using something like `redirect(...)`
    | Setting this to true will render the view once more before redirecting
    |
    */

    'render_on_redirect' => false,

    /*
    |---------------------------------------------------------------------------
    | Eloquent Model Binding
    |---------------------------------------------------------------------------
    |
    | Previous versions of Livewire supported binding directly to eloquent model
    | properties using wire:model by default. However, this behavior has been
    | deemed too "magical" and has therefore been put under a feature flag.
    |
    */

    'legacy_model_binding' => false,

    /*
    |---------------------------------------------------------------------------
    | Auto-inject Frontend Assets
    |---------------------------------------------------------------------------
    |
    | By default, Livewire automatically injects its JavaScript and CSS into the
    | <head> and <body> of pages containing Livewire components. By disabling
    | this behavior, you need to use @livewireStyles and @livewireScripts.
    |
    */

    'inject_assets' => true,

    /*
    |---------------------------------------------------------------------------
    | Navigate (SPA mode)
    |---------------------------------------------------------------------------
    |
    | By adding `wire:navigate` to links in your Livewire application, Livewire
    | will prevent the default link handling and instead request those pages
    | via AJAX, creating an SPA-like effect. Configure this behavior here.
    |
    */

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],

    /*
    |---------------------------------------------------------------------------
    | HTML Morph Markers
    |---------------------------------------------------------------------------
    |
    | Livewire intelligently "morphs" existing HTML into the newly rendered HTML
    | after each update. To make this process more reliable, Livewire injects
    | "markers" into the rendered Blade surrounding @if, @class & @foreach.
    |
    */

    'inject_morph_markers' => true,

    /*
    |---------------------------------------------------------------------------
    | Smart Wire Keys
    |---------------------------------------------------------------------------
    |
    | Livewire uses loops and keys used within loops to generate smart keys that
    | are applied to nested components that don't have them. This makes using
    | nested components more reliable by ensuring that they all have keys.
    |
    */

    'smart_wire_keys' => true,

    /*
    |---------------------------------------------------------------------------
    | Pagination Theme
    |---------------------------------------------------------------------------
    |
    | When enabling Livewire's pagination feature by using the `WithPagination`
    | trait, Livewire will use Tailwind templates to render pagination views
    | on the page. If you want Bootstrap CSS, you can specify: "bootstrap"
    |
    */

    'pagination_theme' => 'tailwind',

    /*
    |---------------------------------------------------------------------------
    | Release Token
    |---------------------------------------------------------------------------
    |
    | This token is stored client-side and sent along with each request to check
    | a users session to see if a new release has invalidated it. If there is
    | a mismatch it will throw an error and prompt for a browser refresh.
    |
    */

    'release_token' => 'a',

    /*
    |---------------------------------------------------------------------------
    | CSP Safe
    |---------------------------------------------------------------------------
    |
    | This config is used to determine if Livewire will use the CSP-safe version
    | of Alpine in its bundle. This is useful for applications that are using
    | strict Content Security Policy (CSP) to protect against XSS attacks.
    |
    */

    'csp_safe' => false,

    /*
    |---------------------------------------------------------------------------
    | Payload Guards
    |---------------------------------------------------------------------------
    |
    | These settings protect against malicious or oversized payloads that could
    | cause denial of service. The default values should feel reasonable for
    | most web applications. Each can be set to null to disable the limit.
    |
    */

    'payload' => [
        'max_size' => 1024 * 1024,   // 1MB - maximum request payload size in bytes
        // 2026-09-17: 10 (default) -> 24. Ver docblock de arriba: el bloque
        // `colophon` (Builder de página > Repeater de columnas > Builder de
        // sub-bloques > Repeater de items de `social_links`) genera property
        // paths de Livewire de hasta ~14 niveles reales en uso normal, por
        // encima del default. No bajar de nuevo sin revisar antes si el
        // esquema de `colophon`/menús de 3 niveles sigue necesitando esta
        // profundidad.
        'max_nesting_depth' => 24,   // Maximum depth of dot-notation property paths
        'max_calls' => 50,           // Maximum method calls per request
        'max_components' => 200,     // Maximum components per batch request
    ],
];
