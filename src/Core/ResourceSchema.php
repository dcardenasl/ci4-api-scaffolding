<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Core;

/**
 * ResourceSchema
 * Aggregates all fields and metadata for the resource to be scaffolded.
 */
readonly class ResourceSchema
{
    /**
     * @param Field[] $fields
     */
    public function __construct(
        public string $resource,
        public string $domain,
        public string $route,
        public array $fields,
        public bool $softDelete = true,
        public bool $publicRead = true,
        public bool $adminWrite = true
    ) {
    }

    public function getResourceLower(): string
    {
        return StringHelper::toCamelCase($this->resource);
    }

    public function getResourcePlural(): string
    {
        return StringHelper::pluralize($this->resource);
    }

    public function getResourcePluralLower(): string
    {
        return StringHelper::toCamelCase($this->getResourcePlural());
    }

    public function getResourcePluralKebab(): string
    {
        return StringHelper::toKebab($this->getResourcePlural());
    }

    public function getResourcePluralSnakeCase(): string
    {
        return StringHelper::toSnakeCase($this->getResourcePlural());
    }

    public function toKebab(string $value): string
    {
        return StringHelper::toKebab($value);
    }

    public function toSnakeCase(string $value): string
    {
        return StringHelper::toSnakeCase($value);
    }
}
