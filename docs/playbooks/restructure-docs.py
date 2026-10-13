#!/usr/bin/env python3
"""Reestructura docs/context/ de Stamless para que cualquier IA lo lea barato.

Uso (desde la raíz del repo):
  python3 docs/playbooks/restructure-docs.py            # simulación, no escribe nada
  python3 docs/playbooks/restructure-docs.py --apply    # aplica los cambios

Opciones:
  --keep N     entradas recientes de PROGRESS.md que se quedan (10 por defecto)
  --force      aplica aunque haya cambios sin commitear en los 4 archivos o ya exista el destino
  --root RUTA  raíz del repo (por defecto: dos niveles arriba de este script)

Qué hace:
  1. DECISIONS.md   -> solo índice; cada ADR pasa a docs/decisions/ADR-NNN.md
  2. PROGRESS.md    -> quedan las N entradas más recientes; el resto va a docs/archive/progress/AAAA-MM.md
  3. CURRENT_STATE.md y TASK.md -> las líneas históricas del encabezado ("> Antes, ...") pasan a
     docs/archive/state/ y queda una sola línea de "Última actualización".
No borra contenido, lo mueve; git es la copia de seguridad. Verifica conteos al terminar.
"""
from __future__ import annotations

import argparse
import datetime
import re
import subprocess
import sys
from pathlib import Path

ADR_HEAD = re.compile(r'^## (ADR-(\d+))\s*[—–-]+\s*(.+?)\s*$')
DATE_HEAD = re.compile(r'^## (\d{4}-\d{2}-\d{2})\b')
ANCHOR = re.compile(r'\]\(#adr-(\d+)[^)]*\)', re.I)
FENCE = re.compile(r'^\s*(```|~~~)')
STATE_LINE = re.compile(r'^> (Antes\b|Última actualización:)')
CONTEXT_FILES = ['DECISIONS.md', 'PROGRESS.md', 'CURRENT_STATE.md', 'TASK.md']


def split_sections(lines, head_re):
    """Devuelve (preámbulo, [(match, líneas)]). Ignora encabezados dentro de bloques de código."""
    pre, secs, cur, fence = [], [], None, False
    for line in lines:
        if FENCE.match(line):
            fence = not fence
        m = None if fence else head_re.match(line.rstrip('\n'))
        if m:
            cur = (m, [line])
            secs.append(cur)
        elif cur:
            cur[1].append(line)
        else:
            pre.append(line)
    return pre, secs


def trim(lines):
    out = list(lines)
    while out and out[-1].strip() in ('', '---'):
        out.pop()
    return out


def read_lines(path: Path):
    return path.read_text(encoding='utf-8').splitlines(keepends=True)


def short(text: str, n: int) -> str:
    text = text.replace('|', '\\|')
    return text if len(text) <= n else text[: n - 1].rstrip() + '…'


