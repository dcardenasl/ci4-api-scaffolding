<?php

declare(strict_types=1);

namespace Tests\Unit\Wiring;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Wiring\ConfigWireman;
use dcardenasl\Ci4ApiScaffolding\Wiring\WiringFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract that:
 * 1. previewWiring() returns snippets without touching disk (the --no-wire path).
 * 2. The generated factory snippet honors every FQCN from ScaffoldingConfig
 *    (no hardcoded App\Repositories\GenericRepository, no hardcoded
 *    App\Services\Core\Mappers\DtoResponseMapper).
 *
 * Acceptance for v0.1: wire() (write-through) is exercised in Phase 4 against
 * the real ci4-api-starter Services.php — no need to simulate the file system
 * here.
 */
final class ConfigWiremanTest extends TestCase
{
    public function testPreviewWiringDoesNotTouchDisk(): void
    {
        $config = ScaffoldingConfig::defaults();
        $wireman = new ConfigWireman($config);
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $traitFile = APPPATH . 'Config/CatalogDomainServices.php';
        $servicesFile = APPPATH . 'Config/Services.php';
        $existedBefore = ['trait' => file_exists($traitFile), 'services' => file_exists($servicesFile)];

        $preview = $wireman->previewWiring($schema);

        $existedAfter = ['trait' => file_exists($traitFile), 'services' => file_exists($servicesFile)];
        $this->assertSame($existedBefore, $existedAfter, 'previewWiring() must not create files');

        $this->assertArrayHasKey('trait_file', $preview);
        $this->assertArrayHasKey('trait_content', $preview);
        $this->assertArrayHasKey('service_method', $preview);
        $this->assertArrayHasKey('services_register', $preview);

        $this->assertSame($traitFile, $preview['trait_file']);
        $this->assertStringContainsString('trait CatalogDomainServices', $preview['trait_content']);
        $this->assertStringContainsString("require_once __DIR__ . '/CatalogDomainServices.php';", $preview['services_register']);
    }

    public function testServiceFactoryHonorsCustomConfig(): void
    {
        // A consumer with a different namespace, repo impl, and mapper impl.
        $config = new ScaffoldingConfig(
            controllerBaseClass: 'Acme\\Http\\BaseApiController',
            serviceBaseClass: 'Acme\\Services\\Core\\AbstractCrud',
            serviceContractInterface: 'Acme\\Services\\Core\\CrudContract',
            modelBaseClass: 'Acme\\Models\\Base',
            entityBaseClass: 'CodeIgniter\\Entity\\Entity',
            migrationBaseClass: 'CodeIgniter\\Database\\Migration',
            requestDtoBaseClass: 'Acme\\DTO\\BaseRequest',
            responseDtoInterface: 'Acme\\DTO\\Contract',
            repositoryInterface: 'Acme\\Persistence\\RepoContract',
            responseMapperInterface: 'Acme\\Mappers\\MapperContract',
            repositoryImplementation: 'Acme\\Persistence\\GenericRepo',
            responseMapperImplementation: 'Acme\\Mappers\\DtoResponseMapper',
            servicesFactoryClass: 'Config\\Services',
            paths: $config_default = (\dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig::defaults())->paths,
            protectedRouteFilters: ['acme-auth'],
            appNamespace: 'Acme',
        );

        $wireman = new ConfigWireman($config);
        $schema = new ResourceSchema(
            resource: 'Order',
            domain: 'Sales',
            route: 'orders',
            fields: [new Field(name: 'total', type: 'decimal')],
        );

        $preview = $wireman->previewWiring($schema);
        $snippet = $preview['service_method'];

        // Custom FQCNs are honored.
        $this->assertStringContainsString('\\Acme\\Mappers\\MapperContract', $snippet);
        $this->assertStringContainsString('\\Acme\\Mappers\\DtoResponseMapper', $snippet);
        $this->assertStringContainsString('\\Acme\\Persistence\\GenericRepo', $snippet);
        $this->assertStringContainsString('\\Acme\\Models\\OrderModel', $snippet);
        $this->assertStringContainsString('\\Acme\\Interfaces\\Sales\\OrderServiceInterface', $snippet);
        $this->assertStringContainsString('\\Acme\\Services\\Sales\\OrderService', $snippet);
        $this->assertStringContainsString('\\Acme\\DTO\\Response\\Sales\\OrderResponseDTO', $snippet);

        // Zero leakage from the App\... defaults.
        $this->assertStringNotContainsString('\\App\\', $snippet);
    }

    public function testWireSucceedsAgainstVanillaCi4ServicesFile(): void
    {
        // Regression: G1 — a fresh CI4 install ships Config/Services.php with the
        // shape `class Services extends BaseService { ... }`, no prior
        // require_once for sibling domain traits, no prior `use ...DomainServices;`.
        // Pre-fix this layout fell through both injection regexes and tripped
        // verifyMainServicesRegistration. The fallback anchors must now make
        // wire() succeed on this layout.
        $configDir = APPPATH . 'Config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o777, true);
        }

