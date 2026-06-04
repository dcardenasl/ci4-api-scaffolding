#!/usr/bin/env bash
# E2E smoke test for ci4-api-scaffolding.
#
# Creates a vanilla CI4 project, installs dcardenasl/ci4-api-core +
# dcardenasl/ci4-api-scaffolding from the local filesystem, scaffolds a
# sample resource, and verifies the generated output passes several gates:
#
#   1. All generated .php files compile (php -l)
#   2. Expected file count (>= 13)
#   3. PHPStan level 5 on generated code
#   4. spark routes:list shows the generated route (best-effort)
#
# Usage (from project root):
#   bin/e2e-smoke.sh
#
# Environment variables:
#   CI4_VERSION    Composer constraint for CI4 (default: 4.7.*)
#   WORK_DIR       Reuse an existing temp dir (default: create + auto-clean)

set -euo pipefail
IFS=$'\n\t'

# ── Resolve paths ────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCAFFOLDING_DIR="$(realpath "$SCRIPT_DIR/..")"
CI4_VERSION="${CI4_VERSION:-4.7.*}"

CLEANUP=false
if [[ -z "${WORK_DIR:-}" ]]; then
    WORK_DIR="$(mktemp -d)"
    CLEANUP=true
fi
[[ "$CLEANUP" == true ]] && trap 'rm -rf "$WORK_DIR"' EXIT

# ── Header ───────────────────────────────────────────────────────────────────

echo "════════════════════════════════════════════════════════"
echo " E2E Smoke Test — ci4-api-scaffolding"
echo "════════════════════════════════════════════════════════"
printf "  PHP:          %s\\n" "$(php -r 'echo PHP_VERSION;')"
printf "  CI4 version:  %s\\n" "$CI4_VERSION"
printf "  scaffolding:  %s\\n" "$SCAFFOLDING_DIR"
printf "  work dir:     %s\\n" "$WORK_DIR"
echo ""

