<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Orchestration;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;

/**
 * Inverse of ScaffoldingOrchestrator + ConfigWireman.
 *
 * Computes the paths a make:crud invocation would have generated and removes them,
 * un-injects the route block from the domain routes file, un-injects the service
 * factories from the domain trait, and (when the domain trait is left empty) also
 * removes the trait file plus its require/use lines from Services.php.
 *
 * Migrations are NOT auto-rolled-back here — they live in the DB, not the file
 * tree, and the caller may want to keep historical timestamps. The remover prints
 * the migration filename so the user can decide whether to `php spark migrate:rollback`.
 */
class ScaffoldRemover
{
    public function __construct(private readonly ScaffoldingConfig $config)
    {
    }

    private function servicesFile(): string
    {
        return APPPATH . 'Config/Services.php';
    }

    /**
     * @return array{deleted: list<string>, not_found: list<string>, routes_cleaned: ?string, trait_cleaned: ?string, trait_removed: ?string, services_cleaned: bool, migration: ?string, warnings: list<string>}
     */
    public function plan(ResourceSchema $schema): array
    {
        return $this->execute($schema, dryRun: true);
    }

    /**
     * @return array{deleted: list<string>, not_found: list<string>, routes_cleaned: ?string, trait_cleaned: ?string, trait_removed: ?string, services_cleaned: bool, migration: ?string, warnings: list<string>}
     */
    public function remove(ResourceSchema $schema): array
    {
        return $this->execute($schema, dryRun: false);
    }

    /**
     * @return array{deleted: list<string>, not_found: list<string>, routes_cleaned: ?string, trait_cleaned: ?string, trait_removed: ?string, services_cleaned: bool, migration: ?string, warnings: list<string>}
     */
    private function execute(ResourceSchema $schema, bool $dryRun): array
    {
        $report = [
            'deleted' => [],
            'not_found' => [],
            'routes_cleaned' => null,
            'trait_cleaned' => null,
            'trait_removed' => null,
            'services_cleaned' => false,
            'migration' => null,
            'warnings' => [],
        ];

        // 1. Delete fixed-name files
        foreach ($this->fixedFiles($schema) as $path) {
            if (file_exists($path)) {
                if (!$dryRun) {
                    @unlink($path);
                }
                $report['deleted'][] = $path;
            } else {
                $report['not_found'][] = $path;
            }
        }

        // 2. Delete migration (timestamp varies — glob)
        $migrationPattern = APPPATH . $this->config->paths->migrations . '/*_Create' . $schema->getResourcePlural() . 'Table.php';
        foreach (glob($migrationPattern) ?: [] as $migration) {
            if (!$dryRun) {
                @unlink($migration);
            }
            $report['deleted'][] = $migration;
            $report['migration'] = $migration;
        }

        // 3. Un-inject route block from domain routes file
        $domainKebab = $schema->toKebab($schema->domain);
        $routesPath = APPPATH . $this->config->paths->routes . "/{$domainKebab}.php";
        if (file_exists($routesPath)) {
            $original    = (string) file_get_contents($routesPath);
            $beforeCount = $this->countControllerReferences($original, $schema->resource);
            $cleaned     = $this->stripRouteBlock($original, $schema);
            if ($cleaned !== null) {
                $afterCount = $this->countControllerReferences($cleaned, $schema->resource);
                if ($afterCount > 0) {
                    // The regex matched and removed *something*, but the controller
                    // still appears in the file. The user likely added custom
                    // routes for the same controller; flag it so they can clean
                    // up by hand instead of leaving orphan references.
                    $report['warnings'][] = sprintf(
                        '%s still contains %d reference(s) to %sController after stripping the standard CRUD block — likely custom routes were appended manually. Remove them by hand.',
                        $routesPath,
                        $afterCount,
                        $schema->resource,
                    );
                }

                if ($this->isEmptyDomainRoute($cleaned)) {
                    if (!$dryRun) {
                        @unlink($routesPath);
                    }
                    $report['deleted'][] = $routesPath;
                } else {
                    if (!$dryRun) {
                        file_put_contents($routesPath, $cleaned);
                    }
                    $report['routes_cleaned'] = $routesPath;
                }
            } elseif ($beforeCount > 0) {
                // The strip regex didn't match anything, but the controller
                // IS referenced in the file — the route block was hand-edited
                // to a non-standard shape and we can't remove it safely.
                $report['warnings'][] = sprintf(
                    'Could not auto-remove %d %sController reference(s) from %s — the routes block does not match the expected layout. Edit the file manually.',
                    $beforeCount,
                    $schema->resource,
                    $routesPath,
                );
            }
        }

        // 4. Un-inject service + mapper from domain trait
        $traitPath = APPPATH . "Config/{$schema->domain}DomainServices.php";
        if (file_exists($traitPath)) {
            $cleaned = $this->stripServiceMethods((string) file_get_contents($traitPath), $schema);
            if ($cleaned !== null) {
                if ($this->isEmptyDomainTrait($cleaned)) {
                    // Domain has no other resources — remove the trait + un-wire from Services.php
                    if (!$dryRun) {
                        @unlink($traitPath);
                    }
                    $report['deleted'][] = $traitPath;
                    $report['trait_removed'] = $traitPath;
                    $servicesCleaned = $this->unregisterDomainFromMainServices($schema->domain, $dryRun);
                    $report['services_cleaned'] = $servicesCleaned;
                } else {
                    if (!$dryRun) {
                        file_put_contents($traitPath, $cleaned);
                    }
                    $report['trait_cleaned'] = $traitPath;
                }
            }
        }

        $this->stripPermissions($schema, $dryRun);

        return $report;
    }

