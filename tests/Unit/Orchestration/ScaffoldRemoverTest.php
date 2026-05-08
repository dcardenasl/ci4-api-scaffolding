<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestration;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Orchestration\ScaffoldRemover;
use PHPUnit\Framework\TestCase;

final class ScaffoldRemoverTest extends TestCase
{
    public function testPlanIncludesAllRequiredReportKeys(): void
    {
        $schema = new ResourceSchema(
            resource: 'TestThing',
            domain: 'TestDomain',
            route: 'test-things',
            fields: [],
        );

        $report = (new ScaffoldRemover(ScaffoldingConfig::defaults()))->plan($schema);

        $this->assertArrayHasKey('deleted', $report);
        $this->assertArrayHasKey('not_found', $report);
        $this->assertArrayHasKey('routes_cleaned', $report);
        $this->assertArrayHasKey('trait_cleaned', $report);
        $this->assertArrayHasKey('trait_removed', $report);
        $this->assertArrayHasKey('services_cleaned', $report);
        $this->assertArrayHasKey('migration', $report);
    }

    public function testPlanCoversCanonicalGeneratedPaths(): void
    {
        $schema = new ResourceSchema(
            resource: 'Sample',
            domain: 'TestDomain',
            route: 'samples',
            fields: [],
        );

        $report = (new ScaffoldRemover(ScaffoldingConfig::defaults()))->plan($schema);
        $allChecked = array_merge($report['deleted'], $report['not_found']);

        $contract = [
            'Controllers/Api/V1/TestDomain/SampleController.php',
            'Services/TestDomain/SampleService.php',
            'Interfaces/TestDomain/SampleServiceInterface.php',
            'DTO/Request/TestDomain/SampleIndexRequestDTO.php',
            'DTO/Request/TestDomain/SampleCreateRequestDTO.php',
            'DTO/Request/TestDomain/SampleUpdateRequestDTO.php',
            'DTO/Response/TestDomain/SampleResponseDTO.php',
            'Documentation/TestDomain/SampleEndpoints.php',
            'Models/SampleModel.php',
            'Entities/SampleEntity.php',
            'Language/en/Samples.php',
            'Language/es/Samples.php',
        ];

        foreach ($contract as $needle) {
            $found = false;
            foreach ($allChecked as $path) {
                if (str_contains($path, $needle)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Remover does not cover '{$needle}' — a generator was likely added without updating ScaffoldRemover.");
        }
    }
}
