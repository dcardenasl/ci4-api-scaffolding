<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\ControllerGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\DtoGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\LanguageGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\MigrationGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\ModelEntityGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\RouteGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\ServiceGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\MatchesSnapshots;

/**
 * Snapshot tests for all 8 generators.
 *
 * Every test calls a generator against the canonical "Product / Catalog" schema
 * and compares each file's content against a stored snapshot. A change to any
 * template causes an explicit diff in the PR — reviewers see exactly what changed
 * in the generated output, not just the template file.
 *
 * To regenerate snapshots after an intentional template change:
 *   vendor/bin/phpunit --filter SnapshotTest -d --update-snapshots
 *
 * Snapshots live in tests/Unit/Generators/__snapshots__/ and are committed.
 */
final class SnapshotTest extends TestCase
{
    use MatchesSnapshots;

    private ScaffoldingConfig $config;
    private ResourceSchema $schema;

    protected function setUp(): void
    {
        $this->config = ScaffoldingConfig::defaults();
        $this->schema = $this->buildSchema();
    }

    // ──── DTO generator ────────────────────────────────────────────────────

    public function testIndexRequestDtoSnapshot(): void
    {
        $artifacts = (new DtoGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'IndexRequestDTO');
        $this->assertMatchesSnapshot($content);
    }

    public function testCreateRequestDtoSnapshot(): void
    {
        $artifacts = (new DtoGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'CreateRequestDTO');
        $this->assertMatchesSnapshot($content);
    }

    public function testUpdateRequestDtoSnapshot(): void
    {
        $artifacts = (new DtoGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'UpdateRequestDTO');
        $this->assertMatchesSnapshot($content);
    }

    public function testResponseDtoSnapshot(): void
    {
        $artifacts = (new DtoGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'ResponseDTO');
        $this->assertMatchesSnapshot($content);
    }

    // ──── Migration generator ──────────────────────────────────────────────

    public function testMigrationSnapshot(): void
    {
        $generator = new MigrationGenerator($this->config);
        $artifacts = $generator->generate($this->schema);
        // Migration filename includes a timestamp — grab the content regardless of filename.
        $content = array_values($artifacts)[0];
        // Strip the timestamp from the class name so the snapshot is deterministic.
        $stable = preg_replace('/\d{4}-\d{2}-\d{2}-\d{6,12}/', 'TIMESTAMP', $content);
        $this->assertMatchesSnapshot($stable);
    }

    // ──── Model + Entity generator ─────────────────────────────────────────

    public function testEntitySnapshot(): void
    {
        $artifacts = (new ModelEntityGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'Entity');
        $this->assertMatchesSnapshot($content);
    }

    public function testModelSnapshot(): void
    {
        $artifacts = (new ModelEntityGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'Model');
        $this->assertMatchesSnapshot($content);
    }

    // ──── Service generator ────────────────────────────────────────────────

    public function testServiceInterfaceSnapshot(): void
    {
        $artifacts = (new ServiceGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'Interface');
        $this->assertMatchesSnapshot($content);
    }

    public function testServiceSnapshot(): void
    {
        $artifacts = (new ServiceGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'Service.php');
        $this->assertMatchesSnapshot($content);
    }

    // ──── Controller generator ─────────────────────────────────────────────

    public function testControllerSnapshot(): void
    {
        $artifacts = (new ControllerGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'Controller.php');
        $this->assertMatchesSnapshot($content);
    }

    public function testEndpointsSnapshot(): void
    {
        $artifacts = (new ControllerGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'Endpoints');
        $this->assertMatchesSnapshot($content);
    }

    // ──── Route generator ──────────────────────────────────────────────────

    public function testRouteSnapshot(): void
    {
        $artifacts = (new RouteGenerator($this->config))->generate($this->schema);
        $content = array_values($artifacts)[0];
        $this->assertMatchesSnapshot($content);
    }

    // ──── Language generator ───────────────────────────────────────────────

    public function testEnLanguageSnapshot(): void
    {
        $artifacts = (new LanguageGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, '/en/');
        $this->assertMatchesSnapshot($content);
    }

    public function testEsLanguageSnapshot(): void
    {
        $artifacts = (new LanguageGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, '/es/');
        $this->assertMatchesSnapshot($content);
    }

    // ──── Test generator ───────────────────────────────────────────────────

    public function testUnitTestSnapshot(): void
    {
        $artifacts = (new TestGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'ServiceTest');
        $this->assertMatchesSnapshot($content);
    }

    public function testIntegrationTestSnapshot(): void
    {
        $artifacts = (new TestGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'ModelTest');
        $this->assertMatchesSnapshot($content);
    }

    public function testFeatureTestSnapshot(): void
    {
        $artifacts = (new TestGenerator($this->config))->generate($this->schema);
        $content = $this->findArtifact($artifacts, 'ControllerTest');
        $this->assertMatchesSnapshot($content);
    }

    // ──── helpers ──────────────────────────────────────────────────────────

    /**
     * Find an artifact whose path contains $needle and return its content.
     * Throws if zero or multiple matches — keeps test intent explicit.
     *
     * @param array<string,string> $artifacts
     */
    private function findArtifact(array $artifacts, string $needle): string
    {
        $matches = array_filter(
            $artifacts,
            static fn (string $path): bool => str_contains($path, $needle),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertCount(
            1,
            $matches,
            "Expected exactly 1 artifact matching '{$needle}', got " . count($matches)
                . '. Paths: ' . implode(', ', array_keys($artifacts))
        );

        return array_values($matches)[0];
    }

    private function buildSchema(): ResourceSchema
    {
        return new ResourceSchema(
            resource: 'Product',
            domain: 'Catalog',
            route: 'products',
            fields: [
                new Field(name: 'name', type: 'string', required: true, searchable: true),
                new Field(name: 'price', type: 'decimal', required: true, filterable: true, precision: '10,2'),
                new Field(name: 'in_stock', type: 'bool', required: false),
            ],
        );
    }
}
