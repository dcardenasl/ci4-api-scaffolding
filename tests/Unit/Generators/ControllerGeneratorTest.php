<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\ControllerGenerator;
use PHPUnit\Framework\TestCase;

final class ControllerGeneratorTest extends TestCase
{
    /**
     * @param array<string, string> $traits
     */
    private static function defaultsWithTraits(array $traits): ScaffoldingConfig
    {
        $d = ScaffoldingConfig::defaults();

        return new ScaffoldingConfig(
            controllerBaseClass: $d->controllerBaseClass,
            serviceBaseClass: $d->serviceBaseClass,
            serviceContractInterface: $d->serviceContractInterface,
            modelBaseClass: $d->modelBaseClass,
            entityBaseClass: $d->entityBaseClass,
            migrationBaseClass: $d->migrationBaseClass,
            requestDtoBaseClass: $d->requestDtoBaseClass,
            responseDtoInterface: $d->responseDtoInterface,
            repositoryInterface: $d->repositoryInterface,
            responseMapperInterface: $d->responseMapperInterface,
            repositoryImplementation: $d->repositoryImplementation,
            responseMapperImplementation: $d->responseMapperImplementation,
            servicesFactoryClass: $d->servicesFactoryClass,
            paths: $d->paths,
            protectedRouteFilters: $d->protectedRouteFilters,
            conditionalControllerTraits: $traits,
        );
    }

    public function testDefaultConfigDoesNotInjectConditionalTraits(): void
    {
        $generator = new ControllerGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);
        $controllerContent = '';
        foreach ($artifacts as $path => $content) {
            if (str_contains($path, 'ProductController')) {
                $controllerContent = $content;
                break;
            }
        }

        $this->assertNotEmpty($controllerContent);
        $this->assertStringContainsString('protected function resolveDefaultService(): ProductServiceInterface', $controllerContent);
        $this->assertStringContainsString('use dcardenasl\\Ci4ApiCore\\Dto\\SecurityContext;', $controllerContent);
        $this->assertStringContainsString('fn (ProductUpdateRequestDTO $dto, SecurityContext $context): mixed =>', $controllerContent);
        $this->assertStringContainsString('fn (array $dto, SecurityContext $context): mixed =>', $controllerContent);
        $this->assertStringNotContainsString('use HasSlugActions;', $controllerContent);
    }

    public function testConditionalTraitInjectedWhenFieldPresent(): void
    {
        $config = self::defaultsWithTraits(['slug' => 'App\\Traits\\Controllers\\HasSlugActions']);
        $generator = new ControllerGenerator($config);
        $schema = new ResourceSchema(
            resource: 'Article',
            domain: 'Blog',
            route: 'articles',
            fields: [
                new Field(name: 'title', type: 'string'),
                new Field(name: 'slug', type: 'string'),
            ],
        );

        $artifacts = $generator->generate($schema);
        $controllerContent = '';
        foreach ($artifacts as $path => $content) {
            if (str_contains($path, 'ArticleController')) {
                $controllerContent = $content;
                break;
            }
        }

        $this->assertStringContainsString('use App\\Traits\\Controllers\\HasSlugActions;', $controllerContent);
        $this->assertStringContainsString('use HasSlugActions;', $controllerContent);
        $this->assertStringContainsString('protected function resolveDefaultService(): ArticleServiceInterface', $controllerContent);
        $this->assertStringContainsString('fn (ArticleUpdateRequestDTO $dto, SecurityContext $context): mixed =>', $controllerContent);
    }

    public function testConditionalTraitNotInjectedWhenFieldAbsent(): void
    {
        $config = self::defaultsWithTraits(['slug' => 'App\\Traits\\Controllers\\HasSlugActions']);
        $generator = new ControllerGenerator($config);
        $schema = new ResourceSchema(
            resource: 'Tag',
            domain: 'Blog',
            route: 'tags',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);
        $controllerContent = '';
        foreach ($artifacts as $path => $content) {
            if (str_contains($path, 'TagController')) {
                $controllerContent = $content;
                break;
            }
        }

        $this->assertStringNotContainsString('HasSlugActions', $controllerContent);
    }
}
