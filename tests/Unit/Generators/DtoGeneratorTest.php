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
}
