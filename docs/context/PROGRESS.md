# Genesis CMS — Historial de progreso

> Log cronológico de avances. Entradas nuevas **arriba** (más reciente primero).
> Formato sugerido por entrada:
>
> ```
> ## YYYY-MM-DD — Título corto
> - **Agente/autor:** ...
> - **Qué se hizo:** ...
> - **Archivos/áreas:** ...
> - **Siguiente:** ...
> ```

## 2026-09-01 — Segundo bug de la misma familia, confirmado y corregido: `Slider` (opacidad/brillo/filtros) también se corrompía al guardar (ver adenda ADR-037)
- **Agente/autor:** Claude
- **Qué se hizo:** Después del fix de `MediaUpload` (entrada de abajo), el Tech Lead reprodujo el guardado sin cambios en Home y, con el inspector del navegador, mostró que el `<img>` del bloque `split` tenía el `src` CORRECTO pero un `style` inline que lo hacía invisible: `filter: brightness(0%) saturate(0%) grayscale(0%) ... blur(0px); opacity: 0`. Nuevo bug, mismo patrón de fondo: `Forms\Components\Slider` (opacidad/brillo/saturación/contraste — usado por `PropertiesSchema` y el bloque `heading`) tiene su propio state cast (`SliderStateCast::get()`, `floatval($state)`). Como el seeder nunca escribe estas propiedades explícitamente, su valor crudo es `null` al momento de guardar, y `floatval(null)` = `0.0` — pisando el default real (`100` para brillo/opacidad/saturación/contraste) con `0`, sin importar que el slider esté configurado con `->default(100)` en Filament (ese default solo aplica al crear un registro nuevo desde cero, no al hidratar datos parciales vía `loadStateFromRelationshipsUsing`). Confirmó también que el problema se agravaba por MAMP Pro sirviendo código cacheado (opcache) — el log no mostraba requests nuevos hasta reiniciar los servidores. Fix: mapa exhaustivo `PageResource::SLIDER_PROPERTY_DEFAULTS` (cada `Slider::make('properties.X')` de la app con su default real: 100 para brillo/opacidad/saturación/contraste, 0 para escala de grises/sepia/rotación de matiz/desenfoque/overlay) + helper `backfillSliderDefaults()`, aplicado tanto al cargar (`loadStateFromRelationshipsUsing`) como al guardar (`saveRelationshipsUsing`, red de seguridad). **Verificado con datos reales**: log del guardado de confirmación del Tech Lead mostró `content.media_id` como escalar (`"4"`/`"5"`) y `properties.media_opacity`/`media_brightness`/`media_filter_saturate`/`contrast`/`brightness` en `100` — ambos fixes funcionando juntos. Mensaje final del Tech Lead: "quedó". Se retiraron los 2 `Log::info()` de diagnóstico temporal usados durante la investigación.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`SLIDER_PROPERTY_DEFAULTS`, `backfillSliderDefaults()`, aplicado en `loadStateFromRelationshipsUsing` y `saveRelationshipsUsing`; logs de diagnóstico retirados), `docs/context/DECISIONS.md` (adenda a ADR-037).
- **Verificación:** Balance de paréntesis/llaves/corchetes (`php_balance.py`) tras cada edición — cuadra (711/711, 33/33, 138/138 en el estado final). Causa y fix confirmados con datos reales del log de producción local del Tech Lead, no solo lectura de código — igual que el bug de `MediaUpload`, esto tiene evidencia directa, no es una red de seguridad especulativa.
- **Siguiente:** Ninguno urgente — el Tech Lead confirmó que quedó resuelto. Si en el futuro se agrega un `Slider::make('properties.X')` nuevo en cualquier Resource, hay que sumarlo a `PageResource::SLIDER_PROPERTY_DEFAULTS` con su default real para no reabrir este bug — vale la pena anotarlo como convención si se formaliza `.ai/rules` más adelante.

## 2026-09-01 — Fix de causa raíz confirmada: `saveRelationshipsUsing` corrompía campos `MediaUpload` al guardar (ver ADR-037)
- **Agente/autor:** Claude
- **Qué se hizo:** Se confirmó (no solo se blindó) la causa exacta del reporte crítico del Tech Lead: guardar una página en Studio (incluso sin cambios) corrompía los campos `MediaUpload`/`FileUpload` de sus bloques (imagen del `split`, `media_id` de cada item de `logos`, etc.). Diagnóstico con evidencia real: se agregó un `Log::info()` temporal en `saveRelationshipsUsing()` que registró el valor exacto de `content.media_id` en un guardado real reproducido por el Tech Lead — `{"537a6e80-33e7-4085-8325-5b5a563fcd60":"4"}` (un array, no el escalar `"4"` esperado). Causa: el `$state` que llega a ese closure trae los campos `MediaUpload` en su forma interna cruda (keyeada por el UUID que Livewire usa para identificar el archivo dentro del widget) porque el cast que normalmente los limpia (`FileUploadStateCast::get()`) es parte del camino de deshidratación nativo de Filament (`->relationship()`), que este Builder no usa — usa `saveRelationshipsUsing` manual porque `Block` necesita lógica propia de creación/actualización/borrado con `tenant_id`/`sort_order`/tipo. El array corrupto se guardaba tal cual en el jsonb; `ResolvesPublicLinks` (ya blindado en fixes anteriores del mismo día) lo descarta por no ser escalar → la imagen se resuelve a `null` y desaparece del sitio público — pero en Studio el widget FileUpload la sigue mostrando bien, porque esa forma corrupta sigue siendo "un archivo cargado válido" para el propio widget (confirmado con capturas del Tech Lead: la miniatura se veía perfecta en el modal de edición, descartando un problema de hidratación). El bug pasó desapercibido toda la sesión porque el contenido sembrado por `db:seed` nunca pasa por este código — recién se manifestó la primera vez que se guardó una página real desde Studio, que coincidió con el trabajo sobre el bloque de partners/logos (de ahí la sospecha inicial del Tech Lead de que "modificar ese bloque" rompió algo — en realidad el bug es genérico a cualquier guardado con `MediaUpload`, y como se resguardan TODOS los bloques de la página junto, un solo guardado de Home corrompió `split` y `logos` a la vez). Fix: helper nuevo `PageResource::unwrapFileUploadState()` (recursivo, detecta la firma exacta por regex de UUID para no tocar por error objetos legítimos de una sola propiedad como `properties.background_color`), aplicado a `content`/`properties`/`links` de cada bloque antes de guardar. Efecto colateral bueno: como la corrupción tiene la misma forma que el fix sabe normalizar, el próximo guardado de una fila ya corrupta la autorepara. Se retiró el `Log::info()` de diagnóstico una vez confirmada la causa. El guard de "estado vacío" agregado horas antes (ver entrada de abajo) se mantiene, es una protección distinta y sigue siendo válida.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (nuevo `unwrapFileUploadState()`, aplicado en `saveRelationshipsUsing`; log de diagnóstico agregado y luego retirado), `docs/context/DECISIONS.md` (ADR-037), `docs/context/CURRENT_STATE.md`, `docs/context/TASK.md`.
- **Verificación:** Balance de paréntesis/llaves/corchetes (`php_balance.py`, comment/string-aware) en `PageResource.php` tras cada edición — cuadra (707/707, 32/32, 136/136 en el estado final). Causa raíz confirmada con datos reales del log de producción local del Tech Lead (`storage/logs/laravel.log`), no solo por lectura de código — a diferencia de los fixes anteriores del día, este SÍ tiene evidencia directa del bug, no es una red de seguridad especulativa. Sin PHP en este sandbox, no se pudo ejecutar el fix — pendiente de confirmación real por el Tech Lead.
- **Siguiente:** El Tech Lead debe: (1) correr `php artisan db:seed` (o al menos `Cliente0ContentSeeder`) para partir de datos limpios en los bloques que quedaron corruptos durante la reproducción de hoy; (2) abrir Home en Studio, guardar sin cambios, y confirmar en el sitio público que la imagen del bloque `split` y los logos de `partners` sobreviven; (3) si algo sigue roto después de esto, ya no es este bug — es un problema nuevo y hay que diagnosticar de cero. Pendiente de más largo plazo, sin urgencia: el dato legado corrupto original (ids no-escalares, `total:24 scalar:12` de los fixes de `ResolvesPublicLinks`) nunca se identificó a nivel de fila — sigue solo blindado, no limpiado, en la DB real.

## 2026-09-01 — Fix real: 500 en `GET /pages/home` — `ResolvesPublicLinks` blindado contra ids no-escalares
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó el sitio `cica360` mostrando la pantalla de error de Astro para `ApiError` — mensaje "Error interno. Intentá de nuevo." (el fijo que devuelve `bootstrap/app.php` para cualquier 500). `storage/app/../storage/logs/laravel.log` (leído directo, este sandbox no tiene PHP pero SÍ acceso de archivo al log real de la app en la Mac del Tech Lead) tenía la causa exacta: `ErrorException: Array to string conversion at app/Http/Concerns/ResolvesPublicLinks.php:225`, dentro de `array_unique($mediaIds)` en `attachResolvedBlockContent()`, disparado por `PageController::show('cica360', 'home')` — 9 ocurrencias, todas hoy ~05:20, todas para la página `home`. `array_unique()` tira ese error cuando alguno de los elementos del array es a su vez un array (PHP intenta castearlo a string para comparar). Repasé el seeder (`Cliente0ContentSeeder`) — todos los `media_id`/`page_id`/`slider_id` que siembra son escalares (`Cliente0MediaSeeder::mediaId()` devuelve `?int`) — así que el dato corrupto no viene de ahí; es contenido YA guardado en la DB real (probablemente un bloque viejo con una forma de campo distinta a la actual, o un dato tocado a mano en Studio) al que no tengo acceso desde este sandbox (sin conexión a Postgres del Tech Lead) para identificar con precisión cuál. Decisión: en vez de perseguir el dato puntual sin poder verlo, blindé el método — nuevo helper `uniqueScalarIds()` filtra cualquier valor no-escalar antes de `array_unique()` (con `array_filter(..., 'is_scalar')`) y logea un `Log::warning()` cuando descarta algo, para dejar rastro sin romper el response completo. Aplicado a los 5 lugares del trait que arman ids para `whereIn()`: `mediaIds`/`pageIds`/`sliderIds` en `attachResolvedBlockContent()` y `pageIds`/`postIds` en `attachResolvedLinks()` (mismo riesgo, no había reportado error ahí todavía pero es el mismo patrón).
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (import de `Log`, helper `uniqueScalarIds()` nuevo, los 5 `array_unique()` reemplazados por el helper).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware (`/tmp/php_balance.py`) — cuadra. Sin PHP interpreter en este sandbox — no se pudo reproducir el 500 real ni confirmar que desaparece. Grep sobre el seeder confirma que ningún `_id` que siembra es un array.
- **Siguiente:** ver entrada siguiente — el primer fix destapó un segundo síntoma del mismo dato corrupto.

## 2026-09-01 — Segunda vuelta del fix anterior: `resolveMediaRef()` también necesitaba el guard
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reinició `npm run dev` de `cica360` para probar el fix de arriba y compartió la terminal: el `ErrorException: Array to string conversion` original ya NO aparece (confirmado en `storage/logs/laravel.log`, sin nuevas ocurrencias después de las 05:20:36), pero apareció un error nuevo, mismo minuto de la prueba (~05:25): `TypeError: array_key_exists(): Argument #1 ($key) must be a valid array offset type at vendor/.../Collection.php:496`. Mismo dato corrupto, síntoma distinto: `uniqueScalarIds()` ya protege el `whereIn()` (arma la Collection `$media` bien), pero `resolveMediaRef($mediaId, $media)` seguía pasando el id CRUDO (el array corrupto) directo a `$media->get($mediaId)` — `Collection::get()` hace `array_key_exists($key, ...)` por debajo, que no tolera `$key` array. Mismo patrón en `transformPublicLink()` (`$pages->get($link['source_id'])`, `$posts->get(...)`), en `transformBlockContent()` para `hero` modo slider (`$sliders->get($content['slider_id'])`) y para `services_grid` (`$pages->get($item['page_id'])`) — los 5 lugares del trait que hacen un lookup puntual (no un `whereIn()` masivo) tenían el mismo riesgo. Fix: helper nuevo `scalarOrNull()` (`is_scalar($value) ? $value : null`) envolviendo el valor crudo en los 5 `->get()`. Confirmado que `Collection::get()` maneja `null` de forma segura (`$key ??= ''`) — no hace falta el chequeo `$mediaId ? ... : null` que reemplazó.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (helper `scalarOrNull()` nuevo, 5 `->get()` envueltos: `transformPublicLink` ×2, `transformBlockContent` ×2 — slider y services_grid page, `resolveMediaRef` ×1).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware — cuadra. Sin PHP interpreter en este sandbox — no se pudo correr, pero el diagnóstico esta vez vino de un log real posterior al fix anterior (confirmando que el primer fix funcionó y aisló el segundo síntoma), no de una suposición.
- **Siguiente:** ver las 2 entradas siguientes — el segundo fix hizo que el backend devolviera 200 por primera vez, y destapó un tercer y cuarto síntoma del mismo dato legado, ya del lado del contrato de la API (arrays que salían como objeto JS).

## 2026-09-01 — Tercera vuelta: `content.items` podía salir como objeto JS en vez de array (keys no-secuenciales)
- **Agente/autor:** Claude
- **Qué se hizo:** con los dos fixes anteriores, `GET /pages/home` devolvió **200** por primera vez en esta sesión de debugging (terminal del Tech Lead: `00:29:07 [200] / 602ms`). Un segundo después: `(content.items ?? []).filter is not a function` en `Logos.astro:47`. Causa: un array PHP con keys no-secuenciales o no-enteras se serializa como objeto JS (`{}`), no array (`[]`) — el `content.items` guardado en DB para algún bloque (mismo dato legado de los 2 fixes anteriores) tiene esa forma, y `array_map()` (que preserva keys) la propaga tal cual al JSON de respuesta. Fix backend: `array_values()` envolviendo el `array_map()` de `ITEMS_MEDIA_FIELD` (features/logos/services_grid) y el de `services_grid`, más una red de seguridad general al final de `transformBlockContent()` — cualquier bloque con `content.items` (incluido `faq`, que este trait nunca transforma puntualmente y antes pasaba sin tocar) sale con `array_values()` aplicado sí o sí. Fix frontend en paralelo (defensa en profundidad, independiente del backend): `Logos.astro` cambia `content.items ?? []` por `Array.isArray(content.items) ? content.items : []`.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (genesis: 2 `array_map()` envueltos en `array_values()` + guard general nuevo antes del `return $content`). `src/components/blocks/Logos.astro` (cica360: `Array.isArray()` guard).
- **Verificación:** balance PHP (comment-aware) y balance+tag-stack Astro (`/tmp/astro_check.py`) — cuadran los dos archivos. Diagnóstico de nuevo desde una terminal real del Tech Lead, no una suposición.
- **Siguiente:** ver entrada siguiente — el mismo Tech Lead reportó, en el mismo intento, un cuarto síntoma equivalente pero en `block.links`.

## 2026-09-01 — Cuarta vuelta: `block.links` tenía el mismo problema (`Collection::map()` sin `->values()`)
- **Agente/autor:** Claude
- **Qué se hizo:** screenshot del Tech Lead, mismo patrón: `block.links.map is not a function` en `Cta.astro:22`. Mismo bug que el de `content.items`, pero en `attachResolvedLinks()`: `collect($record->links ?? [])->map(fn (...) => ...)->all()` — `Collection::map()` también preserva las keys originales, así que si `$record->links` tenía keys no-secuenciales, el `->all()` final devolvía un array PHP con esas keys, serializado como objeto JS. Fix: `->values()` antes de `->all()`.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (`attachResolvedLinks()`, un `->values()` agregado).
- **Verificación:** balance PHP comment-aware — cuadra.
- **Siguiente:** ver entrada siguiente — con la home ya sin 500, apareció un bug real DISTINTO (no relacionado al dato legado): `content.body` nunca se convertía de JSON a HTML.

## 2026-09-01 — Red de seguridad: `saveRelationshipsUsing` de `blocks` ya no puede borrar todos los bloques por un estado vacío
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó algo serio: "si intento editar, pero no cambio nada, solo doy clic en guardar, o con cualquier cambio en Pages, se daña el json o borra el jsonb". Investigué el log real (`storage/logs/laravel.log`) — el `Log::warning` de `uniqueScalarIds()` (fix de ids no-escalares, más arriba) sigue mostrando el MISMO conteo estable (`total:24, scalar:12`) en 8 requests distintos a lo largo de 12 minutos — descarta que el guardado esté generando MÁS corrupción de ids progresivamente. No pude confirmar el mecanismo EXACTO del daño (sin PHP/DB en este sandbox para reproducir un guardado real), pero encontré un patrón genuinamente peligroso en `saveRelationshipsUsing` del `Builder` de bloques: `$record->blocks()->whereNotIn('id', $existingBlockIds)->delete();` al final — si `$state` (el estado del Builder al momento de guardar) llega vacío por CUALQUIER motivo (glitch de Livewire, timing, lo que sea — no pude confirmar la causa raíz), `$existingBlockIds` queda `[]` y esa línea borra TODOS los bloques de la página, sin importar que el usuario no haya tocado nada. Un guardado nunca debería poder destruir contenido real de esta forma. Fix: guard al principio del closure — si `$state` viene vacío y la página YA tiene bloques guardados, se aborta el guardado de bloques (se dejan los existentes intactos) y se logea un `Log::warning()` con el id/slug de la página, en vez de proceder a borrar.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (import de `Log`, guard nuevo al inicio de `saveRelationshipsUsing` del Builder de bloques).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware — cuadra. Sin PHP interpreter en este sandbox — no se pudo reproducir el guardado real ni confirmar que este guard evita el problema reportado. **Esto es una red de seguridad, no necesariamente el fix completo** — protege contra el peor caso (pérdida total de bloques) pero no explica con certeza por qué `$state` llegaría vacío en primer lugar, ni descarta que el daño reportado sea algo más puntual (ej. un campo `content.body` específico llegando vacío en vez de la lista completa de bloques).
- **Siguiente:** el Tech Lead debe reproducir una vez más el guardado sin cambios y reportar: (1) si el problema desapareció por completo con este guard; (2) si sigue viendo algo, compartir qué campo/bloque específico queda dañado (ideal: el valor de `content` de un bloque en la DB, antes y después del guardado) y revisar `laravel.log` justo después del guardado por si aparece el nuevo warning `PageResource: saveRelationshipsUsing de "blocks" recibió estado vacío...` — eso confirmaría que el guard se activó (bloques salvados) y daría una pista real de la causa raíz para investigar a fondo.

## 2026-09-01 — Fix real: `content.body` salía como JSON TipTap crudo, nunca se convertía a HTML (`[object Object]` en pantalla)
- **Agente/autor:** Claude
- **Qué se hizo:** con la home ya devolviendo 200, el Tech Lead compartió un screenshot del sitio real: en vez del texto de cada bloque `rich_text` (y del cuerpo del Hero), la pantalla mostraba literalmente `[object Object]`. Causa, sin relación con los 4 fixes anteriores de esta sesión (ids/keys corruptos) — un bug real distinto, preexistente, que nunca se había visto porque la home nunca había renderizado completa hasta ahora: `Forms\Components\RichEditor::make('content.body')` (usado en `rich_text`, `split`, `legal_notice`, y `answer` de cada item de `faq`) en Filament 5 guarda el contenido como documento TipTap/ProseMirror — JSON estructurado (`{"type":"doc","content":[...]}`), no HTML — pero el frontend (`RichText.astro`, `Split.astro`) siempre esperó `content.body` como un string de HTML listo para `set:html`. Nunca hubo una conversión en el medio: `content.body` salía tal cual (el objeto JSON) en la respuesta de la API, y el navegador lo stringifica a `"[object Object]"` al asignarlo como HTML. Fix: helper nuevo `renderRichContent()` en `ResolvesPublicLinks`, usando `Filament\Forms\Components\RichEditor\RichContentRenderer` — el conversor OFICIAL de Filament (usa `ueberdosis/tiptap-php`, ya en `vendor/`, sin instalar nada nuevo) que convierte el JSON a HTML sanitizado (`Str::sanitizeHtml()`, protege contra XSS). Si el valor YA es un string (ej. `cta` usa `Forms\Components\Textarea::make('content.body')`, plano, no JSON), se devuelve tal cual sin tocar — el helper nunca reprocesa un string como si fuera JSON. Aplicado a `content.body` de CUALQUIER bloque (genérico, no por tipo) y a `items[].answer` de `faq` (mismo campo, mismo problema, un nivel más adentro).
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (import de `RichContentRenderer`, helper `renderRichContent()` nuevo, aplicado a `content.body` genérico y a `faq.items[].answer`).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware — cuadra. Sin PHP interpreter en este sandbox — no se pudo correr `RichContentRenderer` real ni confirmar el HTML de salida.
- **Siguiente:** el Tech Lead debe recargar la home y confirmar que "¿Qué hacemos?"/"¿A quién nos dirigimos?" (y cualquier otro `rich_text`/`split`/`legal_notice`/`faq`) muestran el texto real, no `[object Object]`. Revisar también que las negritas/listas del contenido (marks `bold`, nodos `bulletList`, etc.) se vean bien — el HTML que produce `RichContentRenderer` debería calzar con las clases `.richtext-body` ya definidas en `RichText.astro`/`Split.astro` (mismos tags `<p>`/`<strong>`/`<ul>`/`<li>` que ya se estilaban ahí), pero no se pudo confirmar visualmente desde este sandbox. Con esto, los 5 fixes de hoy sobre `ResolvesPublicLinks.php` deberían dejar la home (y el resto del sitio) completamente funcional.

## 2026-09-01 — Regla "Repeaters collapsed by default" aplicada de verdad (antes solo auditada)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead había pedido "los sections dentro de cualquier repeat tiene que ser collapsed by default" (sesión anterior); en ese momento audité el codebase buscando `Section::make()` anidado dentro de un `Repeater` y no encontré ninguno, así que reporté "nada que corregir". Eso fue una lectura incompleta de la intención real — el Tech Lead lo confirmó de nuevo señalando el propio bloque `logos` (captura: "Empresa asociada 1"/"Empresa asociada 2" con flecha de colapso, ambas expandidas por default): el problema no era `Section` dentro de `Repeater`, era que los **`Repeater` mismos** (`->collapsible()`) no traían `->collapsed()`, así que cada item se renderiza expandido la primera vez que se abre el formulario — exactamente lo que la regla quería evitar. Corregido en TODOS los `Repeater`s de la app que tenían `->collapsible()` sin `->collapsed()`: bloque `logos` (`PageResource.php`, el que motivó el reporte), `features` (Características), `faq` (Preguntas Frecuentes), `services_grid`/Grid de servicios, `LinkSchema::make()` (compartido por CTA/Hero manual/testimonials/etc. — un solo fix cubre todos sus usos), y en `ServiceResource.php` los Repeaters de "¿Qué ofrecemos?" y "Coberturas". `MenuResource.php` y `SliderResource.php` ya cumplían (`->collapsed()`/`->collapsed(true)` ya estaban puestos) — no se tocaron.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (4 Repeaters: logos, features, faq, services_grid), `app/Filament/Schemas/LinkSchema.php` (1 Repeater compartido), `app/Filament/Resources/ServiceResource.php` (2 Repeaters: offers, coverages). Dejados sin tocar a propósito: `Section::make(...)->collapsible()` de nivel superior (no anidados dentro de un Repeater — ej. "Diseño de la sección" en Hero, "¿Por qué elegirnos?"/"Tip de ayuda" en Services) — la regla es sobre repeats, no sobre toda `Section` colapsable de la app.
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware (`/tmp/php_balance.py`) sobre los 3 archivos tocados — cuadra en los tres. Sin PHP interpreter en este sandbox — no se pudo confirmar visualmente en Studio.
- **Siguiente:** el Tech Lead debe confirmar en Studio que los Repeaters de `logos`/`features`/`faq`/`services_grid`/CTA-links/`ServiceResource` (offers/coverages) ahora arrancan colapsados al abrir el formulario. Esta regla queda como estándar para cualquier `Repeater` nuevo: siempre `->collapsible()->collapsed()` salvo pedido explícito en contra.

## 2026-09-01 — Fix real: 500 al editar/reordenar bloques de una página (`sort_order` recibía un UUID)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó un `Illuminate\Database\QueryException` (`SQLSTATE[22P02]: invalid input syntax for type integer`) al guardar Contenidos en `/cica360/pages` — Postgres rechazaba un UUID (`9b5abef3-...`) como valor de la columna `sort_order` (integer) de `blocks`. Causa real en `PageResource.php`, `saveRelationshipsUsing` del `Builder` de bloques: `foreach ($state as $index => $blockData) { ... 'sort_order' => $index ... }` — el `Builder` de Filament 5 (a diferencia de `Repeater`) keyea su array de estado por el ID interno de Livewire de cada item (un string tipo UUID), no por una posición secuencial. Mientras las keys "parecían" enteros pequeños (0,1,2 por casualidad del orden de creación) el bug quedaba invisible; en cuanto un bloque se reordenó/editó de forma que Livewire le asignó una key con forma de UUID real, `$index` dejó de ser castable a integer y Postgres lo rechazó de plano (antes probablemente fallaba silenciosamente como *string numérico* válido, o nunca se había dado el caso). Fix: `foreach (array_values($state) as $index => $blockData)` — reindexa a 0,1,2... preservando el orden real en pantalla, sin depender de las keys internas del componente.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (una línea, `saveRelationshipsUsing` del Builder de bloques).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware (`/tmp/php_balance.py`) — cuadra. Grep sobre el resto de `app/Filament/Resources/*.php` confirma que es el único lugar con este patrón (`MenuResource` y demás Repeaters no lo tienen — usan `orderColumn()` nativo de Filament, que sí maneja la posición correctamente por debajo). Sin PHP interpreter en este sandbox — no se pudo correr `php artisan migrate`/probar el guardado real.
- **Siguiente:** el Tech Lead debe confirmar en Studio que editar/reordenar/duplicar bloques de una página ya no tira el 500 — probar puntualmente reordenar por drag-and-drop (el caso que más probablemente generaba keys "no numéricas") y guardar.

