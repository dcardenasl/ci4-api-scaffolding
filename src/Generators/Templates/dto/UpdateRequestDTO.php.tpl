<?php

declare(strict_types=1);

namespace {ns};

use {baseFqcn};
use OpenApi\Attributes as OA;

#[OA\Schema(schema: '{resource}UpdateRequest')]
readonly class {resource}UpdateRequestDTO extends {baseShort}
{
{properties}
    public function rules(): array
    {
        return [
{rules}        ];
    }

    protected function map(array $data): void
    {
{mappings}    }

    public function toArray(): array
    {
        return array_filter([
{toArray}        ], fn($v) => $v !== null);
    }
}
