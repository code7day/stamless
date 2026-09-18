<?php

namespace App\Console\Commands;

use App\Enums\MediaDiskEnum;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('media:sync-r2 {--disk=r2 : Nombre del disco de destino} {--force : Forzar la sobreescritura de archivos ya existentes en R2} {--dry-run : Simular el proceso sin subir archivos ni modificar la base de datos}')]
#[Description('Sube todos los assets y archivos multimedia locales de storage/app/public hacia Cloudflare R2 y actualiza los registros Media en la base de datos')]
class SyncMediaToR2Command extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $targetDisk = (string) $this->option('disk');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('========================================================================');
        $this->info('🚀 SINCRONIZACIÓN DE MEDIOS HACIA CLOUDFLARE R2');
        $this->info('========================================================================');
        $this->line("Disco destino:  <fg=yellow>{$targetDisk}</>");
        $this->line('Modo forzado:   <fg=yellow>'.($force ? 'Sí (--force)' : 'No (omite archivos existentes)').'</>');
        $this->line('Simulación:     <fg=yellow>'.($dryRun ? 'Sí (--dry-run)' : 'No (ejecución real)').'</>');
        $this->info('========================================================================');

        // 1. Validar configuración del disco destino
        try {
            $storage = Storage::disk($targetDisk);
        } catch (Throwable $e) {
            $this->error("❌ Error al inicializar el disco '{$targetDisk}': ".$e->getMessage());

            return self::FAILURE;
        }

        // 2. Escanear archivos locales en storage/app/public/
        //
        // 2026-09-18: `'local_public'` (antes `'public'`) — desde el fix del
        // mismo día en `config/filesystems.php`, el disco `'public'` pasó a
        // resolver local O R2 según `FILESYSTEM_DISK` (para que Filament
        // infiera bien la visibilidad de `ImageColumn`, ver PROGRESS.md).
        // Este comando necesita el filesystem LOCAL de forma garantizada
        // (lee de acá para subir a R2) sin importar el ambiente — con
        // `'public'` a secas, correr este comando en un servidor con
        // `FILESYSTEM_DISK=r2` habría escaneado el propio bucket R2 en vez
        // del disco local, rompiendo el propósito del comando por completo.
        $localStorage = Storage::disk('local_public');
        $mediaFiles = $localStorage->allFiles('media');
        $assetFiles = $localStorage->allFiles('assets');
        $allFiles = array_merge($mediaFiles, $assetFiles);

        if (empty($allFiles)) {
            $this->warn('⚠️ No se encontraron archivos en storage/app/public/media ni storage/app/public/assets.');

            return self::SUCCESS;
        }

        $this->info('🔍 Encontrados '.count($allFiles).' archivo(s) físico(s) en storage/app/public.');
        $this->newLine();

        $uploaded = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar(count($allFiles));
        $bar->start();

        foreach ($allFiles as $filePath) {
            $existsOnTarget = false;

            if (! $dryRun) {
                try {
                    $existsOnTarget = $storage->exists($filePath);
                } catch (Throwable $e) {
                    $existsOnTarget = false;
                }
            }

            if ($existsOnTarget && ! $force) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if ($dryRun) {
                $uploaded++;
                $bar->advance();

                continue;
            }

            try {
                $stream = $localStorage->readStream($filePath);

                if ($stream === false) {
                    $failed++;
                    $this->newLine();
                    $this->warn("⚠️ No se pudo leer el archivo local: {$filePath}");
                    $bar->advance();

                    continue;
                }

                $mimeType = $localStorage->mimeType($filePath) ?: 'application/octet-stream';
                $storage->put($filePath, $stream, [
                    'visibility' => 'public',
                    'mimetype' => $mimeType,
                    'ContentType' => $mimeType,
                ]);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $uploaded++;
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("❌ Error al subir {$filePath}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // 3. Actualizar registros en base de datos (tabla Media)
        //
        // 2026-09-18: el valor escrito en `Media.disk` pasa a ser SIEMPRE
        // `MediaDiskEnum::Public` (antes escribía `$targetDisk` tal cual,
        // ej. `'r2'`) — desde el fix de `config/filesystems.php` del mismo
        // día, `'public'` es el único nombre de disco canónico que debe
        // quedar guardado en cualquier registro `Media` (sin importar el
        // disco físico real detrás, local o R2), porque es el nombre que
        // `Filament\Tables\Columns\ImageColumn` reconoce automáticamente
        // como público sin necesitar `->visibility('public')` explícito en
        // cada columna (ver PROGRESS.md/DECISIONS.md de ese día). El
        // `--disk` de este comando sigue siendo el disco físico de DESTINO
        // real para el `put()` (por defecto `'r2'`, sin cambios).
        $dbUpdated = 0;
        if (! $dryRun) {
            $dbUpdated = Media::where('disk', '!=', MediaDiskEnum::Public->value)->update([
                'disk' => MediaDiskEnum::Public->value,
            ]);
        } else {
            $dbUpdated = Media::where('disk', '!=', MediaDiskEnum::Public->value)->count();
        }

        // 4. Mostrar resumen
        $this->table(
            ['Métrica', 'Total'],
            [
                ['Archivos físicos escaneados', count($allFiles)],
                ['Archivos subidos a R2', $uploaded],
                ['Archivos omitidos (ya existentes en R2)', $skipped],
                ['Archivos con error', $failed],
                ['Registros Media actualizados en BD', $dbUpdated],
            ]
        );

        if ($dryRun) {
            $this->info('✨ Simulación (--dry-run) completada. Ningún dato fue modificado.');

            return self::SUCCESS;
        }

        $sampleMedia = Media::first();
        if ($sampleMedia) {
            $this->info('🔗 URL de ejemplo resuelta por la API:');
            $this->line("   {$sampleMedia->name} -> <fg=cyan>{$sampleMedia->url()}</>");
        }

        $this->info('🎉 ¡Sincronización hacia Cloudflare R2 completada con éxito!');

        return self::SUCCESS;
    }
}
