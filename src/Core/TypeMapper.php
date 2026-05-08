<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Core;

/**
 * TypeMapper
 * The core brain that maps abstract types to DB/PHP/Validation/OpenAPI specifics.
 */
class TypeMapper
{
    /**
     * @var array<string, array{
     *   db: string,
     *   php: string,
     *   val: string,
     *   oa: string,
     *   oa_format?: string
     * }>
     */
    private static array $map = [
        'string' => [
            'db' => 'VARCHAR',
            'php' => 'string',
            'val' => 'string|max_length[255]',
            'oa' => 'string'
        ],
        'text' => [
            'db' => 'TEXT',
            'php' => 'string',
            'val' => 'string',
            'oa' => 'string'
        ],
        'int' => [
            'db' => 'INT',
            'php' => 'int',
            'val' => 'integer',
            'oa' => 'integer'
        ],
        'integer' => [
            'db' => 'INT',
            'php' => 'int',
            'val' => 'integer',
            'oa' => 'integer'
        ],
        'bool' => [
            'db' => 'TINYINT',
            'php' => 'bool',
            'val' => 'boolean_like',
            'oa' => 'boolean'
        ],
        'decimal' => [
            'db' => 'DECIMAL',
            'php' => 'float',
            'val' => 'decimal',
            'oa' => 'number',
            'oa_format' => 'float'
        ],
        'email' => [
            'db' => 'VARCHAR',
            'php' => 'string',
            'val' => 'string|valid_email|max_length[255]',
            'oa' => 'string',
            'oa_format' => 'email'
        ],
        'date' => [
            'db' => 'DATE',
            'php' => 'string',
            'val' => 'valid_date[Y-m-d]',
            'oa' => 'string',
            'oa_format' => 'date'
        ],
        'datetime' => [
            'db' => 'DATETIME',
            'php' => 'string',
            'val' => 'valid_date',
            'oa' => 'string',
            'oa_format' => 'date-time'
        ],
        'fk' => [
            'db' => 'INT',
            'php' => 'int',
            'val' => 'is_natural_no_zero',
            'oa' => 'integer'
        ],
        'json' => [
            'db' => 'JSON',
            'php' => 'array',
            'val' => 'permit_empty',
            'oa' => 'object'
        ]
    ];

    /**
     * @return array{
     *   db: string,
     *   php: string,
     *   val: string,
     *   oa: string,
     *   oa_format?: string
     * }
     */
    public static function get(string $type): array
    {
        return self::$map[$type] ?? self::$map['string'];
    }

    /**
     * Whether the given type code is recognized by the mapper. Used by the
     * input parsers so a typo like `intenger` fails fast instead of silently
     * mapping to `string`.
     */
    public static function isKnown(string $type): bool
    {
        return isset(self::$map[$type]);
    }

    /**
     * @return list<string> All registered type codes, sorted alphabetically.
     */
    public static function knownTypes(): array
    {
        $types = array_keys(self::$map);
        sort($types);

        return $types;
    }

    public static function getPhpType(string $type, bool $nullable): string
    {
        $phpType = self::get($type)['php'];
        return ($nullable ? '?' : '') . $phpType;
    }

    public static function getValidationRules(Field $field, ?string $tableForUnique = null): string
    {
        $mapping = self::get($field->type);
        $rules = [$field->required ? 'required' : 'permit_empty'];

        // Split the type's val on `|` and merge, so rules like json's `permit_empty` don't
        // duplicate the leading required/permit_empty token.
        if ($mapping['val'] !== '') {
            foreach (explode('|', $mapping['val']) as $rule) {
                if ($rule !== '' && !in_array($rule, $rules, true)) {
                    $rules[] = $rule;
                }
            }
        }

        if ($field->validationRules !== null && $field->validationRules !== '') {
            foreach (explode('|', $field->validationRules) as $rule) {
                if ($rule !== '' && !in_array($rule, $rules, true)) {
                    $rules[] = $rule;
                }
            }
        }

        if ($field->fkTable) {
            $rules[] = "is_not_unique[{$field->fkTable}.id]";
        }

        if ($field->unique && $tableForUnique !== null) {
            $rules[] = "is_unique[{$tableForUnique}.{$field->name}]";
        }

        return implode('|', $rules);
    }
}
