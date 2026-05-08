# Architecture Contract

Non-negotiable architecture rules for modules built with `dcardenasl/ci4-api-core`. These constraints are what the scaffolding engine assumes when generating code; deviating from them without updating `Config\Scaffolding` will produce code that does not compile or does not integrate.

## 1. Layer Contracts

1. Controllers must extend `ApiController` (or your configured `controllerBaseClass`).
2. Controllers must use `handleRequest(…)` and request DTO classes for input validation.
3. Services must contain business logic only (no HTTP response construction).
4. Service reads must return DTOs (`DataTransferObjectInterface`).
5. Command-style service flows must return `OperationResult`.
6. Persistence remains in Models/Entities.

## 2. DTO-First Rules

1. All cross-layer payloads must use DTOs.
2. Request DTOs must extend `BaseRequestDTO` (or your configured `requestDtoBaseClass`).
3. Request DTOs must validate with `rules()` and constructor auto-validation.
4. Response DTOs must implement `DataTransferObjectInterface`.
5. Response DTOs should expose only API-safe fields.

## 3. CRUD Base Contract

For services implementing `CrudServiceContract` / `BaseCrudService`:

1. `index()` must return `DataTransferObjectInterface` (paginated shape via `PaginatedResponseDTO`).
2. `show()`, `store()`, and `update()` return resource DTOs.
3. `destroy()` returns `bool`.

## 4. Controller Pipeline Rules

1. Do not call `collectRequestData()` directly in concrete controllers.
2. Do not reimplement try/catch API normalization in concrete controllers.
3. Keep controllers thin: orchestration only.

## 5. Operational Rules

1. New services must be registered in `app/Config/Services.php`.
2. New modules must include `en` and `es` language files.
3. New modules must include Unit/Feature tests (and Integration when persistence logic is relevant).
4. `make:crud` is the recommended default bootstrap, followed by explicit migration review.
5. Default CRUD persistence should use `GenericRepository`; dedicated repositories are required only for non-trivial domain queries.
6. Runtime classes (`Commands`, `Filters`) should resolve dependencies via container/model helpers (`Config\Services`, `model()`), not direct `new *Model()` calls.
7. `composer quality` must pass before merge.
