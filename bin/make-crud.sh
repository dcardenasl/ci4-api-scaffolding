#!/bin/bash
#
# CodeIgniter 4 CRUD Scaffolding Helper (ci4-api-core package)
# Wrapper around `php spark make:crud` with automatic escaping and validation.
#
# Distributed via Composer's `bin` config — symlinked into vendor/bin.
# Run from your project root (the directory containing composer.json + spark).
#
# Usage:
#   vendor/bin/make-crud.sh <Resource> <Domain> '<Fields>' [SoftDelete] [Route] [--migrate] [--dry-run] [--no-wire]
#
# Examples:
#   vendor/bin/make-crud.sh Product Catalog 'name:string:required|searchable' yes
#   vendor/bin/make-crud.sh UpaEvent Events 'title:string:required|searchable,year:int' yes upa-events
#

set -e
set -o pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Locate the project root by walking up from the current directory looking
# for `composer.json` + `spark`. This makes the script work whether invoked
# from the project root, a subdir, or via the composer-symlinked vendor/bin.
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
    echo "Run this script from inside a CodeIgniter 4 project (a directory that contains composer.json + spark)."
    exit 1
}

# Parse arguments — interleaved positionals + flags.
POSITIONAL=()
MIGRATE=false
DRY_RUN=false
NO_WIRE=false
API_VERSION="v1"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --migrate)  MIGRATE=true; shift ;;
        --dry-run)  DRY_RUN=true; shift ;;
        --no-wire)  NO_WIRE=true; shift ;;
        --version)  API_VERSION="${2:-v1}"; shift 2 ;;
        --version=*) API_VERSION="${1#--version=}"; shift ;;
        --help|-h)
            cat <<'USAGE'
Usage:
  vendor/bin/make-crud.sh <Resource> <Domain> <Fields> [SoftDelete] [Route] [--migrate] [--dry-run] [--no-wire] [--version vN]

Arguments:
  <Resource>     Resource name in StudlyCase (e.g., Audience, SchoolCategory)
  <Domain>       Domain folder name (e.g., Shows, Education, Media)
  <Fields>       Comma-separated fields: name:type:options (use single quotes!)
  [SoftDelete]   yes or no (default: yes)
  [Route]        Custom route slug (default: kebab-case plural of resource)

Flags:
  --migrate      Run 'php spark migrate' after scaffolding and abort if it fails
                 (catches the upstream bug where spark migrate exits 0 on errors).
  --dry-run      Show planned actions without writing files.
  --no-wire      Skip Services.php injection. Print snippets to paste manually.
  --version vN   API version for route path (default: v1). Example: --version v2

Field Options:
  required, nullable, searchable, filterable, unique, index
  fk:table_name                          - Foreign key reference (validated against DB)
  fk:table_name:setnull|restrict|cascade - FK with explicit ON DELETE behavior
USAGE
            exit 0
            ;;
        --*)
            echo -e "${RED}❌ Unknown flag: $1${NC}"
            echo "Run with --help for usage."
            exit 1
            ;;
        *) POSITIONAL+=("$1"); shift ;;
    esac
done
set -- "${POSITIONAL[@]}"

RESOURCE=${1:-}
DOMAIN=${2:-}
FIELDS=${3:-}
SOFT_DELETE=${4:-yes}
ROUTE=${5:-}

if [[ -z "$RESOURCE" || -z "$DOMAIN" || -z "$FIELDS" ]]; then
    echo -e "${RED}❌ Missing arguments${NC}"
    echo "Run with --help for usage."
    exit 1
fi

if [[ "$SOFT_DELETE" != "yes" && "$SOFT_DELETE" != "no" ]]; then
    echo -e "${RED}❌ SoftDelete must be 'yes' or 'no'${NC}"
    exit 1
fi

# Detect uppercase runs (acronyms) and warn about implicit normalization.
if [[ "$RESOURCE" =~ [A-Z]{2,}[a-z] ]] || [[ "$RESOURCE" =~ [A-Z]{2,}$ ]]; then
    if command -v php >/dev/null 2>&1; then
        # shellcheck disable=SC2016
        CANONICAL=$(php -r '
$v = $argv[1];
$v = preg_replace_callback("/([A-Z]+)([A-Z][a-z]|$)/", static fn (array $m): string => ucfirst(strtolower($m[1])) . $m[2], $v);
echo $v;
' -- "$RESOURCE")
        echo -e "${YELLOW}⚠ Resource '${RESOURCE}' contains a run of consecutive uppercase letters.${NC}"
        echo -e "${YELLOW}  Derived names will keep the acronym intact (snake='api_key' instead of 'a_p_i_key').${NC}"
        echo -e "${YELLOW}  If you prefer canonical StudlyCase, re-run with: ${CANONICAL}${NC}"
        echo ""
    fi
fi

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}CRUD Scaffolding Helper (ci4-api-core)${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}Configuration:${NC}"
echo "  Project:      $PROJECT_ROOT"
echo "  Resource:     $RESOURCE"
echo "  Domain:       $DOMAIN"
echo "  Fields:       $FIELDS"
echo "  Soft Delete:  $SOFT_DELETE"
echo "  API Version:  $API_VERSION"
[[ -n "$ROUTE" ]] && echo "  Route:        $ROUTE"
[[ "$DRY_RUN" == true ]] && echo "  Mode:         DRY RUN"
[[ "$NO_WIRE" == true ]] && echo "  Wiring:       --no-wire (manual)"
echo ""

