<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // 2026-09-18: el disco 'public' pasa a ser el ÚNICO punto de
        // configuración de "dónde vive la media pública del tenant" — local
        // en desarrollo (`storage/app/public`, symlink de `storage:link`) o
        // R2 en producción (mismas credenciales que el disco 'r2' de abajo),
        // decidido acá mismo por `FILESYSTEM_DISK`, sin que el código de la
        // app tenga que preguntarlo caso por caso.
        //
        // Por qué importa que se llame LITERALMENTE 'public' (y no 'r2' o
        // cualquier otro nombre) en cualquier ambiente: `Filament\Tables\
        // Columns\ImageColumn::getVisibility()` infiere la visibilidad
        // COMPARANDO EL NOMBRE DEL DISCO contra el string `'public'` — NO
        // lee la clave `'visibility'` de este archivo. Con el disco de
        // media llamándose 'r2' en producción (como era antes), Filament
        // asumía privado sin importar esta config, y armaba URLs firmadas
        // en vez de la URL pública simple — bug real encontrado en
        // producción el 2026-09-18 (ver PROGRESS.md/DECISIONS.md de ese
        // día). Con el disco de media SIEMPRE llamado 'public', ese mismo
        // mecanismo de Filament funciona bien automáticamente, en
        // cualquier ambiente, sin overrides de `->visibility('public')`
        // repetidos en cada columna/campo de la app.
        'public' => array_merge(
            [
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],
            (env('FILESYSTEM_DISK', 'local') === 'r2')
                ? [
                    'driver' => 's3',
                    'key' => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
                    'secret' => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
                    'region' => env('R2_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'auto')),
                    'bucket' => env('R2_BUCKET', env('AWS_BUCKET')),
                    'url' => env('R2_URL', env('AWS_URL')),
                    'endpoint' => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
                    'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
                ]
                : [
                    'driver' => 'local',
                    'root' => storage_path('app/public'),
                    'url' => '/storage',
                ],
        ),

        // Disco FIJO, nunca condicional — siempre el filesystem local del
        // servidor (`storage/app/public`), sin importar `FILESYSTEM_DISK`.
        // Necesario porque el disco `'public'` de arriba ahora SÍ cambia de
        // driver según el ambiente (local o R2) — cualquier código que
        // necesite garantizado "el filesystem local de este servidor" (ej.
        // `media:sync-r2`, que lee archivos locales para subirlos a R2, o
        // `Cliente0MediaSeeder`, que lee los assets sembrados del repo)
        // tiene que usar ESTE disco, no `'public'`.
        'local_public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Se mantiene tal cual (sin cambios) por compatibilidad hacia atrás
        // — registros `Media` ya guardados en producción ANTES del
        // 2026-09-18 tienen `disk = 'r2'` (no `'public'`), y este disco
        // sigue resolviendo esas URLs igual que siempre mientras no se
        // corra la migración de datos que normaliza esos valores a
        // `'public'` (ver `2026_09_18_080000_normalize_media_disk_to_public`).
        // Los uploads NUEVOS (vía `MediaUpload`/`MediaResource`) ya no
        // escriben `'r2'` — usan el disco `'public'` de arriba directo.
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('R2_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'auto')),
            'bucket' => env('R2_BUCKET', env('AWS_BUCKET')),
            'url' => env('R2_URL', env('AWS_URL')),
            'endpoint' => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'visibility' => 'public',
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
