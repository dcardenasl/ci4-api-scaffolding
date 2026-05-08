<?php

declare(strict_types=1);

namespace {ns};

use {baseFqcn};
use OpenApi\Attributes as OA;

#[OA\Schema(schema: '{resource}CreateRequest')]
readonly class {resource}CreateRequestDTO extends {baseShort}
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
        return [
{toArray}        ];
    }
}
