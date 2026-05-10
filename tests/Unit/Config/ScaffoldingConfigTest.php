<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingPaths;
use PHPUnit\Framework\TestCase;

final class ScaffoldingConfigTest extends TestCase
{
    public function testDefaultsMatchTheStarterKitConvention(): void
    {
        $config = ScaffoldingConfig::defaults();

        $this->assertSame('App', $config->appNamespace);
        $this->assertSame('dcardenasl\\Ci4ApiCore\\Http\\ApiController', $config->controllerBaseClass);
        $this->assertSame('dcardenasl\\Ci4ApiCore\\Services\\BaseCrudService', $config->serviceBaseClass);
        // Default ships with no auth filters so the scaffolding engine works
        // out of the box on any CI4 project. Consumers running ci4-api-starter
        // (or any project with registered jwt/permission/throttle filters) should
        // pass their filter list explicitly via App\Config\Scaffolding::build().
        $this->assertSame([], $config->protectedRouteFilters);
    }

    public function testDefaultsPointFilterableSearchableTraitsAtTheCorePackage(): void
    {
        $config = ScaffoldingConfig::defaults();

        $this->assertSame(
            'dcardenasl\\Ci4ApiCore\\Models\\Traits\\Filterable',
            $config->filterableTraitFqcn,
        );
        $this->assertSame(
            'dcardenasl\\Ci4ApiCore\\Models\\Traits\\Searchable',
            $config->searchableTraitFqcn,
        );
    }

    public function testNamespaceForConvertsSlashesToBackslashes(): void
    {
        $config = ScaffoldingConfig::defaults();

        $this->assertSame('App\\DTO\\Request', $config->namespaceFor('DTO/Request'));
        $this->assertSame('App\\Controllers\\Api\\V1', $config->namespaceFor('Controllers/Api/V1'));
        $this->assertSame('App\\Services', $config->namespaceFor('Services'));
    }

    public function testNamespaceForRespectsCustomAppNamespace(): void
    {
        $config = new ScaffoldingConfig(
            controllerBaseClass: 'Acme\\Http\\BaseController',
            serviceBaseClass: 'Acme\\Services\\BaseService',
            serviceContractInterface: 'Acme\\Services\\Contract',
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
            paths: new ScaffoldingPaths(),
            protectedRouteFilters: ['session'],
            appNamespace: 'Acme',
        );

        $this->assertSame('Acme\\DTO\\Request', $config->namespaceFor('DTO/Request'));
    }

    public function testNamespaceForStripsLeadingAndTrailingSlashes(): void
    {
        $config = ScaffoldingConfig::defaults();

        $this->assertSame('App\\DTO\\Request', $config->namespaceFor('/DTO/Request/'));
    }
}
