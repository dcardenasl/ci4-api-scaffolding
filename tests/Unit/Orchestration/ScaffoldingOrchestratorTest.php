<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestration;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\CrudGeneratorInterface;
use dcardenasl\Ci4ApiScaffolding\Orchestration\ScaffoldConflictException;
use dcardenasl\Ci4ApiScaffolding\Orchestration\ScaffoldingOrchestrator;
use PHPUnit\Framework\TestCase;

final class ScaffoldingOrchestratorTest extends TestCase
{
    public function testExceptionSurfacesCaseInsensitiveCollisionsSeparately(): void
    {
        $exact = ['/some/path/Foo.php'];
        $caseInsensitive = ['/some/path/APIKey.php' => '/some/path/ApiKey.php'];

        $exception = new ScaffoldConflictException($exact, $caseInsensitive);
        $message = $exception->getMessage();

        $this->assertStringContainsString('case-insensitive', $message);
        $this->assertStringContainsString('APIKey.php', $message);
        $this->assertStringContainsString('ApiKey.php', $message);
        $this->assertStringContainsString("'ApiKey' instead of 'APIKey'", $message);
    }

    public function testOrchestratorExposesPlanAndWasExisting(): void
    {
        $reflection = new \ReflectionClass(ScaffoldingOrchestrator::class);
        $this->assertTrue($reflection->hasMethod('plan'), 'Orchestrator must expose plan() for --dry-run support');
        $this->assertTrue($reflection->hasMethod('wasExisting'), 'Orchestrator must expose wasExisting() so callers can label CREATED vs UPDATED');
    }

    public function testRollbackRestoresPreExistingFileInsteadOfDeleting(): void
    {
        $dir = sys_get_temp_dir() . '/scaffold_rollback_test_' . uniqid('', true);
        mkdir($dir, 0775, true);

        $existingPath = $dir . '/existing.php';
        $originalContent = '<?php // original';
        file_put_contents($existingPath, $originalContent);

        $orchestrator = new ScaffoldingOrchestrator(ScaffoldingConfig::defaults());
        $rollback = new \ReflectionMethod($orchestrator, 'rollback');
        $rollback->setAccessible(true);

        file_put_contents($existingPath, '<?php // overwritten by scaffold');
        $rollback->invoke($orchestrator, [$existingPath], [$existingPath => $originalContent]);

        $this->assertFileExists($existingPath, 'Pre-existing file must not be deleted on rollback');
        $this->assertSame($originalContent, file_get_contents($existingPath), 'Pre-existing file must be restored to its original content');

        unlink($existingPath);
        rmdir($dir);
    }

    public function testRollbackDeletesNewFileWithNoSnapshot(): void
    {
        $dir = sys_get_temp_dir() . '/scaffold_rollback_test_' . uniqid('', true);
        mkdir($dir, 0775, true);

        $newPath = $dir . '/new.php';
        file_put_contents($newPath, '<?php // new file');

        $orchestrator = new ScaffoldingOrchestrator(ScaffoldingConfig::defaults());
        $rollback = new \ReflectionMethod($orchestrator, 'rollback');
        $rollback->setAccessible(true);

        $rollback->invoke($orchestrator, [$newPath], []);

        $this->assertFileDoesNotExist($newPath, 'New file must be deleted on rollback');

        rmdir($dir);
    }

