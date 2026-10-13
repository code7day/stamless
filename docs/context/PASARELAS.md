# Stamless — Pasarelas de pago: lectura de T&C

> Referencia. Decisión y estado viven en `ROADMAP.md`. Lectura del 2026-10-11.

## Flow (Perú): lectura del 2026-10-11

Flow es la pasarela principal para Perú; dLocal Go queda como segunda opción para cobro internacional. Se confirma con las respuestas de Flow. Cuenta "Stamless CMS Headless" ya creada.

| Tema | Qué dice | Implicancia |
|---|---|---|
| Entidad y ley | En Perú presta el servicio Flow Pagos Perú S.A.C. (RUC 20605041168), ley peruana, tribunales de Lima (Anexo III) | Sin jurisdicción extranjera |
| Abono | Tercer día hábil según la tarifa del panel; puede retener o diferir por contracargos, KYC o riesgo (6.3) | Flujo de caja rápido; sin reserva fija publicada |
| Comisiones (panel) | QR interoperable 2,59 %; tarjetas y Yape 3,50 % + S/ 0,80; PagoEfectivo 3,90 % + S/ 0,80; IGV 18 % sobre la comisión; reembolso S/ 14 | Con precios bajos el fijo pesa: promover anual y QR |
| Contracargos (7) | Retiene fondos, descuenta de liquidaciones y cobra directo si no alcanza; reiterados llevan a suspensión; sin umbral numérico en el texto general | Recibo claro, aviso previo a renovar, cancelación en un clic |
| Cargo automático | T&C especiales: exige autorización expresa del pagador y respaldo; contracargos sin protección de las marcas; Flow puede suspender | Se activa por el área comercial; hasta entonces, prepago |
| Notificación de reclamo (18.3) | 24 h para responder; si no, suspende la cuenta y entrega tus datos al pagador | Casilla de soporte vigilada; respuesta interna en menos de 12 h |
| Tarifas (9.2) | Flow puede cambiarlas con 10 días de aviso; el silencio es aceptación | Vigilar el correo de la cuenta |
| Reembolsos (8) | Salen de fondos aún no abonados al banco; si no hay saldo, el comercio aporta | Reembolso como excepción; el Trial es la garantía |
| Tributario (Anexo III, 6) | El usuario debe mantener su inicio de actividades en SUNAT y emitir comprobantes | RUC de persona natural ya obtenido y registrado en Flow (2026-10-11). Falta definir régimen tributario y comprobantes con contador |
| Anexo I | "Intercambio de archivos" requiere pre-aprobación; prácticas de mercadeo engañosas prohibidas | Enviar a compliance la descripción del producto; renovación transparente |
| KYC (4.3) | Puede exigirse en cualquier momento y retener abonos | Documentos al día |
| Tarjetas (10) | Hay que aceptar todas las tarjetas y no se permite recargo | Mismo precio por método |

API verificada en el OpenAPI v3.0.1: REST con apiKey y firma HMAC-SHA256 de los parámetros ordenados; sandbox en `sandbox.flow.cl`; `payment/create` devuelve url y token; Flow confirma por POST a `urlConfirmation` y se consulta `payment/getStatus`; hay planes (mensual o anual, `trial_period_days`), `customer/register` (tarjeta), `subscription/create`, `refund/create` y liquidaciones. La documentación incluye tarjetas de prueba de Perú para pagos recurrentes. La moneda por defecto es CLP: confirmar PEN en planes y suscripciones.

`.env` local ya tiene `FLOW_API_KEY`, `FLOW_SECRET_KEY` y `FLOW_SITE_URL` (apunta a sandbox). Claves de producción solo en el servidor.

## dLocal Go: T&C leídos el 2026-10-11 y su implicancia

| Cláusula | Qué dice | Implicancia |
|----------|----------|-------------|
| 3.3 KYC | Obligatorio; sin KYC no se reciben fondos; a los 30 días de abrir la cuenta sin KYC se desactiva | Abrir la cuenta solo con los documentos listos (DNI, domicilio, cuenta bancaria, documento tributario) |
| 9 Impuestos | Hay que cargar documentación tributaria emitida por la autoridad (ID tributario), como declaración jurada | Es probable que exijan RUC también a una persona natural. Confirmar |
| 4 y 19 | La cuenta es personal, no transferible, y no se cede sin consentimiento de dLocal | Pasar a la S.A.C. implica cuenta y KYC nuevos. Mantener la capa de pago desacoplada |
| 7 Pagos | Depósito del 5% (hasta 10%) de lo procesado; fondos disponibles unos 14 días después; reservas a criterio de dLocal | Flujo de caja lento. Preguntar cuándo se libera el depósito |
| 5 y 7 Contracargos | El merchant los reembolsa con costos; más de 1,5% en 40 días con tarjeta puede bloquear la cuenta | Períodos prepagados, recibo por correo, descriptor claro, política de reembolso, sin cobros sorpresa |
| Anexo 1 | Prohíbe tácticas engañosas: evadir cancelaciones o reembolsos, opciones preseleccionadas, renovación mal divulgada, reseñas falsas. "Almacenamiento en la nube / cyber lockers" es Restringido | Persuasión solo honesta. Declarar la actividad como CMS/SaaS y pedir confirmación de que la biblioteca de medios no cuenta como almacenamiento |
| 5 Recargos | No se puede cobrar extra por pagar con dLocal sin su permiso | Mismo precio para todos los métodos |
| 13 Vigencia | 24 meses con renovación automática de 24; salida sin causa con 60 días de aviso | Aceptable si se anota la fecha de aviso |
| 15 y 18 | Responsabilidad de dLocal limitada al menor entre las comisiones de 12 meses y USD 5.000; ley del Reino Unido, tribunales de Londres, comunicaciones en inglés | Riesgo conocido, no bloquea |
| 8 Comisiones | Van en una lista de precios aparte (no vista); cada medio de pago nuevo exige T&C propios | Pedir la lista. Yape/Plin y cobro recurrente no aparecen en los T&C: confirmar |
| 11 Datos | dLocal es encargado de los datos de los compradores del merchant | Mencionarlo en la política de privacidad |
| Aviso de privacidad (versión de jun 2022) | dLocal recoge del merchant identidad, ID tributario, cuenta bancaria y **datos biométricos** (KYC); los datos de pago del comprador los recibe dLocal directamente con SmartFields/Redirect; transfiere datos fuera del Reino Unido; los derechos se ejercen solo por su formulario | Usar su checkout alojado (Redirect/SmartFields) para que los datos de tarjeta no pasen por Stamless; confirmarlo. La política de Stamless debe nombrar a dLocal y las transferencias internacionales |

Nota: el texto habla de "Dlocal Direct"; confirmar que aplica a Dlocal Go.

## Preguntas abiertas para el soporte de dLocal Go

1. ¿Aceptan persona natural en Perú, con o sin RUC? ¿Qué documento tributario piden?
2. ¿Qué métodos locales quedan activos en Perú (Yape, Plin, tarjetas) y con qué T&C?
3. ¿Hay cobro recurrente o tokenización de tarjeta? ¿Hay API de pagos y webhooks?
4. ¿Cuáles son las comisiones y cuándo se libera el depósito del 5%?
5. ¿En qué moneda y a qué cuenta liquidan a una persona natural?
6. ¿Un CMS/SaaS con biblioteca de medios entra en "almacenamiento en la nube"?
