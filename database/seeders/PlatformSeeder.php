<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Super-admin Master (Nivel GOD / General de generales): `zedu77@gmail.com`
     * con `is_super_admin = true`, acceso irrestricto a Platform y Studio,
     * habilitado tanto para autenticación directa por contraseña como
     * por Social Login (Google / Gmail).
     *
     * Contraseña de desarrollo únicamente: cambiar/rotar antes de cualquier
     * despliegue real. El cast `hashed` de `User` la hashea al guardar.
     */
    public function run(): void
    {
        // Super Admin Master Principal (Nivel GOD)
        User::updateOrCreate(
            ['email' => 'zedu77@gmail.com'],
            [
                'name' => 'Eduardo Flores',
                'password' => 'password123',
                'tenant_id' => null,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        // Administrador de soporte / manager de plataforma
        User::updateOrCreate(
            ['email' => 'admin@stamless.com'],
            [
                'name' => 'Platform Manager Admin',
                'password' => 'password123',
                'tenant_id' => null,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
