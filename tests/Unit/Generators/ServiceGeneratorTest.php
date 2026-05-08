<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingPaths;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\ServiceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract that ServiceGenerator honors every FQCN it receives
 * from ScaffoldingConfig, with no hardcoded `App\…` references in the
 * rendered output. This is the litmus test for Phase 2's main promise:
 * a consumer with a non-`App` namespace and non-default base classes
 * can use the package without forking it.
 */
final class ServiceGeneratorTest extends TestCase
{
    public function testOutputUsesDefaultsWhenConfigDefaultsAreUsed(): void
    {
        $generator = new ServiceGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);

        $servicePath = APPPATH . 'Services/Catalog/ProductService.php';
        $this->assertArrayHasKey($servicePath, $artifacts);

        $service = $artifacts[$servicePath];
        $this->assertStringContainsString('namespace App\\Services\\Catalog;', $service);
        $this->assertStringContainsString('use dcardenasl\\Ci4ApiCore\\Services\\BaseCrudService;', $service);
        $this->assertStringContainsString('extends BaseCrudService', $service);
    }

    public function testOutputHonorsCustomConfig(): void
    {
        // A consumer with a different namespace, different paths, different base classes.
        $config = new ScaffoldingConfig(
            controllerBaseClass: 'Acme\\Http\\BaseApiController',
            serviceBaseClass: 'Acme\\App\\Services\\Crud\\AbstractCrud',
            serviceContractInterface: 'Acme\\App\\Contracts\\CrudContract',
            modelBaseClass: 'Acme\\Persistence\\Model',
            entityBaseClass: 'CodeIgniter\\Entity\\Entity',
            migrationBaseClass: 'CodeIgniter\\Database\\Migration',
            requestDtoBaseClass: 'Acme\\App\\DTO\\AbstractRequest',
            responseDtoInterface: 'Acme\\App\\DTO\\TransferContract',
            repositoryInterface: 'Acme\\App\\Persistence\\RepoContract',
            responseMapperInterface: 'Acme\\App\\Mappers\\MapperContract',
            repositoryImplementation: 'Acme\\App\\Persistence\\GenericRepo',
            responseMapperImplementation: 'Acme\\App\\Mappers\\DtoMapper',
            servicesFactoryClass: 'Config\\Services',
            paths: new ScaffoldingPaths(
                services: 'App/Domain',
                interfaces: 'App/Contracts',
            ),
            protectedRouteFilters: ['acme-auth'],
            appNamespace: 'Acme',
        );

        $generator = new ServiceGenerator($config);
        $schema = new ResourceSchema(
            resource: 'Order',
            domain: 'Sales',
            route: 'orders',
            fields: [new Field(name: 'total', type: 'decimal')],
        );

        $artifacts = $generator->generate($schema);

        $servicePath = APPPATH . 'App/Domain/Sales/OrderService.php';
        $interfacePath = APPPATH . 'App/Contracts/Sales/OrderServiceInterface.php';
        $this->assertArrayHasKey($servicePath, $artifacts);
        $this->assertArrayHasKey($interfacePath, $artifacts);

        $service = $artifacts[$servicePath];

        // Namespace + use statements come from the custom config.
        $this->assertStringContainsString('namespace Acme\\App\\Domain\\Sales;', $service);
        $this->assertStringContainsString('use Acme\\App\\Persistence\\RepoContract;', $service);
        $this->assertStringContainsString('use Acme\\App\\Mappers\\MapperContract;', $service);
        $this->assertStringContainsString('use Acme\\App\\Contracts\\Sales\\OrderServiceInterface;', $service);
        $this->assertStringContainsString('use Acme\\App\\Services\\Crud\\AbstractCrud;', $service);

        // Class declaration uses short names of the custom base classes.
        $this->assertStringContainsString('extends AbstractCrud', $service);
        $this->assertStringContainsString('implements OrderServiceInterface', $service);

        // Constructor params are typed by the custom interface short names.
        $this->assertStringContainsString('RepoContract $orderRepository', $service);
        $this->assertStringContainsString('MapperContract $responseMapper', $service);

        // No leakage of the `App\…` defaults.
        $this->assertStringNotContainsString('App\\Services\\Core', $service);
        $this->assertStringNotContainsString('BaseCrudService', $service);

        $interface = $artifacts[$interfacePath];
        $this->assertStringContainsString('namespace Acme\\App\\Contracts\\Sales;', $interface);
        $this->assertStringContainsString('use Acme\\App\\Contracts\\CrudContract;', $interface);
        $this->assertStringContainsString('extends CrudContract', $interface);
    }
}