def plan_decisions(root: Path):
    lines = read_lines(root / 'docs/context/DECISIONS.md')
    pre, secs = split_sections(lines, ADR_HEAD)
    intro = []
    for line in pre:
        if line.startswith('## '):
            break
        intro.append(line)
    files, rows, seen = {}, [], {}
    for m, body in secs:
        adr, num, title = m.group(1), int(m.group(2)), m.group(3)
        seen[adr] = seen.get(adr, 0) + 1
        name = f'{adr}.md' if seen[adr] == 1 else f'{adr}-{seen[adr]}.md'
        text = ''.join(trim(body))
        text = ANCHOR.sub(lambda mm: f'](ADR-{mm.group(1)}.md)', text)
        text = '# ' + text[3:]
        files[f'docs/decisions/{name}'] = '> Índice: [DECISIONS.md](../context/DECISIONS.md)\n\n' + text + '\n'
        fecha = re.search(r'\|\s*\*\*Fecha\*\*\s*\|\s*([^|]+?)\s*\|', text)
        estado = re.search(r'\|\s*\*\*Estado\*\*\s*\|\s*(.+?)\s*\|\s*$', text, re.M)
        est = estado.group(1) if estado else ''
        est = re.sub(r'\]\(ADR-(\d+)\.md\)', r'](../decisions/ADR-\1.md)', est)
        rows.append((num, name, adr, fecha.group(1) if fecha else '', title, est))
    rows.sort(key=lambda r: (-r[0], r[1]))
    table = ['| ID | Fecha | Título | Estado |\n', '|----|-------|--------|--------|\n']
    for num, name, adr, fecha, title, est in rows:
        table.append(f'| [{adr}](../decisions/{name}) | {fecha} | {short(title, 150)} | {short(est, 120) if est else ""} |\n')
    hoy = datetime.date.today().isoformat()
    nota = (
        f'> **Estructura (reordenada el {hoy}):** este archivo es solo el índice. El texto completo de cada '
        'decisión vive en `docs/decisions/ADR-NNN.md`. Decisión nueva: crear `docs/decisions/ADR-NNN.md` y '
        'agregar su fila arriba de la tabla. No borrar decisiones: marcar `Superseded` y enlazar la nueva.\n'
    )
    new = ''.join(trim(intro)) + '\n\n' + nota + '\n## Índice\n\n' + ''.join(table)
    files['docs/context/DECISIONS.md'] = new
    return files, {'adrs': len(secs), 'fuente': (root / 'docs/context/DECISIONS.md')}


def plan_progress(root: Path, keep: int):
    lines = read_lines(root / 'docs/context/PROGRESS.md')
    pre, secs = split_sections(lines, DATE_HEAD)
    kept, rest = secs[:keep], secs[keep:]
    by_month: dict[str, list[str]] = {}
    for m, body in rest:
        by_month.setdefault(m.group(1)[:7], []).append(''.join(trim(body)))
    files = {}
    for month, entries in by_month.items():
        files[f'docs/archive/progress/{month}.md'] = (
            f'# Progreso archivado — {month}\n\n> Entradas del historial, más reciente primero. '
            'Vigente: [PROGRESS.md](../../context/PROGRESS.md).\n\n' + '\n\n'.join(entries) + '\n'
        )
    cutoff = kept[-1][0].group(1) if kept else 'n/a'
    nota = (
        f'> Aquí solo están las {len(kept)} entradas más recientes (desde {cutoff}). '
        'El resto se archivó por mes en `docs/archive/progress/`. No lo leas completo: búscalo con grep.\n'
    )
    new = ''.join(trim(pre)) + '\n\n' + nota + '\n' + '\n\n'.join(''.join(trim(b)) for _, b in kept) + '\n'
    files['docs/context/PROGRESS.md'] = new
    return files, {'total': len(secs), 'kept': len(kept), 'archived': len(rest), 'months': len(by_month)}


def plan_state(root: Path, name: str):
    path = root / 'docs/context' / name
    lines = read_lines(path)
    first_h2 = next((i for i, l in enumerate(lines) if l.startswith('## ')), len(lines))
    idx = [i for i, l in enumerate(lines[:first_h2]) if STATE_LINE.match(l)]
    if not idx:
        return {}, {'moved': 0}
    moved = [lines[i] for i in idx]
    latest = moved[0]
    fecha = re.search(r'(\d{4}-\d{2}-\d{2})', latest)
    titulo = re.search(r'\*\*(.+?)\*\*', latest)
    archive = f'docs/archive/state/{path.stem}-header-log.md'
    resumen = (
        f"> Última actualización: {fecha.group(1) if fecha else '?'} — "
        f"{short(titulo.group(1), 200) if titulo else 'ver historial'}. Historial anterior: `{archive}`\n"
    )
    drop = set(idx)
    new = []
    for i, line in enumerate(lines):
        if i == idx[0]:
            new.append(resumen)
        elif i not in drop:
            new.append(line)
    files = {
        f'docs/context/{name}': ''.join(new),
        archive: f'# {path.stem} — historial del encabezado (archivado)\n\n' + '\n'.join(l.rstrip('\n') + '\n' for l in moved),
    }
    return files, {'moved': len(moved)}


