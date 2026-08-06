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
     * @param string $apiVersion API version prefix used by RouteGenerator (e.g. 'v1', 'v2')
     * @param string|null $sluggableField Name of the field content-localized public slugs are
     *                                    generated from. Null means the resource has no public
     *                                    slug (the default). When set, that field must also carry
     *                                    Field::$translatable = true — see FieldStringParser.
     */
    public function __construct(
        public string $resource,
        public string $domain,
        public string $route,
        public array $fields,
        public bool $softDelete = true,
        public bool $publicRead = true,
        public bool $adminWrite = true,
        public string $apiVersion = 'v1',
        public ?string $sluggableField = null,
    ) {
    }

    public function hasTranslatableFields(): bool
    {
        return $this->translatableFieldNames() !== [];
    }

    /** @return list<string> */
    public function translatableFieldNames(): array
    {
        return array_values(array_map(
            static fn (Field $field): string => $field->name,
            array_filter($this->fields, static fn (Field $field): bool => $field->translatable),
        ));
    }

    public function isSluggable(): bool
    {
        return $this->sluggableField !== null;
    }

    /**
     * The registry key `Config\Localization::$translatableFields` is keyed
     * by, and the `translatable_type` / `resource_type` value stored in the
     * sidecar tables. Matches the snake_case-singular convention already in
     * production use (e.g. `collection_item`, `event`).
     */
    public function localizationResourceType(): string
    {
        return $this->toSnakeCase($this->resource);
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
