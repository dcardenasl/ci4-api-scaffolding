# ci4-api-scaffolding: Snapshot

> Actualizado: 2026-11-21 · Lo escribe `cerrar-sesion`; lo lee `abrir-sesion` y `./dcl hoy`.
> Mantenerlo corto: esto no es un registro, es la forma de retomar en frío.

## Dónde quedé

Rama `dev` al día con `origin/dev`. Última versión publicada: v1.2.3 (2026-11-17), con la corrección de `created_at`/`updated_at` en los DTO generados. Adoptó el kit en modo lite el 2026-11-21; el kit no cambió código.

## Próximo paso

Mantención mensual: dependencias y seguridad (`composer audit`, `composer outdated`). Sin versión planificada.

## Bloqueos

- (ninguno)

## Decisiones pendientes de David

- Alcance de la próxima versión: entrevista de roadmap pendiente (`grill-me`), decide David.
- `TASKS.md` aún lista SCAFF-007 en «Próximo», pero el CHANGELOG 1.2.3 dice que quedó corregido. Confirmar y actualizar `TASKS.md`.

## Verificado y no verificado

- Verificado: `python3 .dcl/dcl.py doc-check` tras la adopción (2026-11-21), `git status` y etiquetas del repo.
- No verificado: `.claude/` sin seguimiento en el repo; pruebas (`composer test`) y auditorías de dependencias: no se ejecutaron en la adopción (el entorno de la sesión no tiene PHP ni Composer).

## Comandos para retomar

```bash
git branch --show-current
git status --short
python3 .dcl/dcl.py doc-check
```
