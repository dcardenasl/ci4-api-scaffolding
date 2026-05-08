<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;

/**
 * Contract for every CRUD generator.
 *
 * Implement this interface to plug a custom generator into the scaffolding
 * pipeline. Pass an array of generator instances to ScaffoldingOrchestrator:
 *
 * ```php
 * $orchestrator = new ScaffoldingOrchestrator(
 *     $config,
 *     generators: [
 *         new DtoGenerator($config),
 *         new ServiceGenerator($config),
 *         new MyCustomEventGenerator($config),
 *     ]
 * );
 * ```
 *
 * Or filter out a built-in generator:
 *
 * ```php
 * $gens = ScaffoldingOrchestrator::defaultGenerators($config);
 * $gens = array_filter($gens, fn ($g) => $g->name() !== 'tests');
 * $orchestrator = new ScaffoldingOrchestrator($config, generators: array_values($gens));
 * ```
 */
interface CrudGeneratorInterface
{
    /**
     * A short, stable identifier used to identify this generator.
     * Conventionally lowercase, e.g. 'dto', 'migration', 'tests'.
     */
    public function name(): string;

    /**
     * Generate all artifacts for the given resource schema.
     *
     * @return array<string, string> Map of absolute file path → file content.
     *                              Return an empty array to skip this generator silently.
     */
    public function generate(ResourceSchema $schema): array;
}
