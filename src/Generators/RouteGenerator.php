<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * RouteGenerator
 * Generates or updates the domain-specific route file at the path
 * configured in ScaffoldingPaths::$routes.
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

        $fileExists = file_exists($path);
        $content = $fileExists ? (string) file_get_contents($path) : $this->baseTemplate($schema);
        $injectedContent = $this->injectRoute($schema, $content);

        // Validate only when updating an existing file (not for new template-based generation)
        $controller = "{$schema->resource}Controller";
        if ($fileExists && str_contains($injectedContent, "{$controller}::index")) {
            $this->assertAllRoutesPresent($injectedContent, $controller);
        }

        return [
            $path => $injectedContent,
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
        $resourceLower = $schema->getResourceLower();
        $usePermissionGroups = $this->config->protectedRouteFilters !== [];

        if (str_contains($content, "{$controller}::index")) {
            return $content; // Already exists
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($content);
        if ($ast === null) {
            return $content; // Fallback or handle error
        }

        $filtersList = $this->renderFilterList();

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($filtersList, $route, $controller, $resourceLower, $usePermissionGroups) extends NodeVisitorAbstract {
            private bool $injected = false;
            public function __construct(
                private string $filtersList,
                private string $route,
                private string $controller,
                private string $resourceLower,
                private bool $usePermissionGroups,
            ) {
            }

            public function enterNode(Node $node)
            {
                if ($this->injected || !($node instanceof MethodCall)) {
                    return null;
                }

                // Check if method is 'group'
                if (!($node->name instanceof Node\Identifier && $node->name->toString() === 'group')) {
                    return null;
                }

                // Check if this is the correct group (filter match)
                $filtersNode = $node->args[1] ?? null;
                if (!($filtersNode instanceof Node\Arg && $filtersNode->value instanceof Node\Expr\Array_)) {
                    return null;
                }

                $printer = new PrettyPrinter\Standard();
                $actualFilters = $printer->prettyPrintExpr($filtersNode->value);

                // Remove all whitespace for a loose comparison
                $normalizedActual = (string) preg_replace('/\s+/', '', $actualFilters);
                $normalizedExpected = (string) preg_replace('/\s+/', '', $this->filtersList);

                if (str_contains($normalizedActual, "['filter'=>[]]")) {
                    $normalizedActual = str_replace("['filter'=>[]]", "[]", $normalizedActual);
                }
                if (str_contains($normalizedActual, "[[]]")) {
                    $normalizedActual = str_replace("[[]]", "[]", $normalizedActual);
                }

                if ($normalizedActual !== $normalizedExpected && $normalizedActual !== "['filter'=>" . $normalizedExpected . "]") {
                    return null;
                }

                // Find the closure
                $closureNode = $node->args[2] ?? null;
                if (!($closureNode instanceof Node\Arg && $closureNode->value instanceof Closure)) {
                    return null;
                }

                // 1. Read Group
                $readClosure = new Closure([
                    'params' => [new Node\Param(new Node\Expr\Variable('routes'))],
                    'returnType' => new Node\Identifier('void'),
                    'stmts' => [
                        new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'get', [
                            new Node\Arg(new Node\Scalar\String_($this->route)),
                            new Node\Arg(new Node\Scalar\String_($this->controller . '::index')),
                        ])),
                        new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'get', [
                            new Node\Arg(new Node\Scalar\String_($this->route . '/(:num)')),
                            new Node\Arg(new Node\Scalar\String_($this->controller . '::show/$1')),
                        ])),
                    ]
                ]);
                $readGroup = new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'group', [
                    new Node\Arg(new Node\Scalar\String_('')),
                    new Node\Arg(new Node\Expr\Array_([
                        new Node\Expr\ArrayItem(
                            new Node\Scalar\String_("permission:{$this->resourceLower}.read"),
                            new Node\Scalar\String_('filter')
                        )
                    ])),
                    new Node\Arg($readClosure)
                ]));

                // 2. Write Group
                $writeClosure = new Closure([
                    'params' => [new Node\Param(new Node\Expr\Variable('routes'))],
                    'returnType' => new Node\Identifier('void'),
                    'stmts' => [
                        new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'post', [
                            new Node\Arg(new Node\Scalar\String_($this->route)),
                            new Node\Arg(new Node\Scalar\String_($this->controller . '::create')),
                        ])),
                        new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'put', [
                            new Node\Arg(new Node\Scalar\String_($this->route . '/(:num)')),
                            new Node\Arg(new Node\Scalar\String_($this->controller . '::update/$1')),
                        ])),
                    ]
                ]);
                $writeGroup = new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'group', [
                    new Node\Arg(new Node\Scalar\String_('')),
                    new Node\Arg(new Node\Expr\Array_([
                        new Node\Expr\ArrayItem(
                            new Node\Scalar\String_("permission:{$this->resourceLower}.write"),
                            new Node\Scalar\String_('filter')
                        )
                    ])),
                    new Node\Arg($writeClosure)
                ]));

                // 3. Delete Group
                $deleteClosure = new Closure([
                    'params' => [new Node\Param(new Node\Expr\Variable('routes'))],
                    'returnType' => new Node\Identifier('void'),
                    'stmts' => [
                        new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'delete', [
                            new Node\Arg(new Node\Scalar\String_($this->route . '/(:num)')),
                            new Node\Arg(new Node\Scalar\String_($this->controller . '::delete/$1')),
                        ])),
                    ]
                ]);
                $deleteGroup = new Expression(new Node\Expr\MethodCall(new Node\Expr\Variable('routes'), 'group', [
                    new Node\Arg(new Node\Scalar\String_('')),
                    new Node\Arg(new Node\Expr\Array_([
                        new Node\Expr\ArrayItem(
                            new Node\Scalar\String_("permission:{$this->resourceLower}.delete"),
                            new Node\Scalar\String_('filter')
                        )
                    ])),
                    new Node\Arg($deleteClosure)
                ]));

                if ($this->usePermissionGroups) {
                    // Inject granular read / write / delete permission sub-groups
                    array_push($closureNode->value->stmts, $readGroup, $writeGroup, $deleteGroup);
                } else {
                    // No permission filter configured — inject flat routes directly
                    array_push(
                        $closureNode->value->stmts,
                        ...$readClosure->stmts,
                        ...$writeClosure->stmts,
                        ...$deleteClosure->stmts
                    );
                }

                $this->injected = true;
                return null;
            }
        });

        $traverser->traverse($ast);
        $printer = new PrettyPrinter\Standard();
        return $printer->prettyPrintFile($ast);
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

    // ─── Routes.php glob loader ───────────────────────────────────────────────

    /**
     * Inject a versioned glob loader block into app/Config/Routes.php on first
     * scaffold so all domain route files under Config/Routes/{version}/ are
     * automatically discovered by CodeIgniter.
     *
     * Safe to call on every make:crud invocation — idempotent via markers.
     * Returns true when Routes.php was patched, false when already wired or
     * when injection was skipped (file not found, existing api group detected).
     */
    public function patchMainRoutesLoader(string $apiVersion): bool
    {
        $path = APPPATH . 'Config/Routes.php';

        if (! file_exists($path)) {
            return false;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return false;
        }

        $markerStart = $this->loaderMarkerStart($apiVersion);

        if (str_contains($raw, $markerStart)) {
            return false; // already wired
        }

        // Detect an existing api/{version} group added manually (e.g. ci4-api-starter).
        if (str_contains($raw, "'api/{$apiVersion}'") || str_contains($raw, "\"api/{$apiVersion}\"")) {
            return false;
        }

        $patched = $this->applyLoaderPatch($raw, $apiVersion);

        return file_put_contents($path, $patched) !== false;
    }

    /**
     * Pure string transformation — appends the glob loader block to $content.
     * Exposed as public for unit testing without file I/O.
     */
    public function applyLoaderPatch(string $content, string $apiVersion): string
    {
        return rtrim($content) . "\n\n" . $this->loaderBlock($apiVersion) . "\n";
    }

    private function loaderBlock(string $apiVersion): string
    {
        $routesDir = 'Config/Routes/' . $apiVersion;
        $groupPath = 'api/' . $apiVersion;

        return $this->loaderMarkerStart($apiVersion) . "\n"
            . "\$routes->group('{$groupPath}', function (\$routes) {\n"
            . "    \$routesDir = APPPATH . '{$routesDir}';\n"
            . "    if (is_dir(\$routesDir)) {\n"
            . "        foreach (glob(\$routesDir . '/*.php') as \$file) {\n"
            . "            if (basename(\$file) === 'system.php') {\n"
            . "                continue;\n"
            . "            }\n"
            . "            require \$file;\n"
            . "        }\n"
            . "    }\n"
            . "});\n"
            . $this->loaderMarkerEnd($apiVersion);
    }

    private function loaderMarkerStart(string $apiVersion): string
    {
        return "// ci4-api-scaffolding: api/{$apiVersion} loader start";
    }

    private function loaderMarkerEnd(string $apiVersion): string
    {
        return "// ci4-api-scaffolding: api/{$apiVersion} loader end";
    }
}
