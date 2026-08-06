# Changelog

All notable changes to `dcardenasl/ci4-api-scaffolding` will be documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **`Controller.php.tpl`'s `show()` permission check (SCAFF-009)** — `GET /{resource}/{id}` compared
  against an unprefixed `{resource}.read` permission instead of `{permissionResource}.read` like every
  other action, so it returned `403` unconditionally in any project with a non-empty
  `permissionCodePrefix` — the basic "read one" operation of every scaffolded resource, not just
  translatable ones.

## [1.2.0] — 2026-08-06

> Renumbered from the unreleased `1.1.3` — the DTO null-clearing fix below was cut as a patch, but
> `--translatable`/`--sluggable` (this version's headline addition) is a feature, and the two were
> still unreleased together. SemVer requires the combined unreleased content to ship as the higher
> of the two bumps.

### Added

- **`make:crud --translatable` / `--sluggable=<field>` (LOC-007)** — scaffolds resources that compose
  `dcardenasl/ci4-api-core` v1.2.0's content-localization runtime instead of hand-wiring it per project
  (the exact ~50-line trait-aliasing block that had been copied by hand into 3-4 services per downstream
  project). A `translatable` field-string modifier (`title:string:required|translatable`) registers the
  field for locale-aware translation; `--sluggable=<field>` additionally generates locale-aware public
  slugs from a translatable `string` field.
  - `Core\Field::$translatable` and `Core\ResourceSchema::$sluggableField` (+ `hasTranslatableFields()`,
    `translatableFieldNames()`, `isSluggable()`, `localizationResourceType()`).
  - `ServiceGenerator` composes `HasLocalizedTranslations` alone, or both `HasLocalizedTranslations` +
    `HasPublicSlugs` (with the required trait aliasing and the six lifecycle-hook overrides) when the
    resource is also sluggable — matching `ci4-api-core`'s `docs/EXTENDING_LOCALIZATION.md` §6 example
    exactly. Constructor gains `LocalizedTranslationStore` (+ `PublicSlugStore` when sluggable) params.
  - `DtoGenerator` adds a `translations` property (via `NormalizesLocalizedPayload`) to the Create/Update
    RequestDTOs, and `translations`/`localized` (+ `slug`/`slugs` when sluggable) to the ResponseDTO —
    without these, `ResponseDTO::fromArray()`'s explicit key-mapping would silently drop the fields the
    core traits inject into the response payload.
  - `ConfigWireman` gains `registerTranslatableFields()`: auto-creates `app/Config/Localization.php` on
    first use (mirrors `registerPermissions()`'s handling of `DomainPermissions.php`) and appends the
    resource's translatable fields to `$translatableFields` via AST injection — idempotent, best-effort
    like the existing permissions registration.
  - `module:check` gains a checkpoint: if the generated Service composes `HasLocalizedTranslations` /
    `HasPublicSlugs`, verify `Config\Services::localizedTranslationStore()` / `publicSlugStore()` (and
    `Config\Localization`) actually exist in the consumer app, with a message pointing at
    `EXTENDING_LOCALIZATION.md` — without this, a missing prerequisite only surfaced as a
    `BadMethodCallException` the first time the scaffolded service resolved.
  - `--sluggable=<field>` validates that the named field exists, is of type `string`, and also carries
    the `translatable` modifier — slug generation reads locale-specific values from the translation
    store, so an untranslated source field would only ever produce one slug duplicated across locales.
  - `ControllerGenerator`, `MigrationGenerator`, and `TestGenerator` are unaffected: translatable/sluggable
    resources still store their translations/slugs in the app-owned `translations`/`public_slugs` sidecar
    tables (one pair per app, not per resource — LOC-006), and the Controller/Test layers resolve the
    Service exclusively through the `Config\Services` factory, never via a direct constructor call.
  - `bin/make-crud.sh` forwards `--sluggable=<field>` (and `--sluggable <field>`) to `make:crud`.

### Fixed

- **`DtoGenerator` — scaffolded `*UpdateRequestDTO`s could never clear a nullable field.** `toArray()` wrapped the whole payload in `array_filter($v !== null)`, which silently dropped *any* field the client sent as `null` — including an intentional `{"field": null}` meant to clear a nullable column. Combined with `map()` resolving values via `isset($data[x])` (which reads `false` both when the key is absent *and* when it is present with a `null` value), there was no way for a client to distinguish "leave this field alone" from "clear this field" — both collapsed to the same missing key in the outgoing update payload, so the value silently stayed unchanged. Discovered while diagnosing a "Quitar imagen" (remove image) button that never worked in a downstream project's admin UI; auditing further showed the same pattern hand-copied into 23 already-scaffolded `*UpdateRequestDTO`s across that project's four services (hub + three domain apps), so the fix belongs here, at the source, not in each generated file. `DtoGenerator::updateRequestDto()` now tracks field presence per field into a `$mappedFields` accumulator, keyed off `Field::$nullable` — the same flag that already controls the generated migration's `'null' => true/false`, so a field's clearability in the Update DTO now matches its actual DB constraint by construction:
  - **NOT NULL fields** are only recorded when `map()` resolved a real, non-null value — an explicit `null` is treated the same as omitting the field, same as before. Never writes `NULL` into a NOT NULL column.
  - **Nullable fields** are recorded whenever the key was present in the payload at all, even when the resolved value is `null` — this is what lets `{"field": null}` actually reach `toArray()` and clear the column.
  - Nullable `int`/`float`/`string` fields additionally treat an empty string (`''`) as "no value" — an HTML form's blank text/number input — so it clears the column the same as `null`, rather than silently coercing to `0`/`''`. NOT NULL fields never take this shortcut; an empty string there is a validation concern (`rules()`), not an implicit "leave unchanged".
  - `array`-typed fields now require `is_array($data[x])` in addition to presence, instead of blindly `(array)`-casting whatever was sent (a scalar would previously have been silently wrapped into a single-element array).
  - `toArray()` is now just `return $this->mappedFields;` — no more `array_filter`.
  New regression test `DtoGeneratorTest::testUpdateRequestDtoPreservesExplicitNullOnlyForNullableFields()` asserts the exact generated `map()`/`toArray()` shape for both a NOT NULL and a nullable field. The `testUpdateRequestDtoSnapshot` baseline was regenerated (`--update-snapshots`) — its 3-field canonical schema has no nullable field, so the snapshot alone would not have exercised the fix; the dedicated test above covers that case explicitly.
- **`CreateRequestDTO` and `ResponseDTO` generation are unaffected** — `map()`/`buildMapExpression()` (the Create-DTO code path) already had no such ambiguity: every Create field is expected to be sent, so there is no "omitted vs. explicitly null" distinction to make. Only `buildUpdateMapExpression()` (new, Update-DTO-only) needed the presence-tracking logic.

## [1.1.2] — 2026-07-24

### Security

- **`symfony/yaml`** bumped v7.4.11 → v7.4.14 (transitive via `zircote/swagger-php` / `spatie/phpunit-snapshot-assertions`, dev-only) — resolves CVE-2026-45304 (exponential memory allocation via recursive collection-alias expansion), CVE-2026-45305 (ReDoS via catastrophic backtracking in `Parser::cleanup()`), and CVE-2026-45133 (stack exhaustion via unbounded recursion). No code changes required; the existing constraint already permitted the patched version.

## [1.1.1] — 2026-07-23

### Fixed

- **`Commands\MakeCrudRemove`** — `run()` now detects a non-interactive stdin (`posix_isatty(STDIN)`) before calling `CLI::prompt()` for delete confirmation, and fails clean with "re-run with --force" instead of crashing with `TypeError: InputOutput::input(): Return value must be of type string, bool returned`. This is the same failure class documented for `make:crud` under audit finding C13 (`docs/audits/make-crud-audit.md`) — that command's `gatherFields()` already guarded against it, but the sibling `make:crud:remove` confirmation prompt was never patched. Verified the crash does not delete any files before throwing (the prompt runs before `ScaffoldRemover::remove()`), so this was a hard failure, not silent data loss — but it made the command unusable in CI/non-TTY contexts without `--force`.

## [1.1.0] — 2026-06-12

### Added

- **Granular permission codes** — scaffolding now generates distinct `create`, `update`, and `delete` permissions instead of a generic `write` permission. Routes are grouped by permission filter when `protectedRouteFilters` is configured, enabling fine-grained RBAC (e.g., a user can create products but not update them).
- **Domain permission code prefix** — `ScaffoldingConfig::$permissionCodePrefix` allows APIs to namespace permission codes by domain (e.g., `catalog.product.create` instead of `product.create`), improving clarity when multiple domains share resource names. Defaults to empty string (no prefix).
- **Auto-create `DomainPermissions.php`** — if the permissions config class does not exist, `ConfigWireman` now creates it with an empty `PERMISSIONS` array, eliminating a manual scaffolding step in domain-oriented APIs.

## [1.0.0] — 2026-06-10

### Added

- **Relation field contract** — `make:crud` now recognizes `relation` as an FK-like field type alongside `fk`. Relation fields use the same 4-segment syntax (`field:relation:target_table:modifiers`), validation contract (`is_natural_no_zero|is_not_unique[target.id]`), migration FK handling, interactive prompt support, and referential action modifiers as `fk` fields. This aligns the API scaffold with the admin-starter relation-aware module scaffold that renders FK fields as selectable related resources.

## [0.7.8] — 2026-06-06

### Fixed

- **`ScaffoldingPaths` and `TestGenerator`** — reverted test directory paths to PascalCase (`tests/Unit`, `tests/Integration`, `tests/Feature`). The previous lowercase format violated PSR-4 autoload conventions on case-insensitive filesystems (macOS HFS+, Windows NTFS), causing 30+ generated test classes to be silently skipped by PHPUnit despite being present in the filesystem.

## [0.7.7] — 2026-06-04

### Changed

- **`dcardenasl/ci4-api-core` constraint** — bumped from `^0.9.0` to `^1.0` following the stable v1.0.0 release of the core package.

## [0.7.6.2] — 2026-06-01

### Fixed

- **`ConfigWireman::registerPermissions()`** — fixed incorrect resource name and description pluralization. The `resource` field now correctly uses `$schema->route` (plural) instead of `$schema->getResourceLower()` (singular), and permission descriptions now properly pluralize the resource name (e.g., "Read Products" not "Read Product").

## [0.7.6.1] — 2026-06-01

### Fixed

- **`ConfigWireman::$astEditor` property visibility** — changed from `private` to `protected` to allow subclasses of `ConfigWireman` to call `registerPermissions()`. Completes the v0.7.6 visibility change for the method itself.

## [0.7.6] — 2026-06-01

### Fixed

- **`ConfigWireman::registerPermissions()`** — changed visibility from `private` to `protected` to allow test subclasses to call it during validation. Improves testability without affecting runtime behavior.

### Changed

- **`ModuleCheck`** — improved validation rules and checkpoint reporting for clearer troubleshooting output.

### Added

- **`LanguageGenerator`** — comprehensive test coverage with snapshot tests for both English and Spanish locale generation.

## [0.7.5] — 2026-05-31

### Fixed

- **`RouteGenerator`** — wildcard resource routes are now appended after all explicit route definitions within a group, preventing them from swallowing named routes that appear later in the file (SCAF-010).

## [0.7.4] — 2026-05-30

### Fixed

- **`ScaffoldingPaths`** — test directories now use lowercase (`tests/unit`, `tests/integration`, `tests/feature`) instead of mixed case for compatibility with case-sensitive CI runners on Linux/GitHub Actions.
- **`TestGenerator`** — now generates `tests/unit/Architecture/ArchitectureTest.php` placeholder to prevent CI failures when `phpunit --testsuite=Architecture` is run.

## [0.7.3] — 2026-05-29

### Fixed

- **`RouteGenerator`** — route matching now accepts filter-keyed arrays (`['filter' => [...]]`) as a valid existing-route form; previously, a route file whose group already carried a `filter` key would fail the normalisation check and silently skip injection.

## [0.7.2] — 2026-05-29

### Added

- **`ScaffoldingConfig::$apiVersion`** — configurable API version string (default `v1`) used when generating route paths and test URLs; enables multi-version scaffolding without manual edits.

### Changed

- **`RouteGenerator`** — passes `resourceLower` into the AST route-injection visitor; route statement construction refactored for correctness and readability.
- Updated `Controller.php.tpl` and `Endpoints.php.tpl` to reflect route generation improvements; snapshots updated.

## [0.7.1] — 2026-05-29

### Added

- **CI Pipeline:** Integrated automated scaffolding validation (CRUD generation → unit/feature tests → PHPStan L8 → CS-Fixer).

### Fixed

- **Route Injection:** Replaced string-based route injection in `RouteGenerator` with `nikic/php-parser` AST parsing to ensure robustness against PHP CS Fixer formatting (specifically trailing `: void` in route closures).

## [0.7.0] — 2026-05-28

### Changed

- **`dcardenasl/ci4-api-core` requirement bumped to `^0.9.0`** — aligns with the core 0.9.0 release (shared `HubClient`) and keeps consumers that adopt core 0.9 resolvable alongside this package.
- **Dev tooling aligned with the platform:** `phpunit/phpunit` `^10.5` → `^11.0` and `phpstan/phpstan` `^2.0` → `^2.1`; test config schema bumped to 11.5.
- **CodeIgniter 4 updated to v4.7.3** (`composer.lock`).

## [0.6.0] — 2026-05-27

### Added

- **Typed scaffold stubs** — generated controllers, DTOs, and services now include explicit PHP and PHPDoc types:
  - `Controller.php.tpl`: `resolveDefaultService()` returns the concrete `ServiceInterface`; `handleRequest` closures carry typed `DTO` and `SecurityContext` parameters.
  - `CreateRequestDTO.php.tpl`, `IndexRequestDTO.php.tpl`, `UpdateRequestDTO.php.tpl`, `ResponseDTO.php.tpl`: PHPDoc annotations for `rules(): array<string, string>`, `map(array<string, mixed>): void`, and `toArray(): array<string, mixed>`.
  - `Service.php.tpl`: `@extends BaseCrudService<TEntity>` and `@param Repository<TEntity>` PHPDoc on the constructor. `SecurityContext` import added.
- `TypeMapperCompatibilityTest` — unit test that locks the `bool` ↔ PHP-type compatibility contract to prevent silent regressions.

### Changed

- **`dcardenasl/ci4-api-core` requirement bumped to `^0.8.0`** — picks up `RateLimitResponseHelpers`, `app_id` awareness, and the generic repository/service typing that the new typed stubs reference.

## [0.5.2] — 2026-05-24

### Fixed

- `bin/e2e-smoke.sh`: seed project now requires `dcardenasl/ci4-api-core:^0.7.0` instead of `^0.6.0`, resolving a Composer conflict when installing the dev version of this package against a fresh CI4 project.
- `composer.lock`: updated `dcardenasl/ci4-api-core` from `v0.6.0` to `v0.7.2`.

## [0.5.1] — 2026-05-24

### Added

- `.github/workflows/release.yml` — tag-triggered GitHub Release workflow that extracts the matching CHANGELOG section and publishes it automatically.

## [0.5.0] - 2026-05-22

### Changed

- **`MakeCrud` throws `RuntimeException` when `App\Config\Scaffolding` is not found** instead of silently falling back to empty `protectedRouteFilters`. Previously a yellow warning was printed and scaffolding continued with no auth filters, producing fully open routes. Consumers must have `app/Config/Scaffolding.php` extending `BaseScaffoldingConfig` before running `make:crud`.
- **`dcardenasl/ci4-api-core` requirement bumped to `^0.7.0`** — picks up `findBy()` and `AbstractIntrospectionFilter`.

### Fixed

- `TestGenerator` now recognises `domainauth` as an auth filter (alongside `jwtauth` and `auth`). Domain apps using `domainauth` now get `assertStatus(401)` smoke tests instead of the open-route 200/404 variants.
- Feature test template now generates two smoke test methods: `testIndexSmoke()` (expects 200 for an open route, 401 when gated) and `testShowNotFound()` (expects 404 for an open route, 401 when gated). Previously a single test asserted 404 on the index endpoint, which is incorrect for an empty collection.

### Added

- `ConfigWireman` auto-registers three permissions (`{resource}.read`, `{resource}.write`, `{resource}.delete`) into `app/Config/DomainPermissions.php` when it exists. Idempotent — re-running scaffolding on the same resource is safe.
- `Service.php.tpl` and `ServiceInterface.php.tpl` include a comment guiding developers to throw `\BadMethodCallException` in custom method stubs until they are fully implemented.

## [0.4.0] - 2026-05-17

### Changed

- **`dcardenasl/ci4-api-core` requirement bumped to `^0.6.0`** — alignment with platform-wide CI4 ^4.7 requirements.

### Changed

- **CodeIgniter requirement widened to `^4.7`** — drops support for CI4 4.6.x; locked to 4.7.x (current stable, v4.7.2). README badge, requirements section, and compatibility matrix updated. Consumers still on 4.6 must upgrade before pulling this version.

## [0.3.1] - 2026-05-17

### Changed

- **`dcardenasl/ci4-api-core` requirement widened to `^0.5.0`** — picks up `AbstractServiceClient` and the outbound HTTP config knobs introduced in core v0.5.0 (purely additive, no breaking changes for scaffolding). Consumers on core v0.4.x must upgrade before pulling this version. `bin/e2e-smoke.sh` and the README requirements section updated accordingly.

## [0.3.0] - 2026-05-10

### Added

- **`RouteGenerator::patchMainRoutesLoader(string $apiVersion): bool`** — new public method called automatically by `make:crud` on every scaffold. Injects a versioned glob loader block (`$routes->group('api/{version}', ...)`) into `app/Config/Routes.php` so all domain route files under `app/Config/Routes/{version}/` are discovered by CI4 without manual wiring. Idempotent via `// ci4-api-scaffolding: loader start/end` markers; skips silently when an `api/{version}` group already exists in the file (e.g. projects using `ci4-api-starter`'s hand-crafted routes block). The pure transformation is exposed as `applyLoaderPatch(string $content, string $apiVersion): string` for unit testing without file I/O.

### Changed

- **`dcardenasl/ci4-api-core` requirement raised to `^0.4.1`** — aligns with the `core:install` health route injection shipped in that patch. `^0.4` consumers must upgrade core before pulling this version.
- **`ScaffoldingConfig::defaults()` now accepts `array $protectedRouteFilters = []`** (empty by default). Previously the method hardcoded `['jwtauth', 'permission:iam.superadmin-access', 'throttle']`, which are `ci4-api-starter`-specific filter aliases that don't exist in a generic CI4 project. Consumers running `ci4-api-starter` must now pass their filter list explicitly (e.g. via `App\Config\Scaffolding::build()` or `ScaffoldingConfig::defaults(['jwtauth', 'permission:iam.superadmin-access', 'throttle'])`). The method signature is backwards-compatible; the default behavior changes.

## [0.2.0] - 2026-05-09

### Added
- **`php spark scaffold:check`** — Read-only diagnostic that validates the consumer project's `app/Config/Scaffolding.php`: checks the file exists, extends `BaseScaffoldingConfig`, `build()` succeeds, and all declared FQCNs (14 base classes / interfaces / traits) resolve to loadable symbols. Useful after first install or after updating `dcardenasl/ci4-api-core` to confirm the config still points at real classes.
- **`php spark swagger:generate`** — Generates `public/swagger.json` from OpenAPI annotations across `app/Config/OpenApi.php`, `app/Controllers/`, `app/Documentation/`, `app/DTO/`, and `vendor/dcardenasl/ci4-api-core/src/Dto/`. Requires `zircote/swagger-php` (`^4.10 || ^5 || ^6`) in the consumer's `require-dev`. Subclass the command and override `scanPaths()` to add domain-specific directories.
- **`--version` option for `make:crud` / `make-crud.sh`** — accepts a version string (e.g. `v2`) that targets a different route directory (`app/Config/Routes/v2/`). Generated routes are placed in the versioned subdirectory; unversioned usage defaults to `v1` as before. Useful for projects migrating to a second API version while keeping v1 routes intact. Updated in `MakeCrud`, `RouteGenerator`, `ResourceSchema`, `ScaffoldingConfig`, and `bin/make-crud.sh`.
- **`fromArray(array $data): static` factory on generated `ResponseDTO`s** — `DtoGenerator` now emits a static named constructor on every response DTO. Lets consumers hydrate DTOs from raw associative arrays without calling the constructor directly; consistent with CI4 Entity `fill()` patterns. Snapshot baseline updated.
- **`bin/e2e-smoke.sh`** — End-to-end smoke test script. Creates a vanilla CI4 project via `composer create-project`, installs both `dcardenasl/ci4-api-core` and `dcardenasl/ci4-api-scaffolding` from path repositories, scaffolds a sample `Article` resource, and verifies output through four gates: (1) `php -l` syntax check on all generated files, (2) file count ≥ 13, (3) PHPStan level 5 on generated code, (4) `php spark routes:list` (best-effort). Configurable via `CI4_VERSION`, `CI4_CORE_PATH`, and `WORK_DIR` environment variables.
- **`e2e-integration` GitHub Actions job** — Matrix job (PHP 8.2/8.3 × CI4 4.5.*/4.6.*/4.7.*) that runs `bin/e2e-smoke.sh` on every push and PR. The job checks out both packages, resolving the `ci4-api-core` path dependency the same way the `ci4-compatibility` job does. `shellcheck` validation for `e2e-smoke.sh` added to the existing `test` job.

### Changed
- **Minimum CodeIgniter 4 version raised to `^4.6`** — CI4 4.5.x dropped from the test matrix following upstream security advisories. Consumers on 4.5.x must upgrade before pulling this version.
- **`dcardenasl/ci4-api-core` requirement raised to `^0.4`** — aligns with the integration test infrastructure and the new FQCN checks in `scaffold:check`.

## [0.1.0] - 2026-05-08

Initial release — extracted from `dcardenasl/ci4-api-core` v0.3.0.

### Added
- **`dcardenasl\Ci4ApiScaffolding\Commands\MakeCrud`** — `php spark make:crud` — generates DTOs, service, controller, migration, routes, language files, and tests for a new resource.
- **`dcardenasl\Ci4ApiScaffolding\Commands\MakeCrudRemove`** — `php spark make:crud:remove` — removes previously scaffolded artifacts with `--force` flag for CI use.
- **`dcardenasl\Ci4ApiScaffolding\Commands\ModuleCheck`** — `php spark module:check` — validates 14 post-scaffold wiring checkpoints.
- **8 modular generators** — `DtoGenerator`, `MigrationGenerator`, `ModelEntityGenerator`, `ServiceGenerator`, `ControllerGenerator`, `RouteGenerator`, `LanguageGenerator`, `TestGenerator` — all implementing `CrudGeneratorInterface`.
- **`CrudGeneratorInterface`** — plugin contract: `name(): string` + `generate(ResourceSchema): array<string,string>`. Custom generators can be injected into `ScaffoldingOrchestrator`.
- **`ScaffoldingOrchestrator`** — drives the 8-generator pipeline with `orchestrate(ResourceSchema): list<string>`. Accepts optional `?list<CrudGeneratorInterface>` for plugin architecture.
- **`ScaffoldRemover`** — removes generated artifacts, showing a preview and requesting confirmation unless `--force` is passed.
- **`TemplateRenderer`** + **19 `.php.tpl` template files** in `src/Generators/Templates/` — generator output is stored as plain-text templates; changing output produces an explicit diff in PRs.
- **`ConfigWireman`** + **`PhpAstEditor`** — AST-based injection of generated service/mapper into `app/Config/Services.php`; falls back to a recovery message instead of fragile string-search when the file doesn't match expected structure.
- **`ScaffoldingConfig`** + **`BaseScaffoldingConfig`** + **`ScaffoldingPaths`** — single value object capturing every consumer-app convention: base classes, paths, route filters, namespace prefix.
- **`Field`**, **`ResourceSchema`**, **`TypeMapper`**, **`Fqcn`**, **`StringHelper`** — core domain types used by generators to model the schema being scaffolded.
- **`bin/make-crud.sh`** + **`bin/validate-crud.sh`** — shell wrappers safe for non-TTY contexts (CI, Claude Code). Exposed as Composer `bin` entries so `vendor/bin/make-crud.sh` works in consumer projects.
- **105 tests** covering all 8 generators (including 17 snapshot tests), orchestration, validators, wiring, and commands.

[0.3.1]: https://github.com/dcardenasl/ci4-api-scaffolding/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/dcardenasl/ci4-api-scaffolding/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/dcardenasl/ci4-api-scaffolding/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/dcardenasl/ci4-api-scaffolding/releases/tag/v0.1.0
