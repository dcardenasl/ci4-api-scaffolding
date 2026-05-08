<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Validators;

use InvalidArgumentException;

/**
 * Thrown by {@see FieldStringParser::parse()} when a field declares an
 * unknown type code (e.g. `intenger` instead of `int`). Without this guard
 * `TypeMapper::get()` silently falls back to `string`, generating DTOs with
 * wrong column types and validation rules.
 */
class UnknownFieldTypeException extends InvalidArgumentException
{
    /**
     * @param list<string> $knownTypes
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly string $declaredType,
        public readonly array $knownTypes,
    ) {
        parent::__construct(sprintf(
            "Unknown field type '%s' for field '%s'. Known types: %s",
            $declaredType,
            $fieldName,
            implode(', ', $knownTypes),
        ));
    }
}
