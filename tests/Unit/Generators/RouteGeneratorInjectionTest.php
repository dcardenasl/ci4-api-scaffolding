<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\RouteGenerator;
use PHPUnit\Framework\TestCase;

final class RouteGeneratorInjectionTest extends TestCase
{
    public function testInjectRouteFailsWithVoidClosure(): void
    {
        $generator = new RouteGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        // Content mimicking PHP CS Fixer formatting with : void
        $content = <<<'PHP'
<?php

$routes->group('api', ['filter' => []], function($routes): void {
    // Existing routes
});
PHP;

        // This should currently fail because the regex expects no ': void'
        // The generator fallback is to append to the end, but the goal is to inject INSIDE
        $method = new \ReflectionMethod(RouteGenerator::class, 'injectRoute');
        $method->setAccessible(true);
        $result = $method->invoke($generator, $schema, $content);

        $this->assertStringContainsString('$routes->get(\'products\'', $result);
        $this->assertStringContainsString('// Existing routes', $result);

        // Check if it injected INSIDE the group (between the braces)
        $this->assertStringContainsString('function ($routes): void {', $result);
        $this->assertStringContainsString('$routes->get(\'products\', \'ProductController::index\');', $result);
        $this->assertStringContainsString('});', $result);
    }

    public function testWildcardRouteIsReorderedToEnd(): void
    {
        $generator = new RouteGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );

        $content = <<<'PHP'
<?php

$routes->group('api', ['filter' => []], function($routes): void {
    $routes->get('custom/(:segment)', 'CustomController::show/$1');
});
PHP;

        $method = new \ReflectionMethod(RouteGenerator::class, 'injectRoute');
        $method->setAccessible(true);
        $result = $method->invoke($generator, $schema, $content);

        // Verify that custom/(:segment) (wildcard) is placed AFTER the newly injected products (literal) route
        $posSegment = strpos($result, "custom/(:segment)");
        $posProducts = strpos($result, "products");

        $this->assertNotFalse($posSegment);
        $this->assertNotFalse($posProducts);
        $this->assertGreaterThan($posProducts, $posSegment, "Wildcard route must be placed after literal route.");
    }
}
