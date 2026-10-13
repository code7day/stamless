# Playbook — Condensar CURRENT_STATE.md y TASK.md (para Gemini en Antigravity)

Contexto: tras correr `docs/playbooks/restructure-docs.py --apply`, `CURRENT_STATE.md` y `TASK.md` siguen siendo largos (la sección "Qué está hecho" concentra casi todo). Hay que dejarlos en **snapshots de lectura rápida**.

## Prompt sugerido

> Lee `docs/context/ROADMAP.md`, `docs/context/CURRENT_STATE.md` y `docs/context/TASK.md`.
> Reescribe `CURRENT_STATE.md` como un snapshot de **máximo 200 líneas** con estas secciones: Resumen ejecutivo, Qué existe hoy (por módulo, una línea cada uno con la ruta o ADR relevante), En progreso, Bloqueadores, Siguiente paso inmediato (una línea) y Notas para el próximo agente.
> Reescribe `TASK.md` con: tarea activa, owner, checklist y backlog ordenado alineado a las puertas G1–G6 de `ROADMAP.md`. Máximo 120 líneas.
> Reglas: no inventes hechos; si un dato no está en los docs, no lo pongas. Todo detalle que saques va completo a `docs/archive/state/CURRENT_STATE-condensado-AAAA-MM-DD.md` (o el equivalente de TASK) para no perder nada. No toques `docs/decisions/`, `docs/archive/progress/` ni código.

## Verificación (la hace otra IA o el humano)

- `wc -l docs/context/CURRENT_STATE.md docs/context/TASK.md` ≤ 200 y ≤ 120.
- Cada módulo del archivo condensado apunta a un ADR o a una ruta real (`ls` para comprobar).
- El archivo archivado contiene el texto original de lo removido.
- `git diff --stat` solo muestra cambios en `docs/`.