    public function testRollbackLastRunCleansUpAfterSuccessfulOrchestrate(): void
    {
        // After a successful orchestrate(), rollbackLastRun() must delete all written files.
        // We can't invoke the full orchestrator (it needs APPPATH etc.), but we can verify
        // rollbackLastRun() via the orchestrator's public interface by writing files manually
        // and asserting they are removed.
        $dir = sys_get_temp_dir() . '/scaffold_rollback_last_' . uniqid('', true);
        mkdir($dir, 0775, true);

        $file1 = $dir . '/file1.php';
        $file2 = $dir . '/file2.php';
        file_put_contents($file1, '<?php // 1');
        file_put_contents($file2, '<?php // 2');

        $orchestrator = new ScaffoldingOrchestrator(ScaffoldingConfig::defaults());

        // Inject lastCreatedFiles via reflection to simulate what orchestrate() would populate.
        $lastCreated = new \ReflectionProperty($orchestrator, 'lastCreatedFiles');
        $lastCreated->setAccessible(true);
        $lastCreated->setValue($orchestrator, [$file1, $file2]);

        $lastSnapshots = new \ReflectionProperty($orchestrator, 'lastSnapshots');
        $lastSnapshots->setAccessible(true);
        $lastSnapshots->setValue($orchestrator, []);

        $orchestrator->rollbackLastRun();

        $this->assertFileDoesNotExist($file1, 'File 1 must be deleted by rollbackLastRun()');
        $this->assertFileDoesNotExist($file2, 'File 2 must be deleted by rollbackLastRun()');

        // Properties must be cleared after rollback.
        $this->assertSame([], $lastCreated->getValue($orchestrator));
        $this->assertSame([], $lastSnapshots->getValue($orchestrator));

        rmdir($dir);
    }

    public function testRollbackLastRunIsIdempotent(): void
    {
        // Calling rollbackLastRun() a second time after files are gone must not throw.
        $dir  = sys_get_temp_dir() . '/scaffold_idempotent_' . uniqid('', true);
        mkdir($dir, 0775, true);

        $file = $dir . '/file.php';
        file_put_contents($file, '<?php // tmp');

        $orchestrator = new ScaffoldingOrchestrator(ScaffoldingConfig::defaults());

        $lastCreated = new \ReflectionProperty($orchestrator, 'lastCreatedFiles');
        $lastCreated->setAccessible(true);
        $lastCreated->setValue($orchestrator, [$file]);

        $lastSnapshots = new \ReflectionProperty($orchestrator, 'lastSnapshots');
        $lastSnapshots->setAccessible(true);
        $lastSnapshots->setValue($orchestrator, []);

        $orchestrator->rollbackLastRun(); // first call — deletes file, clears state
        $orchestrator->rollbackLastRun(); // second call — no-op, must not throw

        $this->assertFileDoesNotExist($file);
        rmdir($dir);
    }

    public function testDefaultGeneratorsReturnsEightBuiltins(): void
    {
        $config = ScaffoldingConfig::defaults();
        $generators = ScaffoldingOrchestrator::defaultGenerators($config);

        $this->assertCount(8, $generators);

        $names = array_map(fn (CrudGeneratorInterface $g) => $g->name(), $generators);
        foreach (['dto', 'migration', 'model', 'service', 'controller', 'route', 'language', 'tests'] as $expected) {
            $this->assertContains($expected, $names, "Built-in generator '{$expected}' is missing");
        }
    }

    public function testCustomGeneratorSetIsRespected(): void
    {
        $config = ScaffoldingConfig::defaults();

        $spy = new class () implements CrudGeneratorInterface {
            public bool $called = false;
            public function name(): string
            {
                return 'spy';
            }
            public function generate(ResourceSchema $schema): array
            {
                $this->called = true;
                return [];
            }
        };

        $orchestrator = new ScaffoldingOrchestrator($config, generators: [$spy]);
        $orchestrator->plan(new ResourceSchema(
            resource: 'Foo',
            domain: 'Bar',
            route: 'foos',
            fields: [],
        ));

        $this->assertTrue($spy->called, 'Custom generator must be invoked by orchestrator');
    }

    public function testExcludingGeneratorByNameOmitsItsArtifacts(): void
    {
        $config = ScaffoldingConfig::defaults();
        $gens = ScaffoldingOrchestrator::defaultGenerators($config);

        // Remove the 'tests' generator.
        $filtered = array_values(array_filter($gens, fn ($g) => $g->name() !== 'tests'));
        $this->assertCount(7, $filtered);

        $orchestrator = new ScaffoldingOrchestrator($config, generators: $filtered);
        $plan = $orchestrator->plan(new ResourceSchema(
            resource: 'Widget',
            domain: 'Store',
            route: 'widgets',
            fields: [],
        ));

        foreach (array_keys($plan) as $path) {
            $this->assertStringNotContainsString('Test', basename($path), 'Test artifacts must be absent when tests generator is excluded');
        }
    }
}
