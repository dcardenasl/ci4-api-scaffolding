<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Core\StringHelper;
use dcardenasl\Ci4ApiScaffolding\Generators\RouteGenerator;
use dcardenasl\Ci4ApiScaffolding\Orchestration\ScaffoldConflictException;
use dcardenasl\Ci4ApiScaffolding\Orchestration\ScaffoldingOrchestrator;
use dcardenasl\Ci4ApiScaffolding\Validators\FieldNameValidator;
use dcardenasl\Ci4ApiScaffolding\Validators\FieldStringParser;
use dcardenasl\Ci4ApiScaffolding\Validators\ForeignKeyValidator;
use dcardenasl\Ci4ApiScaffolding\Wiring\ConfigWireman;
use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * Generates a complete CRUD module under the consumer's conventions.
 *
 * Conventions are read from the consumer's `App\Config\Scaffolding` class
 * (which extends `BaseScaffoldingConfig`). If the class is missing, the
 * command falls back to `ScaffoldingConfig::defaults()` and prints a hint.
 */
class MakeCrud extends BaseCommand
{
    protected $group = 'Scaffold';
    protected $name = 'make:crud';
    protected $description = 'Generate a complete CRUD module following the DTO-first architecture.';
    protected $usage = "make:crud <Resource> [--domain <Domain>] [--fields '<fields_string>']\n"
        . "\n"
        . "  IMPORTANT: --fields must be SINGLE-QUOTED. Pipes ('|') in modifier lists\n"
        . "  are shell-special and will be consumed if unquoted, leaving --fields\n"
        . "  truncated and the scaffold incomplete. The recommended entry point is\n"
        . "  the wrapper bin/make-crud.sh which handles quoting for you.";
    protected $arguments = [
        'Resource' => 'Resource singular name (e.g. Product, InvoiceItem)',
    ];
    protected $options = [
        '--domain' => 'Domain folder (default: Catalog)',
        '--route' => 'Route slug plural (default: kebab-case plural of resource)',
        '--fields' => 'Fields definition string (name:type:options,...)',
        '--soft-delete' => 'Enable soft deletes yes|no (default: yes)',
        '--version' => 'API version for route path (default: v1). Example: --version v2 → routes in Config/Routes/v2/',
        '--dry-run' => 'Show planned files and wiring without writing anything',
        '--no-wire' => 'Generate files but skip Services.php injection. Print snippets to paste manually instead.',
        '--skip-fk-validation' => 'Skip the FK target check when the database is unreachable. Use only when you know the targets exist.',
    ];

