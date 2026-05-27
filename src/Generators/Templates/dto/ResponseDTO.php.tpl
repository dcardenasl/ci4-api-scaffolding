<?php

declare(strict_types=1);

namespace {ns};

use {ifaceFqcn};
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: '{resource}Response',
    title: '{resource} Response',
    required: {requiredJson}
)]
final readonly class {resource}ResponseDTO implements {ifaceShort}
{
    public function __construct(
        #[OA\Property(description: 'Unique identifier', example: 1)]
        public int $id,
{params}
        #[OA\Property(property: 'created_at', description: 'Creation timestamp', example: '2026-02-26 12:00:00', nullable: true)]
        public ?string $createdAt = null,
        #[OA\Property(property: 'updated_at', description: 'Last update timestamp', example: '2026-02-26 12:00:00', nullable: true)]
        public ?string $updatedAt = null
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: (int) ($data['id'] ?? 0),
{fromArrayMappings}            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
{toArray}            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
