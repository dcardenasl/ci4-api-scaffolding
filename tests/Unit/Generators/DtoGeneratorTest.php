<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\DtoGenerator;
use PHPUnit\Framework\TestCase;

final class DtoGeneratorTest extends TestCase
{
    public function testGeneratedDtosUsePublicRulesMethod(): void
    {
        $generator = new DtoGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string', required: true)],
        );

        $artifacts = $generator->generate($schema);

        foreach ($artifacts as $path => $content) {
            if (str_contains($path, 'ResponseDTO')) {
                continue; // Response DTOs are readonly and have no rules()
            }
            $this->assertStringContainsString(
                'public function rules(): array',
                $content,
                "Request DTO {$path} must declare rules() as public"
            );
            $this->assertStringNotContainsString(
                'protected function rules(): array',
                $content,
                "Request DTO {$path} must not declare rules() as protected"
            );
            $this->assertStringContainsString(
                '@return array<string, string>',
                $content,
                "Request DTO {$path} must document rules() as a keyed string array"
            );
            $this->assertStringContainsString(
                '@param array<string, mixed> $data',
                $content,
                "Request DTO {$path} must document the input payload shape"
            );
            $this->assertStringContainsString(
                '@return array<string, mixed>',
                $content,
                "Request DTO {$path} must document toArray() as an associative payload"
            );
        }
    }

    public function testGeneratedResponseDtoDocumentsArrayShapes(): void
    {
        $generator = new DtoGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string', required: true)],
        );

        $artifacts = $generator->generate($schema);
        $response = '';
        foreach ($artifacts as $path => $content) {
            if (str_contains($path, 'ResponseDTO')) {
                $response = $content;
                break;
            }
        }

        $this->assertNotEmpty($response);
        $this->assertStringContainsString('@param array<string, mixed> $data', $response);
        $this->assertStringContainsString('public static function fromArray(array $data): static', $response);
        $this->assertStringContainsString('@return array<string, mixed>', $response);
    }

    /**
     * Regression test for the "can't clear a nullable field via update" bug:
     * toArray() used to be array_filter($v !== null), which silently dropped
     * ANY field sent as null — including an intentional {"field": null} meant
     * to clear a nullable column. A client had no way to distinguish "field
     * omitted" from "field explicitly cleared", because both collapsed to the
     * same missing key in the outgoing update payload.
     *
     * The fix tracks field presence explicitly per field, keyed off whether
     * the underlying column is nullable:
     * - NOT NULL fields (name below) are only recorded when a real value was
     *   resolved — an explicit null is treated the same as omitting the
     *   field, matching the DB constraint (never write NULL into a NOT NULL
     *   column).
     * - Nullable fields (summary, stock below) are recorded whenever the key
     *   was present in the payload at all, even when the resolved value is
     *   null — this is what lets an explicit null actually clear the column.
     */
    /**
     * SCAFF-007: the engine-managed timestamps must be cast to string in fromArray(), because the
     * Entity returns CodeIgniter\I18n\Time objects and the constructor expects ?string.
     */
    public function testGeneratedResponseDtoCastsEngineTimestampsInFromArray(): void
    {
        $generator = new DtoGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string', required: true)],
        );

        $artifacts = $generator->generate($schema);
        $responseDto = null;
        foreach ($artifacts as $path => $content) {
            if (str_contains($path, 'ResponseDTO')) {
                $responseDto = $content;
            }
        }

        self::assertNotNull($responseDto);
        self::assertStringContainsString(
            "createdAt: isset(\$data['created_at']) ? (string) \$data['created_at'] : null,",
            $responseDto,
        );
        self::assertStringContainsString(
            "updatedAt: isset(\$data['updated_at']) ? (string) \$data['updated_at'] : null,",
            $responseDto,
        );
        self::assertStringNotContainsString("createdAt: \$data['created_at'] ?? null,", $responseDto);
    }

    /**
     * SCAFF-007 behaviour check: run the generated expression on a real CodeIgniter\I18n\Time value,
     * which is what Entity::toArray() returns for datetime columns. Without the cast, the value is an
     * object and the string-typed constructor argument fails.
     */
    public function testGeneratedTimestampExpressionYieldsStringForCodeIgniterTime(): void
    {
        $generator = new DtoGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string', required: true)],
        );

        $responseDto = '';
        foreach ($generator->generate($schema) as $path => $content) {
            if (str_contains($path, 'ResponseDTO')) {
                $responseDto = $content;
            }
        }

        self::assertMatchesRegularExpression('/createdAt: (.+),\n/', $responseDto);
        preg_match('/createdAt: (.+),\n/', $responseDto, $m);
        $expression = $m[1];

        $data = ['created_at' => new \CodeIgniter\I18n\Time('2026-01-02 03:04:05')];
        $value = eval('return ' . $expression . ';');

        self::assertIsString($value);
        self::assertSame('2026-01-02 03:04:05', $value);
    }

    public function testUpdateRequestDtoPreservesExplicitNullOnlyForNullableFields(): void
    {
        $generator = new DtoGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [
                new Field(name: 'name', type: 'string', required: true),
                new Field(name: 'summary', type: 'text', nullable: true),
                new Field(name: 'stock', type: 'int', nullable: true),
            ],
        );

        $artifacts = $generator->generate($schema);
        $content = '';
        foreach ($artifacts as $path => $fileContent) {
            if (str_contains($path, 'UpdateRequestDTO')) {
                $content = $fileContent;
                break;
            }
        }

        $this->assertNotEmpty($content);

        // The classic bug is gone: toArray() no longer filters nulls out of
        // the whole payload as a side effect of building it.
        $this->assertStringNotContainsString('array_filter(', $content);
        $this->assertStringContainsString('return $this->mappedFields;', $content);

        // NOT NULL field: only tracked when map() resolved a real value.
        $this->assertStringContainsString(
            "if (\$this->name !== null) {\n            \$mappedFields['name'] = \$this->name;\n        }",
            $content
        );

        // Nullable string field: tracked whenever the key was present at
        // all — an explicit {"summary": null} must reach toArray() as null.
        $this->assertStringContainsString(
            "if (array_key_exists('summary', \$data)) {\n            \$mappedFields['summary'] = \$this->summary;\n        }",
            $content
        );
        $this->assertStringContainsString(
            "array_key_exists('summary', \$data) && \$data['summary'] !== null && \$data['summary'] !== '' ? (string) \$data['summary'] : null",
            $content
        );

        // Nullable numeric field: same presence-tracking, and an empty
        // string (an HTML form's blank number input) is treated as "no
        // value" rather than silently coercing to 0.
        $this->assertStringContainsString(
            "if (array_key_exists('stock', \$data)) {\n            \$mappedFields['stock'] = \$this->stock;\n        }",
            $content
        );
        $this->assertStringContainsString(
            "array_key_exists('stock', \$data) && \$data['stock'] !== null && \$data['stock'] !== '' ? (int) \$data['stock'] : null",
            $content
        );
    }
}
