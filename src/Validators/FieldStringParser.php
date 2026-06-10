<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Validators;

use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\TypeMapper;

/**
 * Parses the `--fields` string accepted by `php spark make:crud` into typed Field objects.
 *
 * Syntax:
 *   name:type:modifier1|modifier2|modifier3
 *
 * FK-like types (`fk` or `relation`) use a 4-segment form:
 *   name:fk:target_table:modifier1|modifier2
 *   name:relation:target_table:modifier1|modifier2
 *
 * Multiple fields are comma-separated. The caller is responsible for shell-quoting.
 */
final class FieldStringParser
{
    /**
     * @return list<Field>
     */
    public function parse(string $fieldsArg): array
    {
        $fields = [];
        if (trim($fieldsArg) === '') {
            return $fields;
        }

        foreach (explode(',', $fieldsArg) as $part) {
            $segments = explode(':', trim($part));
            if (count($segments) < 2) {
                continue;
            }

            $name = $segments[0];
            $type = $segments[1];

            // Reject unknown type codes upfront. TypeMapper::get() falls back
            // to 'string' silently for unknown types, which would otherwise
            // produce a DTO with the wrong column type and validation rules.
            if (!TypeMapper::isKnown($type)) {
                throw new UnknownFieldTypeException(
                    fieldName: $name,
                    declaredType: $type,
                    knownTypes: TypeMapper::knownTypes(),
                );
            }

            $isForeignKeyLike = in_array($type, ['fk', 'relation'], true);

            if ($isForeignKeyLike) {
                $fkTable = $segments[2] ?? null;
                $options = explode('|', $segments[3] ?? '');
            } else {
                $fkTable = null;
                $options = explode('|', $segments[2] ?? '');
            }

            // FK referential actions: cascade (default), restrict, setnull.
            // Honored only when type is fk/relation; ignored otherwise.
            $fkOnDelete = 'CASCADE';
            if ($isForeignKeyLike) {
                if (in_array('restrict', $options, true)) {
                    $fkOnDelete = 'RESTRICT';
                } elseif (in_array('setnull', $options, true)) {
                    $fkOnDelete = 'SET NULL';
                }
            }

            $fields[] = new Field(
                name: $name,
                type: $type,
                required: in_array('required', $options, true),
                nullable: in_array('nullable', $options, true),
                searchable: in_array('searchable', $options, true),
                filterable: in_array('filterable', $options, true),
                fkTable: $fkTable,
                unique: in_array('unique', $options, true),
                index: in_array('index', $options, true),
                fkOnDelete: $fkOnDelete
            );
        }

        return $fields;
    }
}
