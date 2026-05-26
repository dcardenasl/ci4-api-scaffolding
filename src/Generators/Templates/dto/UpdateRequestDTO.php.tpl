<?php

declare(strict_types=1);

namespace {ns};

use {baseFqcn};
use OpenApi\Attributes as OA;

#[OA\Schema(schema: '{resource}UpdateRequest')]
readonly class {resource}UpdateRequestDTO extends {baseShort}
{
{properties}
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
{rules}        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function map(array $data): void
    {
{mappings}    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
{toArray}        ], static fn (mixed $value): bool => $value !== null);
    }
}