PASS=0
FAIL=0
pass() { echo "  ✓ $*"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ $*"; FAIL=$((FAIL + 1)); }

# ── Step 1: Vanilla CI4 project ──────────────────────────────────────────────

echo "Step 1 — Create vanilla CI4 project (codeigniter4/appstarter:${CI4_VERSION})"
composer create-project "codeigniter4/appstarter:${CI4_VERSION}" "$WORK_DIR" \
    --no-interaction --prefer-dist --quiet
pass "CI4 project created"

cd "$WORK_DIR"

# ── Step 2: Install packages via path repositories ───────────────────────────

echo "Step 2 — Install ci4-api-core + ci4-api-scaffolding"
# Install stable ci4-api-core before allowing dev stability,
# to avoid Composer evaluating the dev alias and its constraints.
composer require "dcardenasl/ci4-api-core:^1.0" \
    --no-interaction --no-progress

# Now allow dev packages for the local path dependency.
composer config minimum-stability dev   --no-interaction --quiet
composer config prefer-stable    true   --no-interaction --quiet
composer config repositories.ci4-api-scaffolding path "$SCAFFOLDING_DIR" \
    --json --no-interaction --quiet

composer require --dev "dcardenasl/ci4-api-scaffolding:*@dev" \
    --no-interaction --no-progress
pass "Both packages installed"

# ── Step 3: Consumer configuration ───────────────────────────────────────────

echo "Step 3 — Consumer configuration"

# Scaffolding.php — no auth filters so vanilla CI4 doesn't complain about
# unregistered filter aliases when spark boots.
cat > app/Config/Scaffolding.php << 'PHP'
<?php

declare(strict_types=1);

namespace Config;

use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingPaths;

/**
 * E2E smoke test consumer config.
 * Strips auth filters so that `php spark routes:list` works in a vanilla
 * CI4 project without a filter registry.
 */
class Scaffolding extends BaseScaffoldingConfig
{
    public function build(): ScaffoldingConfig
    {
        $d = ScaffoldingConfig::defaults();

        return new ScaffoldingConfig(
            controllerBaseClass:         $d->controllerBaseClass,
            serviceBaseClass:            $d->serviceBaseClass,
            serviceContractInterface:    $d->serviceContractInterface,
            modelBaseClass:              $d->modelBaseClass,
            entityBaseClass:             $d->entityBaseClass,
            migrationBaseClass:          $d->migrationBaseClass,
            requestDtoBaseClass:         $d->requestDtoBaseClass,
            responseDtoInterface:        $d->responseDtoInterface,
            repositoryInterface:         $d->repositoryInterface,
            responseMapperInterface:     $d->responseMapperInterface,
            repositoryImplementation:    $d->repositoryImplementation,
            responseMapperImplementation: $d->responseMapperImplementation,
            servicesFactoryClass:        $d->servicesFactoryClass,
            paths:                       $d->paths,
            protectedRouteFilters:       [],
            appNamespace:                $d->appNamespace,
            conditionalControllerTraits: $d->conditionalControllerTraits,
            filterableTraitFqcn:         $d->filterableTraitFqcn,
            searchableTraitFqcn:         $d->searchableTraitFqcn,
        );
    }
}
PHP
pass "Scaffolding.php created (no auth filters)"

# Minimal .env so CI4 can boot
cp env .env
# CI_ENVIRONMENT must be set to development or testing for spark to run
if grep -q 'CI_ENVIRONMENT' .env; then
    sed -i.bak 's/^# CI_ENVIRONMENT.*/CI_ENVIRONMENT = development/' .env
else
    echo 'CI_ENVIRONMENT = development' >> .env
fi
pass ".env configured"

# Patch app/Config/Routes.php to auto-include generated v1 route files
# (CI4 does not glob from sub-dirs by default)
cat >> app/Config/Routes.php << 'PHP'

// Auto-include generated domain route files (ci4-api-scaffolding E2E).
foreach (glob(APPPATH . 'Config/Routes/v1/*.php') as $_routeFile) {
    require_once $_routeFile;
}
PHP
pass "Routes.php patched for v1 auto-discovery"

# ── Step 4: Run make:crud ─────────────────────────────────────────────────────

echo "Step 4 — Run make:crud (Article / Blog)"
bash vendor/bin/make-crud.sh Article Blog \
    'title:string:required|searchable,body:text:required,published:bool:nullable' \
    yes --no-wire 2>&1 | grep -E "CREATED|WIRING|✅|✓|Step|✗" | head -30 || true
pass "make:crud completed"

# ── Step 5: php -l on all generated PHP files ────────────────────────────────

echo "Step 5 — Syntax check: php -l"
SYNTAX_ERRORS=0
SYNTAX_OK=0

# Locate generated files: anything under app/ or tests/ that belongs to
# the Blog/Article scaffold (avoiding vendor, Config/Routes.php which we patched).
# Use while-read instead of mapfile for bash 3.2 compatibility (macOS).
GENERATED_FILES=()
while IFS= read -r _f; do
    GENERATED_FILES+=("$_f")
done < <(
    find app tests \
        -name "*.php" \
        -not -path "*/vendor/*" \
        -not -path "*/Config/Routes.php" \
        \( \
            -path "*/Blog/*" \
            -o -name "Article*.php" \
        \)
)

for f in "${GENERATED_FILES[@]+"${GENERATED_FILES[@]}"}"; do
    if ! php -l "$f" > /dev/null 2>&1; then
        fail "Syntax error: $f"
        php -l "$f" >&2 || true
        SYNTAX_ERRORS=$((SYNTAX_ERRORS + 1))
    else
        SYNTAX_OK=$((SYNTAX_OK + 1))
    fi
done

if [[ $SYNTAX_ERRORS -gt 0 ]]; then
    echo "  ✗ $SYNTAX_ERRORS file(s) failed php -l"
else
    pass "$SYNTAX_OK file(s) passed php -l"
fi

# ── Step 6: File count ────────────────────────────────────────────────────────

echo "Step 6 — File count (expect >= 13)"
FILE_COUNT="${#GENERATED_FILES[@]}"
if [[ $FILE_COUNT -lt 13 ]]; then
    fail "Only $FILE_COUNT generated files found (expected >= 13)"
    printf "  Files found:\n"
    printf "    %s\n" "${GENERATED_FILES[@]+"${GENERATED_FILES[@]}"}"
else
    pass "$FILE_COUNT generated files"
fi

# ── Step 7: PHPStan level 5 on generated code ────────────────────────────────

echo "Step 7 — PHPStan level 5 on generated files"

# Install phpstan into the temp project (not in ci4-api-scaffolding itself)
composer require --dev "phpstan/phpstan:^2.0" \
    --no-interaction --no-progress --quiet

# Minimal phpstan config that suppresses CI4-global false-positives
cat > phpstan-e2e.neon << 'NEON'
parameters:
    level: 5
    paths:
        - app/Controllers/Api/V1/Blog
        - app/Services/Blog
        - app/Interfaces/Blog
        - app/DTO/Request/Blog
        - app/DTO/Response/Blog
    excludePaths:
        - vendor
    reportUnmatchedIgnoredErrors: false
    ignoreErrors:
        # CI4 global helpers not visible in isolated analysis.
        - message: '#Function (lang|service|log_message|config|env|helper) not found#'
          paths: [app/*]
        # CI4 runtime constants.
        - message: '#Constant (ENVIRONMENT|APPPATH|ROOTPATH|WRITEPATH|FCPATH) not found#'
          paths: [app/*]
        # Config\* classes live in the consumer's app/Config/ — PHPStan can't
        # resolve them from the package side.
        - message: '#(unknown class|Class) Config\\#'
          paths: [app/*]
        # Generated readonly classes have implicit mixed-typed array params.
        - identifier: missingType.iterableValue
          paths: [app/*]
        # Interfaces with no in-package implementors flagged as unused.
        - identifier: interface.unused
          paths: [app/Interfaces/*]
        # OpenApi\Attributes not available: zircote/swagger-php is a consumer-app dep,
        # not installed in the vanilla CI4 project used for E2E.
        - identifier: attribute.notFound
          paths: [app/*]
        # BaseRequestDTO calls map() from its constructor; PHPStan cannot infer that
        # readonly properties assigned inside map() are initialized in the constructor
        # chain. These are true PHP-valid patterns — not bugs.
        - identifier: property.uninitializedReadonly
          paths: [app/DTO/*]
        - identifier: property.readOnlyAssignNotInConstructor
          paths: [app/DTO/*]
        # Config\Services::xyzService() is missing because --no-wire was used.
        # In a real consumer project the wiring step registers these methods.
        - identifier: staticMethod.notFound
          paths: [app/Controllers/*]
NEON

if vendor/bin/phpstan analyse \
    --configuration=phpstan-e2e.neon \
    --memory-limit=512M \
    --no-progress 2>&1; then
    pass "PHPStan level 5 — no errors"
else
    fail "PHPStan reported errors"
fi

# ── Step 8: spark routes list ────────────────────────────────────────────────
# CI4 ≥ 4.6 uses "spark routes list" (space); older versions used "routes:list"
# (colon). Try both; the step is best-effort and never fails the suite.

echo "Step 8 — spark routes list (best-effort)"
ROUTES_OUTPUT="$(php spark routes list 2>&1 || php spark routes:list 2>&1 || true)"
if echo "$ROUTES_OUTPUT" | grep -qi "exception\|fatal error\|parse error"; then
    fail "spark routes list crashed"
    echo "$ROUTES_OUTPUT" | head -20 >&2
elif echo "$ROUTES_OUTPUT" | grep -qi "article\|/blog\|articles"; then
    pass "Route '/api/v1/blog/articles' visible in spark routes list"
else
    echo "  ⚠ Route not found in listing — spark output:"
    echo "$ROUTES_OUTPUT" | head -20
    echo "  (This may be a namespace or filter-registry issue in vanilla CI4.)"
fi

# ── Summary ───────────────────────────────────────────────────────────────────

echo ""
echo "════════════════════════════════════════════════════════"
echo " Summary"
echo "════════════════════════════════════════════════════════"
printf "  ✓ Passed: %s\\n" "$PASS"
printf "  ✗ Failed: %s\\n" "$FAIL"
echo ""

if [[ $FAIL -gt 0 || $SYNTAX_ERRORS -gt 0 ]]; then
    echo "❌ E2E smoke test FAILED"
    exit 1
fi

echo "✅ E2E smoke test PASSED"
