<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Super-admin de plataforma: `tenant_id = null` (todavía no existe la
     * columna `is_super_admin` del DBML de auth avanzado, ver ADR-013 /
     * TASK.md #12; el criterio actual de "es plataforma" es tenant nulo,
     * igual que en el seeder original).
     *
     * Contraseña de desarrollo únicamente: cambiar/rotar antes de cualquier
     * despliegue real. El cast `hashed` de `User` la hashea al guardar.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@stamless.com'],
            [
                'name' => 'Platform Manager Admin',
                'password' => 'password123',
                'tenant_id' => null,
                'email_verified_at' => now(),
            ]
        );
    }
}
