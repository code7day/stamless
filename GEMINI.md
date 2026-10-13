# Gemini / Antigravity — Stamless CMS Headless

La guía canónica para cualquier IA es [`AGENTS.md`](AGENTS.md); **léela primero**. Ahí están qué leer al empezar, las reglas, el handoff y el idioma común. Las guías de Laravel Boost están en el bloque `<laravel-boost-guidelines>` de ese mismo archivo. Este archivo solo agrega lo específico de Gemini.

## Qué se espera de ti

Implementación diaria con tareas **acotadas**: una subtarea de `TASK.md` a la vez, con sus archivos y su criterio de aceptación. También tests, correcciones y resúmenes de archivos largos.

Si una tarea te obliga a decidir arquitectura, cambiar planes o límites, o tocar el rumbo del lanzamiento, **detente** y déjalo como pregunta en `TASK.md`: eso se decide con el humano y Claude en Cowork.

## Lectura mínima

1. `docs/context/ROADMAP.md`, `CURRENT_STATE.md` y `TASK.md`.
2. Lo demás, solo cuando haga falta (tabla de `AGENTS.md`).
3. No abras `PROGRESS.md` completo ni `docs/archive/`. Usa búsqueda y lee el ADR concreto.

## Herramientas del proyecto

- **Laravel Boost (MCP):** configurado para Antigravity en `.agents/mcp_config.json`. Necesita PHP y `vendor/` (`composer install`). Úsalo antes de adivinar: `search-docs`, `database-schema`, `database-query` y `record-rule` (guarda convenciones en `.ai/rules/`).
- **Skills:** `.agents/skills/` (`laravel-best-practices`, `tailwindcss-development`, `infer-conventions`). Actívalas cuando trabajes en ese dominio.
- **Procedimientos:** `docs/playbooks/` (handoff y condensar estado).

## Antes de terminar

Cumple `docs/playbooks/handoff.md`. Termina tu mensaje con `Siguiente paso: …`. Corre los tests afectados y `vendor/bin/pint --dirty --format agent`.
