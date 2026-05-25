# TASKS — ci4-api-scaffolding

> Fuente de verdad para trabajo en este repo.
> Si una tarea impacta a consumers, referenciar también `../TASKS.md`.
> Última actualización: 2026-05-24 (backlog creado desde la auditoría del bootstrap `ci4-catalog`)

---

## 🔴 En progreso

*(vacío)*

---

## 🟡 Próximo

*(vacío)*

---

## ✅ Completadas

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
