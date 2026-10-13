# Stamless — Infraestructura y costos (referencia)

> 2026-10-12. Cifras de proveedores verificadas en la web ese día; precios de terceros cambian, revisar antes de decidir.

## Situación

- VPS actual (Elastika, Perú): 4 GB de RAM, 500 Mbps, 60 GB de disco.
- Renovación en julio de 2027 propuesta por el proveedor: plan 8 GB, S/ 1.447 anual + IGV = S/ 1.747 (pago único). Mensual en 12 cuotas: S/ 201 (S/ 2.412 al año). Incluye 1 Gbps y 1 backup sin persistencia.
- Tipo de cambio SUNAT del 7 de octubre de 2026: S/ 3,437 por dólar.

## Lectura de costos

- 8 GB en Elastika: S/ 1.747 anual = S/ 146 mensual, unos **US$ 42 al mes** con IGV (US$ 36 sin IGV, si se puede usar como crédito fiscal; consultar al contador).
- Referencias en dólares (mercado 2026): Hetzner subió precios en 2026; los planes compartidos de 8 GB aparecen en unos €35 al mes y los más baratos figuran sin disponibilidad. DigitalOcean y Vultr, 8 GB, unos US$ 60 al mes. El precio de Elastika está en línea con el mercado, no por encima.
- El costo de infraestructura **por sitio** es pequeño: con US$ 42 de VPS, cada sitio cuesta US$ 4,2 (10 sitios), US$ 0,84 (50) o US$ 0,21 (200). Los medios van a R2 (unos US$ 0,015 por GB al mes).
- El VPS lo cubren 6 suscripciones Starter (US$ 7). La comisión de Flow (3,5 % más S/ 0,80) y el soporte pesan más que la infraestructura.

## Decisión

- No pasar a 8 GB por calendario: pasar cuando las métricas lo pidan. Antes de prepagar un año completo, preguntar al proveedor si el cambio de plan a mitad de período es prorrateado.
- Umbrales para escalar: RAM sostenida sobre 75 %, uso de swap, o p95 de la API sin caché sobre 500 ms.
- Orden de escala: 1) caché de Cloudflare delante de la API con purga al publicar; 2) VPS de 4 a 8 GB; 3) base de datos en servidor aparte o administrada; 4) varios servidores de aplicación.
- Mantener el despliegue reproducible (contenedores o script) para poder cambiar de proveedor sin rehacer todo.

## Medir hoy en el VPS

`free -m`, `df -h`, `uptime`, `ps aux --sort=-%mem | head`, tamaño de la base (`pg_database_size`), conexiones activas de PostgreSQL y procesos de PHP-FPM. Con eso se decide si 4 GB alcanza.

## Medición del 2026-10-12 (VPS actual, 4 GB, 2 vCPU)

- Memoria: 3.819 MB en total, 791 MB usados, **3.027 MB disponibles**. Swap: 0,7 MB usados de 2 GB.
- CPU: carga promedio 0,09 / 0,03 / 0,01 tras 81 días encendido; los dos núcleos casi en cero.
- Disco: 5,7 GB usados de 79 GB (8 %).
- Servicios en el VPS: Nginx, PHP-FPM 8.4, PostgreSQL 15, Redis, Supervisor, dos colas de Laravel (Stamless y BeeSync), fail2ban.
- Lectura: es una foto de reposo, no una prueba de carga. Con este uso el servidor actual sobra; no hay razón técnica para pasar a 8 GB. Falta medir picos (varios usuarios en Studio, ráfagas de cola, despliegues) con monitoreo de 2 a 4 semanas y una prueba de carga de la API antes del lanzamiento.
- Pendiente de mantenimiento: 81 días sin reiniciar; programar un reinicio para aplicar actualizaciones del kernel.
- Para la renovación: revisar en el carrito del proveedor el precio de la pestaña de 4 GB y comparar contra 8 GB antes de decidir.