    /**
     * @return list<string>
     */
    private function fixedFiles(ResourceSchema $schema): array
    {
        $resource = $schema->resource;
        $domain = $schema->domain;
        $plural = $schema->getResourcePlural();
        $p = $this->config->paths;

        return [
            APPPATH . "{$p->controllers}/{$domain}/{$resource}Controller.php",
            APPPATH . "{$p->services}/{$domain}/{$resource}Service.php",
            APPPATH . "{$p->interfaces}/{$domain}/{$resource}ServiceInterface.php",
            APPPATH . "{$p->requestDtos}/{$domain}/{$resource}IndexRequestDTO.php",
            APPPATH . "{$p->requestDtos}/{$domain}/{$resource}CreateRequestDTO.php",
            APPPATH . "{$p->requestDtos}/{$domain}/{$resource}UpdateRequestDTO.php",
            APPPATH . "{$p->responseDtos}/{$domain}/{$resource}ResponseDTO.php",
            APPPATH . "{$p->documentation}/{$domain}/{$resource}Endpoints.php",
            APPPATH . "{$p->models}/{$resource}Model.php",
            APPPATH . "{$p->entities}/{$resource}Entity.php",
            APPPATH . "{$p->languageEn}/{$plural}.php",
            APPPATH . "{$p->languageEs}/{$plural}.php",
            ROOTPATH . "{$p->unitTests}/{$domain}/{$resource}ServiceTest.php",
            ROOTPATH . "{$p->integrationTests}/{$resource}ModelTest.php",
            ROOTPATH . "{$p->featureTests}/{$domain}/{$resource}ControllerTest.php",
        ];
    }

    private function stripRouteBlock(string $content, ResourceSchema $schema): ?string
    {
        $resource = $schema->resource;
        // Match the entire injected block: '// {$resource} Routes' through the
        // last $routes->delete line for that controller.
        $pattern = '/\n?\s*\/\/\s*' . preg_quote($resource, '/') . ' Routes\n(?:\s*\$routes->[a-z]+\([^\n]*' . preg_quote($resource, '/') . 'Controller::[^\n]*\n){5}/';
        $cleaned = preg_replace($pattern, "\n", $content, 1);

        return $cleaned === $content ? null : $cleaned;
    }

    private function isEmptyDomainRoute(string $content): bool
    {
        // No remaining controller refs for this domain → file is empty of resources.
        return preg_match('/[A-Za-z0-9_]+Controller::(?:index|show|create|update|delete)/', $content) !== 1;
    }

    /**
     * Count how many times a controller's CRUD method handlers appear in the
     * routes file. Used to detect orphaned references after stripRouteBlock()
     * runs against a hand-edited routes file.
     */
    private function countControllerReferences(string $content, string $resource): int
    {
        return preg_match_all(
            '/' . preg_quote($resource, '/') . 'Controller::(?:index|show|create|update|delete)/',
            $content
        ) ?: 0;
    }

    private function stripServiceMethods(string $content, ResourceSchema $schema): ?string
    {
        $lower = $schema->getResourceLower();
        $serviceName = "{$lower}Service";
        $mapperName = "{$lower}ResponseMapper";

        $cleaned = $content;
        foreach ([$mapperName, $serviceName] as $method) {
            $pattern = '/\n\s*public static function ' . preg_quote($method, '/') . '\([^)]*\)[^{]*\{.*?\n    \}\n/s';
            $cleaned = (string) preg_replace($pattern, '', $cleaned, 1);
        }

        return $cleaned === $content ? null : $cleaned;
    }

    private function isEmptyDomainTrait(string $content): bool
    {
        return preg_match('/public static function \w+\(/', $content) === 0;
    }

    private function unregisterDomainFromMainServices(string $domain, bool $dryRun): bool
    {
        $servicesFile = $this->servicesFile();
        if (!file_exists($servicesFile)) {
            return false;
        }

        $content = (string) file_get_contents($servicesFile);
        $requireLine = "require_once __DIR__ . '/{$domain}DomainServices.php';\n";
        $useLine = "    use {$domain}DomainServices;\n";

        $modified = false;
        if (str_contains($content, $requireLine)) {
            $content = str_replace($requireLine, '', $content);
            $modified = true;
        }
        if (str_contains($content, $useLine)) {
            $content = str_replace($useLine, '', $content);
            $modified = true;
        }

        if ($modified && !$dryRun) {
            file_put_contents($servicesFile, $content);
        }

        return $modified;
    }

    private function stripPermissions(ResourceSchema $schema, bool $dryRun): void
    {
        $permissionsFile = APPPATH . 'Config/DomainPermissions.php';
        if (! file_exists($permissionsFile)) {
            return;
        }

        $resource = $schema->getResourceLower();
        $prefix = trim($this->config->permissionCodePrefix, '.');
        $prefix = $prefix === '' ? '' : $prefix . '.';
        $content = (string) file_get_contents($permissionsFile);
        $cleaned = $content;

        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $code = preg_quote($prefix . $resource . '.' . $action, '/');
            $cleaned = (string) preg_replace(
                '/\s*\[\s*[^\]]*[\'"]code[\'"]\s*=>\s*[\'"]' . $code . '[\'"][^\]]*\],?\n?/s',
                '',
                $cleaned
            );
        }

        if ($cleaned !== $content && ! $dryRun) {
            file_put_contents($permissionsFile, $cleaned);
        }
    }
}
