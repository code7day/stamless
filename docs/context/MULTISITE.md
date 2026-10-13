# Stamless — Multi-sitio por cuenta (especificación)

> Estado: borrador para aprobar y convertir en ADR · 2026-10-12 · Decidido por Edu.
> Objetivo: que un propietario administre varios sitios (tenants) con aislamiento total entre ellos, según su plan.

## Punto de partida (verificado en el código)

- Hoy **una cuenta = un sitio**: `users.tenant_id`; `User::getTenants()` y `getDefaultTenant()` devuelven un solo tenant.
- `EnsureUserAccessesOwnTenant` redirige a cualquier usuario que pida un tenant distinto al suyo.
- Ya está bien resuelto: Studio usa `->tenant(Tenant::class, slugAttribute: 'slug')` (Filament soporta selector de sitios), `TenantScope`/`TenantManager` filtran por `tenant_id`, los tokens de API son por tenant (`ResolvesTenant` valida el tenant del token), Spatie Permission corre con `teams=true` y `tenant_id` (roles por sitio), y los secretos (SMTP, deploy token) viven en cada tenant.
- Plan por sitio: `tenants.plan` y `subscriptions.tenant_id`. Falta el concepto de **propietario con varios sitios**.

## Reglas por plan (decisión de Edu)

| Plan | Sitios | Nombre y slug | Cómo se agregan sitios |
|---|---|---|---|
| Trial (14 días) | Solo el primero que registró; nunca crea otro | No puede cambiar nombre ni slug | No aplica |
| Starter | 1 solo cupo | Puede cambiar nombre y slug en Preferencias | No aplica |
| Pro | Puede agregar sitios | Cada sitio edita sus propias preferencias | Cada sitio adicional es **Pro**, a precio fijo, mensual o anual a su elección. Cualquier sitio Pro del propietario puede sumar más |
| Premium | Todo lo de Pro | Igual | Además elige Starter o Pro por sitio. **Activación manual en Platform**, con monto pactado mensual o anual |
| Convenio | Cuenta creada a mano por un admin, con cualquier plan | Igual que su plan | El propietario gestiona su sitio y puede **comprar** sitios nuevos; por defecto solo plan Pro. Dura el tiempo pactado (5, 10 años o indefinido), editable a mano |

Regla transversal: cada sitio es **privado y autónomo** (preferencias, llaves de API, SMTP, deploy, medios, usuarios y roles). Nada se comparte entre sitios, aunque tengan el mismo propietario. En Platform el control B2B es **sitio por sitio**, agrupado por propietario.

## Modelo propuesto

- Tabla pivote `tenant_user` (`user_id`, `tenant_id`, `is_owner`, timestamps). Reemplaza el rol de `users.tenant_id`, que queda como "último sitio usado" y se retira después.
- Propietario = usuario con `is_owner = true` en un sitio. Un sitio tiene un propietario; puede tener otros usuarios con roles de Spatie por sitio.
- Política `TenantPolicy::create(User)`: permite crear un sitio solo si el usuario es propietario de al menos un sitio con plan Pro, Premium o Convenio activo. Trial y Starter nunca.
- Suscripción por sitio, sin cambio de modelo. Campos nuevos en `subscriptions`: `price_override` y `currency` (precio pactado), `agreement_ends_at`, `source` (`self_service` o `manual`).
- Cambiar nombre y slug: `Tenant::canRenameSite()` y `canChangeSlug()` según plan (Trial: no; Starter y superiores: sí, con el límite de cambios de slug actual).
- Studio: selector de sitio en el menú, y página "Crear sitio" (`tenantRegistration` de Filament) protegida por la política. Cada sitio carga su propio plan, límites y marca.
- Platform: vista por propietario con sus sitios, plan y estado de cada uno; alta manual de cuenta y plan; precio y vigencia pactados.

## Cambios en el código (alcance)

1. Migración `tenant_user` y copia de los `users.tenant_id` actuales como propietarios.
2. `User`: `tenants()`, `getTenants()`, `getDefaultTenant()` (último usado), `canAccessTenant()` por pertenencia.
3. Middleware: `EnsureUserAccessesOwnTenant` valida pertenencia, no igualdad; ajustar `RedirectSuperAdminWithoutTenantToPlatform` y `EnsurePasswordIsNotExpired`.
4. Fijar `setPermissionsTeamId()` según el sitio activo en cada petición (roles Spatie por sitio).
5. `PanelCmsProvider`: habilitar selector y `tenantRegistration`; revisar los enlaces que hoy usan `$user->tenant`.
6. Preferencias: reglas de nombre y slug por plan.
7. `PlanEnum` y límites: cuota de sitios por plan; `Tenant::canAddSites()` en el propietario.
8. Platform: recurso de propietarios y sitios; alta manual; precio pactado y vigencia del convenio.
9. Pruebas de aislamiento: usuario con roles distintos en dos sitios; token de API del sitio A no lee el sitio B; medios y SMTP separados; selector no filtra sitios ajenos.

## Riesgos

