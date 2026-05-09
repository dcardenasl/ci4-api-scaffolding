<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;

/**
 * Validate that the consumer project has a working `Config\Scaffolding`.
 *
 * Read-only diagnostic — never writes or patches files. Use it after
 * copying `vendor/dcardenasl/ci4-api-scaffolding/docs/Scaffolding.php.example`
 * into `app/Config/Scaffolding.php` to verify the FQCNs declared in the
 * config actually resolve to loadable classes/interfaces.
 */
class ScaffoldCheck extends BaseCommand
{
    protected $group       = 'ci4-api-scaffolding';
    protected $name        = 'scaffold:check';
    protected $description = 'Verify the consumer\'s Config\\Scaffolding is present and points at loadable classes.';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): void
    {
        CLI::write('');
        CLI::write('ci4-api-scaffolding check', 'yellow');
        CLI::write(str_repeat('─', 64));
        CLI::newLine();

        $configPath = APPPATH . 'Config/Scaffolding.php';

        if (! file_exists($configPath)) {
            CLI::write('  ' . CLI::color('✗', 'red') . '  app/Config/Scaffolding.php not found');
            CLI::newLine();
            CLI::write('  Copy the bundled example to bootstrap a default config:', 'cyan');
            CLI::write('    cp vendor/dcardenasl/ci4-api-scaffolding/docs/Scaffolding.php.example app/Config/Scaffolding.php');
            CLI::newLine();
            exit(1);
        }

        CLI::write('  ' . CLI::color('✓', 'green') . '  app/Config/Scaffolding.php exists');

        $config = $this->loadConfig();
        if ($config === null) {
            exit(1);
        }

        $resolved = $this->buildScaffoldingConfig($config);
        if ($resolved === null) {
            exit(1);
        }

        $failures = $this->validateFqcns($resolved);

        CLI::newLine();
        if ($failures > 0) {
            CLI::error("Found {$failures} unresolved class reference(s) in Config\\Scaffolding.");
            CLI::write('Fix them in app/Config/Scaffolding.php (typo, missing dependency, or wrong namespace).', 'yellow');
            CLI::newLine();
            exit(1);
        }

        CLI::write(CLI::color('Scaffolding configuration is valid.', 'green'));
        CLI::newLine();
    }

    private function loadConfig(): ?BaseScaffoldingConfig
    {
        if (! function_exists('config')) {
            CLI::error('CI4 config() helper is unavailable — is this command being run from a CodeIgniter 4 project?');
            CLI::newLine();

            return null;
        }

        $config = config('Scaffolding');

        if (! $config instanceof BaseScaffoldingConfig) {
            CLI::write('  ' . CLI::color('✗', 'red') . '  Config\\Scaffolding does not extend BaseScaffoldingConfig');

            return null;
        }

        CLI::write('  ' . CLI::color('✓', 'green') . '  Config\\Scaffolding extends BaseScaffoldingConfig');

        return $config;
    }

    private function buildScaffoldingConfig(BaseScaffoldingConfig $config): ?ScaffoldingConfig
    {
        try {
            $built = $config->build();
        } catch (\Throwable $e) {
            CLI::write('  ' . CLI::color('✗', 'red') . '  Config\\Scaffolding::build() threw: ' . $e->getMessage());

            return null;
        }

        CLI::write('  ' . CLI::color('✓', 'green') . '  Config\\Scaffolding::build() returned ScaffoldingConfig');

        return $built;
    }

    private function validateFqcns(ScaffoldingConfig $config): int
    {
        $failures = 0;
        $checks   = [
            'controllerBaseClass'          => $config->controllerBaseClass,
            'serviceBaseClass'             => $config->serviceBaseClass,
            'serviceContractInterface'     => $config->serviceContractInterface,
            'modelBaseClass'               => $config->modelBaseClass,
            'entityBaseClass'              => $config->entityBaseClass,
            'migrationBaseClass'           => $config->migrationBaseClass,
            'requestDtoBaseClass'          => $config->requestDtoBaseClass,
            'responseDtoInterface'         => $config->responseDtoInterface,
            'repositoryInterface'          => $config->repositoryInterface,
            'responseMapperInterface'      => $config->responseMapperInterface,
            'repositoryImplementation'     => $config->repositoryImplementation,
            'responseMapperImplementation' => $config->responseMapperImplementation,
            'filterableTraitFqcn'          => $config->filterableTraitFqcn,
            'searchableTraitFqcn'          => $config->searchableTraitFqcn,
        ];

        CLI::newLine();
        CLI::write('  Resolving declared FQCNs:', 'cyan');
        foreach ($checks as $field => $fqcn) {
            if ($fqcn === '' || class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
                CLI::write('    ' . CLI::color('✓', 'green') . "  {$field} → {$fqcn}");
                continue;
            }

            CLI::write('    ' . CLI::color('✗', 'red') . "  {$field} → {$fqcn} (not loadable)");
            $failures++;
        }

        return $failures;
    }
}
