# Changelog

All notable changes to `dcardenasl/ci4-api-scaffolding` will be documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/spec/v2.0.0.html) with the caveat that pre-1.0 releases may break.

> **Pre-1.0 policy:** MINOR bumps may contain breaking changes. Pin to `~0.x.0` or exact version until v1.0.0 is tagged.

## [Unreleased]

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
