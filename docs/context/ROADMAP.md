# Stamless — Roadmap y norte

> Última actualización: 2026-10-11 · Este archivo es **el norte**. Si contradice a `CURRENT_STATE.md` o `TASK.md`, se corrige uno de los dos.
> Detalle de pasarelas (T&C leídos): `PASARELAS.md`. Correo listo para Flow: `EMAIL_FLOW.md`.

## Norte

Un freelancer o agencia crea su cuenta solo, prueba **14 días sin tarjeta** y paga por **períodos prepagados**. Primero Perú; producto ES/EN/PT para América.

## Decisiones cerradas

| Tema | Decisión |
|---|---|
| Comprador inicial | Freelancers y agencias web |
| Alta | Registro abierto con Trial self-service, sin alta manual |
| Titular | Persona natural (RUC obtenido y registrado en Flow). Inversiones MASANI S.A.C. después. Titular y datos legales son **configuración**, no texto fijo |
| Trial | 14 días, sin tarjeta. Un trial por correo y dominio, correo verificado, límites del plan, solo lectura al vencer (30 días de retención, nunca borrar) |
| Pasarela | **Flow** principal (provisional hasta sus respuestas). dLocal Go segunda opción para internacional. Cobro manual en Platform como respaldo |
| Moneda | Perú: solo **PEN**, lista propia. Resto: solo **USD**. País de facturación elegido en el registro. Extranjeros ven conversión aproximada solo informativa |
| Cobro | Períodos prepagados (mensual/anual) que extienden `current_period_end`. Mismo precio por cualquier método. Cargo automático solo cuando Flow lo active |

## Decisión 2026-10-12: producto primero (multi-sitio) y reglas del día 14

- **Pausa** al lanzamiento comercial (landing, pasarela en producción): primero se termina Studio, API y Platform con **multi-sitio por cuenta**. Especificación en `MULTISITE.md`.
- Trial: 14 días (el material de planes que dice 15 está desactualizado).
- Día 14: el webhook de pago manda. Si paga al vencer o después, la cuenta se reactiva sola; el período pagado empieza al momento del pago.
- Al vencer: solo lectura. La API sigue sirviendo **7 días de gracia**; luego se pausa la API y el contenido se conserva **90 días**. Nunca se borra antes.
- Persuasión sin trucos: resumen día 7, precio en su moneda día 12, aviso día 14, contenido intacto día 15, correo personal día 21, una extensión única de 7 días si ya publicó algo. Incentivo real: anual con 2 meses gratis.

## Puertas de lanzamiento

| ID | Puerta | Contenido | Estado |
|---|---|---|---|
| G0 | Multi-sitio por cuenta | Pivote `tenant_user`, selector de sitio, creación de sitios por plan, Platform por propietario. Cuenta de facturación y modalidades Premium A/B/C. Ver `MULTISITE.md` | Pending |
| G0b | Multi-idioma de contenido por sitio | Idiomas por sitio, grupo de traducción, listado agrupado con banderas, API por encabezado `lang`. Ver `I18N.md` | Pending |
| G1 | Registro self-service | Tenant, usuario, slug/dominio, datos iniciales, verificación de correo, anti-abuso | Pending |
| G2 | Ciclo del Trial | Cron de expiración, avisos día 7, 12 y 14, solo lectura | Pending |
| G3 | Cobro | Interfaz `PaymentGateway` + driver Flow, tabla de precios por plan/moneda/período, webhook, respaldo manual | Pending |
| G4 | Legal y confianza | Precios en S/ y USD, términos, privacidad (Ley 29733), reembolsos, página de seguridad | Pending |
| G5 | Onboarding | Starter kit Astro (base cica360), checklist del primer sitio, docs de la API | Pending |
| G6 | Operación | Métricas del embudo, soporte, backups, monitoreo | Pending |

## Qué se puede hacer ya y qué espera

**Construible ya, sin esperar a nadie (sandbox de Flow):**

- G1 y G2 completas (no tocan la pasarela).
- G3 técnica: `PaymentGateway`, driver Flow contra `sandbox.flow.cl` (`payment/create`, `urlConfirmation`, `payment/getStatus`), esquema de precios por moneda, snapshot de tipo de cambio, tests con claves sandbox.
- G4 y G5 en borrador.

**Espera a Flow (respuestas del correo):** activar tarjetas y cargo automático en producción, PEN/USD vía API, tarjetas internacionales, política de contracargos, aprobación de "intercambio de archivos". Hasta entonces el sandbox sirve para validar que Flow nos conviene; el pase a producción es lo único que se frena.

**Espera al contador:** régimen tributario, tipo de comprobante, IGV y trato de clientes extranjeros. No frena el desarrollo; sí frena el primer cobro real.

El primer cobro real ocurre recién al día 14 de un Trial, así que hay margen para tener todo listo.

## Siguientes pasos

1. Enviar `EMAIL_FLOW.md` (Edu).
2. Commitear el trabajo pendiente del 6 al 11 de octubre y cerrar el logo del mailing (`migrate`, `pint`, tests).
3. Correr `python3 docs/playbooks/restructure-docs.py` (simulación y luego `--apply`) y commitear.
4. ADR de registro + Trial (G1/G2) y ADR de pagos y moneda (G3); construir G1/G2 y luego G3 en sandbox.
5. Consulta con contador (régimen, comprobantes, IGV) (Edu).
6. Alinear `PLANS.md` a Trial de 14 días.

## Abierto

- BeeSync (propio, Cliente 0 de Edu): API REST propia + WebSocket nativo, **no** compatible con Pusher/Reverb. Se integra con un `NotificationChannel` de Laravel (HTTP POST) y un script WSS en el navegador. Pendiente de definir: llave pública de suscripción separada de la llave secreta, y canal por tenant/usuario. Segundo canal de notificaciones tras el correo.
- Si Flow no cobra USD: el internacional pasa a dLocal Go (preguntas en `PASARELAS.md`).
