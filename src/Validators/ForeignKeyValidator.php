<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Validators;

use Config\Database;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use InvalidArgumentException;
use Throwable;

/**
 * Verify that every foreign key in the schema points at a real table BEFORE the
 * scaffolding engine writes ~17 files that would later break at migrate time.
 *
 * Strictness:
 *  - When the schema declares FKs but the database is unreachable, validation
 *    aborts by default — the alternative would be to write files that fail at
 *    `php spark migrate` with a cryptic SQL error. This is finding M2 of the
 *    May 2026 audit.
 *  - Pass `$skipOnDbUnreachable = true` (e.g. via `make:crud --skip-fk-validation`)
 *    to fall back to the historical "warn and continue" behavior. Useful on
 *    dev machines where Docker is not yet running but the user knows the FK
 *    targets exist.
 *  - Schemas with no FKs always pass.
 */
class ForeignKeyValidator
{
    /**
     * @return string[] List of warnings emitted (empty when validation succeeded)
     * @throws InvalidArgumentException When at least one FK target table is missing
     *                                  OR when DB is unreachable while $skipOnDbUnreachable=false
     */
    public function validate(ResourceSchema $schema, bool $skipOnDbUnreachable = false): array
    {
        $fkFields = array_values(array_filter(
            $schema->fields,
            static fn (Field $f): bool => $f->fkTable !== null && $f->fkTable !== ''
        ));

        if (empty($fkFields)) {
            return [];
        }

        try {
            $db = Database::connect();
            $tables = $db->listTables();
            $existing = array_map('strtolower', $tables ?: []);
        } catch (Throwable $e) {
            if ($skipOnDbUnreachable) {
                return [
                    "FK target validation skipped (database unreachable: {$e->getMessage()}). "
                    . "Verify referenced tables exist before running 'php spark migrate'.",
                ];
            }

            throw new InvalidArgumentException(
                "Cannot validate foreign keys: database unreachable ({$e->getMessage()}).\n"
                . "Schemas with FK fields require a live database to verify their targets — otherwise "
                . "the generated migration may fail at 'php spark migrate' with a cryptic SQL error.\n"
                . "If you know the target tables exist, re-run with --skip-fk-validation to bypass this check."
            );
        }

        $missing = [];
        foreach ($fkFields as $field) {
            // The filter above already excludes null/empty fkTable, but PHPStan
            // can't narrow through the closure — guard explicitly so level 8 stays clean.
            if ($field->fkTable === null) {
                continue;
            }
            if (!in_array(strtolower($field->fkTable), $existing, true)) {
                $missing[] = "Field '{$field->name}': foreign key references nonexistent table '{$field->fkTable}'.";
            }
        }

        if (!empty($missing)) {
            $hint = "\nHint: run the migration that creates the target table first, "
                . "or remove the foreign-key modifier and add it in a follow-up scaffold.";
            throw new InvalidArgumentException(implode("\n", $missing) . $hint);
        }

        return [];
    }
}
