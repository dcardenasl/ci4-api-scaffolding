# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this project is

`dcardenasl/ci4-api-scaffolding` is a **Composer library** — a CRUD scaffolding engine for CodeIgniter 4 APIs. It is **not** a runnable CI4 application; it is consumed by other CI4 projects as a `require-dev` dependency.

The entry points are three `php spark` commands registered via CI4's package discovery:
- `make:crud` — generate a full CRUD module (DTOs, service, controller, migration, routes, i18n, tests)
- `make:crud:remove` — remove a previously generated module
- `module:check` — validate 14 post-scaffold wiring checkpoints

Tests run against the library itself, not against a live CI4 app. The E2E test (`tests/E2E/`) spawns a real vanilla CI4 project in a temp directory and runs the full scaffolding pipeline there.

## Key directories

```
src/
├── Commands/          # Three spark commands (entry points)
├── Config/            # ScaffoldingConfig, BaseScaffoldingConfig, ScaffoldingPaths
├── Core/              # Field, ResourceSchema, TypeMapper, StringHelper, Fqcn
├── Generators/        # 8 generators + Templates/ (.php.tpl files)
├── Orchestration/     # ScaffoldingOrchestrator, ScaffoldRemover
├── Validators/        # FieldStringParser, FieldNameValidator, ForeignKeyValidator
└── Wiring/            # ConfigWireman (AST-based Services.php injection), PhpAstEditor

bin/
├── make-crud.sh       # Shell wrapper — safe for non-TTY, exposed as vendor/bin/
├── validate-crud.sh   # Post-scaffold validation wrapper
└── e2e-smoke.sh       # E2E integration test (creates a real CI4 project)

tests/
├── Unit/              # Tests for each generator, orchestrator, validators, wiring
└── E2E/               # EndToEndScaffoldTest — full pipeline test
```

## Essential commands

```bash
# Quality gate (run before every commit)
composer quality          # PHPStan level 8 + PHP CS Fixer dry-run + security audit + PHPUnit

# Individual checks
composer analyse          # PHPStan level 8
composer cs-check         # PHP CS Fixer dry-run
composer cs-fix           # PHP CS Fixer auto-fix
composer test             # PHPUnit (all suites)

# Test suites
composer test -- --testsuite Unit
composer test -- --testsuite E2E

# E2E smoke against real CI4 project (requires Composer and a writable /tmp)
CI4_CORE_PATH=../ci4-api-core bash bin/e2e-smoke.sh
```

`composer quality` must pass before every merge. The CI pipeline also runs shellcheck on the three bin/ scripts.

## How to add a new field type

1. Add an entry to `TypeMapper::$map` in `src/Core/TypeMapper.php` with `db`, `php`, `val`, `oa` keys.
2. Update `FieldStringParser` if the new type requires a non-standard segment syntax (like `fk` uses a 4-segment form).
3. Add the type to the snapshot test baseline in `tests/Unit/Generators/SnapshotTest.php`.
4. Update the Field Types table in `README.md`.
5. Run `composer test` to verify nothing regresses.

## How to modify a generator

All generators live in `src/Generators/`. Each implements `CrudGeneratorInterface`:
- `name(): string` — identifier used in log output and conflict detection
- `generate(ResourceSchema $schema): array<string, string>` — returns a map of `relativePath => fileContents`

Templates live in `src/Generators/Templates/` as `.php.tpl` files. Changing a template produces an explicit diff in snapshot tests — run `composer test -- --testsuite Unit` and if snapshots need updating, delete the `__snapshots__/` directory and re-run.

The orchestrator (`ScaffoldingOrchestrator`) drives all 8 generators in sequence. It supports rollback via `rollbackLastRun()` if wiring fails after files were written.

## How to modify the wiring (Services.php injection)

`ConfigWireman` uses `PhpAstEditor` (backed by `nikic/php-parser`) for AST-based injection. It targets the domain trait that `Config\Services` uses and injects two factory methods: `{resource}Service()` and `{resource}ResponseMapper()`. If the AST structure doesn't match what it expects, it throws `WiringFailedException` and `MakeCrud` rolls back the generated files.

To test wiring changes: `composer test -- --filter ConfigWiremanTest`.

## Snapshot tests

`tests/Unit/Generators/SnapshotTest.php` runs every generator against a known `ResourceSchema` and asserts the output matches stored snapshots in `tests/Unit/Generators/__snapshots__/`. When you intentionally change generator output:

1. Delete the relevant `__snapshots__/*.snap` files.
2. Run `composer test -- --testsuite Unit` — new snapshots are written on first run.
3. Review the generated snapshots in the diff before committing.

## Pre-commit hook

If a pre-commit CS-Fixer hook fails:
```bash
composer cs-fix
git add -u
git commit   # re-commit (new commit, never --amend after hook failure)
```

## Architecture rules

Generated code must satisfy the constraints in the authoritative contract document, which lives
in `ci4-api-core`:

```
vendor/dcardenasl/ci4-api-core/docs/ARCHITECTURE_CONTRACT.md
```

The file at `docs/ARCHITECTURE_CONTRACT.md` in this repo is only a pointer to that location.
The scaffolding engine encodes these constraints in its templates and base-class references in
`src/Config/ScaffoldingConfig.php` — don't change templates in a way that violates the contract
without updating the authoritative document in `ci4-api-core` first.

## What not to do

- Don't run `php spark make:crud` directly from this repo — there is no CI4 app here. The command only works inside a consumer project that has `dcardenasl/ci4-api-scaffolding` installed.
- Don't manually edit files in `src/Generators/Templates/` without checking that snapshot tests still pass.
- Don't skip `composer quality` — PHPStan level 8 catches real bugs.
