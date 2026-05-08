# ci4-api-scaffolding

[![CI](https://github.com/dcardenasl/ci4-api-scaffolding/actions/workflows/ci.yml/badge.svg)](https://github.com/dcardenasl/ci4-api-scaffolding/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![CI4](https://img.shields.io/badge/CodeIgniter-4.5%2B-orange)](https://codeigniter.com/)

CRUD scaffolding engine for CodeIgniter 4 APIs built on [`dcardenasl/ci4-api-core`](https://github.com/dcardenasl/ci4-api-core). One command generates DTOs, service, controller, migration, routes, language files, and tests — all wired to the `ci4-api-core` base classes.

> **Pre-1.0 policy:** MINOR bumps may contain breaking changes. Pin to `~0.x.0` or exact version until v1.0.0.

## Requirements

- PHP `^8.2`
- CodeIgniter 4 `^4.5`
- [`dcardenasl/ci4-api-core`](https://packagist.org/packages/dcardenasl/ci4-api-core) `^0.3` (installed automatically as a dependency)

## Installation

```bash
composer require --dev dcardenasl/ci4-api-scaffolding:^0.1
```

## Quick Start

```bash
bash vendor/bin/make-crud.sh Article Blog \
  'title:string:required|searchable,body:text:required,published:bool:nullable' yes

php spark module:check Article --domain Blog
php spark migrate
```

## Configuration

Create `app/Config/Scaffolding.php` extending `BaseScaffoldingConfig`. If your project follows `ci4-api-starter` conventions, the bundled defaults are enough:

```php
<?php

declare(strict_types=1);

namespace Config;

use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;

class Scaffolding extends BaseScaffoldingConfig {}
```

To customize base classes, paths, or route filters, override `build()`:

```php
use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;

class Scaffolding extends BaseScaffoldingConfig
{
    public function build(): ScaffoldingConfig
    {
        $d = ScaffoldingConfig::defaults();

        return new ScaffoldingConfig(
            ...$d,
            protectedRouteFilters: ['jwtauth', 'permission:my.permission', 'throttle'],
        );
    }
}
```

## Available Commands

| Command | Shell wrapper | Description |
|---|---|---|
| `php spark make:crud` | `bash vendor/bin/make-crud.sh` | Generate a full CRUD module |
| `php spark make:crud:remove` | — | Remove a previously scaffolded module |
| `php spark module:check` | `bash vendor/bin/validate-crud.sh` | Validate 14 post-scaffold wiring checkpoints |

> Always use `vendor/bin/make-crud.sh` in non-TTY environments (CI, scripts). `php spark make:crud` may enter interactive mode when `--fields` is empty.

### `make:crud` signature

```bash
bash vendor/bin/make-crud.sh <Resource> <Domain> '<fields>' [softDelete=yes] [route]
```

Options: `--dry-run` (preview without writing), `--no-wire` (skip `Services.php` injection).

## Field Types

| Type | PHP type | Migration | Notes |
|---|---|---|---|
| `string` | `string` | `VARCHAR(255)` | |
| `text` | `string` | `TEXT` | |
| `int` | `int` | `INT` | |
| `decimal` | `string` | `DECIMAL(10,2)` | String-backed to preserve precision |
| `bool` | `bool` | `TINYINT(1)` | |
| `email` | `string` | `VARCHAR(255)` | Adds `valid_email` validation rule |
| `date` | `string` | `DATE` | |
| `datetime` | `string` | `DATETIME` | |
| `json` | `array` | `JSON` | |
| `fk:table` | `int` | `INT` + FK constraint | References `table.id` |

## Field Modifiers

| Modifier | Effect |
|---|---|
| `required` | Adds `required` validation rule; non-nullable column |
| `nullable` | Nullable column; field omitted from `required` rules |
| `searchable` | Adds `FULLTEXT` index; controller gets `HasSearchableIndex` trait |
| `filterable` | Adds to the model's `$filterableFields` whitelist |
| `unique` | Adds `UNIQUE` index + `is_unique[table.column]` validation |
| `index` | Adds a plain index |

Example: `'name:string:required|searchable,price:decimal:required|filterable'`

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

## Compatibility Matrix

| | PHP 8.2 | PHP 8.3 | PHP 8.4 |
|---|---|---|---|
| CI4 4.5.* | ✅ | ✅ | ✅ |
| CI4 4.6.* | ✅ | ✅ | ✅ |
| CI4 4.7.* | ✅ | ✅ | ✅ |

Tested in CI on every push. PHP 8.4 runs the full suite; PHP 8.2 additionally collects coverage.

## Development

```bash
# Run all quality checks
composer quality   # PHPStan level 8 + PHPUnit + CS-Fixer

# Run tests only
composer test -- --testsuite Unit
composer test -- --testsuite E2E

# Run E2E smoke test against a real vanilla CI4 project
CI4_CORE_PATH=../ci4-api-core bash bin/e2e-smoke.sh
```

## License

MIT — see [LICENSE](LICENSE).
