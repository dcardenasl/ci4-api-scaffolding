<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;

final class TestGeneratorTest extends TestCase
{
    public function testFeatureTestExtendsCi4NativeBaseClassNotConsumerHelper(): void
    {
        // G3 fix: feature stubs must not depend on `Tests\Support\ApiTestCase`,
        // which only ships in ci4-api-starter. Vanilla consumers don't have it.
        $generator = new TestGenerator(ScaffoldingConfig::defaults());

        $featureContent = $this->extract($generator->generate($this->schema()), 'ControllerTest');

        $this->assertStringContainsString('use CodeIgniter\\Test\\CIUnitTestCase;', $featureContent);
        $this->assertStringContainsString('use CodeIgniter\\Test\\DatabaseTestTrait;', $featureContent);
        $this->assertStringContainsString('use CodeIgniter\\Test\\FeatureTestTrait;', $featureContent);
        $this->assertStringContainsString('extends CIUnitTestCase', $featureContent);
        $this->assertStringNotContainsString('Tests\\Support\\ApiTestCase', $featureContent);
        $this->assertStringNotContainsString('clearTestRequestHeaders', $featureContent);
    }

    public function testFeatureTestExpects401WhenJwtAuthFilterIsConfigured(): void
    {
        // Default config protects routes with `jwtauth` — anonymous GET → 401.
        $generator = new TestGenerator(ScaffoldingConfig::defaults());

        $featureContent = $this->extract($generator->generate($this->schema()), 'ControllerTest');

        $this->assertStringContainsString('assertStatus(401)', $featureContent);
        $this->assertStringNotContainsString('assertStatus(404)', $featureContent);
    }

    public function testFeatureTestExpects404WhenNoAuthFilterIsConfigured(): void
    {
        // An open API (e.g. lookup-only kit, or a domain that lifts JWT) — anonymous GET on a
        // missing resource → 404. The smoke test must adapt instead of demanding 401.
        $config = $this->configWithFilters(['throttle']);
        $generator = new TestGenerator($config);

        $featureContent = $this->extract($generator->generate($this->schema()), 'ControllerTest');

        $this->assertStringContainsString('assertStatus(404)', $featureContent);
        $this->assertStringNotContainsString('assertStatus(401)', $featureContent);
    }

    public function testFeatureTestExpects401WhenAppKeyRequiredFilterIsConfigured(): void
    {
        // Server-to-server APIs frequently gate on an X-App-Key — should still be a 401 contract.
        $config = $this->configWithFilters(['appKeyRequired']);
        $generator = new TestGenerator($config);

        $featureContent = $this->extract($generator->generate($this->schema()), 'ControllerTest');

        $this->assertStringContainsString('assertStatus(401)', $featureContent);
    }

    private function schema(): ResourceSchema
    {
        return new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [new Field(name: 'name', type: 'string')],
        );
    }

    /** @param list<string> $filters */
    private function configWithFilters(array $filters): ScaffoldingConfig
    {
        $defaults = ScaffoldingConfig::defaults();

        return new ScaffoldingConfig(
            controllerBaseClass: $defaults->controllerBaseClass,
            serviceBaseClass: $defaults->serviceBaseClass,
            serviceContractInterface: $defaults->serviceContractInterface,
            modelBaseClass: $defaults->modelBaseClass,
            entityBaseClass: $defaults->entityBaseClass,
            migrationBaseClass: $defaults->migrationBaseClass,
            requestDtoBaseClass: $defaults->requestDtoBaseClass,
            responseDtoInterface: $defaults->responseDtoInterface,
            repositoryInterface: $defaults->repositoryInterface,
            responseMapperInterface: $defaults->responseMapperInterface,
            repositoryImplementation: $defaults->repositoryImplementation,
            responseMapperImplementation: $defaults->responseMapperImplementation,
            servicesFactoryClass: $defaults->servicesFactoryClass,
            paths: $defaults->paths,
            protectedRouteFilters: $filters,
            appNamespace: $defaults->appNamespace,
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
