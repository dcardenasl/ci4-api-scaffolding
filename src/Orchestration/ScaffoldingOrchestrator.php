<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Orchestration;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\ControllerGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\CrudGeneratorInterface;
use dcardenasl\Ci4ApiScaffolding\Generators\DtoGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\LanguageGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\MigrationGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\ModelEntityGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\RouteGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\ServiceGenerator;
use dcardenasl\Ci4ApiScaffolding\Generators\TestGenerator;
use RuntimeException;
use Throwable;

/**
 * ScaffoldingOrchestrator
 * Coordinates all modular generators to produce a complete CRUD module.
 *
 * Pure file-tree operation: never writes to anything outside of paths
 * derived from $config->paths. Wiring (Services.php, domain trait) is
 * a separate responsibility handled by ConfigWireman.
 *
 * **Plugin architecture:** pass a custom `$generators` list to override,
 * exclude, or extend the default generator set. Each generator must implement
 * CrudGeneratorInterface. To filter out a built-in by name:
 *
 * ```php
 * $gens = ScaffoldingOrchestrator::defaultGenerators($config);
 * $gens = array_values(array_filter($gens, fn ($g) => $g->name() !== 'tests'));
 * $orchestrator = new ScaffoldingOrchestrator($config, generators: $gens);
 * ```
 */
class ScaffoldingOrchestrator
{
    /** @var list<CrudGeneratorInterface> */
    private array $generators;

    /**
     * Track whether a planned file existed before this run so the caller can show
     * accurate "CREATED:" vs "UPDATED:" labels for upsertable files (notably the
     * domain routes file, which is created once and appended to thereafter).
     *
     * @var array<string, bool>
     */
    private array $preExisting = [];

    /** @var list<string> */
    private array $lastCreatedFiles = [];

    /** @var array<string, string> */
    private array $lastSnapshots = [];

    /**
     * @param list<CrudGeneratorInterface>|null $generators Override the default generator set.
     *                                                       When null, defaultGenerators($config) is used.
     */
    public function __construct(
        private readonly ScaffoldingConfig $config,
        ?array $generators = null
    ) {
        $this->generators = $generators ?? self::defaultGenerators($config);
    }

    /**
     * Return the canonical set of generators in their conventional execution order.
     * Exposed as a static factory so callers can filter or extend the list before
     * passing it to the constructor.
     *
     * @return list<CrudGeneratorInterface>
     */
    public static function defaultGenerators(ScaffoldingConfig $config): array
    {
        return [
            new DtoGenerator($config),
            new MigrationGenerator($config),
            new ModelEntityGenerator($config),
            new ServiceGenerator($config),
            new ControllerGenerator($config),
            new RouteGenerator($config),
            new LanguageGenerator($config),
            new TestGenerator($config),
        ];
    }

    /**
     * Compute the planned (path => content) map without writing anything.
     * Used by --dry-run.
     *
     * @return array<string,string>
     */
    public function plan(ResourceSchema $schema): array
    {
        $result = [];
        foreach ($this->generators as $generator) {
            foreach ($generator->generate($schema) as $path => $content) {
                $result[$path] = $content;
            }
        }
        return $result;
    }

    public function wasExisting(string $path): bool
    {
        return $this->preExisting[$path] ?? false;
    }

    /**
     * @return string[] List of created or updated files
     * @throws ScaffoldConflictException
     */
    public function orchestrate(ResourceSchema $schema): array
    {
        $filesToCreate = $this->plan($schema);

        // Snapshot which paths existed before validation/write so we can label them.
        $this->preExisting = [];
        foreach (array_keys($filesToCreate) as $path) {
            $this->preExisting[$path] = file_exists($path);
        }

        $this->validateFilesDoNotExist($filesToCreate);

        $this->lastCreatedFiles = [];
        $this->lastSnapshots    = [];

        $createdFiles = [];
        $snapshots    = []; // original content of pre-existing files overwritten in this run
        try {
            foreach ($filesToCreate as $path => $content) {
                $this->ensureDirectoryExists(dirname($path));

                if (($this->preExisting[$path] ?? false) && is_readable($path)) {
                    $original = file_get_contents($path);
                    if ($original !== false) {
                        $snapshots[$path] = $original;
                    }
                }

                if (file_put_contents($path, $content) === false) {
                    throw new RuntimeException("Failed to write scaffolded file: {$path}");
                }
                $createdFiles[] = $path;
                $this->lastCreatedFiles = $createdFiles;
                $this->lastSnapshots    = $snapshots;
            }
        } catch (Throwable $e) {
            // Avoid leaving the project in a half-scaffolded state: delete any file
            // we wrote in this run before re-throwing so the user can fix the cause
            // and retry without a ScaffoldConflictException from orphaned files.
            $this->rollback($createdFiles, $snapshots);
            throw $e;
        }

        return $createdFiles;
    }

    /**
     * Roll back all files written by the most recent orchestrate() call.
     * Intended for use by MakeCrud when wire() fails after orchestrate() returns.
     * Idempotent: if the files are already gone, this is a no-op.
     */
    public function rollbackLastRun(): void
    {
        $this->rollback($this->lastCreatedFiles, $this->lastSnapshots);
        $this->lastCreatedFiles = [];
        $this->lastSnapshots    = [];
    }

    /**
     * @param string[]              $createdFiles
     * @param array<string, string> $snapshots    Original content of pre-existing files overwritten in this run
     */
    private function rollback(array $createdFiles, array $snapshots = []): void
    {
        foreach ($createdFiles as $path) {
            if (!file_exists($path)) {
                continue;
            }

            if (isset($snapshots[$path])) {
                // Pre-existing file: restore original content instead of deleting.
                file_put_contents($path, $snapshots[$path]);
            } else {
                @unlink($path);
            }
        }
    }

    /** @param array<string,string> $files */
    private function validateFilesDoNotExist(array $files): void
    {
        $existing = [];
        $caseCollisions = [];

        foreach (array_keys($files) as $path) {
            if ($this->isUpsertableRouteFile($path) && file_exists($path)) {
                continue;
            }

            // Resolve what's actually on disk (case-sensitively) regardless of how the
            // OS answers file_exists(). Distinguishes:
            //  - exact-name match (real overwrite scenario)
            //  - case-insensitive collision (different file on Linux, same file on macOS;
            //    in both cases the user's intent — generate a NEW resource — is broken)
            $existingEntry = $this->resolveSibling($path);

            if ($existingEntry === null) {
                continue;
            }

            $basename = basename($path);
            if ($existingEntry === $basename) {
                $existing[] = $path;
            } else {
                $caseCollisions[$path] = dirname($path) . DIRECTORY_SEPARATOR . $existingEntry;
            }
        }

        if (!empty($existing) || !empty($caseCollisions)) {
            throw new ScaffoldConflictException($existing, $caseCollisions);
        }
    }

    /**
     * Return the actual case-sensitive directory entry that matches the planned path,
     * or null if no entry matches (case-insensitively).
     */
    private function resolveSibling(string $path): ?string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return null;
        }

        $basename = basename($path);
        $basenameLower = strtolower($basename);

        $entries = @scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strtolower($entry) === $basenameLower) {
                return $entry;
            }
        }

        return null;
    }

    private function isUpsertableRouteFile(string $path): bool
    {
        $routesDir = APPPATH . $this->config->paths->routes . '/';

        return str_starts_with($path, $routesDir) && str_ends_with($path, '.php');
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
