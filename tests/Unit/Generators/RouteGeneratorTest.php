<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingPaths;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\RouteGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the second main promise of Phase 2: the route file's protected
 * group filter list comes from ScaffoldingConfig, not from a hardcoded
 * permission. Consumers using a different authz model (session-based,
 * OAuth scopes, anything) can swap the filter without touching the
 * package.
 */
final class RouteGeneratorTest extends TestCase
{
    public function testBaseTemplateUsesDefaultFilters(): void
    {
        $generator = new RouteGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);
        $content = reset($artifacts);

        $this->assertStringContainsString(
            "['filter' => ['jwtauth', 'permission:iam.superadmin-access', 'throttle']]",
            $content
        );
        $this->assertStringContainsString(
            "['namespace' => '\\App\\Controllers\\Api\\V1\\Catalog']",
            $content
        );
    }

    public function testBaseTemplateHonorsCustomFiltersAndNamespace(): void
    {
        $config = new ScaffoldingConfig(
            controllerBaseClass: 'Acme\\Http\\BaseApiController',
            serviceBaseClass: 'Acme\\Services\\Crud\\AbstractCrud',
            serviceContractInterface: 'Acme\\Services\\CrudContract',
            modelBaseClass: 'Acme\\Models\\Base',
            entityBaseClass: 'CodeIgniter\\Entity\\Entity',
            migrationBaseClass: 'CodeIgniter\\Database\\Migration',
            requestDtoBaseClass: 'Acme\\DTO\\BaseRequest',
            responseDtoInterface: 'Acme\\DTO\\Contract',
            repositoryInterface: 'Acme\\Repo\\Contract',
            responseMapperInterface: 'Acme\\Mappers\\Contract',
            repositoryImplementation: 'Acme\\Repo\\GenericRepository',
            responseMapperImplementation: 'Acme\\Mappers\\DtoResponseMapper',
            servicesFactoryClass: 'Config\\Services',
            paths: new ScaffoldingPaths(
                controllers: 'Http/Controllers/Api',
                routes: 'Config/Routes/api',
            ),
            protectedRouteFilters: ['session', 'permission:catalog.write'],
            appNamespace: 'Acme',
        );

        $generator = new RouteGenerator($config);
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);

        $expectedPath = APPPATH . 'Config/Routes/api/catalog.php';
        $this->assertArrayHasKey($expectedPath, $artifacts);

        $content = $artifacts[$expectedPath];

        $this->assertStringContainsString(
            "['filter' => ['session', 'permission:catalog.write']]",
            $content
        );
        $this->assertStringContainsString(
            "['namespace' => '\\Acme\\Http\\Controllers\\Api\\Catalog']",
            $content
        );
        $this->assertStringNotContainsString('iam.superadmin-access', $content);
        $this->assertStringNotContainsString('jwtauth', $content);
    }
}
