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
 *
 * Also covers patchMainRoutesLoader() / applyLoaderPatch() which inject
 * the api/{version} glob loader into the app's main Routes.php.
 */
final class RouteGeneratorTest extends TestCase
{
    public function testBaseTemplateUsesDefaultFilters(): void
    {
        // defaults() now ships with protectedRouteFilters: [] — no auth by default
        $generator = new RouteGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $artifacts = $generator->generate($schema);
        $content = reset($artifacts);

        $this->assertStringContainsString("['filter' => []]", $content);
        $this->assertStringNotContainsString('jwtauth', $content);
        $this->assertStringNotContainsString('iam.superadmin-access', $content);
        $this->assertStringContainsString(
            "['namespace' => '\\App\\Controllers\\Api\\V1\\Catalog']",
            $content
        );
    }

    public function testBaseTemplateWithExplicitFiltersRendersFilterGroup(): void
    {
        $generator = new RouteGenerator(
            ScaffoldingConfig::defaults(
                protectedRouteFilters: ['jwtauth', 'permission:iam.superadmin-access', 'throttle'],
            ),
        );
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
            $content,
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

    // ─── applyLoaderPatch() tests ─────────────────────────────────────────────

    private function generator(): RouteGenerator
    {
        return new RouteGenerator(ScaffoldingConfig::defaults());
    }

    private function minimalRoutesFile(): string
    {
        return <<<'PHP'
<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
PHP;
    }

    public function testApplyLoaderPatchAppendsMarckerAndGlobLoader(): void
    {
        $patched = $this->generator()->applyLoaderPatch($this->minimalRoutesFile(), 'v1');

        $this->assertStringContainsString('// ci4-api-scaffolding: api/v1 loader start', $patched);
        $this->assertStringContainsString('// ci4-api-scaffolding: api/v1 loader end', $patched);
        $this->assertStringContainsString("\$routes->group('api/v1'", $patched);
        $this->assertStringContainsString("APPPATH . 'Config/Routes/v1'", $patched);
        $this->assertStringContainsString("glob(\$routesDir . '/*.php')", $patched);
        $this->assertStringContainsString("basename(\$file) === 'system.php'", $patched);
    }

    public function testApplyLoaderPatchPreservesExistingContent(): void
    {
        $original = $this->minimalRoutesFile();
        $patched  = $this->generator()->applyLoaderPatch($original, 'v1');

        $this->assertStringContainsString("\$routes->get('/', 'Home::index')", $patched);
    }

    public function testApplyLoaderPatchUsesCorrectVersionInMarkerAndGroup(): void
    {
        $patched = $this->generator()->applyLoaderPatch($this->minimalRoutesFile(), 'v2');

        $this->assertStringContainsString('// ci4-api-scaffolding: api/v2 loader start', $patched);
        $this->assertStringContainsString("\$routes->group('api/v2'", $patched);
        $this->assertStringContainsString("APPPATH . 'Config/Routes/v2'", $patched);
        $this->assertStringNotContainsString('v1', $patched);
    }

    public function testApplyLoaderPatchPreservesHealthBlockFromCoreInstall(): void
    {
        $withHealth = $this->minimalRoutesFile() . "\n\n"
            . "// ci4-api-core: health route start\n"
            . "\$routes->get('health', static function () { return response()->setJSON([]); });\n"
            . "// ci4-api-core: health route end\n";

        $patched = $this->generator()->applyLoaderPatch($withHealth, 'v1');

        $this->assertStringContainsString('// ci4-api-core: health route start', $patched);
        $this->assertStringContainsString('// ci4-api-scaffolding: api/v1 loader start', $patched);
    }
}