    public function run(array $params)
    {
        $resourceInput = (string) ($params[0] ?? '');
        if ($resourceInput === '') {
            CLI::error('Resource argument is required. Example: php spark make:crud Product');
            return EXIT_ERROR;
        }

        $config = $this->loadConfig();

        $resource = StringHelper::studly($resourceInput);
        $domain = StringHelper::studly((string) (CLI::getOption('domain') ?: 'Catalog'));
        $route = (string) (CLI::getOption('route') ?: StringHelper::toKebab(StringHelper::pluralize($resource)));
        $fieldsArg = (string) (CLI::getOption('fields') ?: '');
        $softDelete = $this->yesNoOption('soft-delete', true);
        $apiVersion = preg_match('/^v\d+$/', (string) (CLI::getOption('version') ?: 'v1'))
            ? (string) (CLI::getOption('version') ?: 'v1')
            : 'v1';
        $dryRun = CLI::getOption('dry-run') !== null;
        $noWire = CLI::getOption('no-wire') !== null;
        $skipFkValidation = CLI::getOption('skip-fk-validation') !== null;

        if (StringHelper::hasAcronymRun($resource)) {
            CLI::write("⚠ Resource '{$resource}' contains a run of consecutive uppercase letters.", 'yellow');
            CLI::write("  Derived names will keep the acronym intact:", 'yellow');
            CLI::write("    table:    " . StringHelper::toSnakeCase(StringHelper::pluralize($resource)), 'yellow');
            CLI::write("    route:    " . StringHelper::toKebab(StringHelper::pluralize($resource)), 'yellow');
            CLI::write("    var:      \$" . StringHelper::toCamelCase($resource), 'yellow');
            CLI::write("  Class/file names preserve the resource as-typed: {$resource}Controller.php", 'yellow');
            CLI::write("  If you prefer canonical StudlyCase, re-run with: " . preg_replace_callback('/([A-Z]+)([A-Z][a-z]|$)/', static fn (array $m): string => ucfirst(strtolower($m[1])) . $m[2], $resource), 'yellow');
            CLI::newLine();
        }

        CLI::write("🚀 Preparing to scaffold resource: {$resource} in Domain: {$domain}", 'cyan');

        try {
            // 1. Gather Fields (CLI or Interactive)
            $fields = $this->gatherFields($fieldsArg);

            if (empty($fields)) {
                CLI::error('No fields defined. Aborting.');
                return EXIT_ERROR;
            }

            // 1b. Reject field names that would silently break generation
            (new FieldNameValidator())->validate($fields);

            // 2. Build Schema
            $schema = new ResourceSchema(
                resource: $resource,
                domain: $domain,
                route: $route,
                fields: $fields,
                softDelete: $softDelete,
                apiVersion: $apiVersion,
            );

            // 2b. Verify FK targets exist. By default, abort if the DB is
            // unreachable while FKs are declared (audit M2). The user can
            // opt out with --skip-fk-validation when they know the targets exist.
            $fkWarnings = (new ForeignKeyValidator())->validate($schema, skipOnDbUnreachable: $skipFkValidation);
            foreach ($fkWarnings as $warning) {
                CLI::write("⚠ {$warning}", 'yellow');
            }

            // 3. Orchestrate File Generation
            $orchestrator = new ScaffoldingOrchestrator($config);

            if ($dryRun) {
                $plannedFiles = $orchestrator->plan($schema);
                CLI::write('🔎 DRY RUN — no files will be written.', 'cyan');
                CLI::newLine();
                foreach ($plannedFiles as $path => $_content) {
                    CLI::write("Would create: {$path}", 'green');
                }
                CLI::write("Would wire: \\{$config->servicesFactoryClass}::" . $schema->getResourceLower() . 'Service()', 'green');
                CLI::write("Would wire: \\{$config->servicesFactoryClass}::" . $schema->getResourceLower() . 'ResponseMapper()', 'green');
                CLI::newLine();
                CLI::write('✅ Dry-run complete.', 'white', 'green');
                return EXIT_SUCCESS;
            }

            $createdFiles = $orchestrator->orchestrate($schema);

            foreach ($createdFiles as $file) {
                $verb = $orchestrator->wasExisting($file) ? 'UPDATED' : 'CREATED';
                CLI::write("{$verb}: {$file}", 'green');
            }

            // 3b. Inject glob loader into Routes.php on first scaffold for this version.
            $routeGen = new RouteGenerator($config);
            if ($routeGen->patchMainRoutesLoader($apiVersion)) {
                CLI::write("PATCHED: app/Config/Routes.php (api/{$apiVersion} glob loader added)", 'green');
            }

            // 4. Wire Services and Config (or print snippet with --no-wire)
            $wireman = new ConfigWireman($config);

            if ($noWire) {
                $preview = $wireman->previewWiring($schema);
                CLI::newLine();
                CLI::write('--no-wire: Services.php was NOT modified. Apply this manually:', 'yellow');
                CLI::newLine();
                CLI::write($preview['services_register'], 'white');
                CLI::newLine();
                CLI::write("Domain trait file ({$preview['trait_file']}):", 'yellow');
                CLI::write($preview['trait_content'], 'white');
                CLI::newLine();
                CLI::write('Service factory snippet (paste inside the trait):', 'yellow');
                CLI::write($preview['service_method'], 'white');
            } else {
                try {
                    $wireman->wire($schema);
                    CLI::write("WIRING: Services and Mappers registered successfully.", 'green');
                } catch (\dcardenasl\Ci4ApiScaffolding\Wiring\WiringFailedException $e) {
                    CLI::newLine();
                    CLI::write('WIRING FAILED — rolling back generated files...', 'red');
                    $orchestrator->rollbackLastRun();
                    CLI::write('WIRING FAILED — module files were rolled back. Services.php was NOT modified.', 'red');
                    CLI::newLine();
                    CLI::write($e->describe(), 'yellow');
                    CLI::newLine();

                    return EXIT_ERROR;
                }
            }

            CLI::newLine();
            CLI::write('✅ CRUD Module files generated!', 'white', 'green');
            CLI::newLine();

            // 5. Automatic Validation
            CLI::write("🔍 Running module bootstrap check...", 'yellow');
            $this->call('module:check', [$resource, '--domain' => $domain]);

            CLI::newLine();
            CLI::write("🚀 Next Steps:", 'cyan');
            CLI::write("1. Run 'php spark migrate' to create the table.", 'yellow');
            CLI::write("2. Run 'php spark swagger:generate' to update OpenAPI docs (if installed).", 'yellow');
            CLI::write("3. Restart the server to pick up new route files.", 'yellow');
            CLI::newLine();
        } catch (ScaffoldConflictException | InvalidArgumentException $e) {
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        } catch (Exception $e) {
            CLI::error("An error occurred: " . $e->getMessage());
            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    /**
     * Resolve the consumer's Config\Scaffolding (preferred) or fall back to
     * ScaffoldingConfig::defaults() with a hint.
     */
    private function loadConfig(): ScaffoldingConfig
    {
        try {
            $userConfig = config('Scaffolding');
        } catch (Throwable) {
            $userConfig = null;
        }

        if ($userConfig instanceof BaseScaffoldingConfig) {
            return $userConfig->build();
        }

        throw new \RuntimeException(
            "Config\\Scaffolding not found.\n"
            . "Create app/Config/Scaffolding.php extending "
            . "dcardenasl\\Ci4ApiScaffolding\\Config\\BaseScaffoldingConfig "
            . "before running make:crud.\n"
            . "Projects that need no auth can set protectedRouteFilters: [] explicitly."
        );
    }

    /** @return list<Field> */
    private function gatherFields(string $fieldsArg): array
    {
        if ($fieldsArg !== '') {
            return (new FieldStringParser())->parse($fieldsArg);
        }

        // CLI::prompt() returns bool (not string) when stdin is not a TTY,
        // causing a TypeError. Detect non-interactive environments early.
        if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
            throw new InvalidArgumentException(
                "--fields is required in non-interactive mode.\n"
                . "Use: bash vendor/bin/make-crud.sh <Resource> <Domain> '<fields>' yes"
            );
        }

        return $this->gatherFieldsInteractively();
    }

    /** @return list<Field> */
    private function gatherFieldsInteractively(): array
    {
        $fields = [];
        CLI::write('--- Interactive Field Definition ---', 'yellow');

        while (true) {
            $name = CLI::prompt('Field name (or leave empty to finish)');
            if ($name === null || trim($name) === '') {
                break;
            }

            $type = CLI::prompt('Field type', ['string', 'text', 'int', 'bool', 'decimal', 'email', 'date', 'datetime', 'fk', 'relation', 'json'], 'string');
            $required = CLI::prompt('Is required?', ['y', 'n'], 'y') === 'y';
            $searchable = CLI::prompt('Is searchable?', ['y', 'n'], 'n') === 'y';
            $filterable = CLI::prompt('Is filterable?', ['y', 'n'], 'n') === 'y';

            $fkTable = null;
            if (in_array($type, ['fk', 'relation'], true)) {
                $fkTable = CLI::prompt('Foreign key table name');
            }

            $fields[] = new Field(
                name: $name,
                type: $type,
                required: $required,
                nullable: !$required,
                searchable: $searchable,
                filterable: $filterable,
                fkTable: $fkTable
            );

            CLI::write("Field '{$name}' added.", 'cyan');
        }

        return $fields;
    }

    private function yesNoOption(string $name, bool $default): bool
    {
        $raw = CLI::getOption($name);
        if ($raw === null || $raw === true) {
            return $default;
        }
        return in_array(strtolower((string) $raw), ['yes', 'y', 'true', '1'], true);
    }
}
