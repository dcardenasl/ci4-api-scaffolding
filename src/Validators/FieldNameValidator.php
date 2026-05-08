<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Validators;

use dcardenasl\Ci4ApiScaffolding\Core\Field;
use InvalidArgumentException;

/**
 * Rejects field names that would silently produce broken scaffolds:
 * - PHP reserved keywords (would emit invalid PHP properties)
 * - MySQL reserved words commonly used unquoted
 * - duplicate names within the same resource
 * - collisions with engine-managed columns (id, created_at, updated_at, deleted_at)
 * - names that aren't valid PHP identifiers
 */
final class FieldNameValidator
{
    /** @var list<string> */
    private const PHP_RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile',
        'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof',
        'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or',
        'print', 'private', 'protected', 'public', 'readonly', 'require', 'return',
        'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while',
        'xor', 'yield', 'true', 'false', 'null', 'self', 'parent', 'this',
    ];

    /** @var list<string> */
    private const ENGINE_MANAGED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** @var list<string> Common MySQL reserved words worth flagging proactively. */
    private const MYSQL_RESERVED = [
        'select', 'from', 'where', 'join', 'inner', 'outer', 'left', 'right', 'on',
        'create', 'drop', 'alter', 'insert', 'update', 'delete', 'table', 'index',
        'key', 'primary', 'foreign', 'default', 'null', 'not', 'and', 'or', 'order',
        'group', 'having', 'limit', 'offset', 'as', 'distinct', 'union', 'all',
    ];

    private const IDENTIFIER_PATTERN = '/^[a-z_][a-z0-9_]*$/i';

    /**
     * @param list<Field> $fields
     */
    public function validate(array $fields): void
    {
        $seen = [];

        foreach ($fields as $field) {
            $name = $field->name;
            $lower = strtolower($name);

            if (preg_match(self::IDENTIFIER_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(
                    "Field '{$name}' is not a valid identifier (must match [a-z_][a-z0-9_]*)."
                );
            }

            if (in_array($lower, self::ENGINE_MANAGED, true)) {
                throw new InvalidArgumentException(
                    "Field '{$name}' collides with an engine-managed column. The scaffold already generates id/created_at/updated_at/deleted_at — omit it from --fields."
                );
            }

            if (in_array($lower, self::PHP_RESERVED, true)) {
                throw new InvalidArgumentException(
                    "Field '{$name}' is a PHP reserved keyword and cannot be used as a property name."
                );
            }

            if (in_array($lower, self::MYSQL_RESERVED, true)) {
                throw new InvalidArgumentException(
                    "Field '{$name}' is a MySQL reserved word. Pick a more specific name (e.g. order_number instead of order) to avoid query breakage."
                );
            }

            if (isset($seen[$lower])) {
                throw new InvalidArgumentException(
                    "Duplicate field '{$name}' in the same resource. Each column name must be unique."
                );
            }

            // Bool fields without an explicit required/nullable modifier are ambiguous:
            // the DTO ends up `permit_empty|boolean_like` (allows missing) but the PHP
            // type is non-nullable bool with a silent default false. Clients that omit
            // the field get false without knowing it. Force the caller to choose.
            if ($field->type === 'bool' && !$field->required && !$field->nullable) {
                throw new InvalidArgumentException(
                    "Field '{$name}': bool fields must be tagged ':required' or ':nullable'. "
                    . "Without one, the API silently defaults to false when the client omits the value."
                );
            }

            $seen[$lower] = true;
        }
    }
}