def kb(n: int) -> str:
    return f'{n / 1024:.0f} KB (~{n // 4 // 1000}k tokens)'


def main() -> int:
    ap = argparse.ArgumentParser(description='Reestructura docs/context/')
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--keep', type=int, default=10)
    ap.add_argument('--force', action='store_true')
    ap.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[2])
    args = ap.parse_args()
    root = args.root.resolve()

    for n in CONTEXT_FILES:
        if not (root / 'docs/context' / n).is_file():
            print(f'Falta docs/context/{n} en {root}', file=sys.stderr)
            return 1

    if args.apply and not args.force:
        try:
            out = subprocess.run(
                ['git', '-C', str(root), 'status', '--porcelain', '--'] + [f'docs/context/{n}' for n in CONTEXT_FILES],
                capture_output=True, text=True, check=True).stdout.strip()
        except (OSError, subprocess.CalledProcessError):
            print('No pude consultar git; usa --force si estás seguro.', file=sys.stderr)
            return 1
        if out:
            print('Hay cambios sin commitear en los archivos a mover:\n' + out + '\nHaz commit o usa --force.', file=sys.stderr)
            return 1

    plan, stats = {}, {}
    f, stats['decisions'] = plan_decisions(root); plan.update(f)
    f, stats['progress'] = plan_progress(root, args.keep); plan.update(f)
    for n in ('CURRENT_STATE.md', 'TASK.md'):
        f, stats[n] = plan_state(root, n); plan.update(f)

    d, p = stats['decisions'], stats['progress']
    print(f"ADRs detectados: {d['adrs']} -> docs/decisions/ + índice")
    print(f"PROGRESS: {p['total']} entradas = {p['kept']} se quedan + {p['archived']} archivadas en {p['months']} meses")
    print(f"Líneas de historial movidas: CURRENT_STATE={stats['CURRENT_STATE.md']['moved']}, TASK={stats['TASK.md']['moved']}")
    print('\nTamaños de lectura obligatoria/frecuente:')
    for n in CONTEXT_FILES:
        before = (root / 'docs/context' / n).stat().st_size
        after = len(plan[f'docs/context/{n}'].encode('utf-8')) if f'docs/context/{n}' in plan else before
        print(f'  {n:18} {kb(before):24} -> {kb(after)}')

    if not args.apply:
        print('\nSimulación: no se escribió nada. Revisa y ejecuta de nuevo con --apply.')
        return 0

    for rel in plan:
        target = root / rel
        if target.exists() and not args.force and not rel.startswith('docs/context/'):
            print(f'Ya existe {rel}; usa --force para sobrescribir.', file=sys.stderr)
            return 1
    for rel, text in plan.items():
        target = root / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding='utf-8')

    adr_files = len([r for r in plan if r.startswith('docs/decisions/')])
    prog_after = sum(
        sum(1 for line in plan[r].splitlines() if DATE_HEAD.match(line))
        for r in plan if r.startswith('docs/archive/progress/') or r == 'docs/context/PROGRESS.md'
    )
    ok = adr_files == d['adrs'] and prog_after == p['total']
    print(f"\nVerificación: ADR {adr_files}/{d['adrs']}, entradas de PROGRESS {prog_after}/{p['total']} -> {'OK' if ok else 'REVISAR'}")
    for n in ('CURRENT_STATE.md', 'TASK.md'):
        size = (root / 'docs/context' / n).stat().st_size
        if size > 40 * 1024:
            print(f'Aviso: {n} sigue en {kb(size)}. Condénsalo con docs/playbooks/condensar-estado.md.')
    print('Siguiente: git diff --stat, revisa y haz commit.')
    return 0 if ok else 2


if __name__ == '__main__':
    sys.exit(main())
