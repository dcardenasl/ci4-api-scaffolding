# TASKS — ci4-api-scaffolding

> Fuente de verdad para trabajo en este repo.
> Si una tarea impacta a consumers, referenciar también `../TASKS.md`.
> Última actualización: 2026-05-26 (SCAF-004 completado; backlog base creado desde la auditoría del bootstrap `ci4-catalog`)

---

## 🔴 En progreso

*(vacío)*

---

## 🟡 Próximo

*(vacío)*

---

## ✅ Completadas

### SCAF-006 — Robustez en inyección de rutas (F7 / Post-Audit FAQ)
- **Qué**: `RouteGenerator::injectRoute` ahora usa Regex en lugar de `str_contains` para encontrar el punto de inserción dentro del grupo protegido. El patrón soporta firmas de cierre con `: void` (añadidas por PHP CS Fixer) y es flexible con los espacios.
- **Por qué**: F7 del audit de coherencia y hallazgo clave en el audit de FAQ. Evita que el scaffolding rompa o inyecte fuera del grupo si el archivo fue formateado previamente.
- **Verificado**: Implementado y verificado manualmente con mock de contenido formateado.

### SCAF-005 — Alineación de dependencias (audit de coherencia, Tier 2)
- **Qué**: `dcardenasl/ci4-api-core` `^0.8.0` → `^0.9.0` (cascada F4); `phpunit/phpunit` `^10.5` → `^11.0` (F2); `phpstan/phpstan` `^2.0` → `^2.1` (F1); `composer.lock` CI4 `v4.7.2` → `v4.7.3` (F3); schema de `phpunit.xml.dist` a 11.5; branch-alias `0.7.x-dev`.
- **Por qué**: audit de coherencia (2026-05-28). El pin `^0.8.0` excluía core 0.9.0, lo que habría bloqueado a los consumers que adoptan core 0.9 — el bump es obligatorio para la cascada.
- **Verificado**: `composer quality` limpio — PHPStan L8, CS-Fixer, security, 114 tests / 481 assertions (Unit + E2E), 0 deprecations (PHPUnit 11.5). **Released v0.7.0**.

### SCAF-004 — Plantillas del motor con tipos explícitos (2026-05-26)
- **Qué**: `DtoGenerator` ahora emite docblocks `@return array<string, string>` / `@param array<string, mixed>` en todos los request DTOs y `ResponseDTO`. El `Controller` generado también tipa explícitamente las closures de `handleRequest()` con `SecurityContext` y `mixed`, y `UpdateRequestDTO` usa una closure `static fn (mixed $value): bool` para su `array_filter()`.
- **Por qué**: cerrar la deuda de contrato del scaffold para que el código generado sea más legible para humanos y más estable para análisis estático downstream.
- **Verificado**: `composer analyse` limpio, `composer test` limpio, snapshots actualizadas.

### SCAF-003 — Contrato `bool` → `boolean_like` explícito
- **Qué**: se decidió mantener `bool -> boolean_like` como parte del contrato soportado por los starters oficiales, en lugar de introducir una estrategia configurable por target dentro del scaffolder. `README.md` ahora lo deja explícito para consumers no-starter.
- **Por qué**: el repo ya modela sus defaults alrededor de las convenciones de los starter kits; mover este caso a configuración por target agregaba complejidad sin una segunda familia de consumers con necesidades reales distintas.
- **Verificado**: documentación actualizada y cubierta por la nueva prueba de compatibilidad cross-repo de `TypeMapper`.

### SCAF-001 — Cobertura de compatibilidad para boolean fields generados
- **Qué**: se añadió `tests/Unit/Core/TypeMapperCompatibilityTest.php` para fijar que los campos `bool` generan `permit_empty|boolean_like` y para comprobar que los repos soportados downstream (`ci4-api-starter`, `ci4-domain-starter`) exponen y registran `boolean_like`.
- **Por qué**: el fallo del bootstrap `ci4-catalog` no fue del generator en sí, sino drift entre lo que emite y lo que el starter soporta en runtime.
- **Verificado**: test unitario nuevo en el paquete, pensado para fallar en el workspace si vuelve a desaparecer `boolean_like` de un starter soportado.

### SCAF-002 — Documentar límites reales de `make:crud`
- **Qué**: `README.md` ahora incluye la sección `Scaffolding Boundaries`, explicando qué cubre bien `make:crud` y qué casos requieren extensión manual posterior: workflow actions, nested resources, relation arrays, pivots con PK compuesta, cross-field invariants y response enrichment.
- **Por qué**: en la sesión de bootstrap de `ci4-catalog`, la principal confusión no fue que el scaffolder fallara por completo, sino que parecía prometer más de lo que realmente cubre para aggregates.
- **Verificado**: documentación añadida y enlazada en el TOC del README.

---

## ⚪ Backlog

- Mover aquí futuras tareas de field types, relaciones, snapshots y DX que antes vivían mezcladas en `ci4-api-core`.

---

## 🏗️ Contratos de arquitectura

- **Este repo genera código para otros repos** — cualquier cambio debe pensarse como cambio de contrato.
- **No asumir capacidades implícitas en los starters** — si el generator emite una regla, hook o patrón, debe existir en los consumers soportados o estar documentado como requisito.
- **Snapshot tests y e2e smoke** son parte del contrato del repo, no solo tests de conveniencia.
- **Documentar límites del scaffolding** es tan importante como agregar features nuevas.
