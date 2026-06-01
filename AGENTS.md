# ci4-api-scaffolding

CRUD scaffolding engine Composer package (`dcardenasl/ci4-api-scaffolding`, `require-dev`).
Not a runnable CI4 app — consumed by API projects. Provides `make:crud`, `make:crud:remove`, `module:check` spark commands.

## Entry Points

- `src/Commands/` — Three spark commands (consumer entry points)
- `src/Generators/` — 8 generators + `Templates/*.php.tpl` files
- `src/Core/TypeMapper.php` — Field type → db/php/validation/openapi mapping
- `src/Wiring/ConfigWireman.php` — AST-based `Services.php` injection (`nikic/php-parser`)
- `bin/make-crud.sh` — Shell wrapper (exposed as `vendor/bin/` in consumers)

## Contracts & Invariants

- Generated code must satisfy `vendor/dcardenasl/ci4-api-core/docs/ARCHITECTURE_CONTRACT.md`.
- Template changes produce explicit diffs in snapshot tests (`tests/Unit/Generators/__snapshots__/`).
- PHPStan level 8. `composer quality` must pass before every merge.
- CI pipeline also runs `shellcheck` on the three `bin/` scripts.
- Do not add runtime base classes here — those belong in `ci4-api-core`.

## Commands

```bash
composer quality          # PHPStan + CS check + security + PHPUnit
composer test -- --testsuite Unit
composer test -- --testsuite E2E   # spawns a real CI4 project in /tmp
CI4_CORE_PATH=../ci4-api-core bash bin/e2e-smoke.sh
```

## Patterns

Adding a new field type:
1. Add entry to `TypeMapper::$map` with `db`, `php`, `val`, `oa` keys.
2. Update `FieldStringParser` if the syntax is non-standard.
3. Add to snapshot baseline in `tests/Unit/Generators/SnapshotTest.php`.
4. Update the Field Types table in `README.md`.

Modifying a generator:
1. Edit the `.php.tpl` template in `src/Generators/Templates/`.
2. Delete the relevant `__snapshots__/*.snap` files.
3. Run `composer test -- --testsuite Unit` — new snapshots are written on first run.
4. Review the diff before committing.

## Anti-patterns

- Don't run `php spark make:crud` from this repo — no CI4 app here.
- Don't add runtime base classes — those belong in `ci4-api-core`.
- Don't skip snapshot test review after template changes.

## Related Context

- Detailed reference: `CLAUDE.md` (this repo)
- Runtime base classes: `dcardenasl/ci4-api-core` package
- Consumer usage: `dcardenasl/ci4-api-starter`
