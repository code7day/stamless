<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy base para recursos scopeados por tenant Y por rol de Spatie
 * (`Admin`/`Soporte`/`Marketing`/`Editor`/`Author` — ver `UserRoleEnum`).
 * Ver ADR-078 y su addendum de 2026-09-18 (expansión a 5 roles).
 *
 * Motivo: antes de esta clase, `spatie/laravel-permission` estaba
 * instalado y el modal "Crear nuevo colaborador" de Console SÍ asignaba un
 * rol real (`Role::firstOrCreate(...)` + `syncRoles()`, scopeado por tenant
 * vía `teams => true`), pero NINGÚN Resource de Filament lo consultaba —
 * cualquier usuario autenticado del tenant, sin importar el rol elegido al
 * crearlo, tenía el mismo acceso total que un Admin. Reportado en vivo por
 * el Tech Lead: creó un colaborador con rol Editor y notó que tenía acceso
 * a todo igual que el dueño del tenant.
 *
 * Cada Policy concreta de un Resource extiende esta clase y solo declara
 * QUÉ roles pueden hacer QUÉ acción (los 4 arrays de abajo) — la lógica de
 * "cómo" se chequea (rol + pertenencia al tenant del registro) vive acá
 * una sola vez, para no repetirla en cada Policy. `viewAny`/`create` no
 * reciben un `$record` (todavía no existe o es una colección) — solo
 * chequean el rol. `view`/`update`/`delete` SÍ reciben el `$record` y
 * agregan la verificación de tenant (defensa en profundidad sobre el
 * global scope de `HasTenant`, mismo patrón que `ContactPolicy` original).
 *
 * 2026-09-18 (addendum ADR-078, expansión de roles): estos defaults son el
 * grupo "Contenidos" (Page/Post/Service/Testimonial) — `Soporte` y
 * `Marketing` se suman con el mismo alcance que ya tenía `Editor`. Borrar
 * sigue reservado a quienes NO son `Author` (Redactor no puede borrar,
 * pedido explícito del Tech Lead).
 */
abstract class TenantRolePolicy
{
    /** @var list<string> Roles (valores de UserRoleEnum) que pueden listar/ver el recurso. */
    protected array $viewRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
        UserRoleEnum::Author->value,
    ];

    /** @var list<string> Roles que pueden crear un registro nuevo. */
    protected array $createRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
        UserRoleEnum::Author->value,
    ];

    /** @var list<string> Roles que pueden editar un registro existente. */
    protected array $updateRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
        UserRoleEnum::Author->value,
    ];

    /** @var list<string> Roles que pueden borrar un registro. */
    protected array $deleteRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
    ];

    public function viewAny(User $user): bool
    {
        return $this->hasAnyRole($user, $this->viewRoles);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->hasAnyRole($user, $this->viewRoles) && $this->belongsToTenant($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->hasAnyRole($user, $this->createRoles);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->hasAnyRole($user, $this->updateRoles) && $this->belongsToTenant($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->hasAnyRole($user, $this->deleteRoles) && $this->belongsToTenant($user, $record);
    }

    /**
     * Un usuario sin ningún rol asignado (dato legado, o el primer usuario
     * de un tenant creado antes de que existiera este sistema de roles) se
     * trata como `Admin` — mismo fallback que ya usaban `UserResource`/
     * `ManageUsers` (`$record->roles()->first()?->name ?? 'Admin'`) para no
     * dejar afuera de golpe a cuentas existentes sin rol explícito.
     */
    protected function hasAnyRole(User $user, array $roles): bool
    {
        $roleName = $user->roles()->first()?->name ?? UserRoleEnum::Admin->value;

        return in_array($roleName, $roles, true);
    }

    protected function belongsToTenant(User $user, Model $record): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $record->tenant_id;
    }
}
