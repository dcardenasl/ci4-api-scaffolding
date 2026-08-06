<?php

declare(strict_types=1);

namespace {ns};

use {baseFqcn};
use OpenApi\Attributes as OA;
{extraUses}
#[OA\Schema(schema: '{resource}UpdateRequest')]
readonly class {resource}UpdateRequestDTO extends {baseShort}
{
{traitsBlock}{properties}
    /** @var array<string, mixed> */
    private array $mappedFields;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
{rules}        ];
    }

    /**
     * NOT NULL fields never accept an explicit null in the payload — treated
     * the same as omitting the field, matching the DB constraint. Nullable
     * fields preserve an explicit null through to toArray(), so a client
     * sending {"field": null} actually clears that column in the update.
     * Telling "omitted" and "explicitly null" apart requires
     * array_key_exists() here — isset()/?? alone cannot: both read as
     * false/null whether the key is absent or present-with-null.
     *
     * @param array<string, mixed> $data
     */
    protected function map(array $data): void
    {
{mappings}
        $mappedFields = [];
{mappedFieldsBlock}
        $this->mappedFields = $mappedFields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->mappedFields;
    }
}
