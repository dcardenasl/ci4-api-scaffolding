<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Core;

/**
 * Field Value Object
 * Represents a single field in the resource with its metadata.
 */
readonly class Field
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $required = true,
        public bool $nullable = false,
        public ?string $validationRules = null,
        public bool $searchable = false,
        public bool $filterable = false,
        public bool $sortable = false,
        public ?string $fkTable = null,
        public ?string $defaultValue = null,
        public ?int $length = null,
        public ?string $precision = null, // For decimals e.g. "10,2"
        public bool $unique = false,
        public bool $index = false,
        // Foreign key referential action overrides. Default 'CASCADE' preserves
        // historical behavior; 'RESTRICT' blocks parent deletion when children
        // exist (safer for orders/customers); 'SET NULL' nulls the child column
        // (requires the field to be nullable).
        public string $fkOnDelete = 'CASCADE',
        public string $fkOnUpdate = 'CASCADE'
    ) {
    }
}
