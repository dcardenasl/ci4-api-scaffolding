#!/bin/bash
#
# CRUD Validation Script (ci4-api-core package)
# Validates a scaffolded CRUD against known issues — table naming,
# soft-delete consistency, controller / model / route presence.
#
# Distributed via Composer's `bin` config. Run from your project root.
#
# Usage:
#   vendor/bin/validate-crud.sh ResourceName Domain
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

find_project_root() {
    local dir="$PWD"
    while [[ "$dir" != "/" ]]; do
        if [[ -f "$dir/composer.json" && -f "$dir/spark" ]]; then
            echo "$dir"
            return 0
        fi
        dir="$(dirname "$dir")"
    done
    return 1
}

PROJECT_ROOT="$(find_project_root)" || {
    echo -e "${RED}❌ Could not locate the project root.${NC}"
    echo "Run this script from inside a CodeIgniter 4 project."
    exit 1
}

RESOURCE=${1:-}
DOMAIN=${2:-}

if [[ -z "$RESOURCE" || -z "$DOMAIN" ]]; then
    echo -e "${RED}❌ Missing arguments${NC}"
    echo "Usage: vendor/bin/validate-crud.sh ResourceName Domain"
    echo "Example: vendor/bin/validate-crud.sh Product Catalog"
    exit 1
fi

cd "$PROJECT_ROOT"

# Compute domain in kebab-case: TestDomain → test-domain
DOMAIN_KEBAB=$(echo "$DOMAIN" | sed 's/\([A-Z]\)/-\1/g' | sed 's/^-//' | tr '[:upper:]' '[:lower:]')

# Compute the canonical plural class name the scaffolder would emit
# (e.g. User → Users, Category → Categories, Person → People). We delegate to
# the same StringHelper PHP class used by the generators so the bash script
# stays in sync with the PHP source of truth — no more naive `${RESOURCE%y}`.
RESOURCE_PLURAL=""
if [[ -f vendor/autoload.php ]]; then
    RESOURCE_PLURAL=$(php -r '
        require "vendor/autoload.php";
        if (class_exists(\dcardenasl\Ci4ApiCore\Core\StringHelper::class)) {
            echo \dcardenasl\Ci4ApiCore\Core\StringHelper::pluralize($argv[1]);
        }
    ' "$RESOURCE" 2>/dev/null || true)
fi
# Fallback to the unmodified resource name if the PHP call failed —
# the migration glob below uses both forms so it still has a chance to match.
if [[ -z "$RESOURCE_PLURAL" ]]; then
    RESOURCE_PLURAL="$RESOURCE"
fi

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}CRUD Validation: $RESOURCE ($DOMAIN)${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""

PASSED=0
FAILED=0

echo -e "${YELLOW}[1/6] Checking migration exists...${NC}"
# Search for both the singular (defensive) and the canonical plural the
# scaffolder produces. First match wins; either form is acceptable.
MIGRATION=$(find app/Database/Migrations \
    \( -name "*_Create${RESOURCE_PLURAL}Table.php" \
    -o -name "*_Create${RESOURCE}Table.php" \) \
    2>/dev/null | head -1)
if [[ -n "$MIGRATION" ]]; then
    echo -e "${GREEN}✓${NC} Found: $MIGRATION"
    ((PASSED++))
else
    echo -e "${RED}✗${NC} Migration not found for $RESOURCE (looked for *_Create${RESOURCE_PLURAL}Table.php)"
    ((FAILED++))
fi

if [[ -n "$MIGRATION" ]]; then
    echo ""
    echo -e "${YELLOW}[2/6] Checking table name format...${NC}"
    TABLE_NAME=$(grep "createTable(" "$MIGRATION" | head -1 | awk -F"'" '{print $2}')
    if [[ "$TABLE_NAME" =~ ^[a-z_]+$ ]]; then
        echo -e "${GREEN}✓${NC} Table name is snake_case: $TABLE_NAME"
        ((PASSED++))
    else
        echo -e "${RED}✗${NC} Table name is NOT snake_case: $TABLE_NAME"
        echo "   Fix: Edit migration, change 'createTable(\"$TABLE_NAME\")' to proper snake_case"
        ((FAILED++))
    fi
else
    echo -e "${YELLOW}[2/6] Skipped (migration not found)${NC}"
fi

echo ""
echo -e "${YELLOW}[3/6] Checking for deleted_at field...${NC}"
if [[ -n "$MIGRATION" ]]; then
    if grep -q "deleted_at" "$MIGRATION"; then
        echo -e "${YELLOW}⚠${NC} Migration contains 'deleted_at' field"
        echo "   If this resource uses --soft-delete no, remove the deleted_at field"
        ((PASSED++))
    else
        echo -e "${GREEN}✓${NC} No deleted_at field (hard delete or check intentional)"
        ((PASSED++))
    fi
else
    echo -e "${YELLOW}[3/6] Skipped (migration not found)${NC}"
fi

echo ""
echo -e "${YELLOW}[4/6] Checking controller exists...${NC}"
CONTROLLER=$(find app/Controllers -name "${RESOURCE}Controller.php" 2>/dev/null | head -1)
if [[ -n "$CONTROLLER" ]]; then
    echo -e "${GREEN}✓${NC} Found: $CONTROLLER"
    ((PASSED++))
else
    echo -e "${RED}✗${NC} Controller not found: ${RESOURCE}Controller.php"
    ((FAILED++))
fi

echo ""
echo -e "${YELLOW}[5/6] Checking model exists...${NC}"
MODEL=$(find app/Models -name "${RESOURCE}Model.php" 2>/dev/null | head -1)
if [[ -n "$MODEL" ]]; then
    echo -e "${GREEN}✓${NC} Found: $(basename "$MODEL")"
    ((PASSED++))
else
    echo -e "${RED}✗${NC} Model not found: ${RESOURCE}Model.php"
    ((FAILED++))
fi

echo ""
echo -e "${YELLOW}[6/6] Checking routes registered...${NC}"
ROUTE_FILE=$(find app/Config/Routes -name "${DOMAIN_KEBAB}.php" 2>/dev/null | head -1)
if [[ -n "$ROUTE_FILE" ]]; then
    if grep -q "${RESOURCE}Controller" "$ROUTE_FILE"; then
        echo -e "${GREEN}✓${NC} Found and wired: $ROUTE_FILE"
        ((PASSED++))
    else
        echo -e "${YELLOW}⚠${NC} Route file exists but ${RESOURCE}Controller not found in it"
        echo "   File: $ROUTE_FILE"
        ((FAILED++))
    fi
else
    echo -e "${RED}✗${NC} Route file not found for domain ${DOMAIN_KEBAB}"
    ((FAILED++))
fi

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "Results: ${GREEN}$PASSED passed${NC}, ${RED}$FAILED failed${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"

if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}✅ All validations passed!${NC}"
    exit 0
else
    echo -e "${RED}❌ Validation failed. Review errors above and fix.${NC}"
    exit 1
fi