        $servicesFile = $configDir . '/Services.php';
        file_put_contents(
            $servicesFile,
            "<?php\n\nnamespace Config;\n\nuse CodeIgniter\\Config\\BaseService;\n\nclass Services extends BaseService\n{\n    // intentionally empty\n}\n",
        );

        $traitFile = $configDir . '/VanillaDomainServices.php';
        @unlink($traitFile);

        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Widget',
            domain: 'Vanilla',
            route: 'widgets',
            fields: [new Field(name: 'name', type: 'string')],
        );

        try {
            $wireman->wire($schema);
            $updated = (string) file_get_contents($servicesFile);

            $this->assertStringContainsString(
                "require_once __DIR__ . '/VanillaDomainServices.php';",
                $updated,
                'Fallback must inject require_once before class Services',
            );
            $this->assertStringContainsString(
                'use VanillaDomainServices;',
                $updated,
                'Fallback must inject the trait inside the class body',
            );
            $this->assertFileExists($traitFile, 'Domain trait file must be created on disk');
        } finally {
            @unlink($traitFile);
            @unlink($servicesFile);
        }
    }

    public function testWireThrowsWhenServicesFileHasNoServicesClass(): void
    {
        // A truly malformed Services.php (no `class Services extends X` declaration
        // at all) is still a hard failure: the fallback anchor cannot guess where
        // to inject. verifyMainServicesRegistration must surface the problem with
        // the manual snippet so the consumer can recover.
        $configDir = APPPATH . 'Config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o777, true);
        }

        $servicesFile = $configDir . '/Services.php';
        file_put_contents($servicesFile, "<?php\nnamespace Config;\n// the file lost its Services class somehow\n");

        $traitFile = $configDir . '/MisalignedDomainServices.php';
        @unlink($traitFile);

        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Widget',
            domain: 'Misaligned',
            route: 'widgets',
            fields: [new Field(name: 'name', type: 'string')],
        );

        try {
            $wireman->wire($schema);
            $this->fail('Expected WiringFailedException to be thrown for a non-conforming Services.php.');
        } catch (WiringFailedException $e) {
            $this->assertStringContainsString('Misaligned', $e->getMessage());
            $description = $e->describe();
            $this->assertStringContainsString('Services.php', $description);
            $this->assertStringContainsString('use MisalignedDomainServices;', $description);
        } finally {
            @unlink($traitFile);
            @unlink($servicesFile);
        }
    }

    public function testServiceFactoryWithDefaultsMatchesHistoricalShape(): void
    {
        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $snippet = $wireman->previewWiring($schema)['service_method'];

        $this->assertStringContainsString('public static function productService(', $snippet);
        $this->assertStringContainsString('public static function productResponseMapper(', $snippet);
        $this->assertStringContainsString(': \\App\\Interfaces\\Catalog\\ProductServiceInterface', $snippet);
        $this->assertStringContainsString(': \\dcardenasl\\Ci4ApiCore\\Mappers\\ResponseMapperInterface', $snippet);
        $this->assertStringContainsString('new \\App\\Repositories\\GenericRepository(model(\\App\\Models\\ProductModel::class))', $snippet);
        $this->assertStringContainsString('return new \\App\\Services\\Core\\Mappers\\DtoResponseMapper(', $snippet);
        $this->assertStringContainsString('\\App\\DTO\\Response\\Catalog\\ProductResponseDTO::class', $snippet);
    }

    public function testWireSucceedsWithPhp8AttributeOnServicesClass(): void
    {
        // Regression S-01: the old preg_replace anchor `class\s+Services\s+extends\s+\w+`
        // does not match when a PHP 8 attribute sits between the docblock and the class
        // keyword. The AST-based approach is immune to this layout.
        $configDir = APPPATH . 'Config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o777, true);
        }

        $servicesFile = $configDir . '/Services.php';
        file_put_contents(
            $servicesFile,
            "<?php\n\nnamespace Config;\n\nuse CodeIgniter\\Config\\BaseService;\n\n#[\\SomeAttribute]\nclass Services extends BaseService\n{\n    // intentionally empty\n}\n",
        );

        $traitFile = $configDir . '/AttrTestDomainServices.php';
        @unlink($traitFile);

        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema  = new ResourceSchema(
            resource: 'Widget',
            domain: 'AttrTest',
            route: 'widgets',
            fields: [new Field(name: 'name', type: 'string')],
        );

        try {
            $wireman->wire($schema);
            $updated = (string) file_get_contents($servicesFile);

            $this->assertStringContainsString(
                "require_once __DIR__ . '/AttrTestDomainServices.php';",
                $updated,
                'require_once must be injected even when class has a PHP 8 attribute',
            );
            $this->assertStringContainsString(
                'use AttrTestDomainServices;',
                $updated,
                'use Trait must be injected inside the class body',
            );
        } finally {
            @unlink($traitFile);
            @unlink($servicesFile);
        }
    }

    public function testWireAppendsAfterExistingSiblingRequire(): void
    {
        // When Services.php already has a require_once for a sibling domain,
        // the new require_once must be inserted AFTER the existing one (not before).
        $configDir = APPPATH . 'Config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o777, true);
        }

        $servicesFile   = $configDir . '/Services.php';
        $catalogTrait   = $configDir . '/CatalogDomainServices.php';
        $inventoryTrait = $configDir . '/InventoryDomainServices.php';

        file_put_contents(
            $servicesFile,
            "<?php\n\nnamespace Config;\n\nuse CodeIgniter\\Config\\BaseService;\n\n"
            . "require_once __DIR__ . '/CatalogDomainServices.php';\n\n"
            . "class Services extends BaseService\n{\n    use CatalogDomainServices;\n}\n",
        );
        file_put_contents($catalogTrait, "<?php\ndeclare(strict_types=1);\nnamespace Config;\ntrait CatalogDomainServices\n{\n}\n");
        @unlink($inventoryTrait);

        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema  = new ResourceSchema(
            resource: 'Stock',
            domain: 'Inventory',
            route: 'stocks',
            fields: [new Field(name: 'qty', type: 'integer')],
        );

        try {
            $wireman->wire($schema);
            $updated = (string) file_get_contents($servicesFile);

            $catalogPos   = strpos($updated, "require_once __DIR__ . '/CatalogDomainServices.php';");
            $inventoryPos = strpos($updated, "require_once __DIR__ . '/InventoryDomainServices.php';");

            $this->assertNotFalse($catalogPos, 'Original Catalog require_once must still exist');
            $this->assertNotFalse($inventoryPos, 'New Inventory require_once must be injected');
            $this->assertGreaterThan(
                $catalogPos,
                $inventoryPos,
                'Inventory require_once must appear AFTER the Catalog require_once',
            );
        } finally {
            @unlink($inventoryTrait);
            @unlink($catalogTrait);
            @unlink($servicesFile);
        }
    }

    public function testWireInjectsIntoTraitWithClosingBraceInHeredoc(): void
    {
        // Regression S-01: the old strrpos('}') picked the wrong closing brace when
        // the trait already contained a method with a heredoc ending in `}`.
        // wire() skips the domain-trait-creation step when the trait already exists,
        // so pre-creating it with a heredoc-containing method exercises the injection path.
        $configDir = APPPATH . 'Config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o777, true);
        }

        $domain    = 'HeredocTest';
        $traitFile = $configDir . "/{$domain}DomainServices.php";

        // Write a trait with a method that returns a heredoc ending in `}`
        file_put_contents(
            $traitFile,
            "<?php\ndeclare(strict_types=1);\nnamespace Config;\ntrait {$domain}DomainServices\n{\n"
            . "    public static function getTemplate(): string\n    {\n"
            . "        return <<<EOT\n        {\n        }\n        EOT;\n    }\n}\n",
        );

        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema  = new ResourceSchema(
            resource: 'Widget',
            domain: $domain,
            route: 'widgets',
            fields: [new Field(name: 'name', type: 'string')],
        );

        try {
            // Trait exists → wire() skips registration and goes straight to injection.
            $wireman->wire($schema);
            $updated = (string) file_get_contents($traitFile);

            $this->assertStringContainsString(
                'public static function widgetService(',
                $updated,
                'Factory method must be injected correctly despite heredoc containing `}`',
            );
            $this->assertStringContainsString(
                'public static function getTemplate(',
                $updated,
                'Original method must remain intact',
            );
        } finally {
            @unlink($traitFile);
        }
    }

    public function testWireIsIdempotentForServiceFactory(): void
    {
        // Calling wire() twice on a trait that already has the factory method
        // must not duplicate it — the str_contains early-return guard must work.
        $configDir = APPPATH . 'Config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o777, true);
        }

        $domain    = 'IdempotentTest';
        $traitFile = $configDir . "/{$domain}DomainServices.php";
        file_put_contents(
            $traitFile,
            "<?php\ndeclare(strict_types=1);\nnamespace Config;\ntrait {$domain}DomainServices\n{\n}\n",
        );

        $wireman = new ConfigWireman(ScaffoldingConfig::defaults());
        $schema  = new ResourceSchema(
            resource: 'Widget',
            domain: $domain,
            route: 'widgets',
            fields: [new Field(name: 'name', type: 'string')],
        );

        try {
            $wireman->wire($schema); // first call — injects
            $wireman->wire($schema); // second call — must be a no-op for the factory

            $updated = (string) file_get_contents($traitFile);
            $count   = substr_count($updated, 'public static function widgetService(');

            $this->assertSame(1, $count, 'widgetService() must appear exactly once after two wire() calls');
        } finally {
            @unlink($traitFile);
        }
    }
}
