<?php

namespace Tests\Feature\Console;

use App\Enums\MediaDiskEnum;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncMediaToR2CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_media_to_r2_command_uploads_files_and_updates_database(): void
    {
        Storage::fake('public');
        Storage::fake('r2');

        Storage::disk('public')->put('media/sample-slide.webp', 'fake-image-content');
        Storage::disk('public')->put('assets/login-cover.jpg', 'fake-asset-content');

        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $media = Media::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sample Slide',
            'file_name' => 'sample-slide.webp',
            'mime_type' => 'image/webp',
            'size' => 1024,
            'path' => 'media/sample-slide.webp',
            'disk' => MediaDiskEnum::Public->value,
        ]);

        $this->artisan('media:sync-r2 --disk=r2')
            ->expectsOutputToContain('SINCRONIZACIÓN DE MEDIOS HACIA CLOUDFLARE R2')
            ->expectsOutputToContain('Archivos subidos a R2')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists('media/sample-slide.webp');
        Storage::disk('r2')->assertExists('assets/login-cover.jpg');

        $this->assertEquals(MediaDiskEnum::R2, $media->fresh()->disk);
    }

    public function test_sync_media_to_r2_dry_run_does_not_modify_storage_or_database(): void
    {
        Storage::fake('public');
        Storage::fake('r2');

        Storage::disk('public')->put('media/sample-slide.webp', 'fake-image-content');

        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $media = Media::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sample Slide',
            'file_name' => 'sample-slide.webp',
            'mime_type' => 'image/webp',
            'size' => 1024,
            'path' => 'media/sample-slide.webp',
            'disk' => MediaDiskEnum::Public->value,
        ]);

        $this->artisan('media:sync-r2 --disk=r2 --dry-run')
            ->expectsOutputToContain('Simulación (--dry-run) completada')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing('media/sample-slide.webp');
        $this->assertEquals(MediaDiskEnum::Public, $media->fresh()->disk);
    }
}