- **Aislamiento**: es el punto crítico. Todo lo que hoy usa `$user->tenant` asume un único sitio; un descuido filtra datos entre sitios. Mitigación: pruebas de aislamiento antes de habilitar la creación de sitios.
- **Roles por sitio**: con Spatie `teams`, el `team id` debe fijarse en cada petición, o los permisos se evalúan en el sitio equivocado.
- **Descenso de plan**: un propietario con 3 sitios Pro que cancela uno no pierde los otros; cada sitio paga por separado. Si cancela todos los Pro, no puede crear sitios nuevos, pero conserva los existentes.
- **Traspaso a un cliente** (agencia entrega el sitio): cambiar `is_owner` y la suscripción. Queda para después, pero el modelo lo permite.

## Decisiones abiertas

1. ¿Un sitio Starter puede ascender a Pro y luego agregar sitios? (propuesta: sí, es el camino de crecimiento natural).
2. Precio por sitio adicional: ¿mismo precio que el primer Pro ($20 mensual, $200 anual) o con descuento por volumen más adelante?
3. Convenio: ¿la compra de sitios adicionales tiene precio normal Pro o uno pactado? (propuesta: Pro normal por defecto, editable a mano).

## Revisión 2026-10-12: cuenta de facturación y modalidades Premium

Las modalidades Premium exigen separar **quién paga** de **quién gestiona el sitio**. Se agrega la entidad `accounts` (cuenta de facturación) y se ajusta el modelo anterior.

- `accounts`: `uuid`, `name`, `owner_user_id` (titular que paga), `contract_type` (`self_service`, `premium_direct`, `agency_partner`, `agency_network`, `convenio`), `currency`, `site_discount_pct`, `extra_site_discount_pct`, `package_sites_total`, `package_sites_paid`, `base_fee_amount`, `billing_cycle`, `agreement_ends_at`, `notes`.
- `tenants.account_id`: cada sitio pertenece a una cuenta de facturación. El plan sigue siendo por sitio (`tenants.plan`, `subscriptions`).
- `tenant_user`: acceso al sitio, con `role_owner` (varios propietarios o co-propietarios por sitio). El que paga (cuenta) y el que gestiona (propietario del sitio) pueden ser personas distintas.
- Un cliente de agencia es propietario de su sitio sin ver ni tocar los otros sitios de la agencia.
- **Traspaso**: mover un sitio a la cuenta del cliente (el cliente paga a precio normal). Es la salida si la agencia deja de pagar y una ventaja comercial.

### Modalidades (se asignan a mano en Platform, con asesor)

| Modalidad | Nombre sugerido | Cuota | Sitios | Precio de sitios |
|---|---|---|---|---|
| A | Premium Cuenta | Desde $99 mensual / $990 anual | Ilimitados, cada uno con sus propios límites | Starter o Pro a tarifa normal |
| B | Agencia Partner | Cuota de plataforma (propuesta $29 mensual o $290 anual) | Ilimitados; subcuentas propietarias para clientes | 25 % menos que la tarifa normal, cada sitio activado y pagado |
| C | Agencia Network | Paquete anual de 10 sitios pagando 5 a 7 | 10 incluidos; sin costo de activación dentro del paquete | Fuera del paquete, 30 % menos que la tarifa normal. Co-propietarios por sitio |

### Cálculo de referencia (USD, Pro $20 mensual / $200 anual; Starter $7 / $70)

- **B**: sitio Pro con 25 % menos = $15 mensual ($150 anual); Starter = $5,25 ($52,50 anual). Con cuota de $29, la agencia empieza a ahorrar desde unos 6 sitios Pro. Con 10 sitios Pro paga $179 en vez de $200. Con 5 sitios paga más que el precio normal: por eso la cuota debe comprar valor (propietarios cliente, marca, soporte), no solo descuento.
- **C**: 10 sitios Pro al precio de 5 = $1.000 anual ($100 por sitio, $8,33 al mes). Al precio de 6 = $1.200; de 7 = $1.400. Extras a 30 % menos = $140 anual por sitio Pro ($49 si es Starter).
- Escalera de precio unitario Pro anual: normal $200, B $150 más cuota, extra de C $140, paquete de C $100 a $140. Más compromiso, menor precio unitario.
- **Sin pérdida** mientras el costo de infraestructura por sitio activo sea bajo (almacenamiento R2, base de datos, ancho de banda) y la comisión de Flow (3,50 % más S/ 0,80 por cobro) quede cubierta. El pago anual en un solo cobro paga menos comisión que el mensual. Falta medir el costo real por sitio.
- **Recomendación de negociación**: ofrecer C como "10 por 7" y dejar "10 por 5" como concesión de cierre. Platform debe mostrar un aviso cuando el precio pactado baje del piso (costo + comisión + soporte) y pedir confirmación.

### Decisiones de Edu (2026-10-12)

- Nombres confirmados: Premium Cuenta (A), Agencia Partner (B), Agencia Network (C). Cuota de B: $29 mensual o $290 anual.
- C se ofrece como **10 sitios por el precio de 7** ($1.400 anual con sitios Pro). Más abajo no sin autorización.