## 2026-09-01 — Bloque `logos`: ampliado de 7 a 10 placeholders para probar el carousel real
- **Agente/autor:** Claude
- **Qué se hizo:** pedido explícito del Tech Lead ("generar 10 logos de partners de ejemplo en seeder"). Con exactamente 7 items el bloque `logos` de la home solo mostraba la grilla estática de una página — el modo carousel de `Logos.astro` (paginado de a 7, flechas/dots/drag) nunca se probaba en la práctica. Se generaron 3 placeholders nuevos (`cica360_media_logo_8/9/10.png`, 128×128, transparente) con Python/Pillow, en el mismo estilo visual que los 7 existentes (badge blanco translúcido + glifo abstracto gris + subrayado, sin nombre de marca real — son placeholders, el Tech Lead los reemplaza cuando tenga logos reales de socios). `Cliente0MediaSeeder::FILES` gana `logo_8`/`logo_9`/`logo_10`; `Cliente0ContentSeeder` extiende `content.items[]` del bloque `logos` de la home de 7 a 10 entradas. Con 10 > 7, `Logos.astro` ahora sí pagina en 2 páginas (7 + 3) apenas se corra el seeder — primera vez que el modo carousel del bloque se ejercita con datos reales sembrados (antes solo se había verificado por revisión de código).
- **Archivos/áreas:** `storage/app/public/media/cica360_media_logo_8.png`, `_9.png`, `_10.png` (nuevos, commiteados). `database/seeders/Cliente0MediaSeeder.php` (3 entradas nuevas en `FILES`). `database/seeders/Cliente0ContentSeeder.php` (bloque `logos` de la home, 3 items nuevos en `content.items[]`).
- **Verificación:** balance de llaves/paréntesis/corchetes (comment/string-aware, `/tmp/php_balance.py`) sobre ambos seeders — cuadra en los dos. Sin PHP interpreter en este sandbox (`php -l` no disponible) — no se pudo correr lint real ni el seeder. `Repeater::maxItems(28)` de `PageResource.php` sigue sin tocarse — 10 está cómodo bajo el tope.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0MediaSeeder --class=Cliente0ContentSeeder` (o el flujo completo) para sembrar los 3 logos nuevos y confirmar visualmente en `/` que el carousel de "Empresas con las que trabajamos" ahora pagina (7 + 3) con flechas/dots/drag funcionando.

## 2026-08-31 — Bloque `logos`: 7 logos reales sembrados + filtro grayscale/opacidad + tope de 28 items
- **Agente/autor:** Claude
- **Qué se hizo:** el bloque `logos` ("Empresas con las que trabajamos") nunca mostraba nada en el sitio real — `Cliente0ContentSeeder` lo sembraba con 5 items placeholder con `media_id: null`, y el frontend descarta en silencio cualquier item sin media. El Tech Lead subió 7 archivos reales (`cica360_media_logo_1.png`...`_7.png`, ya commiteados) y pidió terminar la integración: (1) `Cliente0MediaSeeder` gana las 7 entradas nuevas (mismo patrón de 2 pasos ya establecido: archivo commiteado + `firstOrCreate` por path); (2) `Cliente0ContentSeeder` reemplaza los 5 placeholders `null` por los 7 `media_id` reales (vía `Cliente0MediaSeeder::mediaId()`), agrega `subtitle` (el bloque nunca lo había tenido) y `properties.media_filter_grayscale => 100`/`media_opacity => 60` (filtro por defecto). (3) Filtro configurable pedido: NO se crearon properties nuevas — `media_filter_grayscale`/`media_opacity` ya existían como properties genéricas reusables (mismo set agregado para `split` el mismo día), solo se sumaron al `PropertiesSchema::make([...])` del bloque `logos` en `PageResource.php`, ahora agrupadas en su propia Section colapsable "Personalización de estilos" a 2 columnas (antes eran 2 campos sueltos sin agrupar). (4) Pregunta del Tech Lead "cuánto brands como máximo listará el api": no hay un mecanismo de límite tipo `testimonials` acá (el bloque `logos` es un `Repeater` autocontenido en `content.items[]`, no una tabla resuelta en runtime) — se agregó `->maxItems(28)` al `Repeater` (4 páginas completas de 7, ver criterio "de 7 en 7" del frontend) como tope explícito de UX/consistencia, documentado en el código. (5) `->description()` nueva en la Section "Galería de Logos" explicando el comportamiento del frontend (7 columnas por página, carousel con más de 7) para que quede claro desde Studio sin tener que mirar el código del sitio.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `logos`: Section "Galería de Logos" con `description()` + `maxItems(28)`, nueva Section "Personalización de estilos" a 2 columnas), `database/seeders/Cliente0MediaSeeder.php` (7 logos nuevos), `database/seeders/Cliente0ContentSeeder.php` (bloque `logos` de la home: `media_id`s reales, `subtitle`, `properties`).
- **Verificación:** checker PHP-aware en Python confirma balance en los 3 archivos tocados. Sin migración nueva (properties genéricas dentro del jsonb `properties` ya existente).
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed` (o el flujo completo) para que los 7 logos y el subtítulo tomen efecto. El resto de la implementación (carousel paginado de a 7, filtro CSS con hover a color real) vive en `cica360` — ver su `PROGRESS.md`.

## 2026-08-31 — Bloque `testimonials`: property `item_background_opacity` + layout a 2 columnas en "Personalización de estilos" y en el enlace "Ver más"
- **Agente/autor:** Claude
- **Qué se hizo:** 3 ajustes de UX sobre el bloque `testimonials`, a pedido del Tech Lead con capturas del form real. (1) `Grid` de "Página superior/Estado/Fecha de publicación" en `PageResource` (Configuración de `pages`) — esto era sobre `pages`, no `testimonials`, ver nota abajo. (2) Los 4 campos del enlace "Ver más" (Estilo/Texto/Tipo de origen/Destino) pasan de `Grid::make(3)` a `Grid::make(2)`. (3) La Section "Personalización de estilos" del bloque `testimonials` pasa de 1 columna (todo apilado) a 2 (`->columns(2)` en el `Group` que devuelve `PropertiesSchema::make()`). (4) Nueva property `item_background_opacity` (`Forms\Components\Slider`, 0–100, default 30, mismo patrón que `overlay_opacity`) — el % de opacidad del fondo de cada tarjeta (`item_background_color`) ya NO está fijo en código (30%/50% hardcodeado en `Testimonials.astro`), ahora es configurable desde Studio; el hover sigue subiendo +20 puntos automáticamente (tope 100%), sin campo propio para eso — consumido del lado de `cica360` vía nuevas custom properties CSS `--item-bg-opacity`/`--item-bg-hover-opacity`.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (`item_background_opacity` nuevo), `app/Filament/Resources/PageResource.php` (bloque `testimonials`: Grid del enlace a 2 columnas, `Personalización de estilos` a 2 columnas + incluye la property nueva; y por separado, bloque `pages`: `parent_id`+`Estado`+`Fecha de publicación` unificados en un solo `Grid::make(3)`).
- **Verificación:** checker PHP-aware en Python confirma balance en ambos archivos.
- **Siguiente:** ninguno — no requiere migración (jsonb existente) ni re-seed (el default de 30% aplica solo con el helper `?? 30` del lado del frontend si la property no está seteada).

## 2026-08-31 — Bloque `testimonials`: property `item_background_color` + colores/subtítulo reales en el seeder (corrección "expectativa vs realidad" del Tech Lead)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead mandó 2 capturas (mockup vs. lo real en CICA360) señalando diferencias de diseño en la sección "Casos de éxito". Del lado de `genesis`, lo que hacía falta: (1) property nueva `item_background_color` (`App\Filament\Schemas\PropertiesSchema`, `ColorPicker`) — fondo de CADA tarjeta de testimonio, independiente del fondo de la sección (`background_color`, ya existía); agregada al schema de "Personalización de estilos" del bloque `testimonials` en `PageResource.php`. (2) Se confirmó que el resto de los pedidos YA estaban implementados del lado de `genesis` sin necesitar cambios: el admin ya podía personalizar `pretitle`/`title`/`subtitle` del bloque (`HeadingFieldset::make()` con sus defaults), el botón "Más casos de éxito" ya apuntaba a la página interna real (`Cliente0ContentSeeder::upsertHomePage()` linkea a `pages['casos-de-exito']`), y `ResolvesPublicLinks::transformBlockContent()` ya recortaba/ordenaba los testimonios según el `content.limit`/`content.order` propio de CADA bloque (no un límite global). (3) `Cliente0ContentSeeder` actualizado: `background_color` pasa de un teal ad-hoc (`#2b7c89`) a `cicagreen-500` real (`#206576`, tomado de `cica360/src/styles/global.css`), se agrega `item_background_color` = `cicagreen-400` (`#4D919E`) en los 2 bloques `testimonials` sembrados (home y la página `casos-de-exito`), y se agrega `subtitle` ("Conectamos conocimientos, potenciamos decisiones.") al bloque de la home — el subtítulo YA se renderizaba del lado del frontend, el gap real era que nunca se había sembrado. El resto del rediseño visual (avatares más grandes, tarjetas con fondo propio, cita sin comillas, firma en una línea, carousel con swipe táctil para más de 3 testimonios) vive del lado de `cica360` — ver su `PROGRESS.md`.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/PageResource.php` (bloque `testimonials`), `database/seeders/Cliente0ContentSeeder.php` (`upsertHomePage()`/`upsertCasosDeExitoPage()`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos tocados. Sin migración nueva — `item_background_color` es solo una clave nueva dentro del jsonb `properties` ya existente, no requiere `php artisan migrate`, solo re-sembrar contenido.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0ContentSeeder` (o el flujo de seed completo) para que los 2 bloques `testimonials` ya sembrados tomen los colores/subtítulo nuevos — es `updateOrCreate`, seguro re-ejecutarlo. Confirmar visualmente contra el mockup una vez corrido.

## 2026-08-31 — Árbol de `pages` hasta 3 niveles (`parent_id`), solo organización en Studio — ver ADR-036
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead reusando la UI de jerarquía de `MenuResource` (implementada más temprano el mismo día) como referencia: "como podemos hacer para armar arbol tree de navegacion hasta en 3 niveles, cuando una pagina tenga parent a otra pagina". Se preguntó explícitamente si el árbol debía anidar también la URL pública o quedarse solo como organización en Studio — el Tech Lead confirmó **"Solo organización (recomendado)"**. Implementado: (1) migración nueva `2026_08_31_000006_add_parent_id_to_pages_table.php` — `parent_id` autorreferenciado, nullable, `foreignId('parent_id')->constrained('pages')->nullOnDelete()`, índice compuesto `[tenant_id, parent_id]`; (2) `App\Models\Page` — `parent_id` agregado a `#[Fillable]`, relaciones nuevas `parent(): BelongsTo` / `children(): HasMany` (mismo patrón ya usado en `MenuItem`), método `depth(): int` (sube por `parent` hasta 2 veces, con guard defensivo); (3) `PageResource` — nuevo `Select::make('parent_id')` en el tab "Configuración" (justo después de `HeadingFieldset`), con `options()` que excluye la página misma (al editar), todos sus descendientes (vía nuevo helper privado `descendantPageIds()`, evita ciclos) y cualquier página que ya esté en profundidad 2 (evita un 4to nivel); `table()` ahora hace eager-load de `parent.parent` y ordena raíz-primero (`orderByRaw('parent_id IS NOT NULL')` + `orderBy('title')`), y la columna `title` indenta visualmente con `str_repeat('— ', $record->depth())` — se le quitó `->sortable()` para no romper ese orden agrupado con un clic de header.
- **Archivos/áreas:** `database/migrations/2026_08_31_000006_add_parent_id_to_pages_table.php` (nuevo), `app/Models/Page.php`, `app/Filament/Resources/PageResource.php`, `docs/context/DECISIONS.md` (ADR-036 nuevo).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos tocados (`Page.php`: 19/19 paren, 11/11 brace, 3/3 bracket; migración: 13/13 paren, 5/5 brace, 1/1 bracket; `PageResource.php` completo: 673/673 paren, 25/25 brace, 131/131 bracket). Sin cambios en `ResolvesPublicLinks`, la API pública, ni la unicidad de `slug` (`[tenant_id, lang_iso, slug]` intacta) — confirmado por revisión manual, ningún archivo de esos tocado.
- **Siguiente:** el Tech Lead debe correr `php artisan migrate` para aplicar la columna `parent_id` antes de que el campo funcione en Studio. Gap conocido, no resuelto acá: el `Toggle::make('is_home')` del FORM (`HeadingFieldset`) todavía no impide que dos páginas del mismo tenant queden marcadas como home a la vez (esa protección solo se agregó al toggle de la TABLA en una entrada anterior).

