<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\ModelEntityGenerator;
use PHPUnit\Framework\TestCase;

final class ModelEntityGeneratorTest extends TestCase
{
    public function testDefaultConfigGeneratesModelExtendingBaseAuditableModel(): void
    {
        $generator = new ModelEntityGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);
        $modelContent = $this->extract($artifacts, 'ProductModel');

        $this->assertNotEmpty($modelContent, 'ModelEntityGenerator must produce a model file');
        $this->assertStringContainsString('extends BaseAuditableModel', $modelContent);
        $this->assertStringNotContainsString('use App\\Traits\\Auditable;', $modelContent);
    }

    public function testModelImportsCoreFilterableSearchableTraitsByDefault(): void
    {
        $generator = new ModelEntityGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string', searchable: true)],
        );

        $modelContent = $this->extract($generator->generate($schema), 'ProductModel');

        $this->assertStringContainsString(
            'use dcardenasl\\Ci4ApiCore\\Models\\Traits\\Filterable;',
            $modelContent,
        );
        $this->assertStringContainsString(
            'use dcardenasl\\Ci4ApiCore\\Models\\Traits\\Searchable;',
            $modelContent,
        );
        $this->assertStringNotContainsString('use App\\Traits\\Filterable;', $modelContent);
        $this->assertStringNotContainsString('use App\\Traits\\Searchable;', $modelContent);
    }

    public function testEntityRegistersDecimalCastHandlerWhenDecimalFieldPresent(): void
    {
        $generator = new ModelEntityGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [
                new Field(name: 'name', type: 'string'),
                new Field(name: 'price', type: 'decimal'),
            ],
        );

        $entityContent = $this->extract($generator->generate($schema), 'ProductEntity');

        $this->assertStringContainsString(
            'use dcardenasl\\Ci4ApiCore\\DataCasts\\DecimalCast;',
            $entityContent,
        );
        $this->assertStringContainsString("'decimal' => DecimalCast::class,", $entityContent);
        $this->assertStringContainsString("'price' => 'decimal',", $entityContent);
    }

    public function testEntityOmitsCastHandlersWhenNoDecimalField(): void
    {
        $generator = new ModelEntityGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $entityContent = $this->extract($generator->generate($schema), 'ProductEntity');

        $this->assertStringNotContainsString('castHandlers', $entityContent);
        $this->assertStringNotContainsString('DecimalCast', $entityContent);
    }

    public function testEntityCastsArrayHasUniformIndentationFromFirstKey(): void
    {
        $generator = new ModelEntityGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $entityContent = $this->extract($generator->generate($schema), 'ProductEntity');

        // B2 fix: every key inside the $casts array body must be indented with
        // 8 spaces (4 for class body + 4 for array body). Pre-fix the first
        // line lacked indentation.
        $this->assertMatchesRegularExpression(
            "/protected \\\$casts = \\[\n        'id' => 'integer',\n        'name' => 'string',/",
            $entityContent,
        );
    }

    /** @param array<string,string> $artifacts */
    private function extract(array $artifacts, string $needle): string
    {
        foreach ($artifacts as $path => $content) {
            if (str_contains($path, $needle)) {
                return $content;
            }
        }

        return '';
    }
}
