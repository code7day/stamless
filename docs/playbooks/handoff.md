# Playbook — Handoff (cierre de sesión)

Objetivo: 5 minutos. **Reemplaza, no acumules**: el historial vive en `PROGRESS.md`, los ADR y git.

1. **`CURRENT_STATE.md`**: actualiza "qué está hecho", "en progreso", "siguiente paso inmediato" y "bloqueadores". Máximo ~200 líneas. Lo que ya no sea relevante se borra.
2. **`TASK.md`**: owner, checklist de la tarea activa y siguiente subtarea. Una sola tarea activa.
3. **`PROGRESS.md`**: una entrada nueva ARRIBA, máximo 8 líneas: `## AAAA-MM-DD — Título`, agente, qué se hizo, archivos, tests y siguiente paso.
4. **Decisión nueva** → crea `docs/decisions/ADR-NNN.md` (siguiente número) y agrega su fila en la tabla de `DECISIONS.md`.
5. **Cambió el diseño** → `ARCHITECTURE.md`. **Cambió un plan o límite** → `PLANS.md`. **Cambió el rumbo o una puerta de lanzamiento** → `ROADMAP.md`.
6. **Convención o trampa de código** → `record-rule` de Laravel Boost (queda en `.ai/rules/`).
7. Tu último mensaje al humano termina con una línea: `Siguiente paso: …`.