## 2026-08-31 — `SliderResource`: columna `Activo` clickeable tipo toggle, mismo ícono en los dos estados (mismo criterio que `is_home`)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead extendiendo el mismo criterio de `is_home` (PageResource) a "todas las columnas Activo" — se confirmó por grep que `SliderResource` es la ÚNICA columna de tabla en toda la app etiquetada "Activo" (`MenuResource` tiene el mismo label pero solo como campo de FORM dentro del Repeater anidado de items, no como columna de tabla). Mismo patrón: `->trueIcon()`/`->falseIcon()` al mismo heroicon de check, `->falseColor('gray')` en vez del `danger` por defecto, `->action()` colgado de la `IconColumn` para togglear `is_active` con un clic. A diferencia de `is_home`, acá NO hay regla de "uno solo a la vez" — cualquier cantidad de sliders puede estar activa simultáneamente, así que el toggle es un simple flip sin lógica adicional.
- **Archivos/áreas:** `app/Filament/Resources/SliderResource.php` (columna `is_active`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance. Grep confirma que no queda ninguna otra columna "Activo" sin actualizar.
- **Siguiente:** ninguno.

## 2026-08-31 — `PageResource`: columna `is_home` clickeable tipo toggle, mismo ícono en los dos estados
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — en vez de tilde verde / X roja para "Inicio" (`is_home`), mostrar el MISMO ícono de check en los dos estados (gris "apagado", verde "prendido") y que sea clickeable, activando la página como Home directo desde la tabla sin abrir el form. Se usó `Column::action()` (disponible en cualquier columna, no solo `ToggleColumn` — que además se ve como un switch, no como este ícono) colgado de la `IconColumn` existente: `->trueIcon()`/`->falseIcon()` al mismo heroicon, `->falseColor('gray')` en vez del `danger` por defecto de `->boolean()`. La acción del clic, además, hace cumplir la regla de negocio implícita de que solo puede haber 1 página Home por tenant a la vez (no estaba enforced en ningún lado antes — ni en el `Toggle` del form, ni a nivel de modelo/observer — así que antes de este cambio técnicamente se podían marcar 2+ páginas como Home sin que nada lo impidiera): al activar una, desactiva cualquier otra que ya estuviera marcada.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (columna `is_home`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance.
- **Siguiente:** el `Toggle::make('is_home')` del FORM (`HeadingFieldset.php`) sigue sin la misma protección de "solo 1 a la vez" — si el Tech Lead lo marca desde ahí en vez de la tabla, puede volver a duplicarse. Si hace falta, aplicar la misma regla ahí (ej. `afterStateUpdated` que desmarque las demás), no se tocó en esta vuelta por no ser lo pedido.

## 2026-08-31 — `PageResource`: título+slug+tipo fusionados en 1 columna
- **Agente/autor:** Claude
- **Qué se hizo:** mismo pedido de fusión ya aplicado a Servicios/Testimonios/Publicaciones, ahora en Páginas: "de igual forma, poner debajo del titulo: el slug de la pagina - Tipo de contenido". Se eliminaron las columnas separadas `slug` y `type` del listado; la columna `title` queda con el título en negrita arriba y `"{slug} - {Tipo}"` en gris debajo (vía `->description()`), resolviendo el label de `PageTypeEnum` con `$record->type?->getLabel()`.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`table()`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance.
- **Siguiente:** ninguno.

## 2026-08-31 — Fechas amigables en todos los listados + formato absoluto `d:m:Y h:i a` (extiende ADR-021)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — "en todos los listados la fechas que sean amigables para humanos y si hay que mostrar fecha que muestre d:m:Y h:i a". `App\Support\FriendlyDate` ya existía desde ADR-021 (relativo tipo "hace 5 min" para fechas de menos de 28 días, absoluto para el resto) pero solo se había aplicado a `ApiTokens`, y el formato absoluto era un shape hand-rolleado por idioma (`17 Ago 12:25 am`, con mapa de meses abreviados es/en/pt) — quedaba pendiente extenderlo al resto de Console (anotado explícitamente como pendiente en ADR-021 punto 3). Cambios: (1) `FriendlyDate::absolute()` reemplazado por el formato fijo pedido `d:m:Y h:i a` (numérico, sin depender del idioma) — se eliminó el mapa `MONTHS` que ya no hace falta, código más simple. (2) `PageResource`/`PostResource`: la columna `published_at` pasó de `->dateTime()` (timestamp crudo de Filament) a `->formatStateUsing(fn ($state) => FriendlyDate::format($state))`, mismo patrón que `ApiTokens`. Revisado el resto de los Resources de Filament (`TestimonialResource`, `ServiceResource`, `MenuResource`) — ninguno muestra una columna de fecha en su tabla (solo `ServiceResource` tiene `published_at` como campo de FORM, no de listado), así que con estos 2 cambios quedan cubiertas TODAS las columnas de fecha visibles en algún listado de Console.
- **Archivos/áreas:** `app/Support/FriendlyDate.php` (formato absoluto simplificado), `app/Filament/Resources/PageResource.php` y `PostResource.php` (columna `published_at`). Actualización en ADR-021 (`DECISIONS.md`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance en los 3 archivos.
- **Siguiente:** confirmar visualmente en Studio que `published_at` en Pages/Posts se ve relativo para contenido reciente y `d:m:Y h:i a` para el resto; si en el futuro se agrega alguna columna de fecha nueva a cualquier Resource, usar `FriendlyDate::format()` desde el vamos en vez de `->dateTime()` crudo.

## 2026-08-31 — `PostResource`: título+slug fusionados, `Tenant::publicUrl()` nuevo (sin usar por ahora — el Tech Lead prefirió texto plano)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — mismo patrón de fusión ya aplicado a Testimonios/Servicios para la columna `title`+`slug` de Publicaciones. Primera vuelta: el slug como LINK real a la URL pública completa — "que muestre el slug, pero como link que tenga la url completa" → "la url completa se saca del dominio de la app [tenant] + /blog/ + slug" → "recuerda que estamos frente a un servicio multitenant" (el dominio no puede ser fijo, se resuelve por el tenant dueño de cada post, vía la tabla `domains` ya existente — `tenant_id`/`domain`/`is_primary`, sin migración nueva). Se agregaron 2 helpers en `Tenant`: `primaryDomain(): ?Domain` y `publicUrl(string $path = ''): ?string` (`https://{dominio}/{path}`, `null` si el tenant no tiene dominio cargado — a propósito sin fallback a un dominio hardcodeado, por la "regla de oro" de ARCHITECTURE.md §4). Segunda vuelta, el mismo día: el Tech Lead se lo pensó de nuevo y pidió texto plano, SIN hipervínculo — "que muestre debajo URL: /blog/[slug] sin hipervinculo". Revertido el `->url()`/`->openUrlInNewTab()` de la columna; queda solo `->description()` con el texto `"URL: /blog/{slug}"`. Los helpers de `Tenant` se dejaron en el modelo (no rompen nada, sin uso actual) por si hace falta un link real en otro lugar más adelante.
- **Archivos/áreas:** `app/Models/Tenant.php` (+`primaryDomain()`, +`publicUrl()`, sin consumidor actual), `app/Filament/Resources/PostResource.php` (columna `title` fusionada con `description()` de texto plano, elimina la columna `slug` separada).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance en los 2 archivos.
- **Siguiente:** ninguno urgente — quedó en el estado final pedido. Si en el futuro se quiere el link real, ya está `Tenant::publicUrl()` listo para reengancharlo en la columna (recordar cargar el dominio real de CICA360 en `domains` con `is_primary = true` primero, todavía no está cargado).

## 2026-08-31 — `MenuResource`: jerarquía de 3 niveles con drag-to-sort propio por nivel (menú/submenú/sub-submenú)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — "quiero hacer sort pero hasta 3 niveles, menu, sub menu y sub-submenu". El form de `MenuResource` tenía un único `Repeater` PLANO ligado a `Menu::items()` (todos los items del menú, sin distinguir nivel) con un `Select::make('parent_id')` manual para elegir el padre — funcional pero sin jerarquía visual, y el drag-to-sort mezclaba todos los niveles en una sola lista. El esquema de `menu_items` ya soportaba jerarquía real (`parent_id` autorreferenciado, índice `[menu_id, parent_id, sort_order]`, y los modelos `Menu::rootItems()`/`MenuItem::children()` ya existían) — no hizo falta ninguna migración. Se reemplazó el `Repeater` plano por 3 `Repeater`s ANIDADOS recursivamente (método `MenuResource::menuItemFields(int $depth)`, reutiliza los mismos campos en los 3 niveles): nivel 1 ligado a `Menu::rootItems()`, nivel 2 y 3 ligados a `MenuItem::children()` del item padre — cada uno con su propio `->orderColumn('sort_order')`, así el drag-to-sort de un submenú reordena SOLO sus hermanos directos, no toca los demás niveles. El `Select` de `parent_id` se eliminó: la jerarquía ahora es estructural (la posición del item dentro del Repeater anidado define el padre), Filament la resuelve sola al guardar.
- **Archivos/áreas:** `app/Filament/Resources/MenuResource.php` (`form()` reescrito + nuevo método `menuItemFields()`). Sin cambios de modelo ni migración — `Menu::rootItems()`/`MenuItem::children()` ya existían.
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes. Revisado el mecanismo interno de Filament (`Repeater::relationship()`/`saveToRelationship()`/`orderColumn()`) para confirmar que Repeaters anidados con relación propia en cada nivel resuelven su registro padre correctamente vía el contexto del item contenedor — patrón estándar de Filament, no requiere configuración extra.
- **Siguiente:** el Tech Lead debe confirmar visualmente en Studio: crear un item de nivel 1, agregarle un sub-elemento (nivel 2), agregarle un sub-sub-elemento (nivel 3), reordenar dentro de cada nivel por separado, guardar y volver a abrir para confirmar que la jerarquía persiste.

## 2026-08-31 — Fix real: `Error` al convertir `CountryEnum` a string + modal de `ServiceResource` reducido a la mitad (extiende ADR-035)
- **Agente/autor:** Claude
- **Qué se hizo:** el fix anterior de `Service::sanitizeCountries()` (que asumía que Filament siempre entrega strings crudos) seguía rompiendo, ahora con `Error: Object of class App\Enums\CountryEnum could not be converted to string` en `Service.php:64`. Causa real: cuando el `Select` de Filament usa `->options(CountryEnum::class)`, Filament hidrata el estado del campo con INSTANCIAS de `CountryEnum`, no con los strings crudos guardados en la fila — `(string) $enumInstance` rompe porque un Enum de PHP no implementa `__toString()`. Fix: `sanitizeCountries()` ahora distingue los dos casos (`$code instanceof CountryEnum` → usa `->value`; si no, castea a string) antes de normalizar. Además, pedido del Tech Lead: el slide-over de `ServiceResource` ("Editar"/"Crear", en la tabla y en el header) ocupaba casi toda la pantalla con `modalWidth('6xl')` — reducido a `modalWidth('3xl')` (aprox. la mitad: 48rem vs. 72rem) en las 3 acciones que abren el form.
- **Archivos/áreas:** `app/Models/Service.php` (`sanitizeCountries()`), `app/Filament/Resources/ServiceResource.php` (2 acciones), `app/Filament/Resources/ServiceResource/Pages/ManageServices.php` (1 acción).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance en los 3 archivos; confirmado que no queda ningún `6xl` en ninguno de los dos archivos de `ServiceResource`.
- **Siguiente:** confirmar en Studio que abrir/editar cualquier servicio ya no rompe, y que el modal ahora se ve proporcional (no casi fullscreen). Si `3xl` resulta muy angosto para el Tabs con 2 Repeaters, ajustar a `4xl`.

## 2026-08-31 — Fix real: 500 en `ServiceResource` por códigos de país legado + `Cliente0ServicesSeeder` ampliado a 12 (extiende ADR-035)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó un 500 real al abrir/guardar una fila en `/services`: `TypeError` en `Filament\Forms\Components\Concerns\CanDisableOptions::isOptionDisabled()` — "`Argument #2 ($label) must be of type Htmlable|string, null given`". Causa: las filas sembradas antes de ADR-035 (con `Cliente0ServicesSeeder` viejo) quedaron con códigos de país en minúscula (`ar`, `uy`, ...) que el `CountryEnum` nuevo ya no reconoce; Filament no tolera que el valor guardado no exista entre las `options()` actuales del `Select` y rompe apenas intenta calcular el label para pintar el chip seleccionado — pasa con solo ABRIR el form, antes de guardar nada. Esto también explica por qué el listado mostraba el código ISO crudo en vez del nombre en algunos badges: `CountryEnum::tryFrom()` fallaba silenciosamente para esos valores viejos y caía al fallback `?? $state`. Fix de 2 partes: (1) `Service::sanitizeCountries()` nuevo (mayúscula + descarta códigos que no existan hoy en el Enum), enchufado en `ServiceResource` vía `->afterStateHydrated()` (sanea al abrir el form, evita el crash) y `->dehydrateStateUsing()` (sanea al guardar); (2) migración `2026_08_31_000005_normalize_services_countries.php` que recorre `services` con el query builder (no Eloquent — `HasTenant` necesita contexto de tenant que una migración no tiene) y normaliza cualquier fila ya sembrada con códigos viejos, directo en Postgres. Se descartó explícitamente combinar un `Attribute` mutator con el cast `'array'` ya existente para el mismo campo en el modelo, por riesgo de interacción poco clara entre ambos mecanismos de Eloquent — se centralizó la regla en un método estático simple en su lugar. De paso, pedido explícito del Tech Lead ("generar 12 servicios"): `Cliente0ServicesSeeder` ampliado de 7 a **12 servicios** — 5 nuevos sin captura de mockup (Seguros de Vida y Salud, Recursos Humanos y Gestión de Nómina, Comercio Exterior y Aduanas, Seguros Empresariales y Riesgos Corporativos, Turismo y Asistencia al Viajero), contenido redactado en el mismo tono/estructura, dentro del rubro real de CICA360, a revisar por el Tech Lead. `image_id` sigue `null` en los 12.
- **Archivos/áreas:** `app/Models/Service.php` (+`sanitizeCountries()`), `app/Filament/Resources/ServiceResource.php` (`countries`: `afterStateHydrated()`/`dehydrateStateUsing()`), `database/migrations/2026_08_31_000005_normalize_services_countries.php` (nueva), `database/seeders/Cliente0ServicesSeeder.php` (7 → 12 servicios).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 4 archivos; conteo confirma 12 servicios (37 ocurrencias de `'title' =>` = 12×3 + 1 del `foreach`), y los 12 usan códigos ISO en mayúscula válidos.
- **Siguiente:** el Tech Lead debe correr `php artisan migrate` (aplica la migración de normalización sobre los 7 servicios ya sembrados) seguido de `php artisan db:seed --class=Cliente0ServicesSeeder` (para que entren los 5 nuevos), luego `vendor/bin/pint --dirty --format agent`, y confirmar en Studio que: (a) ya no rompe al abrir/editar ningún servicio, (b) el listado muestra el nombre completo del país en los badges, no el código, (c) los 12 servicios aparecen en `/services`.

## 2026-08-31 — `CountryEnum`: listado ISO 3166-1 completo (249 países), campo opcional, reutilizable por cualquier tenant (ADR-035)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead pidió repensar `countries` para que (1) no sea requerido, (2) cubra todos los países del mundo con ISO => NAME (no solo los 6 de CICA360), y (3) sea "reutilizado por cualquier tenant" — también mencionó como alternativa un campo custom tipo tags con autocompletado cruzando otros servicios. Resuelto con el catálogo ISO en vez de tags libres: país tiene una respuesta objetivamente correcta y finita, freeform no da un código limpio para mapear a bandera. `App\Enums\CountryEnum` reescrito: 6 casos → 249 (listado ISO 3166-1 alpha-2 completo, nombres en español vía CLDR) + `Global`. Valores en MAYÚSCULA (`PE`, `AR`, ...) — misma clave que usará la convención de banderas del frontend `media/flags/flag_{ISO}.webp`. Nuevos helpers pensados para cuando se construya la API pública de `services`: `toApiArray()` (`['iso' => ..., 'name' => ...]`), `resolveMany(array $codes)`, `flagPath()`; `Service::countriesResolved()` expone lo mismo a nivel de modelo. En `ServiceResource`: `countries` ya no `->required()`, `->searchable()` agregado al form y al filtro de tabla (imprescindible con 249 opciones). Un `Enum` PHP es por definición un catálogo compartido por todo el código, no una tabla `tenant_id`-scoped — cubrir TODOS los países ya lo hace reutilizable por cualquier tenant futuro sin tocar código ni sembrar datos por cliente. La idea de tags libres queda anotada en el ADR como solución razonable para un campo *distinto* (clasificación abierta propia de cada tenant, sin catálogo universal) si en el futuro se pide algo así — no implementada ahora.
- **Archivos/áreas:** `app/Enums/CountryEnum.php` (reescrito completo), `app/Models/Service.php` (+`countriesResolved()`), `app/Filament/Resources/ServiceResource.php` (`countries` no requerido + `searchable()` en form y filtro), `database/seeders/Cliente0ServicesSeeder.php` (7 entradas: códigos de país pasados a mayúscula). ADR-035 en `DECISIONS.md`, nota de superseded parcial agregada a ADR-034.
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python (ignora comentarios/strings) confirma balance de paréntesis/llaves/corchetes en los 4 archivos tocados; conteo confirma 249 casos ISO + `Global` = 250, sin identificadores de caso duplicados.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0ServicesSeeder` de nuevo (los 7 servicios ya sembrados quedaron con códigos en minúscula que el Enum nuevo ya no reconoce — `updateOrCreate` hace que re-correrlo sea seguro), luego `vendor/bin/pint --dirty --format agent` y confirmar visualmente en Studio que el selector de país ahora es buscable y opcional. Pendiente, sin pedido concreto todavía: subir los archivos de bandera reales a `media/flags/` en el frontend, y el sistema de tags genérico por tenant si llega a pedirse.

## 2026-08-31 — Fix real: `TypeError` en la columna `countries` de `ServiceResource` (extiende ADR-034)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó un 500 real al abrir `/services` en Studio: `formatStateUsing(): Argument #1 ($state) must be of type ?array, string given`. Causa: con `->badge()` activo sobre una columna cuyo estado es un array (`countries`), Filament itera el array y llama `formatStateUsing` UNA VEZ POR CADA VALOR para pintar un badge por país — no recibe el array completo como asumí al tipar `fn (?array $state)`. Fix: la closure pasa a `fn (?string $state) => CountryEnum::tryFrom($state ?? '')?->getLabel() ?? $state`, un país a la vez.
- **Archivos/áreas:** `app/Filament/Resources/ServiceResource.php` (columna `countries`).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes.
- **Siguiente:** confirmar en Studio que `/services` carga sin error y las banderas se ven correctas por fila.

## 2026-08-31 — Módulo de Servicios: tabla `services` + `ServiceResource` + 7 servicios sembrados (ADR-034)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead compartió capturas del catálogo público "Servicios" (9 cards, 2 duplicadas — 7 títulos reales) y el detalle completo de "Seguros generales" (banner, intro, tabs "¿Qué ofrecemos?"/"Coberturas", "¿Por qué elegirnos?", tip), pidiendo "similar a páginas... una tabla de servicios con contenido en JSONB y links, meta y properties también... un recurso bien elaborado". Se creó: (1) tabla `services` con el mismo esqueleto que `pages` (pretitle/title/subtitle/slug/status/meta/links/properties/published_at) más `image_id`, `countries` (jsonb) y `content` (jsonb: intro/offers/coverages/why_choose_us/tip). (2) `App\Enums\CountryEnum` nuevo (ar/uy/py/ec/us/global). (3) `App\Filament\Resources\ServiceResource`: Tabs de 3 pestañas (Configuración/Contenido/SEO-Enlaces) mismo patrón que `PageResource`, con 2 Repeaters en Contenido (`offers`, `coverages` con `TagsInput` para el detalle opcional tipo "Automotores → Cubrimos daños por: ..."). Importante: NO se reutilizó `HeadingFieldset::make(hasSlug: true)` porque ese modo valida unicidad de slug hardcodeado contra `Page::class` — se usó `afterTitleUpdated` + un `TextInput::make('slug')` propio con `unique(Service::class, ...)`. `->modalWidth('6xl')` en las 3 acciones (mismo criterio aprendido con `TestimonialResource` este mismo día: dar el ancho real que el layout necesita). (4) `Cliente0ServicesSeeder`: 7 servicios (no 9 — las 2 cards duplicadas de la captura eran relleno de grilla de demo). Solo "Seguros generales" tenía contenido de detalle real en la captura, sembrado verbatim (incluye "Estudio Jurídico Mosquera – Perticaro & Abogados", "Patente N° 11 – SSN Argentina"); los otros 6 llevan contenido de ejemplo razonable a revisar por el Tech Lead. `image_id` null en los 7 (fotos vienen después).
- **Archivos/áreas:** `database/migrations/2026_08_31_000004_create_services_table.php`, `app/Enums/CountryEnum.php`, `app/Models/Service.php`, `app/Filament/Resources/ServiceResource.php` + `Pages/ManageServices.php`, `database/seeders/Cliente0ServicesSeeder.php` (nuevo), `database/seeders/DatabaseSeeder.php`. ADR-034 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en todos los archivos tocados; conteo confirma 7 servicios sembrados.
- **Siguiente:** correr en local `php artisan migrate && php artisan db:seed`, `vendor/bin/pint --dirty --format agent`, confirmar visualmente el form en Studio. Fuera de esta vuelta a propósito (el Tech Lead no lo pidió todavía): exponer `services` en la API pública (`GET /v1/{tenant}/services`, `/services/{slug}`), decidir si `services_grid` pasa a consumir esta tabla (mismo tratamiento que `testimonials` en ADR-033), y construir el catálogo/detalle en `cica360` — pendiente además de que lleguen las imágenes reales de los 7 servicios.

## 2026-08-31 — Dataset de ejemplo ampliado de 4 a 12 testimonios, 5 fotos reutilizadas al azar (extiende ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido explícito del Tech Lead — "generar 12 testimonios random y reutilizar de forma aleatoria las 5 fotos almacenadas". `Cliente0TestimonialsSeeder` pasa de 4 a 12 entradas (se mantienen los 4 originales sin cambios, se suman 8 nuevos con roles variados — contadora, industria textil, salud, agro, arquitectura, comercio, docencia universitaria, RRHH/logística — para reflejar mejor el rango real de servicios de CICA360). Con solo 5 fotos reales disponibles para 12 personas, cada `avatar_file` se asignó a mano en un orden mezclado que reutiliza cada foto 2-3 veces sin repetir en testimonios consecutivos — a propósito NO generado con `rand()` en cada corrida, para no romper la idempotencia esperada del seeder. `Cliente0MediaSeeder`: los `name`/`alt` de los 5 registros `Media` se generalizaron ("Avatar Testimonio N" en vez de atados a una persona puntual, ya que cada foto ahora es compartida). `Cliente0ContentSeeder`: comentario del bloque `testimonials` de "Casos de éxito" actualizado (ya no dice "los trae todos" — ahora trae 4 de 12, límite sin cambios).
- **Archivos/áreas:** `database/seeders/Cliente0TestimonialsSeeder.php` (12 entradas), `database/seeders/Cliente0MediaSeeder.php` (comentarios/nombres de los 5 avatares), `database/seeders/Cliente0ContentSeeder.php` (comentario). Actualización 3 de ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos; conteo de entradas confirma 12 `name`/12 `avatar_file` en el seeder.
- **Siguiente:** correr en local `php artisan db:seed --class=Cliente0TestimonialsSeeder` (ya corrido `Cliente0MediaSeeder` con los 5 avatares) y confirmar visualmente en `TestimonialResource` que los 12 testimonios aparecen con la foto correspondiente asignada.

## 2026-08-31 — `TestimonialResource`: fix real de fullwidth — `Group`/`Grid` anidados aplanados a campos directos de la Section (extiende ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** el fix anterior (Section a 3 columnas + 2 `Group` internos, uno con `Grid::make(2)` adentro) no resolvió el problema real — el Tech Lead mandó una captura nueva mostrando la Section TODAVÍA más angosta, con mucho espacio vacío a la derecha pese al `modalWidth('2xl')` ya aplicado. Causa identificada: grids de Filament anidados (`Section` con columnas → `Group` → otro `Grid` con columnas) pueden colapsar el contenedor intermedio a un ancho "shrink-to-fit" en vez de estirarse al 100% de su celda. Fix real: aplanada la estructura — los 5 campos (`avatar_id`, `name`, `role`, `quote`, `is_visible`) pasan a ser hijos DIRECTOS de la única `Section::make()->columns(3)`, cada uno con su propio `columnSpan()`, sin `Group` ni `Grid` intermedios; se sumó `->extraAttributes(['class' => 'w-full'])` a la Section como refuerzo. Import de `Grid`/`Group` (ya sin uso) eliminado del archivo.
- **Archivos/áreas:** `app/Filament/Resources/TestimonialResource.php` (`form()`). Actualización 2 de ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes.
- **Siguiente:** confirmar visualmente en Studio que la Section ahora sí ocupa el ancho completo del modal; si el problema persiste, revisar si hay CSS custom (`public/css/filament/*.css`) pisando el layout de este Resource en particular.

## 2026-08-31 — `TestimonialResource`: form reorganizado a 2 columnas (avatar+datos) + modal más ancho + avatares reales sembrados (extiende ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead mandó 2 capturas del `TestimonialResource` recién creado: (1) el árbol de archivos mostrando 5 fotos nuevas subidas a `storage/app/public/media/` (`cica360_media_testimony-{1..5}.webp`), y (2) el modal "Editar Testimonio" con el form de una sola columna viéndose angosto y con mucho espacio desperdiciado a los costados dentro del slide-over. Dos fixes: (1) **UX del form** — `Section` pasa de una columna a `columns(3)`: avatar en su propio `Group` (1 columna, angosta), Nombre+Puesto+Frase en otro `Group` (2 columnas, ancha real), Visible a ancho completo al pie. Las 3 acciones que abren el form (`EditAction`, ambos `CreateAction`) ganan `->modalWidth('2xl')` — el slide-over usaba un ancho angosto por default sin importar cuánto espacio interno pidiera el schema. (2) **Avatares reales** — las 5 fotos se agregaron a `Cliente0MediaSeeder::FILES` (mismo patrón que slides/splits); `Cliente0TestimonialsSeeder` ahora asigna `avatar_id` a cada uno de los 4 testimonios vía `Cliente0MediaSeeder::mediaId()`. La 5ta queda sembrada sin asignar, disponible para un testimonio futuro.
- **Archivos/áreas:** `app/Filament/Resources/TestimonialResource.php` (form + `modalWidth` en tabla), `app/Filament/Resources/TestimonialResource/Pages/ManageTestimonials.php` (`modalWidth` en el `CreateAction` del header), `database/seeders/Cliente0MediaSeeder.php` (+5 entradas), `database/seeders/Cliente0TestimonialsSeeder.php` (`avatar_id` real por testimonio). Actualización de ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 4 archivos tocados.
- **Siguiente:** correr en local `php artisan db:seed --class=Cliente0MediaSeeder && php artisan db:seed --class=Cliente0TestimonialsSeeder` (o `db:seed` completo), `vendor/bin/pint --dirty --format agent`, y confirmar visualmente que el modal se ve bien y los avatares reales cargan (ya no debería verse el fallback de iniciales en Cliente 0, salvo que se agregue un 5to testimonio sin asignarle la foto ya sembrada).

## 2026-08-31 — Módulo propio de Testimonios/Casos de Éxito: tabla + `TestimonialResource` + bloque reducido a filtro (ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** el bloque `testimonials` guardaba los testimonios inline en un `Repeater` (`content.items`), sin gestión centralizada ni forma de ocultar uno puntual sin borrarlo, y con el mismo bug de campos duplicados de ADR-031/032 (`TextInput::make('title')`/`('subtitle')` sueltos junto a `HeadingFieldset::make()`, que ya los provee). A pedido del Tech Lead ("quiero una gestión mediante un recurso Filamente 5 profesional con mucho UX"): (1) nueva tabla `testimonials` (`name`/`role`/`quote`/`avatar_id`/`is_visible`/`sort_order`, tenant-scoped) + modelo `Testimonial`. (2) Nuevo `TestimonialResource` (patrón `ManageRecords` de página única, avatar circular vía `MediaUpload::circleCropper()`, toggle de visibilidad inline, filtro de visibilidad, acciones masivas mostrar/ocultar, drag-reorder por `sort_order`). (3) El bloque `testimonials` de `PageResource.php` se reescribió: ahora es solo encabezado (`HeadingFieldset`, sin duplicados) + filtro (`content.limit`, `content.order` asc/desc por fecha) + un enlace único opcional (mismo patrón `LinkSchema::makeSingle()` de `rich_text`, con su `properties.show_link`) + "Personalización de estilos". (4) `ResolvesPublicLinks::attachResolvedBlockContent()` gana la resolución en runtime: una sola query de todos los testimonios visibles del tenant (compartida entre bloques, evita N+1), recortada/ordenada en memoria por cada bloque según su propio filtro, entregada en la MISMA forma que ya consumía el frontend (`content.items[]` con `name`/`role`/`quote`/`avatar`) — `limit`/`order` nunca salen en la response pública. (5) Nuevo `Cliente0TestimonialsSeeder` (los 4 testimonios de ejemplo, antes duplicados entre 2 bloques, ahora una sola fuente) registrado junto a `Cliente0MediaSeeder`; los 2 bloques `testimonials` sembrados (home y página "casos-de-exito") pasan de `content.items` a `content.limit`/`content.order` + fondo teal (`#2b7c89`, del design system real de CICA360). (6) `cica360/src/components/blocks/Testimonials.astro` reescrito: corrige el bug real (`TestimonialItem.author` nunca coincidía con el `name` que mandaba el backend — los nombres jamás se renderizaron) y reemplaza el stub sin estilos por el diseño del mockup (fondo sólido, tarjetas con avatar circular + fallback de iniciales, frase/nombre en itálica, botón "ver más" con ícono de ojo).
- **Archivos/áreas:** `database/migrations/2026_08_31_000003_create_testimonials_table.php`, `app/Models/Testimonial.php`, `app/Filament/Resources/TestimonialResource.php` + `Pages/ManageTestimonials.php`, `app/Filament/Resources/PageResource.php` (bloque `testimonials`), `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0TestimonialsSeeder.php` (nuevo), `database/seeders/DatabaseSeeder.php`, `database/seeders/Cliente0ContentSeeder.php`, `cica360/src/components/blocks/Testimonials.astro`, `cica360/docs/context/api/stamless-api-v1.md`. ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP/npm en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en todos los archivos PHP tocados; tag-balance + brace-count en `Testimonials.astro` también cuadra.
- **Siguiente:** correr en local `php artisan migrate && php artisan db:seed`, `vendor/bin/pint --dirty --format agent`, y confirmar visualmente el bloque contra el mockup (avatares con fallback de iniciales hasta cargar fotos reales vía Studio — ningún testimonio sembrado trae `avatar_id`).

## 2026-08-31 — Revertido `properties.list_icon` (íconos de lista por bloque) — el Tech Lead pidió algo más simple, resuelto 100% en frontend
- **Agente/autor:** Claude
- **Qué se hizo:** empecé a implementar un selector de ícono para los `<li>` del cuerpo de texto (`properties.list_icon`: tag/plus-circle/check-circle) — nuevo componente en `PropertiesSchema`, campo en el Fieldset "Sección" de `split` en `PageResource.php`, valores sembrados en los 2 bloques `Split` del home (`Cliente0ContentSeeder`). El Tech Lead lo frenó ("para no hacerlo muy complicado") y pidió en cambio una viñeta DOT más grande, sin selector — no requiere ninguna property nueva, se resuelve 100% en CSS del lado de `cica360` (ver `cica360/docs/context/PROGRESS.md`). Se revirtieron los 3 cambios de este lado por completo (sin dejar el campo "por las dudas" — el Tech Lead ya lo descartó explícitamente, dejarlo muerto en el schema hubiera sido el mismo anti-patrón de "property sin consumidor" que se corrigió varias veces esta sesión).
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (queda en 41 componentes, sin cambio neto), `app/Filament/Resources/PageResource.php` (bloques `split` y `rich_text`, vuelven a su estado previo), `database/seeders/Cliente0ContentSeeder.php` (los 2 `Split` del home vuelven a solo `content_width: 'full'`).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos.
- **Siguiente:** ninguno de este lado — el ajuste real (tamaño del punto) se confirma visualmente contra `cica360`.

## 2026-08-31 — Seeder: `content_width: 'full'` sembrado en los 2 `split` del home (extiende ADR-032)
- **Agente/autor:** Claude
- **Qué se hizo:** los 2 bloques `split` de la home ("¿Qué hacemos?"/"¿A quién nos dirigimos?") no tenían `properties` en absoluto — el frontend caía al fallback `?? 'boxed'`, no al fullwidth bleed que el diseño real usa (mismo diseño que motivó agregar `content_width` en la entrada anterior). Se sembró `'properties' => ['content_width' => 'full']` explícito en ambos, para no depender del fallback.
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (`upsertHomePage()`, los 2 bloques `Split`).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes.
- **Siguiente:** correr `php artisan db:seed --class=Cliente0ContentSeeder` (o `db:seed` completo) en local y confirmar visualmente que ambos splits del home quedan fullwidth.

## 2026-08-31 — `split`: `content_width` fullwidth asimétrico (imagen bleed + texto con padding) + `media_position` reubicado (extiende ADR-032)
- **Agente/autor:** Claude
- **Qué se hizo:** dos correcciones más sobre el sitio real corriendo. (1) "Posición de la imagen" se movió de arriba (junto a `is_visible`) hacia adentro de "Personalización de estilos" > "Sección" — solo reubicación de UI, el campo sigue siendo `content.media_position` (no se duplicó a `properties`, eso hubiera reabierto el bug de ADR-031). (2) El Tech Lead mandó una captura del sitio ya corriendo: para `split`, "fullwidth" significa que la imagen llega hasta el borde real del viewport (bleed) mientras el texto conserva el padding estándar de 80px solo del lado exterior — asimétrico, distinto al `full` simétrico de `rich_text`. Se agregó `content_width` (`full`/`boxed`/`narrow`) al Fieldset "Sección" de `split`. `Split.astro`: `boxed`/`narrow` usan un contenedor centrado simétrico (mismo patrón que `rich_text`); `full` no lleva padding en el contenedor — la imagen bleedea y la columna de texto recibe el padding de 80px calculado dinámicamente según de qué lado está la imagen (`mediaOnRight`).
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `split`), `cica360/src/components/blocks/Split.astro`, `cica360/docs/context/api/stamless-api-v1.md`. Actualización 2 de ADR-032 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en `PageResource.php`; tag-balance + brace-count en `Split.astro` también cuadra; los 10 bloques JSON de `stamless-api-v1.md` siguen parseando OK.
- **Siguiente:** correr en local `vendor/bin/pint --dirty --format agent` y confirmar visualmente en el sitio real que `content_width: full` reproduce exactamente el bleed de la captura que mandó el Tech Lead.

## 2026-08-31 — `split`: `MediaUpload`+`HeadingFieldset` a 2 columnas, sección "Personalización de estilos" con filtros/blend de imagen (extiende ADR-032)
- **Agente/autor:** Claude
- **Qué se hizo:** dos rondas más de feedback del Tech Lead sobre capturas reales del bloque `split` en Studio, seguidas a la entrada anterior de este mismo día. (1) "Imagen / Elemento Multimedia" y "Encabezado (Opcional)" quedaban cada uno en su propia fila completa — envueltos en `Grid::make(2)` para que compartan fila. (2) Los 4 campos de estilo (`Color de fondo`, `Color de texto`, `Espaciado vertical`, `Animación de entrada`) estaban apilados sueltos debajo de "Enlaces" — el Tech Lead pidió "una sección a 2 columnas, algo más profesional" y de paso "filtros y blends para la imagen bien ordenados". Se creó una `Section::make('Personalización de estilos')` colapsable con 2 `Fieldset` a 2 columnas: "Sección" (los mismos 4 campos de antes) e "Imagen: filtros y efectos" (10 campos NUEVOS: `media_blend_mode`, `media_brightness`, `media_opacity`, `media_radius` + los 6 filtros CSS clásicos — mismo set que ya tenía el fondo del Slide en ADR-027, generalizado en `PropertiesSchema` bajo el prefijo `media_*` en vez de `slide_background_*` para que cualquier bloque futuro con `MediaUpload` lo pueda reusar). `Split.astro` los consume de inmediato con el mismo patrón de `Hero.astro` (`filter`/`opacity`/`mix-blend-mode` combinados en un `style`, más una clase `rounded-*` para `media_radius`) — evitando a propósito el anti-patrón de property "registrada pero sin consumidor" que se corrigió varias veces esta sesión.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (+10 componentes: de 31 a 41), `app/Filament/Resources/PageResource.php` (bloque `split`), `cica360/src/components/blocks/Split.astro`. Actualización de ADR-032 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en ambos archivos PHP; tag-balance + brace-count en `Split.astro` también cuadra.
- **Siguiente:** correr en local `vendor/bin/pint --dirty --format agent`, confirmar visualmente en Studio que "Personalización de estilos" queda colapsada por default y los filtros de imagen se ven en Split.astro contra contenido real.

## 2026-08-31 — Fix orden de seeders (imagen faltante en `split`), reorg del form `split` y mensajes de validación en español
- **Agente/autor:** Claude
- **Qué se hizo:** 3 issues reportados por el Tech Lead sobre una captura de Studio con el bloque `split`. (1) **"no hay imagen inyectada"**: bug propio — al agregar `Cliente0MediaSeeder::mediaId()` dentro de `Cliente0ContentSeeder::upsertHomePage()` (entrada anterior) no se movió `Cliente0MediaSeeder` antes en el orden de `DatabaseSeeder::run()`; corría DESPUÉS de `Cliente0ContentSeeder`, así que las filas `Media` de los splits todavía no existían y `mediaId()` devolvía `null` siempre. Reordenado: `Cliente0Seeder → Cliente0MediaSeeder → Cliente0ContentSeeder → Cliente0HomeSlidesSeeder → Cliente0PostsSeeder`. (2) **UX del form `split`**: el `Grid::make(2)` con `[Hidden lang_iso, Toggle is_visible, MediaUpload media_id, Select media_position]` dejaba una celda vacía (3 campos visibles sobre grid de 2 columnas). Reorganizado: `Hidden` sale del grid, `Toggle is_visible` + `Select content.media_position` llenan la fila pareja (posición se decide antes de subir el archivo, como pidió el Tech Lead), y `MediaUpload` pasa a su propia fila con `->columnSpanFull()` (más ancho, con preview — tiene sentido que no comparta fila). El tipo de archivo permitido lo sigue limitando el propio `MediaUpload`, sin cambios ahí. (3) **`is_visible` sin explicación**: es funcional (confirmado por grep — `PageController::show()` filtra `->where('is_visible', true)` antes de exponer bloques por API), pero no lo decía en ningún lado del form. Se agregó `->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')` a las 5 ocurrencias de `Toggle::make('is_visible')` en `PageResource.php` (no solo `split`, por consistencia). (4) **Mensajes de validación en inglés pese a `APP_LOCALE=es`**: el proyecto nunca tuvo carpeta `lang/` propia — Laravel 11+ no la scaffoldea por defecto y cae al inglés embebido en `vendor/laravel/framework`. Creados `lang/es/validation.php`, `auth.php`, `passwords.php`, `pagination.php` (traducciones completas, misma estructura de claves que el vendor en inglés) — Filament ya traía sus propios `es` para tablas/forms/notificaciones, pero el core de Laravel no.
- **Archivos/áreas:** `database/seeders/DatabaseSeeder.php` (orden + docblock), `app/Filament/Resources/PageResource.php` (bloque `split` reorganizado, `helperText` en 5 Toggles `is_visible`), `lang/es/{validation,auth,passwords,pagination}.php` (nuevos).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python (respeta comillas/escapes/comentarios reales, no regex ingenuo) confirma balance exacto de paréntesis en `PageResource.php` tras el edit; balance de llaves/corchetes también cuadra en los demás archivos.
- **Siguiente:** correr en local `php artisan migrate:fresh --seed` (o al menos `db:seed`) y confirmar visualmente que el bloque `split` trae imagen; `php artisan config:clear` si el locale sigue viéndose en inglés (por si hay `config:cache` viejo pisando `.env`); `vendor/bin/pint --dirty --format agent`.

## 2026-08-31 — `split`: `->cloneable()`, duplicado eliminado, contenido real del home + `Split.astro` implementado (ADR-032)
- **Qué se hizo:** el Tech Lead preguntó si se puede clonar bloques en Studio — sí, Filament 5 lo trae nativo (`->cloneable()` en el `Builder`, ya agregado). De paso, revisando el bloque `split` para armar el seeder del contenido real de la home ("¿Qué hacemos?"/"¿A quién nos dirigimos?", mockup compartido), encontré el mismo bug de ADR-031: `properties.media_position` duplicaba `content.media_position` (que es el real, required, resuelto por el backend) — eliminado. El seeder tenía "¿Qué hacemos?" como bloque `Features` (no matchea el mockup) y "¿A quién nos dirigimos?" con `media_id: null` — se convirtió el primero a `Split` y se completaron ambos con contenido real + 2 imágenes nuevas (`cica360_media_split_1.webp`/`_2.webp`, mismo patrón `Cliente0MediaSeeder` de ADR-030). `Split.astro` (frontend, antes stub) se implementó completo: grid de 2 columnas con `media_position` alternando el orden en desktop.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php`, `database/seeders/Cliente0MediaSeeder.php`, `database/seeders/Cliente0ContentSeeder.php`. Ver `cica360/docs/context/PROGRESS.md` para `Split.astro`.
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python en los 3 archivos, cuadra.
- **Siguiente:** `php artisan migrate && php artisan db:seed` en local y confirmar visualmente contra el mockup.

## 2026-08-31 — Hero: `HeadingFieldset` también movido a modo Manual (extiende ADR-031)
- **Qué se hizo:** siguiendo el mismo criterio de la entrada anterior, el Tech Lead notó que "Encabezado (Opcional)" (pretitle/título/subtítulo del Block) quedaba visible siempre, "por las puras" en modo Slider — cada Slide ya trae su propio pretitle/título/subtítulo, el front nunca lee el del Block salvo en el fallback manual. Se movió adentro de "Configuración Manual". En modo Slider el bloque `hero` queda solo con "Modo del Hero" + "Seleccionar Slider". También se recortaron los comentarios inline largos que había dejado en `PageResource.php` a un puntero corto a este ADR (el Tech Lead los encontró redundantes con lo ya escrito acá).
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php`.
- **Verificación:** balance de llaves/paréntesis/corchetes vía script Python — cuadra (20/20, 614/614, 115/115).

## 2026-08-31 — Hero: campos de modo Manual agrupados bajo un solo `->visible()` + duplicado eliminado (ADR-031)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead marcó con captura que "Botón de acción (CTA)", "Color de fondo", "Color de texto", "Opacidad del overlay", "Alineación del texto", "Espaciado vertical (Padding)" y "Animación de entrada" se mostraban siempre en el bloque `hero`, sin importar `content.mode` — en modo Slider no significan nada. Se movió `LinkSchema::make('links', ...)` + los componentes de `PropertiesSchema` (antes sueltos al final del bloque) DENTRO de la Section "Configuración Manual" ya existente (que ya tenía su propio `->visible()` para modo manual) — un solo gate para todo el grupo. Reorganizado en `Grid::make(2)` dentro de una nueva sub-Section "Diseño de la sección" en vez de lista plana. Al reorganizar apareció un duplicado real: `content.overlay_opacity`/`content.align` (campos viejos, sueltos) pisaban el mismo concepto que `properties.overlay_opacity`/`properties.text_align` de `PropertiesSchema` — confirmado por grep que no se leen en ningún otro lado (ni backend ni `Hero.astro`), se eliminaron.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `hero` completo). ADR-031 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python, cuadra (20/20, 612/612, 114/114).
- **Nota pendiente, fuera de alcance de este cambio:** `properties.overlay_opacity` sigue sin consumidor en el frontend — `Hero.astro` en modo Manual usa un overlay de legibilidad fijo (gradiente hardcodeado), no dinámico. El campo en Studio ya existe si se quiere conectar a futuro.
- **Siguiente:** confirmar visualmente el nuevo layout de 2 columnas en Studio real, y que ocultar/mostrar según `content.mode` funciona como se espera.

## 2026-08-31 — Nuevo patrón: `Cliente0MediaSeeder` siembra `media` desde archivos commiteados, seeders de contenido resuelven la FK (ADR-030)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead corrió `php artisan migrate && php artisan db:seed` y notó que las 3 slides del home perdieron los fondos subidos a mano por Studio (2 repetían la misma imagen, una quedó sin fondo). Se revisó todo el código de los seeders/migración de esta sesión y ninguno escribe a `image_desktop_id`/`image_tablet_id`/`image_mobile_id` ni toca `media` — no se pudo confirmar la causa exacta desde este sandbox (sin acceso a la BD real). El Tech Lead optó por resetear los datos y pidió, en cambio, que el contenido inicial (datos Y media) quede 100% reproducible desde seeders, sin volver a subir archivos a mano — "inyectar en media primero y luego la relación con slides", patrón a extender "a los demás contenidos o secciones". Implementado: `Cliente0MediaSeeder` (nuevo) crea los 3 registros `Media` de las slides del home desde archivos YA COMMITEADOS en `storage/app/public/media/` (`cica360_media_slide{1,2,3}.webp` — confirmados en disco, no están en `.gitignore`), idempotente (`firstOrCreate` por `tenant_id`+`path`), con un helper estático `mediaId()` reusable. `Cliente0HomeSlidesSeeder` ahora resuelve cada fondo vía ese helper y asigna el mismo id a `image_desktop_id`/`image_tablet_id`/`image_mobile_id` (un solo fondo por breakpoint por ahora, pedido explícito) — si el `Media` no existe, la FK se omite del payload en vez de forzar `null` (nunca pisa una elección manual en Studio).
- **Archivos/áreas:** `database/seeders/Cliente0MediaSeeder.php` (nuevo), `database/seeders/Cliente0HomeSlidesSeeder.php` (`SLIDES` gana `background_file`, `run()` resuelve la FK condicionalmente), `database/seeders/DatabaseSeeder.php` (orden: `Cliente0MediaSeeder` entre `Cliente0ContentSeeder` y `Cliente0HomeSlidesSeeder`). ADR-030 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python en los 3 archivos, todo cuadra. Confirmado por `ls`/`Storage::exists` lógico que los 3 `.webp` existen físicamente en `storage/app/public/media/` (46KB/116KB/172KB).
- **Siguiente:** correr `php artisan db:seed` (datos ya reseteados por el Tech Lead) y confirmar visualmente que cada slide trae el fondo correcto según su posición (slide 1 → `cica360_media_slide1.webp`, etc.). Extender el mismo patrón a `Cliente0ContentSeeder` cuando haya featured images de pages/posts que sembrar.

## 2026-08-31 — Hero: `show_scroll_indicator` corregido a nivel Slider (no por slide)
- **Agente/autor:** Claude
- **Qué se hizo:** corrección, mismo día, sobre una primera pasada de `show_scroll_indicator` en el Hero que lo había agregado como property POR SLIDE (mismo patrón que `decorator_bottom`). El Tech Lead aclaró: "las propiedades del slider en general, no dentro de cada slide, solo una vez desde el slider para sobreponerse sobre el decorador... para todos los slides detrás" — y, para el modo Manual del Hero (sin Slider asociado), que el toggle equivalente debe vivir en el propio bloque, visible solo cuando `content.mode === 'manual'`. Cambios: (1) `sliders` gana su primera columna `properties` (jsonb, nullable) — no la tenía, solo title/slug/is_active — vía nueva migración. (2) `Slider` model: `properties` a `$fillable` y cast `array`. (3) `SliderResource.php`: el toggle se movió de adentro del `Repeater` de slides (fieldset "Decorador inferior", revertido a sus 3 campos originales) a la sección "General" a nivel Slider, fieldset nuevo "Flecha de scroll". (4) `PageResource.php`: el bloque `hero` gana el mismo toggle (reusado de `PropertiesSchema`), visible solo si `content.mode === 'manual'` — oculto en modo slider porque ahí el control real pasa al Slider elegido. (5) Seed: se retiró de `Cliente0HomeSlidesSeeder::baseProperties()` (ya no por slide) y se agregó una sola vez en `Cliente0Seeder::upsertPlaceholderSlider()` (`properties: ['show_scroll_indicator' => true]` en el Slider `home`).
- **Archivos/áreas:** `database/migrations/2026_08_31_000002_add_properties_to_sliders_table.php` (nueva), `app/Models/Slider.php`, `app/Filament/Resources/SliderResource.php`, `app/Filament/Resources/PageResource.php` (bloque `hero`), `database/seeders/Cliente0HomeSlidesSeeder.php`, `database/seeders/Cliente0Seeder.php`. ADR-027 en `DECISIONS.md` corregido (no se agregó una "Actualización" nueva encima de la errónea — se reescribió esa misma entrada para reflejar el diseño final).
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python en los 6 archivos tocados, todo cuadra. Ver `cica360/docs/context/PROGRESS.md` para la mitad frontend (`Hero.astro`, `types.ts`, `stamless-api-v1.md`).
- **Siguiente:** correr en local `php artisan migrate`, `vendor/bin/pint --dirty --format agent`, `php artisan test`, y `php artisan db:seed` (o `migrate:fresh --seed`) para que el Slider `home` quede con `properties.show_scroll_indicator = true` en la BD real.

## 2026-08-31 — `rich_text`: `link_radius` + `link_size` (el botón dejó de tener bordes/tamaño fijos)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead mandó una captura del botón "CONOCE +" real (esquinas redondeadas moderadas, no pill) y notó que el `rounded-full` había quedado hardcodeado en el frontend — pidió una property para elegirlo (`xs`/`sm`/`md`/`lg`/`xl`/`full`) y, en el mismo mensaje, otra para el tamaño (`small`/`normal`/`large` — el botón actual quedaba fijo en "large"). Se agregaron `link_radius` y `link_size` a `PropertiesSchema`, ambas con `default('lg')` para no romper el aspecto de nada ya sembrado.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (+2 componentes, de 29 a 31), `app/Filament/Resources/PageResource.php` (grid de 3 columnas en la sección "Enlace" de `rich_text`: `show_link`/`link_radius`/`link_size`), `database/seeders/Cliente0ContentSeeder.php` (bloque de home siembra `link_radius: 'lg'`/`link_size: 'lg'` explícitos). Detalle completo en la "Actualización" de ADR-029 en `DECISIONS.md`.
- **Verificación:** balance de llaves/paréntesis/corchetes vía script Python en los 3 archivos — todo cuadra. Sin PHP en este sandbox.
- **Siguiente:** correr `php artisan db:seed --class=Cliente0ContentSeeder` en local y confirmar visualmente contra la captura del Tech Lead. Ver `cica360/docs/context/PROGRESS.md` para la mitad frontend (`RichText.astro`: `LINK_RADIUS_CLASSES`/`LINK_SIZE_CLASSES`).

## 2026-08-31 — Bloque `rich_text`: personalización visual completa + enlace único opcional (ADR-029)
- **Agente/autor:** Claude
- **Qué se hizo:** a pedido directo del Tech Lead (con mockup de referencia), se reesquematizó el bloque `rich_text` en `PageResource.php` para exponer fondo, color de texto, alineación, ancho de contenido, padding, decoradores arriba/abajo (con color y opacidad — se agregó `decorator_top_opacity` por simetría con el inferior, que ya la tenía), flecha indicadora de scroll, y un enlace "ver más" único y opcional (no un Repeater — reusa `LinkSchema::makeSingle()`, ya existente desde ADR-027) gateado por un toggle `properties.show_link` para poder ocultar el botón sin perder lo cargado. El form quedó reorganizado en 2 `Section` colapsables en vez de una lista plana de campos.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (+3 componentes: `show_scroll_indicator`, `show_link`, `decorator_top_opacity`), `app/Filament/Resources/PageResource.php` (bloque `rich_text` reescrito, `$richTextLinkFields` local var, import de `Fieldset`), `database/seeders/Cliente0ContentSeeder.php` (los 4 bloques `rich_text` existentes siembran `properties` completo; el de home además siembra `links[0]` con el link "Conoce más" → `sobre-cica`, `type: outline`).
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes verificado vía script Python (código real, ignorando comentarios `//` que abren/cierran paréntesis en distinta línea) en los 3 archivos tocados, todo cuadra.
- **Siguiente:** correr en local `vendor/bin/pint --dirty --format agent`, `php artisan test`, y `php artisan db:seed --class=Cliente0ContentSeeder` para confirmar que el seeder corre limpio contra la BD real. Ver también `cica360/docs/context/PROGRESS.md` para la mitad frontend de este mismo cambio (`RichText.astro` reescrito, docs del API actualizados).

---

## 2026-08-30 — `MediaUpload`: reemplazo de `MediaSelect` (dropdown+modal) por subida directa con preview real

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead planteó, con capturas comparando el Studio actual (campos "Imagen Desktop/Tablet/Móvil" mostrando solo el nombre del archivo elegido en un `Select`) contra un sitio de referencia (subida directa con preview grande, texto de dimensiones recomendadas, reproductor de video inline), que el patrón actual de `MediaSelect` — un `Select` que obliga a abrir un modal para subir y luego elegir de una lista — "no lo veo usable". Tras análisis (confirmado: la tabla `media` centralizada sigue teniendo sentido para no duplicar archivos entre campos/tenants; la API (`MediaResource`) ya expone `url`/`uuid`, nunca el `id` interno — no hacía falta ADR nuevo para eso) se propuso reemplazar solo la UX del campo, aprobado por el Tech Lead ("adelante, es mejor cambiar la experiencia visual").
  - Nuevo `App\Filament\Schemas\MediaUpload::make(string $name, string $label, string $accept = 'image')` — es un `Filament\Forms\Components\FileUpload` real (drag&drop, preview nativo de imagen o video vía FilePond, sin pasar por modal), pero su estado NO es la ruta en disco (lo que un `FileUpload` espera de forma nativa) sino el `id` interno del registro `Media` creado al subir — así se sigue asignando directo a las FKs existentes (`image_desktop_id`, `video_desktop_id`, etc.) sin migraciones ni cambios de contrato en la API.
  - Mecanismo: `fetchFileInformation(false)` (el estado no es una ruta real, así que se desactiva el chequeo nativo `Storage::exists()`), `saveUploadedFileUsing()` reutiliza `$component->saveUploadedFile($file)` (lógica de storage nativa de Filament: directorio/disco/visibilidad) y crea el `Media` a partir de la ruta resultante, devolviendo `(string) $media->id`; `getUploadedFileUsing()` resuelve ese id a `{name, size, type, url}` vía `Media::find()->url()` (preview real, incluye video porque FilePond decide `<img>`/`<video>`/ícono según el `type` mime devuelto — no hizo falta lógica especial para video); `deleteUploadedFileUsing()` borra el archivo del disco del `Media` y el registro.
  - `accept: 'image'` (default) aplica `->image()->imageEditor()->maxSize(5120)`; `accept: 'video'` aplica `->acceptedFileTypes([...])->maxSize(51200)`; `accept: 'any'` solo limita tamaño.
  - Reemplazados los 24 call sites de `MediaSelect::make(...)` en `SliderResource.php` (5, incluye los 2 campos de video con `accept: 'video'`), `PageResource.php` (14) y `PostResource.php` (5) — mismo nombre/label/`->required()`/`->visible()` condicionales preservados sin tocar.
  - `MediaSelect.php` queda sin uso (marcado `@deprecated` en el propio archivo) — no se pudo borrar en este sandbox (el folder de trabajo del usuario no permite `rm`), seguro de eliminar en un PR normal.
- **Trade-off consciente, no resuelto:** el viejo `MediaSelect` tenía un `TextInput` de `alt_text` dentro del modal de creación; `MediaUpload` no tiene ningún campo para capturarlo (coherente con el pedido de simplificar a "solo arrastrar el archivo", y con el mockup de referencia que tampoco lo mostraba) — si se necesita alt text editable por imagen, hace falta un campo aparte junto al `MediaUpload` en cada Resource. No implementado — a definir con el Tech Lead si hace falta.
- **Archivos/áreas:** `app/Filament/Schemas/MediaUpload.php` (nuevo), `app/Filament/Schemas/MediaSelect.php` (deprecado, sin borrar), `app/Filament/Resources/{SliderResource,PageResource,PostResource}.php`.
- **Pendiente de verificación:** este sandbox no tiene PHP disponible — no se pudo correr `php artisan test`, `vendor/bin/pint --dirty` ni probar en vivo la subida/preview/borrado en Studio. Falta: (1) confirmar que `imageEditor()` no requiera una dependencia JS/paquete no instalado en este proyecto; (2) probar el flujo completo (subir, ver preview, editar registro existente con FK ya poblada — el id viejo debe resolver a preview correctamente vía `getUploadedFileUsing`, guardar de nuevo sin re-subir, quitar el archivo y confirmar que borra el `Media` y el archivo del disco).
- **Siguiente:** correr Pint + tests en local; probar el form de Studio contra el pedido; decidir si se necesita un campo de `alt_text` aparte.

**Fix same-day #2 (UX del Repeater de Slides):** el Tech Lead pidió 3 ajustes menores tras ver el form en Studio: (a) "Diapositivas" → **"Slides"** (se deja el anglicismo a propósito — "Diapositivas" se confunde con PowerPoint/Google Slides); (b) el Repeater de slides venía envuelto en un `Section::make('Diapositivas')` redundante (Section "Slides" conteniendo un campo también llamado "Slides") — se quitó el `Section` wrapper, el `Repeater::make('slides')` queda directo en el nivel superior del form; (c) los items del Repeater (cada slide) ahora arrancan **colapsados por default** (`->collapsed()` en el Repeater, además de `->collapsible()` que ya tenía). `itemLabel` fallback también actualizado de "Nueva diapositiva" a "Nuevo slide" por consistencia.
- **Archivos/áreas:** `app/Filament/Resources/SliderResource.php`.

**Fix same-day (reportado por el Tech Lead probando en vivo):** tras subir un archivo el preview se veía bien (misma sesión Livewire), pero al recargar el navegador y volver a editar el registro, el campo quedaba pegado en "Cargando... Esperando tamaño" para siempre. Causa: `getUploadedFileUsing()` armaba la URL de preview con `Media::url()`, que a propósito fuerza el host de la API pública (`stamless.urls.api` → `api.stamless.host`) para que la respuesta del API sea consumible desde un frontend en otro dominio (cica360). Studio vive en su propio host (`stamless.urls.studio` → `studio.stamless.host`) — FilePond, para archivos previsualizables (imagen/video), hace su propio `fetch()` contra esa URL para medir tamaño/tipo (no recibe esos datos del backend de entrada), y esa request cross-origin quedaba bloqueada porque `config/cors.php` solo cubre `v1/*`, no `storage/*`. Fix: nuevo helper privado `MediaUpload::previewUrl()` que resuelve la URL directo del driver del disco (`Storage::disk(...)->url($path)`) sin pasar por el host-completion de `Media::url()` — con disco local/public da una ruta relativa (mismo origen que Studio, sin CORS), y con R2/S3 el driver ya devuelve una URL absoluta propia (sin cambios de comportamiento ahí). `Media::url()` (usado por la API pública) no se tocó.

## 2026-08-30 — Ajustes de UX de Studio sobre ADR-027: CTA único por slide, reorganización de tabs, corrección de posiciones sembradas

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead revisó el form real de Slides en Studio (screenshot del tab "Enlaces y Propiedades") y los 3 mockups finales de lanzamiento, y pidió 3 ajustes:
  1. **Un solo CTA por slide, sin Repeater.** Nuevo `LinkSchema::makeSingle(string $name = 'links')` — bindea los mismos campos que la variante `Repeater` (`LinkSchema::make()`, que **no se tocó**, sigue siendo usada por `PageResource`/`PostResource` para bloques que sí necesitan múltiples enlaces) directo a `"links.0.*"` vía dot-path, mismo patrón que `properties.*` ya usado en `PropertiesSchema`. `links` sigue siendo array en DB/API (un solo elemento) — no cambia el contrato público, solo la UX de edición (ya no se puede "agregar otro enlace" desde un slide).
  2. **Reorganización de tabs del form de Slides:** "Fondo y Multimedia" → "Fondo". "Enlaces y Propiedades" → "Enlaces" (CTA único a la izquierda junto al toggle de video; fieldset "Propiedades" —target de apertura/alt SEO/clase CSS/id HTML— a la derecha, en el espacio que dejó libre "Posición y Contenido" al mudarse). El tab de decoradores/efectos gana la sección "Posición y Contenido" y se renombra "Estilos" (elegido en vez de "Propiedades" —la otra opción que dio el Tech Lead— para no repetir el nombre del fieldset nuevo del tab "Enlaces").
  3. **Corrección de los valores sembrados de `position_container`:** al revisar de nuevo los 3 mockups (ahora provistos como imágenes individuales de alta resolución, "datos finales para lanzamiento"), el contenido de texto/CTA de las 3 slides está claramente anclado ABAJO (con bastante espacio vacío arriba, no centrado verticalmente) — se había sembrado `middle-left`/`middle-left`/`middle-center` en la vuelta anterior (ADR-027), corregido a `bottom-left`/`bottom-left`/`bottom-center` (la alineación horizontal `left`/`left`/`center` ya estaba correcta).
  - Detalle completo de la decisión (por qué `makeSingle()` en vez de tocar `make()`, por qué "Estilos" y no "Propiedades") documentado como actualización de **ADR-027** en `DECISIONS.md`.
- **Archivos/áreas:** `app/Filament/Schemas/LinkSchema.php` (método nuevo `makeSingle()`), `app/Filament/Resources/SliderResource.php` (tabs reorganizados), `database/seeders/Cliente0HomeSlidesSeeder.php` (posiciones corregidas).
- **Pendiente de verificación:** este sandbox no tiene PHP disponible. Falta correr `php artisan db:seed --class=Cliente0HomeSlidesSeeder` (o el seeder completo) para que los valores corregidos se reflejen en una BD ya sembrada, y confirmar visualmente en Studio que el tab "Enlaces" edita/guarda correctamente el CTA único (probar creación Y edición de un slide existente que ya tenía `links` como array — el dot-path `links.0.*` debería leer/escribir sobre el mismo primer elemento sin problema, pero no se pudo probar en vivo).
- **Siguiente:** correr seeders/tests/Pint en local; confirmar visualmente el form de Studio contra el pedido.

## 2026-08-30 — ADR-027: Slide gana `position_container`, `align_content`, decorador inferior configurable y efectos visuales de fondo

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead pidió, para el Hero de cica360 (ver `HERO-3-SLIDES-IMPORTANTS.png`), un set grande de campos de diseño configurables por slide — posición del contenedor de texto/CTA (9 combinaciones), alineación interna del contenido, un decorador SVG inferior (shape + color + opacidad-como-gradiente) y efectos visuales completos sobre la imagen de fondo (color, brillo, opacidad, blend-mode, 6 filtros CSS). Antes de migrar se auditó `SliderResource.php` y se encontró que el proyecto ya resuelve este tipo de campo (posición, alineación, decoradores con color) vía `properties` jsonb + `App\Filament\Schemas\PropertiesSchema` — así que los ~15 campos nuevos se agregaron como claves de `properties`, **no** como columnas dedicadas. Detalle completo de la decisión en **ADR-027** (`DECISIONS.md`). De paso, se eliminó `slides.description` (columna de texto libre sin uso real, a pedido explícito del Tech Lead) — esa sí requirió una migración real (`DROP COLUMN`).
  - 4 Enums PHP nuevos: `PositionContainerEnum`, `AlignContentEnum`, `DecoratorShapeEnum`, `BlendModeEnum` (todos `HasLabel`).
  - `PropertiesSchema::makeComponents()` pasa de 12 a 26 componentes disponibles (agrega `position_container`, `align_content`, `decorator_bottom_opacity` y los 10 `slide_background_*`; migra `decorator_top`/`decorator_bottom` de arrays hardcodeados a `DecoratorShapeEnum::class`).
  - `SliderResource.php`: quitado el campo `description` del tab "Contenido"; nuevo tab "Decorador y Efectos" en el form de Slides.
  - `Slide.php`: quitado `description` de `Fillable`. `SlideResource.php` (API): quitado `description` de la respuesta.
  - `Cliente0HomeSlidesSeeder.php`: las 3 slides del home ahora seedean `properties` completo (`position_container`/`align_content` específicos por slide, según la lectura de `HERO-3-SLIDES-IMPORTANTS.png`: slides 1-2 `middle-left`/`left`, slide 3 `middle-center`/`center`; decorador `wave` blanco sólido; resto de efectos en sus defaults).
  - `docs/context/api/stamless-api-v1.md` (repo cica360) actualizado con la forma completa de `properties` de un Slide y sus defaults.
- **Archivos/áreas:** `app/Enums/{PositionContainerEnum,AlignContentEnum,DecoratorShapeEnum,BlendModeEnum}.php` (nuevos), `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/SliderResource.php`, `app/Models/Slide.php`, `app/Http/Resources/Api/V1/SlideResource.php`, `database/migrations/2026_08_30_000001_drop_description_from_slides_table.php` (nuevo), `database/seeders/Cliente0HomeSlidesSeeder.php`. Consumido en el repo cica360: `src/lib/types.ts` (nuevo `SlideProperties`), `src/components/blocks/Hero.astro` (posición/alineación/decorador/efectos + animación de entrada fija, no configurable).
- **Pendiente de verificación:** este sandbox no tiene PHP disponible — no se pudo correr `php artisan migrate`, `php artisan test` ni `vendor/bin/pint --dirty`. Falta correr la migración nueva y la suite completa en local antes de dar esto por cerrado (revisar en particular que `SliderResource`/`SlideResource` sigan pasando con la columna `description` fuera).
- **Siguiente:** correr migración + tests + Pint en un entorno con PHP. El botón "play video" / `has_presentation_video` mencionado por el Tech Lead ("y boton play a video mas adelante") queda explícitamente fuera de esta vuelta — no implementado todavía en `Hero.astro`.

## 2026-08-30 — `MenuItem` API expone `is_home` resuelto

- **Agente/autor:** Claude
- **Qué se hizo:** el front (cica360) necesitaba excluir el link "Home" del menú de navegación (el logo ya enlaza a `/`), pero comparar `href === '/'` no es seguro — un item de menú puede apuntar a la home con un título/slug distinto ("Inicio", "Portada"). Se agregó `is_home` a la respuesta pública de `GET /menus/{slug}`, resuelto desde `Page.is_home` (la misma fuente que ya se administra en el Studio):
  - `MenuController::attachResolvedHrefs()` ahora también setea un atributo transitorio `resolved_is_home` en el mismo batch de queries que ya resolvía `href` (sin queries extra — reusa el `Page::whereIn(...)->get(['id', 'slug', 'is_home'])` existente).
  - `MenuItemResource` expone `'is_home' => (bool) $this->resolved_is_home`.
  - Siempre `false` para items `post`/`external`/`custom`.
  - Test nuevo: `tests/Feature/Api/V1/MenuApiTest.php` — cubre un item de home con título distinto ("Inicio"), un item normal, y un item custom, verificando `is_home` en los tres casos.
- **Archivos/áreas:** `app/Http/Controllers/Api/V1/MenuController.php`, `app/Http/Resources/Api/V1/MenuItemResource.php`, `tests/Feature/Api/V1/MenuApiTest.php`. Documentado también en `docs/context/api/stamless-api-v1.md` (repo cica360) y consumido en `src/lib/types.ts`/`src/components/Header.astro` (repo cica360).
- **Pendiente de verificación:** este sandbox no tiene PHP disponible — no se pudo correr `php artisan test` ni `vendor/bin/pint --dirty`. Falta correr `php artisan test --compact tests/Feature/Api/V1/MenuApiTest.php` y Pint localmente antes de dar el cambio por cerrado.
- **Siguiente:** correr el test y Pint en un entorno con PHP; si el test falla, revisar el binding de `is_active` en `MenuController::show` (el scope `with(['items' => ...])` filtra por `is_active`, no debería afectar este test ya que no seteamos esa columna explícitamente y su default es `true`).

## 2026-08-28 — HeadingFieldset reutilizable + CSS groupeado en Filament 5

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Creado `app/Filament/Schemas/HeadingFieldset.php` — clase reutilizable que encapsula `pretitle / title / subtitle` con el fieldset groupeado. Acepta `required: bool` y `label: string`.
  - Documentado DOM real de Filament 5: `fieldset.fi-sc-fieldset > div.fi-sc > div.fi-grid-col > div.fi-fo-field > div.fi-input-wrp`. Las clases de Filament 3 (`fi-fo-component-ctn`) no existen en v5.
  - CSS final en `public/css/filament/api-console.css`: esquinas redondeadas arriba (`:first-child`), abajo (`:last-child`), plano en medio.
  - Inyección CSS en Filament 5 resuelta con `->renderHook(PanelsRenderHook::HEAD_END, ...)`. Nota: `->viteTheme()` en Filament 5 **reemplaza** el tema (diferente a v3).
- **Archivos:** `HeadingFieldset.php` (nuevo), `SliderResource.php`, `PanelCmsProvider.php`, `api-console.css`
- **Siguiente:** ajuste fino de borders divisorios (usuario); integración WhatsApp CloudAPI.

---

## 2026-08-27 — Fix: `Media::url()` devolvía ruta relativa (404 en el frontend headless)

- **Agente/autor:** Claude, a pedido del Tech Lead (bug real reportado desde CICA360: `Failed to load resource... 404` en `localhost:4321/storage/media/*.webp`).
- **Qué se hizo:**
  - Causa raíz: `config/filesystems.php`, disco `public`, tiene `'url' => '/storage'` (relativo, default del skeleton de Laravel 13). `Media::url()` delegaba directo en `Storage::disk(...)->url($path)`, así que para media en disco `local`/`public` (el fallback de desarrollo, ver `MediaDiskEnum`) devolvía `/storage/media/xxx.webp` sin host — funciona para consumidores same-origin (previews dentro de Studio), pero es inútil para un frontend headless en otro origen por completo (CICA360/Astro corriendo en `localhost:4321`): el navegador resolvía esa ruta relativa contra su propio origen, no contra el backend, de ahí el 404.
  - Fix en `app/Models/Media.php`: `url()` ahora detecta si el resultado del disco ya es absoluto (`http://`/`https://`, caso de R2/S3 en producción — no se toca) y, si no lo es, lo completa con `config('stamless.urls.api')` (fallback a `config('app.url')`). En local, ambos hosts sirven físicamente el mismo `public/storage` (monolito único, múltiples vhosts sobre el mismo docroot — ver ARCHITECTURE.md §4), así que resuelve sin cambios de infraestructura.
  - Único call site real de `Media::url()` en código de la app: `App\Http\Resources\Api\V1\MediaResource` — no hay tests que aserten el valor exacto de esa URL, y no se tocó ningún otro consumidor.
  - No se pudo correr `php artisan test` en este sandbox (sin PHP) — pendiente de confirmación del humano.
- **Archivos/áreas:** `app/Models/Media.php`.
- **Siguiente:** correr la suite de tests localmente y confirmar en el navegador que las imágenes de CICA360 cargan desde `api.stamless.host/storage/media/...` en vez de `localhost:4321/storage/media/...`.

---

## 2026-08-27 — Rebrand completo Genesisly → Stamless (ADR-026)

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Nuevo `config/stamless.php`: claves `api`, `studio`, `platform`, `graphql`. `config/genesis.php` convertido en alias deprecated.
  - `.env.example`: `APP_NAME=Stamless`, URLs `stamless.host`, renombradas `APP_URL_CONSOLE`→`APP_URL_STUDIO` / `APP_URL_MANAGER`→`APP_URL_PLATFORM`. `SANCTUM_STATEFUL_DOMAINS` actualizado.
  - Todos los callsites `config('genesis.urls.*')` en código ejecutable reemplazados por `config('stamless.urls.*')`: `bootstrap/app.php` (×3), `routes/web.php` (×3), `routes/api.php` (×1), `PanelCmsProvider` (×1), `ApiPlayground.php` (×2), `api-documentation.blade.php` (×1), `api-playground.blade.php` (×2), `TestCase.php` (×1), `ExampleTest.php` (×1).
  - `PanelManagerProvider.php` → nuevo `PanelPlatformProvider.php` (`->id('platform')`, domain `stamless.urls.platform`, discover paths `Filament/Platform/`). Registrado en `bootstrap/providers.php`.
  - `PanelCmsProvider`: dominio actualizado a `stamless.urls.studio`.
  - Landing `home.blade.php`: `<title>Stamless</title>`, `<h1>Stamless</h1>`.
  - Seeder `PlatformSeeder`: `admin@genesisly.host` → `admin@stamless.com`.
  - Docs API (`v1.md`, `openapi.v1.yaml`): todos los hosts a `api.stamless.host` / `studio.stamless.host`.
  - Nueva ruta: `Route::domain(graphql.stamless.host)` → 404 vacío en cualquier path (host reservado, sin Lighthouse).
  - Ruta `genesis.home` → `stamless.home`.
  - `ARCHITECTURE.md`: tabla de hosts (×2) con las 5 filas Stamless, texto "Genesis CMS" → "Stamless", panel descriptions.
  - `DECISIONS.md`: ADR-022 marcado `Superseded by ADR-026`; ADR-026 agregado completo al índice y al body.
  - `CURRENT_STATE.md`: actualizado timestamp, estado de salud, referencias de host.
  - `tests/Feature/Filament/ApiTokensRegenerateTest.php`: comentario `console.genesisly.host` → `studio.stamless.host`.
  - `cors.php`: comentario actualizado.
  - `AppServiceProvider.php`: comentario actualizado.
  - **Verificación grep final**: 0 resultados `genesisly` en `app/ config/ resources/ database/seeders/ tests/ routes/ bootstrap/ .env.example`.
- **Archivos/áreas:** `config/`, `bootstrap/`, `routes/`, `app/Providers/`, `app/Filament/`, `resources/views/`, `database/seeders/`, `tests/`, `docs/`, `.env.example`
- **Siguiente:**
  - Actualizar `.env` local con las nuevas variables (`APP_URL_STUDIO`, `APP_URL_PLATFORM`).
  - Agregar 5 hosts en `/etc/hosts` y 5 vhosts SSL en MAMP PRO.
  - Regenerar token Sanctum de prueba local (el host cambió).
  - Correr `php artisan test --compact` para confirmar que todo pasa.
  - Continuar con frontend CICA360 (Astro vs Next.js — ADR pendiente).

---

## 2026-08-20 — Corrección final: método estático makeComponents() en PropertiesSchema

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Creado y expuesto el método estático `makeComponents()` en `PropertiesSchema.php` que retorna el listado crudo de campos de propiedades en formato de array.
  - Reemplazada la llamada `make()->getComponents()` en `SliderResource.php` por `makeComponents()`. Esto corrige la excepción `BadMethodCallException` puesto que `Group` en esta versión de Filament no expone un getter público de componentes en su interfaz macroable.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
- **Siguiente:**
  - Foco único en el frontend CICA360.

---

## 2026-08-20 — Corrección de ancho de campos en el Fieldset de Diseño y Posición

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Desempaquetado el array de componentes de `PropertiesSchema` directamente sobre el `Fieldset` utilizando `getComponents()`.
  - Esto eliminó el wrapper intermedio del componente `Group`, forzando a los campos internos (ColorPicker, Selects, Slider) a expandirse a todo lo ancho de las columnas asignadas dentro del Fieldset.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
- **Siguiente:**
  - Continuar con el desarrollo/maquetación del front de CICA360.

---

## 2026-08-20 — Refactor de Propiedades de diseño: Fieldset y reordenación de content_position

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Envueltas las propiedades de diseño de la derecha bajo un componente `Fieldset` con la leyenda "Diseño y Posición".
  - Reubicado el campo de selección `properties.content_position` para que se posicione después de la alineación del texto (`text_align`).
  - Configurado `content_position` para ocupar 1 columna (removiendo `columnSpanFull`), permitiendo que todos los campos del panel de propiedades se muestren en un grid balanceado de 2 columnas.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
- **Siguiente:**
  - Continuar con el desarrollo/maquetación del front de CICA360.

---

## 2026-08-20 — Refactor de LinkSchema: layout de 2 columnas y uso de Fieldset

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Modificado el esquema de Enlaces/CTAs (`LinkSchema.php`) para estructurar todos los campos del formulario en un layout limpio de 2 columnas.
  - Agrupados los campos de metadatos avanzados (Destino de apertura, Texto Alt SEO, Clase CSS, ID HTML) dentro de un componente `Fieldset` con la leyenda "Propiedades del enlace", separándolos visualmente y en 2 columnas internas.
- **Archivos/áreas:**
  - `app/Filament/Schemas/LinkSchema.php`
- **Siguiente:**
  - Foco único en el frontend CICA360.

---

## 2026-08-20 — Refactor final y compactación de la pestaña de Enlaces y Propiedades en el Slider

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Rediseñado el tab de **Enlaces y Propiedades** en una grilla de 4 columnas para aliviar la saturación visual.
  - Mitad izquierda (2 columnas) asignada al listado de Enlaces/CTAs (`LinkSchema`) y al interruptor/ID de video de presentación.
  - Mitad derecha (2 columnas) asignada a los campos de personalización de diseño (`PropertiesSchema`) dispuestos en una grilla interna de 2 columnas.
  - Creada e integrada la nueva propiedad de diseño `properties.content_position` en `PropertiesSchema.php` con opciones de alineación vertical y horizontal para posicionar el contenedor de títulos + botón (ej. `left-top`, `center-middle`, `right-bottom`, etc.).
  - Configurado `collapsible()->collapsed()` en las secciones principales del formulario (`General` y `Diapositivas`) para que comiencen contraídas por defecto en el panel.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
- **Siguiente:**
  - Continuar con el desarrollo/maquetación del front de CICA360.

---

## 2026-08-20 — Refactor de SliderResource para optimización de UX

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Optimizado el formulario de `SliderResource` para mejorar su UX en modo `slideOver()`.
  - Implementado un diseño en Grid de 3 columnas para separar el formulario "General" (1/3) de las "Diapositivas" (2/3) y evitar colisiones visuales.
  - Reemplazadas las secciones anidadas y pesadas dentro de cada diapositiva (Repeater) por un componente de pestañas (`Tabs` con pestañas para `Contenido`, `Fondo y Multimedia`, y `Enlaces y Propiedades`), simplificando drásticamente el espacio vertical y el orden mental.
  - Ampliado el ancho del slideover modal a `FiveExtraLarge` en las acciones `EditAction` y `CreateAction` (tanto en la tabla como en la cabecera de la página) para dar espacio cómodo a los campos.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/SliderResource/Pages/ManageSliders.php`
- **Siguiente:**
  - Continuar con las tareas de maquetación del frontend CICA360.

---

## 2026-08-20 — Cierre de lado producto (API); foco único = front CICA360

- **Agente/autor:** Grok CLI (handoff explícito del humano)
- **Qué se hizo:** El humano declara **cerrado el lado producto/API**. No hay más trabajo de backend en esta fase. Documentado el único foco de la próxima sesión.
- **Cerrado de producto:**
  - REST v1 (`/v1/{tenant_slug}/...`, sin prefijo `/api`, host API Genesisly)
  - Auth Sanctum (Bearer, abilities `content:read` / `forms:submit`)
  - Envelope de error formalizado
  - Seeds CICA360 (tenant, contenido, menú, slider home, form contacto)
  - Playground + docs en Console
  - Hosts Genesisly (`api` / `console` / `manager` / landing)
- **Único foco al volver:** frontend CICA360 en shared hosting, consumiendo:
  - `GET /v1/cica360/pages`
  - `GET /v1/cica360/pages/{slug}`
  - `GET /v1/cica360/menus/menu-principal`
  - `GET /v1/cica360/sliders/home`
  - `POST /v1/cica360/forms/contacto/submit`
- **Fuera hasta que el sitio esté en el aire:** GraphQL, marca fina, polish de Console (Contacts Resource, FriendlyDate resto).
- **Archivos/áreas:** `docs/context/CURRENT_STATE.md`, `docs/context/TASK.md`, este log.
- **Siguiente:** P2 #11 — elegir Astro vs Next (static export) + ADR + scaffold.

---

## 2026-08-20 — Regenerar API Tokens (Sanctum) en Console

- **Agente/autor:** Claude (ticket: "Regenerar API Tokens (Sanctum) en Console").
- **Qué se hizo:** `App\Filament\Pages\ApiTokens` gana una acción de tabla "Regenerar" junto a "Revocar" (icono refresh, color `warning`). Al confirmar ("La clave anterior dejará de funcionar de inmediato."): borra el `PersonalAccessToken` actual, crea uno nuevo con `$user->createToken($name, $abilities, $expiresAt)` clonando `name`/`abilities` exactos; `expires_at` se clona tal cual si el token no había vencido, o se pide elegir una nueva ventana (Nunca/1/30/90/365 días, mismo `Select` que usa "Crear token") si ya estaba expirado — el form de la acción es condicional según `$record->expires_at?->isPast()`. El plaintext nuevo se muestra una sola vez reutilizando el mismo banner Alpine (`$plainTextToken`) que ya usaba "Crear token" — sin cambios de vista. Scoping: `abort_unless($record->tokenable_type === User::class && $record->tokenable_id === auth()->id(), 403)` dentro del `action()` — un user no puede regenerar el token de otro user del mismo tenant (403); un token de otro tenant ni siquiera resuelve como record (`getTableQuery()` ya scopea por tenant), así que da 404 antes de llegar al check de ownership.
- **Tests:** `tests/Feature/Filament/ApiTokensRegenerateTest.php` (nuevo, primer test de este repo que ejercita un `Filament\Pages\Page` custom vía `Livewire::test()` + `callTableAction()` — patrón: `actingAs($user)` + `Filament::setCurrentPanel(Filament::getPanel('cms'))` + `Filament::setTenant($tenant)`, sin necesidad de simular el dominio real de Console porque `Livewire::test()` monta el componente directo, no pasa por el router). 6 tests: token viejo deja de autenticar (`PersonalAccessToken::findToken()` devuelve `null`) y el nuevo sí resuelve "limpio" (`last_used_at = null`); abilities no cambian; expiración se clona si no había vencido; expiración vencida exige elegir una nueva (falla de validación si no se manda, éxito si se manda); un user no regenera el token de otro user del mismo tenant (403); un user no regenera un token de otro tenant (`ActionNotResolvableException`, ver abajo). Se verificaron a mano las firmas exactas de los helpers de testing de Filament/Livewire/Sanctum contra el código real en `vendor/` (presente en el repo) antes de escribir los tests, en vez de asumir la API de memoria — pero no había forma de ejecutar la suite en este sandbox (sin PHP/Composer).
- **Corrección tras la corrida real del humano**: `php artisan test --filter=ApiTokensRegenerateTest` dio **5/6 en verde**; el único fallo fue `a user cannot regenerate a token from a different tenant`, que esperaba un 404 HTTP limpio pero el framework tiraba `Filament\Actions\Exceptions\ActionNotResolvableException` (excepción PHP interna, no HTTP) — Filament no logra resolver el `record` cuando no está dentro de `getTableQuery()` (que ya scopea por tenant) y aborta ahí mismo, **antes** de llegar al `action()` closure donde vive el check de ownership de esta feature. No era un bug de la feature (el token de otro tenant nunca se toca, el aislamiento funciona) sino una expectativa incorrecta del test — corregido para esperar `ActionNotResolvableException` explícitamente (con `try/catch` + `$this->fail()` si no se lanza) en vez de un status 404. **Pendiente**: re-correr `php artisan test --filter=ApiTokensRegenerateTest` para confirmar 6/6 en verde tras este fix (no se pudo re-ejecutar desde este sandbox).
- **No se tocó:** ninguna ruta de API/GraphQL/landing, ningún seed de contenido, ninguna migración (se reutilizan las columnas `last_four`/`abilities`/`expires_at` ya existentes de ADR-018/019) — no hizo falta ADR nuevo, es una Action de Filament sobre el modelo ya aceptado.
- **Archivos/áreas:** `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensRegenerateTest.php` (nuevo).
- **Siguiente:** correr `php artisan test` localmente y confirmar los 6 tests nuevos en verde junto con el resto de la suite; probar manualmente en `console.genesisly.host` → Desarrolladores → API Tokens.

---

## 2026-08-20 — Auditoría y consolidación del ecosistema de dominios/rutas/seguridad/excepciones

- **Agente/autor:** Claude (Tech Lead pidió revisar todos los cambios directos de arquitectura/infraestructura hechos en dominios/subdominios, convención de rutas, seguridad y excepciones — varios de esos cambios los había hecho otro agente, Antigravity/Gemini, en la misma jornada, ver entrada de abajo — para dejar el estado 100% documentado y consistente para cualquier agente futuro).
- **Qué se hizo:** Auditoría cruzada de código real vs. docs (no se asumió nada, se leyó cada archivo fuente): `routes/api.php`, `routes/web.php`, `bootstrap/app.php`, `config/genesis.php`, `config/cors.php`, `app/Http/Middleware/ResolveTenant.php`, `PanelCmsProvider`/`PanelManagerProvider`, `.env.example`, `ApiPlayground.php`, `tests/TestCase.php` + tests de `Api/V1`, `DECISIONS.md` (ADR-012 a ADR-025), `docs/api/v1.md`, `docs/api/openapi.v1.yaml`. Confirmado: la migración a `Route::domain()` + `apiPrefix: ''` (ADR-025, sin prefijo `/api`) ya estaba completa y consistente en código y tests (`TestCase::prepareUrlForRequest()` resuelve el host de la API automáticamente para cualquier request a `/v1/...`). Se encontraron y corrigieron 6 inconsistencias reales:
  1. **Bug funcional real**: `config/cors.php` seguía con `'paths' => ['api/*']` después de adoptar ADR-025 — como `HandleCors` matchea por path (no por Host) y la API ya no tiene ese prefijo, CORS había dejado de aplicarse a la API **en silencio**, sin error visible. Corregido a `'paths' => ['v1/*']`.
  2. `routes/api.php`: comentario de cabecera todavía decía "vive bajo `/api/v1/{tenant_slug}`" — corregido.
  3. `docs/context/ARCHITECTURE.md` §4: URL del health check documentada como `https://api.genesisly.host/api/v1/health` (con `/api` de más) — corregida a `/v1/health`. También corregida en `CURRENT_STATE.md`.
  4. `docs/context/ARCHITECTURE.md`: quedaban restos muy desactualizados de una versión pre-Sanctum/pre-Laravel-13 del documento (tabla de stack con "Auth: Sanctum/Passport (TBD)", "Laravel 11/12", fecha de última actualización 2026-08-11, sección §9 con un borrador de API sin auth ni `{tenant_slug}`) — actualizados/reescritos para reflejar el estado real, con cross-links explícitos a ADR-012/016/018/020/023/024/025.
  5. `docs/api/v1.md` y `docs/api/openapi.v1.yaml`: los ejemplos de response de error (403, 422) todavía mostraban el formato **pre-ADR-024** (mensaje `"El token no pertenece a este tenant."` en vez del mensaje fijo genérico "No tenés permiso para este recurso.", y `errors.detail` de texto libre en vez de `errors.code`+`errors.fields` para 422) — corregidos en ambos archivos para que coincidan exactamente con lo que devuelve `bootstrap/app.php` hoy. También se agregó `errors.code` a los ejemplos 401/404/429 del OpenAPI que no lo tenían.
  6. `docs/context/ARCHITECTURE.md` §"Seguridad y Excepciones": expandida con la regla crítica de "mensaje fijo por status, nunca `$e->getMessage()`" (el bug de `prepareException()`/Sanctum documentado en ADR-024) para que ningún agente futuro reintroduzca ese bug al tocar el handler.
- **Verificado, no solo asumido:** se confirmó con un script de balance de paréntesis/llaves/corchetes (sin PHP en este sandbox) que `config/cors.php`, `routes/api.php`, `routes/web.php` y `bootstrap/app.php` quedaron sintácticamente balanceados tras los edits.
- **No se tocó**: ningún código de negocio, ninguna decisión de arquitectura nueva (todo lo corregido ya estaba decidido en ADRs existentes, especialmente ADR-025) — no se creó ADR nuevo a propósito, esto es un pase de consistencia, no una decisión.
- **Archivos/áreas:** `config/cors.php`, `routes/api.php`, `docs/context/ARCHITECTURE.md`, `docs/context/CURRENT_STATE.md`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Siguiente:** el estado de dominios/rutas/seguridad/excepciones ya está consolidado y verificado end-to-end (código + tests + docs se dicen lo mismo). Retomar el Filament Resource de Contacts o decidir el frontend Cliente 0.

---

## 2026-08-20 — Eliminación de dominios hardcodeados en todo el proyecto

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Removidos **todos** los dominios/subdominios hardcodeados fuera de `.env` y comentarios de documentación:
    - `routes/web.php` L5: `'genesisly.host'` → `parse_url(config('app.url'), PHP_URL_HOST)` (mismo patrón que ya usaban las demás route groups del archivo).
    - `config/cors.php`: `allowed_origins` (array estático con `http/https://genesisly.host`) y `allowed_origins_patterns` (regex hardcodeada) → ambos derivados dinámicamente de `config('app.url')`.
    - `resources/views/public/home.blade.php` footer: texto literal `genesisly.host` → `{{ parse_url(config('app.url'), PHP_URL_HOST) }}`.
    - `database/seeders/Cliente0Seeder.php`: constante `CONSOLE_DOMAIN = 'console.genesisly.host'` → método estático `consoleDomain()` que lee `config('genesis.urls.console')`.
    - `tests/Feature/ExampleTest.php`: todos los URLs absolutos con dominio hardcodeado → helpers privados `landingUrl()` / `apiUrl()` que derivan de `config('app.url')` y `config('genesis.urls.api')`.
    - `tests/TestCase.php`: fallback literal `'api.genesisly.host'` en `prepareUrlForRequest()` → `config('genesis.urls.api', config('app.url'))`.
  - Configurado `composer dev` para excluir el proceso `server` (`php artisan serve`) via `DevCommands::except('server')` en `AppServiceProvider::boot()` — necesario porque MAMP PRO ya sirve la app en `https://genesisly.host` y el proceso `server` conflictuaba.
  - Instalado paquete `fontaine` (npm) para eliminar warning de Vite sobre fallbacks de fuente optimizados.
- **Archivos/áreas:**
  - `routes/web.php`
  - `config/cors.php`
  - `resources/views/public/home.blade.php`
  - `database/seeders/Cliente0Seeder.php`
  - `tests/Feature/ExampleTest.php`
  - `tests/TestCase.php`
  - `app/Providers/AppServiceProvider.php`
  - `package.json` / `package-lock.json` (fontaine)
- **Regla establecida:** Ningún dominio o subdominio puede estar hardcodeado en código ejecutable. Toda referencia a dominios debe ir a través de `config('app.url')`, `config('genesis.urls.api')`, `config('genesis.urls.console')`, o `config('genesis.urls.manager')`, que a su vez leen de `APP_URL`, `APP_URL_API`, `APP_URL_CONSOLE`, `APP_URL_MANAGER` en `.env`.
- **Siguiente:**
  - Correr `php artisan test` para confirmar que los tests refactorizados siguen en verde.
  - Continuar con las tareas pendientes de TASK.md.

## 2026-08-20 — Ocultamiento de bienvenida de Laravel en subdominios y API root segura

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Corregido el problema de visualización de la pantalla de bienvenida por defecto de Laravel ("Let's get started") al acceder a la raíz del subdominio de API (`api.genesisly.host`).
  - La raíz `/` del subdominio de API ahora retorna una respuesta HTTP `404 Not Found` completamente vacía (`response('', 404)`), haciendo que la raíz de la API luzca como si no estuviera configurada (silencio total de cara a bots y escáneres).
  - Implementado el endpoint público `/api/v1/health` en `api.genesisly.host` para realizar pruebas de conexión a la base de datos y proveer un mecanismo estándar para ping-pong y monitoreo de uptime.
  - Modificado el fallback genérico de la ruta raíz `/` de Laravel para redirigir de manera segura a la landing page principal (`genesisly.host`), y cualquier intento de acceder a `/api/*` o `/graphql/*` en el dominio principal (`genesisly.host`) o a `/graphql/*` en el de la API (`api.genesisly.host`) es capturado para devolver una respuesta `404` completamente vacía.
  - Creados nuevos tests automatizados en `tests/Feature/ExampleTest.php` para validar el enrutamiento correcto de los dominios principal, API (404 root, 200 health, 404 graphql), rutas `/api` y `/graphql` en landing domain, y fallbacks no mapeados.
- **Archivos/áreas:**
  - `routes/web.php`
  - `tests/Feature/ExampleTest.php`
- **Siguiente:**
  - Continuar con el Filament Resource de Contacts o la API v1.

## 2026-08-19 — Envelope de error API formalizado (ADR-024, extiende ADR-023)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Ticket explícito: formalizar el envelope de error de `/api/*` con una tabla de mapeo completa (excepción → status → `errors.code` snake_case → mensaje), reutilizando el helper existente (`ApiResponds`) en vez de duplicar lógica en el handler global, y agregar cobertura de test para los casos principales.
  - Nueva clase `App\Support\Api\ErrorEnvelope` (estática, no trait): el handler de excepciones vive en un `Closure` de `bootstrap/app.php` sin `$this`, así que no puede usar un trait — pero sí un método estático. `App\Http\Concerns\ApiResponds::error()` ahora delega en `ErrorEnvelope::make()` en vez de armar el array a mano — un error devuelto por un controller y uno armado por una excepción no capturada tienen garantizado el mismo shape exacto. (Se descartó explícitamente intentar llamar un método estático de un trait directamente como `ApiResponds::metodo()` sin una clase que lo use — la semántica de PHP para eso es ambigua y no se pudo verificar en este sandbox sin PHP; una clase estática dedicada es inequívoca.)
  - `bootstrap/app.php` reescrito: mapa completo `status` → `errors.code` → `message` para `AuthenticationException` (401, distingue `unauthenticated` sin token de `token_invalid` con token presente pero inválido — antes, ADR-023, ambos casos compartían el mismo `errors.code`), `AuthorizationException`/abilities de Sanctum (403, `forbidden`, siempre mensaje genérico en español — el default de Sanctum es `"Invalid ability provided."` en inglés y nunca debe llegar al cliente tal cual), `ModelNotFoundException`/`NotFoundHttpException` (404, `not_found`, preserva mensajes custom propios como `"Tenant no encontrado."` de `ResolvesTenant`/ADR-018 cuando existen), `ValidationException` (422, `validation`, con `errors.fields`), `ThrottleRequestsException` (429, `too_many_requests`, agregado explícito — antes caía por el fallback genérico de `HttpExceptionInterface`), y `Throwable` no controlado (500, `server_error`, `errors.detail` con el mensaje real **solo si `APP_DEBUG=true`**, nunca un stack trace, nunca en producción).
  - `POST forms/{slug}/submit`: los campos requeridos de un `Form` son datos configurables por tenant (`FormField.is_required`), no un rule set estático de Laravel — así que no hay un `FormRequest`/`Validator` real detrás, y `ContactSubmissionService::assertRequiredFieldsPresent()` tiraba un `InvalidArgumentException` genérico con un string libre (`"Faltan campos requeridos: email, message"`), sin desglose por campo. Se creó `App\Exceptions\Api\MissingRequiredFieldsException` (extiende `InvalidArgumentException` A PROPÓSITO, no `ValidationException`) con un método `fields()` que arma `{ campo: [mensajes] }`. Se evaluó y descartó migrar a `Illuminate\Validation\Validator` real: hubiera roto `ContactSubmissionServiceTest::test_it_throws_when_a_required_field_is_missing()` (hace `expectException(InvalidArgumentException::class)`, y `ValidationException` no extiende esa clase) y dependía de archivos de idioma `resources/lang/es/validation.php` no confirmados en este sandbox. `FormSubmissionController::store()` gana un catch específico para la nueva excepción (antes del catch genérico de `InvalidArgumentException`, que se mantiene como fallback).
  - Tests: reforzados `ApiAuthTest` (mensaje/código exacto del 401 sin token dropeando "el header" del texto — ver nota de wording más abajo —, `errors.code: token_invalid` para token inválido, `errors.code: forbidden` + mensaje genérico para token de otro tenant y para ability faltante) y `PageApiTest` (`errors.code: not_found` en tenant inexistente/inactivo). Test file nuevo `tests/Feature/Api/V1/FormSubmissionApiTest.php` — `forms/submit` no tenía NINGUNA cobertura a nivel API todavía (solo a nivel de servicio en `ContactSubmissionServiceTest`): cubre cuerpo vacío → 422 con `errors.fields` completo, y el happy path 201.
  - Wording: el mensaje 401 "sin token" pasó de "No autenticado. Enviá un token Bearer en el header Authorization." (ADR-023) a "No autenticado. Enviá un token Bearer en Authorization." (ADR-024, texto pedido explícitamente en el ticket) — actualizado en el test y en `docs/v1.md`.
  - `docs/v1.md` (tabla de errores, client-facing): agregada columna `errors.code`, actualizados todos los ejemplos de respuesta para incluir el `errors.code` real de cada caso, agregada nota de que `errors.code` es estable para lógica de cliente y `message` puede cambiar de texto.
  - Extra (no pedido explícitamente, pero directamente en el espíritu del ticket — "formalizar el envelope para TODA la API"): los 404 que devuelven los controllers directamente sin pasar por una excepción (`PageController`/`MenuController`/`MediaController`/`SliderController`/`PostController`/`FormSubmissionController`, cada uno con su `$this->error('X no encontrada.', 404)`) ahora también incluyen `errors.code: 'not_found'` — antes solo los 404 que SÍ pasaban por una excepción (vía `ResolvesTenant`) tenían `errors.code`; los explícitos no tenían `errors` en absoluto. Cambio mecánico y seguro (agregar un tercer argumento a una llamada ya existente), ningún test dependía de la ausencia de esa clave.
- **Archivos/áreas:** `bootstrap/app.php`, `app/Support/Api/ErrorEnvelope.php` (nuevo), `app/Http/Concerns/ApiResponds.php`, `app/Exceptions/Api/MissingRequiredFieldsException.php` (nuevo), `app/Services/ContactSubmissionService.php`, `app/Http/Controllers/Api/V1/{FormSubmissionController,PageController,MenuController,MediaController,SliderController,PostController}.php`, `tests/Feature/Api/V1/{ApiAuthTest,PageApiTest}.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php` (nuevo), `docs/v1.md`, `docs/context/DECISIONS.md` (ADR-024)
- **Siguiente:** correr `php artisan test` — no se pudo ejecutar desde este sandbox (sin PHP disponible). Prestar atención especial a que `ContactSubmissionServiceTest` siga pasando sin cambios (la nueva excepción es un `InvalidArgumentException` a propósito, no debería romper nada, pero es la garantía más frágil de este cambio).

### Follow-up (mismo día): bug real encontrado por el humano al correr `php artisan test`

El humano corrió la suite completa y mandó screenshot: 25/26 en verde, 1 falla real —
`ApiAuthTest::test_token_without_the_required_ability_is_forbidden` esperaba el mensaje
genérico `'No tenés permiso para este recurso.'` y recibió `'Invalid ability provided.'`
(el mensaje default de Sanctum, en inglés, filtrado tal cual).

Causa raíz (leída del vendor, no supuesta): `Illuminate\Foundation\Exceptions\Handler::render()`
llama a `prepareException($e)` **antes** de correr cualquier callback registrado con
`$exceptions->render()`, y `prepareException()` convierte incondicionalmente TODA
`AuthorizationException` (incluida `Laravel\Sanctum\Exceptions\MissingAbilityException`,
que extiende esa clase) en `AccessDeniedHttpException` — una clase completamente
distinta que ya NO es `instanceof AuthorizationException`. Mi código original tenía
`$e instanceof AuthorizationException => 'No tenés permiso...'` como caso especial
ANTES del fallback genérico `$e->getMessage() ?: '...'`, pero esa rama nunca podía
matchear (el tipo real ya cambió), así que siempre caía al fallback, que sí preservaba
`$e->getMessage()` — y ese mensaje resultó ser el default crudo de Sanctum.

Fix: se sacó la dependencia de `$e->getMessage()` como fallback para 401/403/404/429 —
ahora el `message` es **fijo por status**, sin excepciones, tal como pedía la tabla
original del ticket. El detalle específico de la excepción (cuando existe) solo se
expone en `errors.detail`, y únicamente para `500` bajo `APP_DEBUG=true` — no para
403/404, para no arriesgar filtrar texto de librerías de terceros en esos casos
tampoco. Se agregó un comentario extenso en `bootstrap/app.php` explicando el
comportamiento de `prepareException()` para que no se vuelva a asumir que
`instanceof AuthorizationException` funciona ahí adentro.

- **Archivos/áreas:** `bootstrap/app.php`
- **Siguiente:** re-correr `php artisan test` — debería quedar 26/26 en verde ahora (no se pudo confirmar desde este sandbox, sin PHP).

---

## 2026-08-19 — Fix: 401 de la API filtraba `route('login')` (ADR-023)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Ticket reportado con repro exacto: `GET /v1/{tenant}/pages` sin `Authorization` devolvía `422` con `{"success":false,"message":"Route [login] not defined.","status_code":422}` en vez de un `401` limpio.
  - Investigado leyendo el vendor de Laravel directamente (no supuesto): `Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()` registra por defecto `redirectGuestsTo(fn () => route('login'))` **antes** de correr el callback de `bootstrap/app.php`. Esta app es 100% headless y nunca definió una ruta `login`. Cuando `Illuminate\Auth\Middleware\Authenticate::unauthenticated()` evalúa `$request->expectsJson() ? null : $this->redirectTo($request)` y `expectsJson()` da `false` (clientes que no mandan `Accept: application/json` — curl liso, herramientas que no lo declaran), intenta resolver `route('login')`, que no existe, y tira `Symfony\Component\Routing\Exception\RouteNotFoundException` (subclase de `\InvalidArgumentException`) **antes** de que la `AuthenticationException` real llegue a construirse. Nuestro handler de `bootstrap/app.php` clasifica esa `InvalidArgumentException` como `422` (correcto para otros casos), de ahí el código y mensaje equivocados. Los tests existentes de `ApiAuthTest` no lo detectaban porque todos usan `getJson()`, que fuerza `Accept: application/json` y evita el bug — confirmado que es un gap real de cobertura, no una casualidad.
  - Fix en `bootstrap/app.php`: `$middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'))` — para `/api/*` el guest nunca intenta redirigir a nada, sin importar el header Accept, así que `AuthenticationException` se construye normal y la maneja el `$exceptions->render()` que ya existía (ADR-018).
  - De paso, mejora pedida explícitamente: el envelope 401 ahora distingue "no mandaste token" ("No autenticado. Enviá un token Bearer en el header Authorization.") de "mandaste un token pero es inválido/expiró/fue revocado" ("Token inválido o expirado.") — mirando si el request trae `Authorization: Bearer ...` (Sanctum no lanza excepciones distintas para cada caso, así que esa es la única señal disponible). Ambos casos agregan `errors: {"code": "unauthenticated"}` al payload. `AuthorizationException` sigue devolviendo `403` sin cambios — no se tocó nada de eso ni las abilities de Sanctum.
  - 3 tests nuevos en `ApiAuthTest`: (1) reproduce el escenario exacto del bug usando `$this->get()` (no `getJson()`, a propósito, para no mandar `Accept: application/json`) y confirma `401` sin la palabra "login" en el body; (2) confirma el mensaje distinto para token inválido (`Bearer token-que-no-existe`); (3) el test original de "sin token" ahora también verifica `message` y `errors.code`.
  - No se creó ninguna ruta `login` web (a propósito, según lo pedido) — el mismo problema late para rutas `web/*` protegidas por auth si algún día existieran, pero hoy `routes/web.php` no tiene ninguna, así que queda fuera de alcance.
  - Escrito ADR-023 (corto) documentando causa raíz, decisión y alternativas descartadas (crear ruta login dummy; parchear el mensaje de la excepción en vez de evitar que ocurra).
- **Archivos/áreas:** `bootstrap/app.php`, `tests/Feature/Api/V1/ApiAuthTest.php`, `docs/v1.md` (tabla de errores 401 actualizada a los dos mensajes reales), `docs/context/DECISIONS.md` (ADR-023)
- **Siguiente:** correr `php artisan test` — no se pudo ejecutar desde este sandbox (sin PHP disponible), pendiente de confirmación del humano. Si algo no compila, revisar primero `bootstrap/app.php` (es el archivo más sensible de este fix).

---

## 2026-08-18 — Landing page pública tipo teaser (estilo Apple) en genesisly.host

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Rediseñada la vista `public.home` en `resources/views/public/home.blade.php` para actuar como un teaser estilo Apple extremadamente minimalista (silencio visual completo sin cajas ni bordes, alineación óptica ligeramente elevada, tipografías e incrementos de tamaño fluidos, animaciones sutiles de entrada, fondo oscuro #0B0C0E y footer dividido en los extremos: 'genesisly.host' y 'Pronto' en dorado).
  - Registrada la ruta en `routes/web.php` condicionada al dominio `genesisly.host` para evitar conflictos con otras rutas y asegurar que el dominio principal sirva la landing.
  - Verificada la compatibilidad y correcto funcionamiento ejecutando la suite de pruebas `php artisan test` con resultado exitoso al 100% (26 tests pasados).
- **Archivos/áreas:**
  - `resources/views/public/home.blade.php`
  - `routes/web.php`
- **Siguiente:**
  - Retomar el Filament Resource de Contacts (#19b) o extender FriendlyDate.

## 2026-08-17 — Fix: falso "sombreado de selección" en bloques de código de API Documentation (modo oscuro)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano reportó (con screenshot) que el JSON de ejemplo en **API Documentation** se veía con una caja redondeada de fondo por cada línea, como si el texto estuviera seleccionado — confuso para el usuario.
  - Investigado leyendo el CSS propio (no había ninguna regla de `background` en `.gnss-json-*` ni en `.token.*` de Prism) y descartando `::selection` (ni nuestro CSS ni el bundle de Filament definen uno que aplique acá — los únicos `::selection` de Filament son de CodeMirror, no relevantes). La causa real: especificidad CSS. `.gnss-prose pre code { background: none; ... }` (especificidad 0,1,2) está pensada para resetear el fondo del código inline (`.gnss-prose code`, pensado para texto entre backticks sueltos) cuando ese `<code>` es en realidad el que envuelve un fence ```` ```json ```` completo dentro de un `<pre>`. Pero `html.dark .gnss-prose code { background: rgb(255 255 255 / 0.08); ... }` (especificidad 0,2,2) es MÁS específica, así que en modo oscuro gana ella igual, sin importar el orden en el archivo. Al ser `<code>` un elemento inline envolviendo contenido de varias líneas, ese fondo se renderiza como una caja redondeada separada por cada línea visual — visualmente indistinguible de una selección de texto.
  - Fix: nueva regla `html.dark .gnss-prose pre code { background: none; }`, con la misma especificidad exacta que la regla que ganaba, para neutralizarla específicamente dentro de bloques `<pre>` sin tocar el estilo de código inline normal (que sigue viéndose bien fuera de los fences).
- **Archivos/áreas:** `public/css/filament/api-console.css`
- **Siguiente:** confirmar en browser (modo oscuro) que el JSON de ejemplo ya no muestra esa caja por línea.

---

## 2026-08-17 — Limpieza de lenguaje interno en la documentación pública de la API

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano señaló que `docs/v1.md` (mostrado tal cual dentro de Console → **API Documentation**, es lo que lee el desarrollador del frontend del tenant) tenía frases como *"Está pensada para un único idioma por MVP"* y *"Desde ADR-018..."* — jerga interna de gestión de proyecto que un cliente final no entiende, y que además sugiere que la plataforma está en una "etapa de prueba" (riesgo real: el cliente no quiere dejar sus datos en algo que suena a beta/incompleto).
  - Barrido completo de `docs/v1.md` y `docs/api/openapi.v1.yaml` (el spec OpenAPI vinculado desde la misma página) sacando: toda mención a "MVP", toda referencia a `ADR-XXX`, y el link/mención a `CURRENT_STATE.md` (documento interno de gestión). Reescrito manteniendo el contenido técnico real, solo sin el envoltorio de "esto es un plan interno" (ej. "Está pensada para un único idioma por MVP" → "Actualmente opera en un único idioma"; "Desde ADR-018, toda la API requiere..." → "Toda la API requiere...").
  - La sección "Pendientes conocidos (honestidad ante todo)" se renombró a "Limitaciones actuales" y se le sacaron los ítems que revelaban roadmap/bloqueadores internos (ej. "bloqueador de Cloudflare R2", "cambio de contrato público, pendiente como su propio paso") — quedan solo limitaciones técnicas reales y neutras (sin rotación automática de tokens, sin validación de `mime_type`, campos de media en `null` hasta que se cargue el archivo).
  - De paso se encontró y corrigió una inconsistencia real (no cosmética): los ejemplos de cURL/fetch y la sección `servers` del OpenAPI usaban `.../v1/...` sin el prefijo `/api`, mientras que la sección "Base URL" del mismo documento (y las rutas reales de `routes/api.php`) sí lo llevan (`.../v1/...`). Un desarrollador copiando esos ejemplos tal cual habría pegado contra una URL que no existe. Unificado a `/v1/...` en los tres lugares.
  - **Alcance de la limpieza**: solo se tocaron los dos archivos client-facing (`docs/v1.md`, `docs/api/openapi.v1.yaml`). Los docblocks de PHP en `app/Filament/Pages/*.php` (`ApiTokens`, `ApiPlayground`, `ApiDocumentation`, `Preferences`) siguen citando ADRs a propósito — son comentarios de código para desarrolladores/agentes, nunca se renderizan para el usuario final, así que no aplica la misma regla.
- **Archivos/áreas:** `docs/v1.md`, `docs/api/openapi.v1.yaml`
- **Siguiente:** tener esta regla presente para cualquier documentación nueva de cara al cliente (nunca "MVP"/ADRs/docs internos ahí) — ya quedó anotada en el handoff de `TASK.md`.

---

## 2026-08-17 — Fix: link a openapi.v1.yaml roto (404) en API Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano reportó un 404 en `console.genesisly.host/cica360/openapi.v1.yaml` al clickear un link dentro de **API Documentation**.
  - Causa: `docs/v1.md` (la fuente de la documentación) tiene un link relativo `[docs/api/openapi.v1.yaml](./openapi.v1.yaml)` — correcto para navegar el repo (GitHub, editor), pero `App\Filament\Pages\ApiDocumentation` renderiza ese markdown DENTRO de una page Filament servida en `/{tenant}/api-documentation`, así que el browser resolvía el relativo contra esa URL en vez de contra la raíz del repo → `/{tenant}/openapi.v1.yaml`, ruta que nunca existió.
  - Fix en dos partes: (1) nueva ruta `GET /openapi.v1.yaml` en `routes/web.php`, scopeada al dominio de Console vía `config('genesis.urls.console')`, sirviendo el contenido crudo de `docs/api/openapi.v1.yaml` con `Content-Type: application/yaml` — sin autenticación a propósito, es un contrato de API público pensado para cargarse en Swagger UI/Postman, no datos de tenant. (2) `ApiDocumentation::getMarkdownHtml()` ahora reescribe `href="./openapi.v1.yaml"` → `href="{{ route('docs.openapi-yaml') }}"` después de convertir el markdown a HTML.
- **Archivos/áreas:** `routes/web.php`, `app/Filament/Pages/ApiDocumentation.php`
- **Siguiente:** confirmar en browser que el link ahora descarga/muestra el YAML en vez de 404.

---

## 2026-08-17 — Menú del avatar: "Preferencias" y "Cambiar contraseña"

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Pedido del humano: en el dropdown del avatar (que hasta ahora solo tenía el toggle de tema claro/oscuro/sistema y "Salir"), agregar accesos directos a "Preferencias" y a un cambio de contraseña.
  - Creada `App\Filament\Pages\ChangePassword`: page nueva con `$shouldRegisterNavigation = false` (a propósito no aparece en el sidebar — su único acceso es el dropdown del avatar, tal como se pidió). Formulario con `current_password` (`->currentPassword()`, valida contra el guard correcto vía Filament), `password` (`Password::default()` + `->confirmed()`) y `password_confirmation`. Guarda con `$user->update(['password' => $data['password']])`, apoyándose en el cast `'hashed'` que ya tiene `User` — no hace falta `Hash::make()` manual.
  - `PanelCmsProvider::panel()` gana `->userMenuItems([...])` con dos `Filament\Navigation\MenuItem`: "Preferencias" (ícono `heroicon-o-adjustments-horizontal`, URL vía `Preferences::getUrl()`) y "Cambiar contraseña" (ícono `heroicon-o-lock-closed`, URL vía `ChangePassword::getUrl()`). Se agregan a los ítems nativos de Filament (toggle de tema, Salir) sin reemplazarlos.
  - Verificado el API real de Filament antes de escribir código (no se asumió nada): `Panel::userMenuItems(array $items)` acepta `Action | Closure | MenuItem`; `Filament\Navigation\MenuItem` tiene `label()`/`icon()`/`url()`/`sort()` confirmados por lectura directa de `vendor/filament/filament/src/Navigation/MenuItem.php`; `TextInput::currentPassword()` confirmado en `vendor/filament/forms/src/Components/TextInput.php:66`.
  - El formulario/vista sigue el mismo patrón ya establecido por `Preferences` (misma sesión, ADR-021): `HasForms`/`InteractsWithForms`, `Schema` con `->statePath('data')`, blade mínimo con `<x-filament-panels::page>` + `.gnss-card`.
- **Archivos/áreas:** `app/Filament/Pages/ChangePassword.php` (nuevo), `resources/views/filament/pages/change-password.blade.php` (nuevo), `app/Providers/Filament/PanelCmsProvider.php` (`->userMenuItems()`), `docs/context/DECISIONS.md` (nota agregada a ADR-021)
- **Siguiente:** correr `php artisan migrate` (pendiente de la vuelta anterior); verificar visualmente en browser que el dropdown del avatar muestra ambas opciones nuevas y que ambos flujos (guardar preferencias, cambiar contraseña) funcionan end-to-end.
- **Follow-up mismo día:** el humano pidió sacar "Preferencias" del sidebar (grupo "Cuenta") porque quedó duplicada con el dropdown del avatar. Fix: `App\Filament\Pages\Preferences` gana `$shouldRegisterNavigation = false` — la page sigue existiendo igual, solo deja de listarse en el menú principal; el único acceso ahora es el dropdown del avatar.

---

## 2026-08-17 — Preferencias de usuario (idioma/zona horaria) + fechas amigables + fix de bugs en ApiTokens

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Reporte del humano: en **API Tokens**, la columna "Token" se veía completamente vacía en las 3 filas, y los tokens sin expiración no mostraban "Nunca" en la columna "Expira". Investigado a fondo (sin poder ejecutar PHP, solo lectura de código fuente):
    - `last_four` nunca se persistía: `Laravel\Sanctum\PersonalAccessToken` define `$fillable = ['name', 'token', 'abilities', 'expires_at']` — `last_four` (columna agregada en una migración nuestra aparte) NO está ahí, así que `$model->update(['last_four' => ...])` lo descartaba en silencio por protección de mass-assignment, dejando la columna NULL para siempre. Fix: `forceFill(['last_four' => ...])->save()` en `ApiTokens::getHeaderActions()` y `ApiPlayground::getHeaderActions()` (ambos crean tokens).
    - `expires_at` con valor `null` no mostraba "Nunca": el patrón que SÍ funcionaba en la misma tabla (`last_used_at` con `->placeholder('Nunca usado')`) reveló que Filament no evalúa `formatStateUsing` para decidir si mostrar el placeholder — hay que declarar `->placeholder()` explícitamente en vez de manejar el `null` a mano dentro del formatter. Aplicado a `last_four` (`placeholder('—')`) y `expires_at` (`placeholder('Nunca')`).
  - Pedido explícito del humano: fechas "amigables y abreviadas" en toda Console, respetando idioma/zona horaria elegidos por el usuario (dio como ejemplo `America/Lima` y el formato `17 Ago 12:25 am`), con un recurso de settings para configurarlo.
    - Migración `users.locale` (default `es`) + `users.timezone` (default `America/Lima`).
    - `App\Support\FriendlyDate::format($date, ?User $user = null)`: relativo natural localizado si la fecha está a menos de 28 días de "ahora" (`Carbon::diffForHumans()`), absoluto abreviado con shape fijo (`17 Ago 12:25 am`, año solo si difiere del actual) en caso contrario — con un mapa de meses abreviados propio (es/en/pt) en vez de depender del meridiano/abreviaturas de Carbon por locale, para que el shape sea siempre igual.
    - `App\Filament\Pages\Preferences` (grupo **Cuenta**): Select de idioma (reusa `LanguageEnum`) + Select de zona horaria (`DateTimeZone::listIdentifiers()`, searchable), guarda en `auth()->user()`.
    - Aplicado `FriendlyDate` a las 3 columnas de fecha de `ApiTokens` (`last_used_at`, `expires_at`, `created_at`).
  - **Alcance explícito**: solo `ApiTokens` usa `FriendlyDate` por ahora — extenderlo a Pages/Posts/Media/Contacts y demás Resources queda como tarea de seguimiento (#19b en TASK.md), no se tocó todo Console de una.
- **Archivos/áreas:** `database/migrations/2026_08_17_090000_add_locale_and_timezone_to_users_table.php`, `app/Models/User.php`, `app/Support/FriendlyDate.php` (nuevo), `app/Filament/Pages/{Preferences,ApiTokens,ApiPlayground}.php`, `resources/views/filament/pages/preferences.blade.php` (nuevo), `docs/context/DECISIONS.md` (ADR-021)
- **Siguiente:** correr `php artisan migrate` (columna nueva en `users`) antes de abrir Preferencias; confirmar visualmente que Token/Expira/fechas de `ApiTokens` ahora se ven bien.

---

## 2026-08-17 — Fix: SSL local en el Playground (self-request a APP_URL_API)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el Playground ahora le pega correctamente a `https://api.genesisly.host/...` (gracias al fix de dominios de la vuelta anterior), pero eso es un *self-request* del propio server Laravel hacia otro subdominio del mismo monolito — y en local ese dominio resuelve contra un certificado autofirmado (MAMP PRO/Herd/Valet) que el cURL de PHP no confía por defecto, tirando `cURL error 60: SSL certificate problem: unable to get local issuer certificate`. Se agregó `$request->withoutVerifying()` en `ApiPlayground::sendRequest()`, condicionado estrictamente a `app()->isLocal()` — nunca se desactiva la verificación TLS en producción.
- **Archivos/áreas:** `app/Filament/Pages/ApiPlayground.php`
- **Siguiente:** confirmar que el Playground ahora sí completa el request contra CICA360 en local.

---

## 2026-08-17 — Syntax highlighting real en los bloques de código de Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** los bloques de código de `docs/v1.md` renderizados en Console se veían en un solo color (gris plano), sin distinguir claves/strings/números como sí hace el Playground. Se agregó **Prism.js vía CDN** (`prism-core` + `clike`/`javascript`/`typescript`/`json`/`bash`, sin build step ni bundler — igual que el resto de este panel) en `api-documentation.blade.php`, cargado al final del body en `<script>` planos (sin `defer`) para que corran sincrónicamente antes de que Alpine inicialice el `x-init` de la página. Los fences ` ```jsonc ` de `v1.md` (JSON con comentarios `//`) no son un lenguaje real de Prism — se extiende el grammar de `json` en runtime (`Prism.languages.extend('json', {comment: ...})`) en vez de traer un componente que no existe en el CDN. Los colores de los tokens (`public/css/filament/api-console.css`) reutilizan las mismas variables semánticas de Filament que ya usa el highlighter de JSON del Playground (`--info-*` para claves, `--success-*` para strings, `--warning-*` para números), para que un JSON se vea igual en ambas páginas.
- **Archivos/áreas:** `resources/views/filament/pages/api-documentation.blade.php`, `public/css/filament/api-console.css`
- **Siguiente:** confirmar en el navegador que los bloques ` ```json `/` ```jsonc `/` ```bash `/` ```ts ` de la documentación ahora se ven coloreados.

---

## 2026-08-17 — Fix: contenido de Documentation cortado detrás del margen derecho

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** screenshot del humano mostraba texto cortado en seco contra el borde derecho del navegador (sin scroll, sin wrap), tanto en prosa normal como en los chips del banner. Causa: `.gnss-layout` usaba `grid-template-columns: 260px 1fr` — por spec CSS, un track `1fr` se resuelve como `minmax(auto, 1fr)`, y `auto` no puede achicarse por debajo del contenido "no quebrable" más ancho de sus hijos. Los chips `gnss-chip--mono` (URLs largas tipo `https://api.genesisly.host/v1/{tenant_slug}/...`) tenían `white-space: nowrap`, dándoles un ancho mínimo enorme que se propagaba hacia arriba — y como `.gnss-layout`/`.gnss-banner` son a su vez items dentro del grid `.fi-page-content` de Filament, terminaba empujando la página entera más ancha que el viewport. Fix: `minmax(0, 1fr)` en el track del grid, `min-width: 0` en cada nivel intermedio (`.gnss-layout`, `.gnss-stack`, `.gnss-sticky`, `.gnss-banner`, `.gnss-card`, `.gnss-prose`), y los chips mono ahora quiebran (`white-space: normal; word-break: break-all`) en vez de forzar el ancho. De paso: confirmado por lectura del código fuente de Filament (`Pages\Concerns\HasMaxWidth` solo lo consumen `SimplePage`/`EditProfile`, no las páginas normales) que **no existe ningún max-width propio de Filament limitando el ancho de una page custom** — el contenido ya ocupa el 100% del área disponible por defecto; no hacía falta ninguna opción de "fullwidth" adicional, solo corregir el overflow.
- **Archivos/áreas:** `public/css/filament/api-console.css`
- **Siguiente:** confirmar en el navegador que el contenido ahora respeta el ancho del viewport y usa el 100% del área disponible sin cortes.

---

## 2026-08-17 — Dominios base del monolito centralizados desde .env

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el humano ya tenía `APP_URL_API`/`APP_URL_CONSOLE`/`APP_URL_MANAGER` cargados en `.env`/`.env.example` (junto a `APP_URL`, reservado para la landing) y pidió que el código los usara en vez de tener dominios hardcodeados sueltos. Se agregó `config/genesis.php` (`urls.api`/`urls.console`/`urls.manager`, con fallback a `APP_URL`) y se corrigieron los tres puntos que tenían esto mal resuelto: `PanelCmsProvider`/`PanelManagerProvider` tenían el dominio de `->domain(...)` escrito como string literal (`'console.genesisly.host'`, `'manager.genesisly.host'`) en vez de leerlo de config; `ApiPlayground` y `api-documentation.blade.php` usaban `config('app.url')` (el dominio de la **landing**) para armar la URL real del request y para mostrarla en el Playground/Docs, cuando debería ser el dominio de la **API**. Documentado como ADR-020, incluyendo el pendiente honesto de que la API sigue viviendo bajo `/v1/...` (no `/v1/...`) porque separar eso requiere un `Route::domain()` dedicado — cambio de contrato público, no forzado en esta vuelta.
- **Archivos/áreas:** `config/genesis.php` (nuevo), `app/Providers/Filament/{PanelCmsProvider,PanelManagerProvider}.php`, `app/Filament/Pages/ApiPlayground.php`, `resources/views/filament/pages/{api-playground,api-documentation}.blade.php`, `docs/v1.md`, `docs/context/DECISIONS.md` (ADR-020)
- **Siguiente:** confirmar en el navegador que Console/Manager siguen resolviendo por dominio correctamente (no debería cambiar nada visible, es el mismo valor, solo leído de otro lugar).

---

## 2026-08-16 — Rediseño de Playground/Documentation para calzar con el look & feel real de Filament

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el fix anterior (mismo día) resolvió que los íconos y colores se vieran, pero el resultado visual inventaba una paleta y una estética propia (badges tipo pill, banner con gradiente, bloques de código en navy) que no calzaba con el resto de Console — feedback directo: "no encaja con los estilos de Filament, se ve horrible y no genera confianza". Se reescribió `public/css/filament/api-console.css` desde cero para que en vez de definir colores propios, **consuma directamente las variables CSS en tiempo de ejecución que el propio Filament expone en `:root`** (`--primary-*`, `--gray-*`, `--success-*`, `--warning-*`, `--danger-*`, `--info-*` — generadas por `Filament\Support\Assets\AssetManager::renderStyles()` a partir de los colores configurados en `PanelCmsProvider`, hoy `Color::Amber` de primary), y calcando los patrones reales de los componentes de Filament (`vendor/filament/support/resources/css/components/{section,badge,tabs,callout,button}.css`): cards con sombra tipo "ring" en vez de borde visible (`box-shadow: 0 0 0 1px rgb(3 7 18/.05)`, igual que `.fi-section`), badges `rounded-md` con `ring` sutil (igual que `.fi-badge`) en vez de pills, tabs con fondo pill activo (igual que `.fi-tabs-item`) en vez de underline, botones sólidos `var(--primary-600)` sin gradiente, y bloques de código usando el propio `var(--gray-950)` del panel en vez de un navy inventado.
  - De paso se corrigió un bug real: `.gnss-method--get { composes: gnss-badge--info; }` usaba sintaxis de CSS Modules (`composes`), que no existe en CSS plano de navegador — no tenía ningún efecto. Se combinaron los selectores directamente.
  - Se eliminó código muerto: `ApiPlayground::METHOD_COLORS` y `methodBadgeClasses()` (reemplazados por las clases `gnss-method--{método}` del CSS, ya no se llamaban desde el blade actual).
  - Verificación estática añadida: script que extrae todas las clases `gnss-*` usadas en los 3 blade y confirma que cada una tiene una regla definida en el CSS (detectó y corrigió 2 clases faltantes: `gnss-chip--muted`, `gnss-icon-muted`).
- **Archivos/áreas:** `public/css/filament/api-console.css`, `app/Filament/Pages/ApiPlayground.php`
- **Siguiente:** el humano debe recargar Console y confirmar que ahora sí se siente parte del mismo panel (mismos grises, mismo primary configurado, mismos radios/sombras que el resto de Filament) en vez de una herramienta con estética propia.

---

## 2026-08-16 — Fix: iconos gigantes / sin color en Playground y Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el rediseño anterior (mismo día) se veía roto en el navegador real: íconos Heroicon renderizando a tamaño gigante (cientos de px) y tarjetas/badges sin color ni bordes. Causa raíz: este panel de Filament **no tiene un tema custom con pipeline de Vite/Tailwind** que escanee `resources/views/filament/**` ni `app/Filament/**` — corre sobre el CSS pre-compilado del paquete `filament/filament`, que solo incluye las clases utilitarias que el propio Filament usa en sus vistas core. Cualquier clase Tailwind que yo escribiera a mano en mis blade custom (`h-3.5`, `bg-sky-100`, `lg:grid-cols-[240px_1fr]`, etc.) no tenía ninguna regla CSS asociada — de ahí que las imágenes SVG (sin `width`/`height` propios) se renderizaran a tamaño intrínseco del navegador y las cards quedaran sin fondo/bordes/colores.
  - Fix: se escribió `public/css/filament/api-console.css` — CSS plano, sin Tailwind ni build step, con paleta propia (variables CSS, con overrides para `html.dark`), cubriendo layout (grid del sidebar), badges de método HTTP coloreados, tabs de response, syntax highlighting de JSON, tabla de headers, banner con acento en gradiente, TOC de la documentación, prose del markdown, y el banner de "guardá este token ahora" de `ApiTokens`.
  - Registrado como asset de Filament vía `FilamentAsset::register([Css::make('api-console', asset(...))])` en `PanelCmsProvider::boot()` (antes el provider solo tenía `panel()`, sin `boot()`).
  - Los 3 blade views afectados (`api-playground`, `api-documentation`, `api-tokens`) se reescribieron reemplazando las clases Tailwind por clases semánticas `gnss-*` del nuevo CSS. La lógica Livewire/Alpine (wire:click, x-data, x-show, IntersectionObserver del scrollspy) no cambió.
  - El highlighter de JSON en `ApiPlayground::highlightJson()` también se actualizó para emitir clases `gnss-json-*` en vez de `text-sky-400`/`text-emerald-400`/etc.
- **Archivos/áreas:** `public/css/filament/api-console.css` (nuevo), `app/Providers/Filament/PanelCmsProvider.php`, `app/Filament/Pages/ApiPlayground.php`, `resources/views/filament/pages/{api-playground,api-documentation,api-tokens}.blade.php`
- **Siguiente:** el humano debe recargar Console (puede necesitar limpiar caché del navegador o hacer un hard refresh) y confirmar visualmente que ahora se ven bien. No requiere `npm run build` ni ningún paso de compilación — el CSS ya está en su ubicación final servible (`public/css/filament/api-console.css`).

---

## 2026-08-16 — Rediseño visual de API Playground y API Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** las dos páginas de Console del grupo "Desarrolladores" eran funcionales pero visualmente planas (un form + una caja de texto). Se rediseñaron sin agregar dependencias nuevas:
  - **API Playground:** sidebar sticky con los 8 endpoints de ejemplo agrupados por recurso, cada uno con badge de método coloreado (GET/POST/PUT/PATCH/DELETE); response con tabs Pretty/Raw/Headers; JSON con syntax highlighting real (hecho server-side con regex sobre el string ya escaneado, sin librerías JS externas); botones de copiar con feedback ("¡Copiado!") vía Alpine; status badge con ícono (check/x) y duración con ícono de reloj; card de "destino real" mostrando `config('app.url')`.
  - **API Documentation:** banner superior con badges (versión, tipo de auth, base URL) y botón directo a "Abrir Playground"; sidebar sticky con índice navegable (H2/H3 extraídos del markdown crudo) con scrollspy vía `IntersectionObserver` y buscador que filtra el índice en vivo; anchors (`id`) inyectados en los headings del HTML renderizado; botón de copiar auto-inyectado en cada bloque de código del markdown.
  - Bug propio detectado y corregido durante la implementación: el highlighter usaba `e()` (que escapa comillas a `&quot;`) antes de aplicar los regex de resaltado, lo que rompía el matching — cambiado a `htmlspecialchars(..., ENT_NOQUOTES)` ya que el output va dentro de `<pre><code>`, no de un atributo HTML.
- **Archivos/áreas:** `app/Filament/Pages/{ApiPlayground,ApiDocumentation}.php`, `resources/views/filament/pages/{api-playground,api-documentation}.blade.php`
- **Siguiente:** el humano debe abrir ambas páginas en Console (con el servidor ya corriendo tras cerrar la vuelta de Sanctum) y confirmar visualmente que el sidebar, los tabs y el scrollspy se comportan bien — esto no se pudo probar en navegador real desde este entorno, solo verificación estática (balance de llaves/paréntesis, nombres de íconos Heroicon confirmados contra `vendor/blade-ui-kit/blade-heroicons`).

---

## 2026-08-16 — Fix: alias de middleware `abilities` faltante en bootstrap/app.php

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** El humano corrió por primera vez el ciclo completo en su máquina: `composer require laravel/sanctum:^4.0` (el `composer install` inicial no alcanzaba porque `composer.lock` no tenía la dependencia nueva), `php artisan migrate` (25+2 migraciones, incluidas las de Sanctum) y `php artisan db:seed` — todo corrió en verde. `php artisan test` corrió por primera vez con Sanctum realmente instalado y expuso un bug real (no de entorno): **16 de 26 tests fallaban con `BindingResolutionException: Target class [abilities] does not exist.`** — el middleware `abilities:*` usado en `routes/api.php` nunca se registró como alias. A diferencia de lo asumido en ADR-018, Sanctum **no** auto-registra `CheckAbilities`/`CheckForAnyAbility` como alias de ruta en apps Laravel 11+ sin `Http/Kernel.php`; hay que declararlos a mano en `bootstrap/app.php` vía `$middleware->alias([...])`. Corregido agregando `'abilities' => CheckAbilities::class` y `'ability' => CheckForAnyAbility::class` al `withMiddleware()`.
- **Archivos/áreas:** `bootstrap/app.php`
- **Siguiente:** el humano debe re-correr `php artisan test` para confirmar que los 16 tests que fallaban por este alias ahora pasan (los otros 10 ya pasaban: `TenantIsolationTest`, `ContactSubmissionServiceTest`, `ExampleTest`, `Tests\Unit\ExampleTest`).

**Actualización (misma sesión):** el re-run mostró 25/26 en verde; el único fallo restante fue `ApiAuthTest::test_revoked_token_returns_401_on_subsequent_requests` (esperaba 401, recibió 200). Causa: no es un bug de producción — es que el `RequestGuard` de Sanctum cachea el usuario resuelto en la instancia de guard, y el test hace dos requests HTTP simuladas dentro del mismo método sin reiniciar la `Application`, así que la segunda seguía viendo el usuario resuelto por la primera aunque el token ya estaba borrado. Fix: agregado `$this->app['auth']->forgetGuards();` entre las dos requests del test (patrón documentado del framework, `AuthManager::forgetGuards()` existe justo para esto). Archivo: `tests/Feature/Api/V1/ApiAuthTest.php`. Pendiente: confirmar 26/26 con un último re-run.

---

## 2026-08-16 — Cierre de gaps de ADR-018: ids anidados en content.items[] + expiración de tokens

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Verificado explícitamente (de nuevo) que este sandbox no puede correr `composer install`/`php artisan *`: sin PHP, sin Composer, sin `sudo`/apt funcional, sin docker, y con la red bloqueada por allowlist hacia todo host externo relevante (`github.com`, `packagist.org`, `getcomposer.org`, `deb.debian.org`, `archive.ubuntu.com` — todos probados y bloqueados). Esto queda documentado explícitamente para que quede claro que no es una omisión sino una limitación real del entorno.
  - `App\Http\Concerns\ResolvesPublicLinks::attachResolvedHeroContent()` renombrado y expandido a `attachResolvedBlockContent()`: ahora resuelve TODOS los ids internos de `content` — `heading`/`image`/`split`/`hero`(manual) a nivel bloque, y `image_id`/`avatar_id`/`media_id`/`page_id` dentro de `content.items[]` de `features`/`testimonials`/`logos`/`services_grid` — en 3 queries batched (Media/Page/Slider), sin importar cuántos bloques/items tenga la página.
  - `App\Filament\Pages\ApiTokens` gana selector de expiración al crear el token (Nunca/1/30/90/365 días) + columna `expires_at` en el listado (con color danger si ya venció). Sanctum rechaza automáticamente tokens vencidos, sin lógica de enforcement extra.
  - `App\Filament\Pages\ApiPlayground` usa el mismo helper para que sus tokens de prueba expiren en 24hs por defecto.
  - Test nuevo (`PageApiTest::test_page_response_resolves_nested_media_and_page_ids_inside_block_items`) cubriendo un `services_grid` con `image_id` y `page_id` simultáneos.
  - Actualizado `docs/v1.md` (tabla de mapeo id-interno → campo público, sección de expiración, "Pendientes conocidos" reescrita) y `docs/api/openapi.v1.yaml`.
- **Archivos/áreas:**
  - `app/Http/Concerns/ResolvesPublicLinks.php`, `app/Http/Controllers/Api/V1/PageController.php`
  - `app/Filament/Pages/{ApiTokens,ApiPlayground}.php`
  - `tests/Feature/Api/V1/PageApiTest.php`
  - `docs/api/{v1.md,openapi.v1.yaml}`, `docs/context/DECISIONS.md` (ADR-019)
- **Siguiente:**
  - `composer install` + `php artisan migrate` + `php artisan db:seed` + `php artisan test` — bloqueante, tiene que correrlo el humano en su máquina.
  - Probar el Playground end-to-end con un token real.
  - Filament Resource de Contacts; decisión de frontend.
- **Estado del código de aplicación:** Ambos gaps cerrados a nivel de código, verificado estáticamente. Sigue pendiente la ejecución real (composer/migrate/seed/test) porque ningún agente de este proyecto tuvo, hasta ahora, un entorno con PHP/Composer disponible.

---

## 2026-08-16 — Seguridad API por tokens (Sanctum) + Playground + Docs + Contrato público sin ids internos

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Protegida toda `/v1/{tenant_slug}/*` con Laravel Sanctum (Bearer tokens, guard `sanctum`, abilities `content:read`/`forms:submit`). `ResolvesTenant` valida ownership del token contra el tenant del path (403 si no coincide, sin filtrar si el tenant existe).
  - `App\Filament\Pages\ApiTokens`: crear token (nombre + abilities), plaintext visible una sola vez con advertencia, listado enmascarado (últimos 4 caracteres), revocar inmediato. Scoped al tenant actual.
  - `App\Filament\Pages\ApiPlayground`: arma y ejecuta requests HTTP reales contra la API del entorno (endpoint precargado agrupado, método, path, headers, body JSON), botón para generar un token de prueba desechable, muestra status/duración/JSON pretty, copiar response/cURL.
  - `App\Filament\Pages\ApiDocumentation`: renderiza `docs/v1.md` (Markdown → HTML) dentro de Console.
  - `docs/v1.md` (documentación completa: auth, envelope, errores, cada endpoint con ejemplos reales) + `docs/api/openapi.v1.yaml`.
  - Corregido el contrato público de responses: `App\Http\Concerns\ResolvesPublicLinks` resuelve `links[].source_id` → `source_slug`+`href` (batcheado, sin N+1) y `hero.content.slider_id` → `content.slider_slug`; `App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields` fuerza `properties`/`content`/`meta` vacíos a `{}` en vez de `[]`. Aplicado en Page/Block/Post/Slide Resources.
  - `Cliente0PostsSeeder`: 3 posts publicados de prueba para CICA360, idempotente.
  - Tests: `ApiAuthTest` (401 sin token, 200 con token válido, 403 token de otro tenant, 403 sin ability, 401 tras revocar), `PageApiTest` extendido (test de que la response no expone `source_id`/`slider_id`), `PostApiTest` (posts index incluye los seeded, post por slug).
- **Archivos/áreas:**
  - `composer.json` (agrega `laravel/sanctum`), `config/auth.php` (guard `sanctum`), `app/Models/User.php` (`HasApiTokens`)
  - `database/migrations/2026_08_16_130000_create_personal_access_tokens_table.php`, `..._130100_add_display_fields_...php`
  - `app/Http/Concerns/{ResolvesTenant,ResolvesPublicLinks}.php`, `app/Http/Resources/Api/V1/Concerns/NormalizesJsonFields.php`
  - `app/Http/Resources/Api/V1/{BlockResource,PageResource,PostResource,SlideResource}.php`
  - `app/Http/Controllers/Api/V1/{Controller,PageController,PostController,SliderController}.php`
  - `routes/api.php`, `bootstrap/app.php` (401/403 en el envelope)
  - `app/Filament/Pages/{ApiTokens,ApiPlayground,ApiDocumentation}.php` + sus blade views
  - `database/seeders/{Cliente0PostsSeeder,DatabaseSeeder}.php`
  - `docs/api/{v1.md,openapi.v1.yaml}`
  - `tests/Feature/Api/V1/{PageApiTest,ApiAuthTest,PostApiTest}.php`
  - `docs/context/DECISIONS.md` (ADR-018)
- **Siguiente:**
  - `composer install` + `php artisan migrate` + `php artisan db:seed` + `php artisan test` contra la BD real.
  - Generar un token real en Console y probar el Playground end-to-end.
  - Filament Resource de Contacts; decisión de frontend.
- **Estado del código de aplicación:** Bloque completo a nivel de código, pendiente de instalación real de `laravel/sanctum` (`composer install`) y ejecución de migraciones/seeders/tests — no ejecutable en el sandbox de este agente (sin PHP/Composer).

---

## 2026-08-16 — Contenido mínimo real de CICA360 (seeder)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Creado `Database\Seeders\Cliente0ContentSeeder`: 5 páginas publicadas con bloques (`home`, `sobre-cica`, `servicios`, `casos-de-exito`, `contacto`), menú `menu-principal` (5 items → páginas reales) y formulario "Contacto principal" (campos name/email/phone/message, reutilizando las `FormFieldDefinition` globales de ADR-014).
  - Los bloques usan exactamente el schema `content`/`links` de los Resources de Filament ya construidos (`LinkSchema`, claves `content.*` por tipo de bloque en `PageResource`): hero (modo `slider` → referencia al slider `home`), rich_text, features, split, testimonials, logos, cta, services_grid, contact_form.
  - Actualizado `Cliente0HomeSlidesSeeder`: los CTA de las 3 slides ahora resuelven a páginas reales (`source_type = page`) en vez de un placeholder `#`, con fallback automático si las páginas todavía no existen.
  - Reordenado `DatabaseSeeder`: `Cliente0ContentSeeder` corre antes que `Cliente0HomeSlidesSeeder` para que la resolución de CTAs funcione en una corrida limpia.
  - Todo idempotente (`updateOrCreate` + poda de filas sobrantes por `sort_order`), sin depender de media real (todos los `*_id` de imagen quedan `null`).
  - Confirmado por el humano: `php artisan test` corre en verde (16 tests Feature + 1 Unit, 67 aserciones); el warning de PHP que aparecía en cada test ("use statement... Throwable") era opcache/file-cache obsoleto local, no un problema de `bootstrap/app.php`.
- **Archivos/áreas:**
  - `database/seeders/Cliente0ContentSeeder.php` (nuevo)
  - `database/seeders/Cliente0HomeSlidesSeeder.php` (CTAs → páginas reales)
  - `database/seeders/DatabaseSeeder.php` (orden de ejecución)
  - `docs/context/DECISIONS.md` (ADR-017)
- **Siguiente:**
  - Correr `php artisan db:seed` contra la BD real y verificar visualmente en Filament.
  - Probar el API v1 contra `cica360` con datos reales.
  - Filament Resource de Contacts; decisión de frontend.
- **Estado del código de aplicación:** Seeder completo a nivel de código, pendiente de ejecución real contra la BD (no ejecutable en el sandbox de este agente).

---

## 2026-08-16 — API REST pública v1 (Headless MVP)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Implementado el API REST público `/v1/{tenant_slug}/...` para el MVP Headless: `GET pages`, `pages/{slug}`, `posts`, `posts/{slug}`, `menus/{slug}`, `sliders/{slug}`, `media/{uuid}`, `POST forms/{slug}/submit`.
  - Envoltura JSON estándar (ADR-009) vía trait `ApiResponds` (`success`/`error`/`paginated`), con `meta`/`links` de paginación.
  - Resolución de tenant explícita por controller (trait `ResolvesTenant`, reutiliza `TenantManager::setTenant()`) en vez de middleware — investigado y documentado el motivo (timing del pipeline de Laravel) en ADR-016.
  - `pages/{slug}` con blocks visibles ordenados; `menus/{slug}` con árbol `parent_id` armado en memoria (cero N+1) y resolución batched de `href` para items Page/Post; `sliders/{slug}` con slides activos y las 5 relaciones de media resueltas.
  - `forms/{slug}/submit` reutiliza `ContactSubmissionService` (ADR-015) sin duplicar lógica de dominio; nunca expone datos sensibles.
  - CORS (`config/cors.php`), rate limiting (`RateLimiter::for('api')` 60/min, `for('forms')` 10/min) y excepciones de `api/*` normalizadas a la envoltura JSON en `bootstrap/app.php`.
  - Tests de aislamiento tenant + page-by-slug + paginación en `tests/Feature/Api/V1/PageApiTest.php` (7 casos).
- **Archivos/áreas:**
  - `routes/api.php`, `bootstrap/app.php`, `config/cors.php`, `app/Providers/AppServiceProvider.php`
  - `app/Http/Concerns/{ApiResponds,ResolvesTenant}.php`
  - `app/Http/Controllers/Api/V1/{Controller,PageController,PostController,MenuController,SliderController,MediaController,FormSubmissionController}.php`
  - `app/Http/Resources/Api/V1/{MediaResource,BlockResource,PageResource,PageSummaryResource,PostResource,PostSummaryResource,MenuItemResource,SliderResource,SlideResource}.php`
  - `tests/Feature/Api/V1/PageApiTest.php`
  - `docs/context/DECISIONS.md` (ADR-016)
- **Siguiente:**
  - Correr `php artisan test` contra la BD real (no ejecutable en el sandbox de este agente).
  - Sembrar contenido real de CICA360 (pages/blocks/slider/menu) en Filament para probar el API con datos reales.
  - Filament Resource de Contacts; decisión de auth avanzado del DBML; arranque del frontend Cliente 0.
- **Estado del código de aplicación:** API v1 completa a nivel de código (routing/controllers/resources/seguridad básica), pendiente de ejecución real de tests y de contenido de prueba en CICA360.

---

## 2026-08-16 — MediaSelect modal, Sliders interactivos, Decoradores Top/Bottom y Hero Responsivo
- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Cambiada la URL del disco público en `config/filesystems.php` a la ruta relativa `/storage` para evitar errores CORS y cargas infinitas de Livewire.
  - Corregida la reactividad de `SliderResource` y `MenuResource` para resolver correctamente los objetos Enum PHP en la UI de Filament 5.
  - Agregado el componente `MediaSelect::make()` para permitir la carga directa modal o reutilización de imágenes en posts, sliders, páginas y bloques.
  - Implementado el bloque `heading` en el builder de `PageResource` con soporte para 3 imágenes responsivas (Desktop/Tablet/Mobile) y múltiples propiedades visuales de estilo (decoradores independientes, brillo, contraste, color, opacidad).
  - Rediseñada la sección SEO en `PageResource` y `PostResource` separando Metadata SEO de Open Graph con soporte responsivo para imágenes rectangular y cuadrada.
  - Actualizados los campos de opacidad, brillo, contraste y transparencia en `PropertiesSchema` y el bloque `heading` para usar componentes `Slider` de Filament con indicadores de valor interactivos (`tooltips()`).
  - Separados los decoradores superiores e inferiores en `PropertiesSchema` y el bloque `heading` con controles de color reactivos e independientes.
  - Añadido soporte para 3 imágenes responsivas (Desktop, Tablet, Mobile) en la configuración manual del bloque `hero` en `PageResource`.
- **Archivos/áreas:**
  - `config/filesystems.php`
  - `app/Enums/BlockTypeEnum.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/PostResource.php`
- **Siguiente:**
  - Desarrollar el Filament Resource de Contacts o la API REST pública `/v1`.

## 2026-08-14 — Ocultado de Idioma (lang_iso) + Corrección de Upload Disk
- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Ocultados todos los selectores de idioma (`lang_iso`) en los formularios y sub-repetidores del panel Console (`PageResource`, `SliderResource`, `MenuResource`, `PostResource` y todos sus bloques y sub-esquemas).
  - Eliminadas las columnas de idioma y los filtros de idioma de los listados/tablas de Filament Console.
  - Asegurada la inyección de `'es'` de manera invisible y transparente mediante campos ocultos con `.default('es')`.
  - Corregido el guardado de la columna `disk` de multimedia en `MediaResource`: agregada asignación dinámica en el evento `afterStateUpdated` del componente `FileUpload` para que apunte al disco real utilizado (`public` en local/desarrollo), lo que habilita la visualización y previsualización correctas de imágenes en el listado.
  - Solucionados los namespaces incompatibles de `Grid` y `Group` en `LinkSchema.php` y `PropertiesSchema.php` para adaptarlos a Filament 5.
- **Archivos/áreas:**
  - `app/Filament/Schemas/LinkSchema.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
  - `app/Filament/Resources/MediaResource.php`
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/PostResource.php`
- **Siguiente:**
  - Continuar con el Filament Resource de `Contact`.

## 2026-08-14 — Re-esquematización de Filament Resources Console
- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Diseñados y creados esquemas reutilizables `LinkSchema` y `PropertiesSchema` para estructurar la columna de enlaces `links` (con soporte para tipo de destino: página, entrada, URL externa, personalizado) y propiedades tipadas de visualización `properties` (color de fondo/texto, opacidad, alineación, anchos, rellenos, animaciones).
  - Refactorizado `PageResource.php` para incorporar 12 tipos distintos de bloques en el `Builder` (Hero con soporte de slider/manual, RichText, Image, CTA, Features, FAQ, ContactForm, LegalNotice, Split, Testimonials, Logos, ServicesGrid) estructurado bajo un sistema de tres pestañas (Contenido, Configuración, SEO / Enlaces) que almacena metadatos SEO tipados dentro de `meta`.
  - Refactorizado `SliderResource.php` en su sección de slides para soportar selección condicional de imágenes o videos responsivos según el tipo de fondo, toggles dinámicos de video de presentación de YouTube, y la integración de `LinkSchema` y `PropertiesSchema`.
  - Refactorizado `MenuResource.php` para unificar el campo de referencia (`reference_id`) e incorporar resolución reactiva de enlaces (vaciado de estado al cambiar entre Página, Post o URL).
  - Refactorizado `PostResource.php` para re-estructurar los metadatos SEO tipados y los componentes de enlace y propiedades.
  - Ejecutada exitosamente la suite de pruebas unitarias (`php artisan test`), verificando que los 10 tests unitarios y de integración y las 36 aserciones de aislamiento y negocio se mantengan al 100% verdes.
- **Archivos/áreas:**
  - `app/Filament/Schemas/LinkSchema.php` (Nuevo)
  - `app/Filament/Schemas/PropertiesSchema.php` (Nuevo)
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/PostResource.php`
- **Siguiente:**
  - Construir el Filament Resource de Contacts.
  - Desarrollar endpoints públicos en `/v1/...` para páginas, menús y settings.

## 2026-08-13 — Dominio y seguridad de Forms + Contacts

- **Agente/autor:** Tech Lead backend (Claude)
- **Qué se hizo:**
  - Bloque acotado explícitamente a dominio/seguridad de Forms + Contacts, en paralelo a otro agente construyendo los Filament Resources de contenido (Pages/Blocks/Sliders/Menus/Posts/Media) — sin tocar ni duplicar ese trabajo.
  - `App\Services\ContactSubmissionService`: único punto de entrada para guardar un envío de `Form`. Mapea `name`/`email`/`phone`/`company` a las columnas dedicadas de `Contact` (ya cifradas vía cast `encrypted`); cualquier otro campo va a `Contact::data` (jsonb), cifrado individualmente (`Crypt::encryptString`) si su `FormField::is_encrypted` es `true`, en texto plano si no. Valida presencia de campos requeridos (`InvalidArgumentException` si falta alguno). Expone `decryptData(Contact $contact)` que descifra selectivamente usando `FormField.is_encrypted` (por `name`) como única fuente de verdad — no se duplica esa metadata dentro del jsonb.
  - `App\Mail\ContactFormSubmitted`: Mailable Markdown limpio (sin lógica de cifrado ni de negocio, recibe el payload ya descifrado por el service) que notifica a `Form.notification_email`. El envío es best-effort: si falla, se loguea (`Log::warning`) pero el `Contact` ya guardado nunca se pierde. Vista en `resources/views/emails/contacts/form-submitted.blade.php`.
  - `App\Http\Resources\ContactResource`: transformer whitelist (uuid, name, status, source, assigned_to, timestamps) que nunca incluye email/phone/company/data/notes/ip/user_agent — listo para cuando exista la API pública/admin.
  - `App\Policies\ContactPolicy`: autorización tenant-aware (`viewAny`/`view`/`viewSensitive`/`create`/`update`/`delete`), resuelta por convención de Laravel sin registro manual. `viewSensitive` incluye TODO explícito para exigir re-auth antes de exports/listados masivos (fuera de alcance de este bloque, tal cual se pidió).
  - `App\Services\DataMasker`: helpers estáticos de enmascarado (`email()`, `phone()`, `value()`) para previsualización en UI/logs sin descifrar el valor completo.
  - `tests/Feature/ContactSubmissionServiceTest.php`: 3 tests — cifrado a nivel de fila cruda (bypass del cast de Eloquent) para email/phone/campo dinámico marcado, que un campo no marcado (`message`) queda legible en el jsonb crudo, `decryptData()` descifra correctamente, validación de campos requeridos, y aislamiento por tenant (mismo patrón que `TenantIsolationTest`).
  - Verificación: sin PHP en el sandbox del agente (igual que sesiones anteriores), revisión estática — balance de llaves, clase↔archivo, cada array de datos cruzado contra el `#[Fillable]` real de `Form`/`FormField`/`Contact`/`ContactActivity`, y confirmación en `vendor/` de que `Illuminate\Mail\Mailables\{Envelope,Content}`, `Illuminate\Http\Resources\Json\JsonResource` y `Illuminate\Contracts\Encryption\DecryptException` existen. Se confirmó además que Laravel resuelve `ContactPolicy` por convención (`Gate::guessPolicyName`) sin necesidad de `AuthServiceProvider` (el proyecto no tiene uno, es normal en Laravel 13).
  - Nueva decisión registrada en `DECISIONS.md` (ADR-015), incluyendo qué queda explícitamente fuera de alcance: honeypot/reCAPTCHA sin aplicar todavía (los flags ya existen en el esquema desde ADR-013), re-auth para exports, y el Filament Resource de Contacts en sí.
- **Archivos/áreas:**
  - `app/Services/{ContactSubmissionService,DataMasker}.php`
  - `app/Mail/ContactFormSubmitted.php`, `resources/views/emails/contacts/form-submitted.blade.php`
  - `app/Http/Resources/ContactResource.php`
  - `app/Policies/ContactPolicy.php`
  - `tests/Feature/ContactSubmissionServiceTest.php`
  - `docs/context/DECISIONS.md` (ADR-015)
- **Siguiente:**
  - Correr `php artisan test` en el entorno local real.
  - Construir el Filament Resource de Contacts sobre esta base (sin reinventar cifrado/autorización).
  - Decidir cuándo aplicar honeypot/reCAPTCHA y el flujo de re-auth para exports.
- **Estado del código de aplicación:** Service, Mail, Resource, Policy y tests completos y revisados estáticamente; pendiente de ejecución real (`php artisan test`).

---

## 2026-08-13 — Seeders mínimos idempotentes del MVP + Cliente 0 = CICA360

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano confirmó que las 25 migraciones de ADR-013 corrieron exitosamente contra PostgreSQL real.
  - Se reemplazó el `DatabaseSeeder` monolítico (con `Model::create()` directos, no seguro de re-ejecutar) por 5 seeders separados por responsabilidad, todos idempotentes vía `firstOrCreate`/`updateOrCreate`: `PlanSeeder`, `ModuleSeeder`, `FormFieldDefinitionSeeder`, `PlatformSeeder`, `Cliente0Seeder`.
  - `PlanSeeder`: crea el plan **Free** (`is_free`, `price_monthly`/`price_yearly` en 0, `max_users=1`, `max_pages=20`, `max_posts=50`, `max_storage_mb=500`) y 5 `plan_features` (mismos límites + `modules_vertical=false`), en `lang_iso=es`.
  - `ModuleSeeder`: crea 7 módulos core (`pages`, `posts`, `media`, `menus`, `settings`, `sliders`, `contacts`, todos `is_core=true`, `type=utility`) y los asocia al plan Free vía `plan_module` (`syncWithoutDetaching`).
  - `FormFieldDefinitionSeeder`: crea 6 definiciones de campo del sistema (`name`, `email`, `phone`, `company`, `subject`, `message`) con sus flags `default_required`/`default_encrypted` correctos (`email` y `phone` cifrados por defecto).
  - `PlatformSeeder`: super-admin de plataforma (`admin@genesisly.host`, `tenant_id=null`), idempotente.
  - `Cliente0Seeder`: confirma el **nombre real de Cliente 0: CICA360** (Centro Internacional de Consultoría y Asesoría). Busca primero por los valores nuevos (`slug=cica360`, `owner@cica360.com`) y hace fallback a los valores placeholder del scaffold original (`slug=cliente-0`, `admin@cliente0.com`) para **renombrar in-place** en vez de duplicar — necesario porque el plan Free limita `max_users=1`. Crea/actualiza: dominio `console.genesisly.host`, usuario owner (contraseña dev solo al crear, nunca al renombrar), suscripción activa al plan Free, `tenant_modules` para los 7 módulos core, settings (`site_name`, `default_locale`, `available_locales`) y un slider placeholder `home` sin slides.
  - `DatabaseSeeder` ahora solo orquesta el orden: `PlanSeeder → ModuleSeeder → FormFieldDefinitionSeeder → PlatformSeeder → Cliente0Seeder`.
  - Verificación: sin PHP disponible en el sandbox del agente (mismo bloqueador que la sesión anterior), se hizo revisión estática — balance de llaves, clase↔archivo, y cruce de cada array de datos contra el `#[Fillable]` real de cada modelo (`Plan`, `PlanFeature`, `Module`, `TenantModule`, `FormFieldDefinition`, `Tenant`, `Domain`, `User`, `Subscription`, `Setting`, `Slider`) para descartar errores de mass-assignment. Se confirmó además que `TenantManager` solo se muta desde el middleware HTTP `ResolveTenant` (nunca en `artisan db:seed`), así que los seeders no dependen de estado global de tenant y siempre pasan `tenant_id` explícito.
  - Nueva decisión registrada en `DECISIONS.md` (ADR-014).
- **Archivos/áreas:**
  - `database/seeders/{PlanSeeder,ModuleSeeder,FormFieldDefinitionSeeder,PlatformSeeder,Cliente0Seeder,DatabaseSeeder}.php`
  - `docs/context/DECISIONS.md` (ADR-014)
- **Siguiente:**
  - Correr `php artisan db:seed` en el entorno local real y confirmar los datos.
  - Construir los Filament Resources del contenido (Pages/Blocks, Posts, Sliders, Menus, Contacts).
- **Estado del código de aplicación:** Seeders completos y revisados estáticamente; pendiente de ejecución real contra la base de datos.

---

## 2026-08-13 — Esquema final de base de datos: contenido y negocio (migraciones + modelos)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Se recibió el DBML "FINAL (MVP)" del esquema de base de datos y, tras confirmar alcance con el humano (contenido + negocio, dejando auth avanzado para otra sesión — ver ADR-013), se implementaron 25 migraciones nuevas y 22 modelos Eloquent nuevos.
  - Módulos implementados: `media`; `pages` + `blocks`; `posts`; `sliders` + `slides`; `menus` + `menu_items`; `plans` + `plan_features` + `subscriptions` + `payment_methods` + `invoices` + `invoice_items` + `transactions`; `modules` + `plan_module` + `tenant_modules`; `form_field_definitions` + `forms` + `form_fields` + `contacts` + `contact_activities`.
  - Se creó `app/Enums/` con 18 Enums PHP nativos (`LanguageEnum`, `PageTypeEnum`, `PublishStatusEnum`, `BlockTypeEnum`, `SlideBackgroundTypeEnum`, `MenuItemTypeEnum`, `LinkTargetEnum`, `MediaDiskEnum`, `SubscriptionStatusEnum`, `BillingCycleEnum`, `InvoiceStatusEnum`, `TransactionTypeEnum`, `TransactionStatusEnum`, `PaymentMethodTypeEnum`, `ModuleTypeEnum`, `FormFieldTypeEnum`, `ContactStatusEnum`, `ContactActivityTypeEnum`), todos implementando `Filament\Support\Contracts\HasLabel`.
  - `tenants` se extendió con `short_hash` (default calculado en Postgres + asignado en `creating()`), `is_active`, `plan`. `settings` se extendió con `type`.
  - Todas las tablas tenant-scoped usan `HasTenant` + `HasUuid`; las tablas globales de plataforma (`plans`, `plan_features`, `modules`, `plan_module`, `form_field_definitions`) no llevan `tenant_id`, igual que `tenants`/`domains` (ver ARCHITECTURE.md §3).
  - `Contact.email/phone/company` usan el cast `encrypted` de Laravel y están en `$hidden`; `Contact.data` (jsonb) guarda las respuestas dinámicas del formulario.
  - Índices únicos compuestos `(tenant_id, lang_iso, slug)` en `pages`, `posts`, `sliders`, `menus`, `forms`; FKs con `constrained()` y `onDelete` explícito (`cascadeOnDelete`/`nullOnDelete`/`restrictOnDelete` según corresponda) en todas las tablas.
  - No se instaló `doctrine/dbal` (no estaba en el proyecto): se evitó cualquier `->change()` de columnas existentes; ver ADR-013 para el detalle de las dos desviaciones menores respecto al DBML que esto obligó (`tenants.short_hash` NOT NULL vía default de Postgres en vez de backfill+alter, `settings.tenant_id` se mantiene NOT NULL).
  - Verificación: no había PHP disponible en el sandbox del agente (`php`/`composer` no instalados, sin permisos de `apt`/`sudo` para instalarlos), así que no se pudo correr `php artisan migrate` real. Se hizo una verificación estática exhaustiva: balance de llaves en todos los archivos nuevos, correspondencia 1:1 entre clases de Enum declaradas y referenciadas, correspondencia clase↔nombre de archivo en todos los modelos, orden de dependencias FK entre las 25 migraciones (ninguna referencia hacia adelante), y confirmación en `vendor/` de que `jsonb()`, `decimal()`, `restrictOnDelete()` y los atributos `#[Fillable]`/`#[Hidden]` existen en la versión instalada de Laravel 13 / Filament 5.
- **Archivos/áreas:**
  - `app/Enums/*.php` (18 archivos nuevos)
  - `app/Models/{Media,Page,Block,Post,Slider,Slide,Menu,MenuItem,Plan,PlanFeature,Subscription,PaymentMethod,Invoice,InvoiceItem,Transaction,Module,TenantModule,FormFieldDefinition,Form,FormField,Contact,ContactActivity}.php`
  - `app/Models/{Tenant,Setting}.php` (extendidos)
  - `database/migrations/2026_08_13_*.php` (25 archivos)
  - `docs/context/DECISIONS.md` (ADR-013)
- **Siguiente:**
  - Correr `php artisan migrate` en el entorno local real y validar contra PostgreSQL.
  - Construir los Filament Resources del contenido (Pages/Blocks, Posts, Sliders, Menus, Contacts) — explícitamente fuera de alcance de esta sesión.
  - Decidir con el humano cuándo abordar la capa de auth avanzada del DBML (roles/permissions tenant-aware, Passport OAuth, Sanctum, `social_accounts`, `tenant_user`) como sesión separada con su propio ADR.
- **Estado del código de aplicación:** Migraciones y modelos completos y revisados estáticamente; pendiente de ejecución real contra la base de datos.

---

## 2026-08-13 — Recursos de Filament (Console) simples con Slide-over

- **Agente/autor:** Tech Lead (Gemini)
- **Qué se hizo:**
  - Corregida la migración de `short_hash` agregando detección de driver (`DB::connection()->getDriverName()`) para usar `''` como valor predeterminado en SQLite, solventando la limitación de SQLite para añadir columnas con funciones dinámicas en sentencias ALTER TABLE sin sacrificar la lógica en Postgres (producción).
  - Rediseñados los recursos de contenido del panel Console (`PageResource`, `SliderResource`, `MenuResource`, `PostResource`, `MediaResource`) como CRUDs simples (`ManageRecords`) que se gestionan por completo a través de paneles laterales deslizables (`slideOver()`) directamente desde la vista del listado.
  - Integradas las relaciones secundarias como repetidores anidados (`blocks` en `Page`, `slides` en `Slider` e `items` en `Menu`) ordenables (`sort_order`) y colapsables dentro del formulario principal, eliminando la necesidad de archivos de Relation Managers independientes.
  - Unificados los namespaces de las acciones (`EditAction`, `DeleteAction`, `CreateAction`, `BulkActionGroup`, `DeleteBulkAction`) bajo el nuevo estándar unificado de Filament 5 (`Filament\Actions\*`), resolviendo el error de clase `EditAction` no encontrada.
  - Actualizados los recursos con firmas de métodos estrictamente compatibles con Filament 5 (`form(Schema $schema): Schema` e imports asociados).
  - Todos los tests de la suite de pruebas unitarias pasan con éxito (10 tests, 36 aserciones).
- **Archivos/areas:**
  - `database/migrations/2026_08_13_000001_add_business_fields_to_tenants_table.php`
  - `app/Filament/Resources/*` (MediaResource, PageResource, SliderResource, MenuResource, PostResource)
- **Siguiente:**
  - Desarrollar la API REST pública versión 1 (`/v1/pages`, `/v1/menus`, `/v1/settings`) para que puedan ser consumidas por el frontend estático de Astro o Next.js.
- **Estado del código de aplicación:** Completamente funcional, testeado y listo para pruebas en `console.genesisly.host`.

## 2026-08-12 — Base de datos genesis_cms, Settings tenant-aware, Subdominios y Paneles de Filament

- **Agente/autor:** Tech Lead (Gemini)
- **Qué se hizo:**
  - Creada la base de datos limpia `genesis_cms` en PostgreSQL Docker en el puerto 5434 y configurados `.env` y `.env.example`.
  - Definidos los nuevos ADRs de ID/UUID/Slug, estándar de API JSON, uso de Enums, tabla settings y subdominios con paneles de Filament.
  - Implementado el trait `HasUuid` para autogenerar UUIDs en inserciones y configurado `$routeKeyName` en modelos.
  - Creada la tabla y el modelo `Setting` con alcance tenant-aware (`tenant_id`) y clave única `(tenant_id, key)`.
  - Desarrollado el `SettingService` singleton y el helper global `setting($key, $default)` con caché optimizada para evitar queries N+1.
  - Creado el observer `SettingObserver` que invalida automáticamente la caché del inquilino al modificar una setting.
  - Configurados los subdominios de Filament eliminando `AdminPanelProvider` y añadiendo `PanelCmsProvider` (`console.genesisly.host` con tenancy) y `PanelManagerProvider` (`manager.genesisly.host` sin tenancy).
  - Diseñado y ejecutado el `DatabaseSeeder` para inicializar el super-admin global, el Cliente 0, sus dominios de resolución, el administrador del tenant y sus configuraciones iniciales.
  - Añadidas aserciones de UUID y de settings en `TenantIsolationTest`. Todos los 7 tests pasaron de forma exitosa (21 aserciones).
- **Archivos/áreas:**
  - `bootstrap/providers.php`, `bootstrap/app.php`, `composer.json`
  - `app/Traits/HasUuid.php`, `app/Models/Setting.php`, `app/Observers/SettingObserver.php`
  - `app/Services/SettingService.php`, `app/Helpers/settings_helper.php`
  - `app/Providers/Filament/PanelCmsProvider.php`, `app/Providers/Filament/PanelManagerProvider.php`
  - `database/migrations/*`, `database/seeders/DatabaseSeeder.php`
  - `tests/Feature/TenantIsolationTest.php`
- **Siguiente:**
  - Implementar los modelos de Contenido: `Page` y `Block`, y configurar sus recursos en el panel de Filament.
- **Estado del código de aplicación:** 100% funcional y testeado, base de datos limpia y estructura robusta.

## 2026-08-12 — Scaffold del proyecto y esqueleto multi-tenant

- **Agente/autor:** Tech Lead (Gemini)
- **Qué se hizo:**
  - Configurada la base de datos PostgreSQL Docker en el puerto 5434 y creado el schema `genesis`.
  - Instalado Filament v5 con soporte para Livewire v4 sobre Laravel 13.
  - Creadas las migraciones para `tenants`, `domains` y la relación en la tabla `users` dentro del schema `genesis`.
  - Implementado el singleton `TenantManager` y el middleware global `ResolveTenant` para resolver el tenant mediante hostnames, headers y parámetros.
  - Implementado el trait `HasTenant` con su respectivo global scope `TenantScope` para aislamiento de datos.
  - Integrado el multi-tenancy nativo de Filament en el modelo `User` (mediante el contrato `HasTenants`) y en `AdminPanelProvider`.
  - Creadas y verificadas las pruebas de aislamiento de datos en `TenantIsolationTest`. Todos los tests pasaron exitosamente.
- **Archivos/áreas:**
  - `.env`, `.env.example`, `config/database.php`, `bootstrap/app.php`
  - `app/Models/Tenant.php`, `app/Models/Domain.php`, `app/Models/User.php`
  - `app/Models/Scopes/TenantScope.php`, `app/Traits/HasTenant.php`
  - `app/Services/TenantManager.php`, `app/Http/Middleware/ResolveTenant.php`
  - `app/Providers/Filament/AdminPanelProvider.php`
  - `database/migrations/2026_08_12_000001_create_tenants_and_domains_tables.php`
  - `tests/Feature/TenantIsolationTest.php`
- **Siguiente:**
  - Implementar los modelos de Contenido: `Page` y `Block`, y configurar sus recursos en el panel de Filament.
- **Estado del código de aplicación:** Ejecutable y testeado, base multi-tenant lista para el MVP.

## 2026-08-11 — Creación de estructura de contexto del proyecto

- **Agente/autor:** Arquitecto / Tech Lead (sesión de bootstrap de docs)
- **Qué se hizo:**
  - Creada la carpeta `docs/context/` con la documentación operativa del proyecto.
  - Añadidos: `ARCHITECTURE.md`, `CURRENT_STATE.md`, `DECISIONS.md`, `TASK.md`, `PROGRESS.md`.
  - Añadidos en la raíz: `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `README.md`.
  - Registradas decisiones iniciales (ADR-001 … ADR-006): nombre, stack, multi-tenancy, R2, freemium, Cliente 0.
  - Definida la tarea activa: bootstrap Laravel + Filament hacia MVP Headless Cliente 0.
- **Archivos/áreas:**
  - `docs/context/*`
  - `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `README.md`
- **Siguiente:**
  - Scaffold del proyecto Laravel 11/12 + Filament + PostgreSQL.
  - Implementar esqueleto multi-tenant (`tenants`, `domains`, scopes).
- **Estado del código de aplicación:** aún no existe (repo de docs/contexto).
