# ci4-api-scaffolding

[![CI](https://github.com/dcardenasl/ci4-api-scaffolding/actions/workflows/ci.yml/badge.svg)](https://github.com/dcardenasl/ci4-api-scaffolding/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![CI4](https://img.shields.io/badge/CodeIgniter-4.7%2B-orange)](https://codeigniter.com/)
[![Latest Stable Version](https://poser.pugx.org/dcardenasl/ci4-api-scaffolding/v)](https://packagist.org/packages/dcardenasl/ci4-api-scaffolding)
[![Total Downloads](https://poser.pugx.org/dcardenasl/ci4-api-scaffolding/downloads)](https://packagist.org/packages/dcardenasl/ci4-api-scaffolding)

CRUD scaffolding engine for CodeIgniter 4 APIs built on [`dcardenasl/ci4-api-core`](https://github.com/dcardenasl/ci4-api-core). One command generates DTOs, service, controller, migration, routes, language files, and tests — all wired to the `ci4-api-core` base classes.

> **Pre-1.0 policy:** MINOR bumps may contain breaking changes. Pin to `~0.x.0` or exact version until v1.0.0.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Available Commands](#available-commands)
- [Field Types](#field-types)
- [Field Modifiers](#field-modifiers)
- [Generated Artifacts](#generated-artifacts)
- [Compatibility Matrix](#compatibility-matrix)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [Example Project](#example-project)

## Requirements

- PHP `^8.2`
- CodeIgniter 4 `^4.7`
- [`dcardenasl/ci4-api-core`](https://packagist.org/packages/dcardenasl/ci4-api-core) `^0.6` (installed automatically as a dependency)

## Installation

```bash
composer require --dev dcardenasl/ci4-api-scaffolding:^0.4
```

## Quick Start

```bash
bash vendor/bin/make-crud.sh Article Blog \
  'title:string:required|searchable,body:text:required,published:bool:nullable' yes

php spark module:check Article --domain Blog
php spark migrate
```

## Configuration

Create `app/Config/Scaffolding.php` extending `BaseScaffoldingConfig`. If your project follows `ci4-api-starter` conventions, the bundled defaults work without any overrides:

```php
<?php

declare(strict_types=1);

namespace Config;

use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;

class Scaffolding extends BaseScaffoldingConfig
{
    public function build(): ScaffoldingConfig
    {
        return ScaffoldingConfig::defaults();
    }
}
```

To customize base classes, paths, or route filters, pass named arguments to `ScaffoldingConfig`:

```php
use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingPaths;

class Scaffolding extends BaseScaffoldingConfig
{
    public function build(): ScaffoldingConfig
    {
        $defaults = ScaffoldingConfig::defaults();

        return new ScaffoldingConfig(
            ...(array) $defaults,
            // Lock new routes behind a custom permission instead of the default
            // superadmin-only gate:
            protectedRouteFilters: ['jwtauth', 'permission:catalog.admin', 'throttle'],
            // Override where generated controllers are written:
            paths: new ScaffoldingPaths(controllers: 'Controllers/Api/V2'),
        );
    }
}
```

**Default route filters** (when no `Scaffolding` config is found): `['jwtauth', 'permission:iam.superadmin-access', 'throttle']`. New resources are unreachable by non-superadmins until you intentionally loosen this filter.

**All configurable options** (`ScaffoldingConfig` constructor parameters):

| Option | Default | Purpose |
|---|---|---|
| `controllerBaseClass` | `dcardenasl\Ci4ApiCore\Http\ApiController` | Base class generated controllers extend |
| `serviceBaseClass` | `dcardenasl\Ci4ApiCore\Services\BaseCrudService` | Base class generated services extend |
| `requestDtoBaseClass` | `dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO` | Base class generated request DTOs extend |
| `responseDtoInterface` | `dcardenasl\Ci4ApiCore\Dto\DataTransferObjectInterface` | Interface response DTOs implement |
| `modelBaseClass` | `dcardenasl\Ci4ApiCore\Models\BaseAuditableModel` | Base class generated models extend |
| `protectedRouteFilters` | `['jwtauth', 'permission:iam.superadmin-access', 'throttle']` | Filters on the protected route group |
| `appNamespace` | `App` | Top-level namespace of the consumer app |
| `paths` | `ScaffoldingPaths::defaults()` | All output directories (see `ScaffoldingPaths`) |
| `openApiTagPrefix` | `null` (uses domain name) | Custom OpenAPI tag prefix |
| `conditionalControllerTraits` | `[]` | `fieldName => TraitFQCN` map for auto-injected controller traits |

**Path overrides** (`ScaffoldingPaths` constructor parameters, all relative to `APPPATH`):

| Option | Default |
|---|---|
| `controllers` | `Controllers/Api/V1` |
| `services` | `Services` |
| `interfaces` | `Interfaces` |
| `requestDtos` | `DTO/Request` |
| `responseDtos` | `DTO/Response` |
| `models` | `Models` |
| `entities` | `Entities` |
| `migrations` | `Database/Migrations` |
| `routes` | `Config/Routes/v1` |
| `documentation` | `Documentation` |
| `languageEn` | `Language/en` |
| `languageEs` | `Language/es` |
| `unitTests` | `tests/Unit/Services` (relative to `ROOTPATH`) |
| `integrationTests` | `tests/Integration/Models` (relative to `ROOTPATH`) |
| `featureTests` | `tests/Feature/Controllers` (relative to `ROOTPATH`) |

## Available Commands

| Command | Shell wrapper | Description |
|---|---|---|
| `php spark make:crud` | `bash vendor/bin/make-crud.sh` | Generate a full CRUD module |
| `php spark make:crud:remove` | — | Remove a previously scaffolded module |
| `php spark module:check` | `bash vendor/bin/validate-crud.sh` | Validate 14 post-scaffold wiring checkpoints |
| `php spark scaffold:check` | — | Verify `Config\Scaffolding` exists and all FQCNs resolve |
| `php spark swagger:generate` | — | Generate `public/swagger.json` from OpenAPI annotations |

> Always use `vendor/bin/make-crud.sh` in non-TTY environments (CI, Claude Code, scripts). `php spark make:crud` falls back to interactive mode when `--fields` is not provided, which hangs in non-TTY contexts.

### `make:crud` — full options

```bash
# Shell wrapper (recommended for scripts and CI)
bash vendor/bin/make-crud.sh <Resource> <Domain> '<fields>' [softDelete=yes] [route]
    [--dry-run] [--no-wire] [--migrate]

# Direct spark command (TTY only)
php spark make:crud <Resource> \
    [--domain <Domain>] \
    [--fields '<fields>'] \
    [--route <route-slug>] \
    [--soft-delete yes|no] \
    [--dry-run] \
    [--no-wire] \
    [--skip-fk-validation]
```

| Option | Default | Purpose |
|---|---|---|
| `--domain` / arg 2 | `Catalog` | Domain folder (groups related resources) |
| `--fields` / arg 3 | interactive | Field definition string (see [Field Types](#field-types)) |
| `--route` / arg 5 | kebab-case plural of resource | Route slug used in the URL |
| `--soft-delete` / arg 4 | `yes` | Emit `deleted_at` column and soft-delete logic |
| `--version` | `v1` | Target a versioned route directory (e.g. `--version v2` writes routes to `Config/Routes/v2/`) |
| `--dry-run` | off | Preview planned files and wiring without writing anything |
| `--no-wire` | off | Generate files but skip `Services.php` injection; prints snippets to paste manually |
| `--skip-fk-validation` | off | Skip the FK target existence check when the database is unreachable |
| `--migrate` (wrapper only) | off | Auto-run `php spark migrate` after scaffolding |

### `make:crud:remove` — full options

```bash
php spark make:crud:remove <Resource> [--domain <Domain>] [--force]
```

`--force` skips the confirmation prompt (useful in CI). Without `--force`, the command lists the files it would delete and asks for confirmation.

### `module:check` / `validate-crud.sh`

```bash
# Via spark (inside consumer project)
php spark module:check <Resource> --domain <Domain>

# Via shell wrapper
bash vendor/bin/validate-crud.sh <Resource> <Domain>
```

Validates 14 post-scaffold wiring checkpoints: migration exists, table naming, soft-delete consistency, controller/model/service/route presence, `Services.php` wiring, language files, test files. Exits non-zero if any checkpoint fails.

### `scaffold:check`

```bash
php spark scaffold:check
```

Read-only diagnostic — never writes files. Verifies that `app/Config/Scaffolding.php` exists, extends `BaseScaffoldingConfig`, and that all 14 FQCNs it declares (base classes, interfaces, traits) are loadable. Run after first install or after bumping `dcardenasl/ci4-api-core` to confirm the config still points at real classes.

If the file is missing, the command prints the `cp` command to bootstrap a default config from the bundled example.

### `swagger:generate`

```bash
php spark swagger:generate
```

Generates `public/swagger.json` from OpenAPI annotations. Scans `app/Config/OpenApi.php`, `app/Controllers/`, `app/Documentation/`, `app/DTO/`, and `vendor/dcardenasl/ci4-api-core/src/Dto/` by default. Requires `zircote/swagger-php` in the consumer's `require-dev`:

```bash
composer require --dev zircote/swagger-php
```

To scan additional directories, subclass the command and override `scanPaths()`:

```php
class MySwaggerGenerate extends \dcardenasl\Ci4ApiScaffolding\Commands\SwaggerGenerate
{
    protected function scanPaths(): array
    {
        return [...parent::scanPaths(), APPPATH . 'Modules/'];
    }
}
```

## Field Types

Field type codes used in the `--fields` string. All types are recognized case-sensitively.

| Type | Alias | PHP type | DB column | OpenAPI | Validation (auto-added) |
|---|---|---|---|---|---|
| `string` | — | `string` | `VARCHAR(255)` | `string` | `string\|max_length[255]` |
| `text` | — | `string` | `TEXT` | `string` | `string` |
| `int` | `integer` | `int` | `INT` | `integer` | `integer` |
| `decimal` | — | `float` | `DECIMAL(10,2)` | `number` (float) | `decimal` |
| `bool` | — | `bool` | `TINYINT(1)` | `boolean` | `boolean_like` |
| `email` | — | `string` | `VARCHAR(255)` | `string` (email) | `string\|valid_email\|max_length[255]` |
| `date` | — | `string` | `DATE` | `string` (date) | `valid_date[Y-m-d]` |
| `datetime` | — | `string` | `DATETIME` | `string` (date-time) | `valid_date` |
| `json` | — | `array` | `JSON` | `object` | `permit_empty` |
| `fk` | — | `int` | `INT` + FK constraint | `integer` | `is_natural_no_zero\|is_not_unique[table.id]` |

**FK field syntax** — uses a 4-segment form because the target table name is a required third segment:

```
author_id:fk:users:required
category_id:fk:categories:required|filterable
```

## Field Modifiers

Modifiers follow the type (or the FK table) and are separated by `|`:

```
name:type:modifier1|modifier2
name:fk:target_table:modifier1|modifier2
```

| Modifier | Effect |
|---|---|
| `required` | `required` validation rule; non-nullable column |
| `nullable` | Nullable column; `permit_empty` validation rule |
| `searchable` | Adds `FULLTEXT` index; controller gets `HasSearchableIndex` trait |
| `filterable` | Adds field to the model's `$filterableFields` whitelist |
| `unique` | Adds `UNIQUE` index + `is_unique[table.column]` validation |
| `index` | Adds a plain (non-unique) index |
| `cascade` | FK only — `ON DELETE CASCADE` (default for `fk` fields) |
| `restrict` | FK only — `ON DELETE RESTRICT` |
| `setnull` | FK only — `ON DELETE SET NULL` |

Full example:

```
'title:string:required|searchable,price:decimal:required|filterable,author_id:fk:users:required|restrict'
```

## Generated Artifacts

`make:crud Article Blog 'title:string:required' yes` creates 17 files:

```
app/DTO/Request/Blog/ArticleIndexRequestDTO.php
app/DTO/Request/Blog/ArticleCreateRequestDTO.php
app/DTO/Request/Blog/ArticleUpdateRequestDTO.php
app/DTO/Response/Blog/ArticleResponseDTO.php
app/Database/Migrations/<timestamp>_CreateArticlesTable.php
app/Entities/ArticleEntity.php
app/Models/ArticleModel.php
app/Interfaces/Blog/ArticleServiceInterface.php
app/Services/Blog/ArticleService.php
app/Controllers/Api/V1/Blog/ArticleController.php
app/Documentation/Blog/ArticleEndpoints.php
app/Config/Routes/v1/blog.php
app/Language/en/Articles.php
app/Language/es/Articles.php
tests/Unit/Services/Blog/ArticleServiceTest.php
tests/Integration/Models/ArticleModelTest.php
tests/Feature/Controllers/Blog/ArticleControllerTest.php
```

`Services.php` is also updated (or a snippet is printed with `--no-wire`) to register the new service and response mapper.

## Compatibility Matrix

| | PHP 8.2 | PHP 8.3 | PHP 8.4 |
|---|---|---|---|
| CI4 4.7.* | ✅ | ✅ | ✅* |

\* PHP 8.4 is tested against the locked CI4 version in the `test` job. The explicit CI4-compatibility matrix covers PHP 8.2 and 8.3. CI4 4.5.x and 4.6.x were dropped: 4.5.x for security advisories, 4.6.x because v0.3.2 widened the floor to `^4.7`.

CI runs on every push: PHPStan level 8, PHP CS Fixer, full unit suite, E2E smoke test (creates a real CI4 project and scaffolds into it). PHP 8.2 additionally collects coverage.

## Development

```bash
# Run all quality checks
composer quality   # PHPStan level 8 + PHPUnit + CS-Fixer + security audit

# Run tests only
composer test -- --testsuite Unit
composer test -- --testsuite E2E

# Run E2E smoke test against a real vanilla CI4 project
CI4_CORE_PATH=../ci4-api-core bash bin/e2e-smoke.sh

# Auto-fix code style
composer cs-fix
```

For architecture constraints that generated code must satisfy, see [docs/ARCHITECTURE_CONTRACT.md](docs/ARCHITECTURE_CONTRACT.md).

## Troubleshooting

**`--fields` is empty / scaffold produces a partial module**
Always single-quote the fields string. Unquoted pipes (`|`) are consumed by the shell before the command sees them:
```bash
# Wrong — shell eats the pipe
bash vendor/bin/make-crud.sh Article Blog title:string:required|searchable yes

# Correct
bash vendor/bin/make-crud.sh Article Blog 'title:string:required|searchable' yes
```

**`php spark make:crud` hangs in a script / CI**
It entered interactive mode because `--fields` was empty and stdin is not a TTY. Use `vendor/bin/make-crud.sh` instead, which guards against this and requires `--fields` in non-TTY contexts.

**Wiring failed / `Services.php` was not modified**
The `ConfigWireman` uses AST-based injection and expects `Services.php` to follow the CI4 convention (a class with static factory methods in a trait). Run with `--no-wire` to get the snippet to paste manually:
```bash
php spark make:crud Article --domain Blog --fields '...' --no-wire
```

**FK validation aborts because the DB is unreachable**
Pass `--skip-fk-validation` when you know the target tables exist but the DB isn't available (e.g. in a fresh setup before `migrate`):
```bash
php spark make:crud Article --domain Blog --fields 'author_id:fk:users:required' --skip-fk-validation
```

**Scaffolded routes don't appear in `php spark routes:list`**
New route files are not hot-reloaded. Restart the server after scaffolding:
```bash
pkill -f 'spark serve'; php spark serve --port 8080 &
```

**`module:check` fails on a valid module**
Run `php spark module:check <Resource> --domain <Domain>` to see which of the 14 checkpoints failed and why.

## Example Project

[**ci4-api-core-example**](https://github.com/dcardenasl/ci4-api-core-example) is a complete, runnable Catalog API (Categories + Products) built entirely with this scaffolding engine — minimal hand-written code. Each step is a separate git commit so you can trace exactly what `make-crud.sh` generates, from a blank CI4 project to a production-ready API with filtering, searching, pagination, and OpenAPI docs.

## License

MIT — see [LICENSE](LICENSE).