cd "$PROJECT_ROOT"

# Step 1: Run make:crud
echo -e "${YELLOW}Step 1: Scaffolding CRUD...${NC}"

SPARK_FLAGS=()
[[ -n "$ROUTE" ]] && SPARK_FLAGS+=(--route "$ROUTE")
[[ "$API_VERSION" != "v1" ]] && SPARK_FLAGS+=(--version "$API_VERSION")
[[ "$DRY_RUN" == true ]] && SPARK_FLAGS+=(--dry-run)
[[ "$NO_WIRE" == true ]] && SPARK_FLAGS+=(--no-wire)

SCAFFOLD_LOG=$(mktemp -t make-crud.XXXXXX)
trap 'rm -f "$SCAFFOLD_LOG"' EXIT

if php spark make:crud "$RESOURCE" \
    --domain "$DOMAIN" \
    --fields "$FIELDS" \
    --soft-delete "$SOFT_DELETE" \
    "${SPARK_FLAGS[@]}" > "$SCAFFOLD_LOG" 2>&1; then
    grep -E "CREATED|UPDATED|WIRING|✅|Would create|Would wire|--no-wire" "$SCAFFOLD_LOG" || true
    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${GREEN}✓ Dry-run complete (no files written)${NC}"
        cat "$SCAFFOLD_LOG"
        exit 0
    fi
    echo -e "${GREEN}✓ Scaffolding complete${NC}"
else
    echo -e "${RED}✗ Scaffolding failed${NC}"
    echo -e "${YELLOW}--- spark output ---${NC}"
    cat "$SCAFFOLD_LOG"
    echo -e "${YELLOW}--- end spark output ---${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}Step 2: Auto-fixing code style (best effort)...${NC}"
if composer cs-fix --quiet >/dev/null 2>&1; then
    echo -e "${GREEN}✓ Code style fixed${NC}"
elif composer format --quiet >/dev/null 2>&1; then
    echo -e "${GREEN}✓ Code style fixed (composer format)${NC}"
else
    echo -e "${YELLOW}⚠ No cs-fix/format script found in composer.json — skipping${NC}"
fi

# Optional Step 2b: --migrate runs and validates migrations.
if [[ "$MIGRATE" == true ]]; then
    echo ""
    echo -e "${YELLOW}Step 2b: Running migrations (--migrate)...${NC}"
    MIGRATE_LOG=$(mktemp -t make-crud-migrate.XXXXXX)
    trap 'rm -f "$SCAFFOLD_LOG" "$MIGRATE_LOG"' EXIT
    php spark migrate --no-color > "$MIGRATE_LOG" 2>&1 || true
    if ! grep -qF 'Migrations complete.' "$MIGRATE_LOG"; then
        echo -e "${RED}✗ Migration failed (spark exits 0 even on SQL errors — see output below)${NC}"
        echo -e "${YELLOW}--- migrate output ---${NC}"
        cat "$MIGRATE_LOG"
        echo -e "${YELLOW}--- end migrate output ---${NC}"
        echo ""
        echo -e "${YELLOW}Tip: run 'php spark make:crud:remove ${RESOURCE} --domain ${DOMAIN}' to clean up, then fix the issue and retry.${NC}"
        exit 1
    fi
    grep -E 'Running|complete' "$MIGRATE_LOG" | tail -10 || true
    echo -e "${GREEN}✓ Migrations applied${NC}"
fi

echo ""
echo -e "${YELLOW}Step 3: Next steps${NC}"
echo -e "  1. Review migration: ${BLUE}app/Database/Migrations/*_Create${RESOURCE}*Table.php${NC}"
echo -e "  2. Run migrations:   ${BLUE}php spark migrate${NC}"
echo "  3. Restart server to detect new route files."
echo "  4. (Optional) Run swagger:generate if your project has it."
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Scaffolding complete!${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
