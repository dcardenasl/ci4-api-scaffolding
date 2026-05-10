<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;

/**
 * RouteGenerator
 * Generates or updates the domain-specific route file at the path
 * configured in ScaffoldingPaths::$routes.
 *
 * The "protected" group's filter list is taken from
 * ScaffoldingConfig::$protectedRouteFilters — no longer hardcoded to a
 * specific permission. Consumers can ship their own filter convention
 * via App\Config\Scaffolding.
 */
class RouteGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'route';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $domainKebab = $schema->toKebab($schema->domain);
        $routesDir = (string) preg_replace('/v\d+$/', $schema->apiVersion, $this->config->paths->routes);
        $path = APPPATH . $routesDir . "/{$domainKebab}.php";

        $content = file_exists($path) ? (string) file_get_contents($path) : $this->baseTemplate($schema);

        return [
            $path => $this->injectRoute($schema, $content),
        ];
    }

    private function baseTemplate(ResourceSchema $schema): string
    {
        $domainKebab = $schema->toKebab($schema->domain);
        $controllersNs = '\\' . $this->config->namespaceFor($this->config->paths->controllers) . '\\' . $schema->domain;
        $filtersList = $this->renderFilterList();

        return $this->renderer->render('route/DomainRoutes', [
            'domainKebab'   => $domainKebab,
            'controllersNs' => $controllersNs,
            'filtersList'   => $filtersList,
        ]);
    }

    private function injectRoute(ResourceSchema $schema, string $content): string
    {
        $resource = $schema->resource;
        $route = $schema->route;
        $controller = "{$resource}Controller";

        $routeBlock = $this->renderer->render('route/RouteBlock', [
            'resource'   => $resource,
            'route'      => $route,
            'controller' => $controller,
        ]);

        if (str_contains($content, "{$controller}::index")) {
            return $content; // Already exists
        }

        // Try to inject inside the protected group
        $filtersList = $this->renderFilterList();
        $search = "['filter' => {$filtersList}], function (\$routes) {";
        $injected = null;
        if (str_contains($content, $search)) {
            $pos      = strpos($content, $search) + strlen($search);
            $injected = substr($content, 0, $pos) . "\n" . $routeBlock . substr($content, $pos);
        } else {
            // Fallback: append to end
            $injected = $content . "\n" . $routeBlock;
        }

        $this->assertAllRoutesPresent($injected, $controller);

        return $injected;
    }

    /**
     * Confirm that the injected content actually contains all five CRUD route
     * lines for this controller. Defends against template regressions where
     * the heredoc gets accidentally truncated, or where the injection target
     * pattern matches but the resulting concatenation drops part of the block.
     */
    private function assertAllRoutesPresent(string $content, string $controller): void
    {
        $requiredVerbs = ['index', 'show', 'create', 'update', 'delete'];
        $missing       = [];

        foreach ($requiredVerbs as $verb) {
            if (!str_contains($content, "{$controller}::{$verb}")) {
                $missing[] = $verb;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Route generator failed to emit all CRUD routes for %s — missing: %s. '
                . 'This usually means the route file already had a non-standard layout. '
                . 'Inspect the generated route file and add the missing routes manually.',
                $controller,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Render the protectedRouteFilters list as PHP source.
     * E.g. ['jwtauth', 'permission:foo', 'throttle']  =>  "['jwtauth', 'permission:foo', 'throttle']"
     */
    private function renderFilterList(): string
    {
        $quoted = array_map(static fn (string $f): string => "'" . addslashes($f) . "'", $this->config->protectedRouteFilters);
        return '[' . implode(', ', $quoted) . ']';
    }
}
