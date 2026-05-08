<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Wiring;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * ConfigWireman
 * Automates the "wiring" of services and mappers in the consumer's
 * `app/Config/Services.php` and per-domain trait files.
 *
 * Uses nikic/php-parser for AST-level injection with format-preserving printing,
 * making the wiring immune to heredocs, PHP 8 attributes, and non-standard layouts
 * that broke the previous regex + strrpos approach.
 *
 * If the AST injection still cannot locate the expected class/trait node (e.g. the
 * consumer's Services.php omits `class Services`), the spark command's `--no-wire`
 * flag swaps `wire()` for `previewWiring()`, which returns the snippets the consumer
 * must paste manually.
 */
class ConfigWireman
{
    private readonly PhpAstEditor $astEditor;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->astEditor = new PhpAstEditor();
    }

    private function servicesFile(): string
    {
        return APPPATH . 'Config/Services.php';
    }

    private function domainTraitFile(string $domain): string
    {
        return APPPATH . "Config/{$domain}DomainServices.php";
    }

    /**
     * Inject the trait + service factory in-place. Used by the default
     * (write-through) make:crud invocation.
     *
     * Each injection step is verified after writing — if the AST editor
     * cannot locate the expected class/trait structure, this method throws a
     * WiringFailedException carrying the manual snippet so the user can
     * recover instead of being left with a half-wired module.
     */
    public function wire(ResourceSchema $schema): void
    {
        $domain          = $schema->domain;
        $domainTraitFile = $this->domainTraitFile($domain);

        // 1. Domain trait file
        if (!file_exists($domainTraitFile)) {
            $this->createDomainTrait($domain, $domainTraitFile);
            if (!file_exists($domainTraitFile)) {
                throw new WiringFailedException(
                    sprintf('Failed to create domain trait file: %s', $domainTraitFile),
                    $this->previewWiring($schema)
                );
            }

            $this->registerDomainInMainServices($domain, $schema);
            $this->verifyMainServicesRegistration($domain, $schema);
        }

        // 2. Service factory inside the trait
        $this->injectServiceAndMapper($schema, $domainTraitFile);
        $this->verifyServiceFactoryInjection($schema, $domainTraitFile);
    }

    /**
     * Produce the snippets the consumer must paste manually, without touching
     * any file. Used by `make:crud --no-wire` so a consumer with a non-standard
     * Services.php can still benefit from the file generation while handling
     * wiring themselves.
     *
     * @return array{trait_file: string, trait_content: string, service_method: string, services_register: string}
     */
    public function previewWiring(ResourceSchema $schema): array
    {
        $domain = $schema->domain;

        return [
            'trait_file' => $this->domainTraitFile($domain),
            'trait_content' => $this->domainTraitTemplate($domain),
            'service_method' => $this->serviceFactorySnippet($schema),
            'services_register' => $this->servicesRegisterSnippet($domain),
        ];
    }

    private function createDomainTrait(string $domain, string $path): void
    {
        file_put_contents($path, $this->domainTraitTemplate($domain));
    }

    private function domainTraitTemplate(string $domain): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Config;

trait {$domain}DomainServices
{
}
PHP;
    }

    private function registerDomainInMainServices(string $domain, ResourceSchema $schema): void
    {
        $servicesFile = $this->servicesFile();
        if (!file_exists($servicesFile)) {
            return;
        }

        $originalContent = (string) file_get_contents($servicesFile);
        $content         = $originalContent;
        $requireLine     = "require_once __DIR__ . '/{$domain}DomainServices.php';";
        $useLine         = "    use {$domain}DomainServices;";

        // --- require_once injection ---
        if (!str_contains($content, $requireLine)) {
            $edited = $this->astEditor->edit($content, function (array &$stmts) use ($domain): bool {
                $finder  = new NodeFinder();
                $newNode = $this->buildRequireNode($domain);

                // CI4 Services.php always declares `namespace Config;`.
                // The require_once and class nodes live inside Stmt\Namespace_->stmts,
                // not at the file top-level. Fall back to top-level for namespace-free files.
                $ns = $finder->findFirstInstanceOf($stmts, Node\Stmt\Namespace_::class);

                if ($ns instanceof Node\Stmt\Namespace_) {
                    $this->injectRequireIntoStmts($ns->stmts, $newNode);
                } else {
                    $this->injectRequireIntoStmts($stmts, $newNode);
                }

                return true;
            });

            if ($edited !== null) {
                $content = $edited;
            }
        }

        // --- use Trait injection ---
        if (!str_contains($content, $useLine)) {
            $edited = $this->astEditor->edit($content, function (array &$stmts) use ($domain): bool {
                $finder = new NodeFinder();
                $class  = $finder->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
                if ($class === null || $class->name?->name !== 'Services') {
                    return false;
                }

                $newUse = new Node\Stmt\TraitUse([new Node\Name("{$domain}DomainServices")]);

                // Find the position of the last TraitUse via an explicit integer counter
                // to avoid int|string key ambiguity when using array_keys() on an untyped array.
                $lastTraitUsePos = -1;
                $pos             = 0;
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\TraitUse) {
                        $lastTraitUsePos = $pos;
                    }
                    $pos++;
                }

                if ($lastTraitUsePos >= 0) {
                    array_splice($class->stmts, $lastTraitUsePos + 1, 0, [$newUse]);
                } else {
                    // Fallback G1: no prior trait use — prepend as first class statement.
                    array_unshift($class->stmts, $newUse);
                }

                return true;
            });

            if ($edited !== null) {
                $content = $edited;
            }
        }

        file_put_contents($servicesFile, $content);

        // Post-write validation: re-parse; restore original and throw on failure.
        if (!$this->astEditor->isValidPhp($content)) {
            file_put_contents($servicesFile, $originalContent);
            throw new WiringFailedException(
                sprintf(
                    'AST re-parse failed after injecting domain %s into Services.php — original restored.',
                    $domain
                ),
                $this->previewWiring($schema)
            );
        }
    }

    private function injectServiceAndMapper(ResourceSchema $schema, string $path): void
    {
        $content       = (string) file_get_contents($path);
        $resourceLower = $schema->getResourceLower();
        $serviceName   = "{$resourceLower}Service";

        if (str_contains($content, "function {$serviceName}")) {
            return; // Already injected — idempotent
        }

        $snippet = $this->serviceFactorySnippet($schema);
        $methods = $this->extractMethodsFromSnippet($snippet);

        $edited = $this->astEditor->edit($content, static function (array &$stmts) use ($methods): bool {
            $trait = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Trait_::class);
            if ($trait === null) {
                return false;
            }

            foreach ($methods as $method) {
                $trait->stmts[] = $method;
            }

            return true;
        });

        if ($edited !== null) {
            file_put_contents($path, $edited);
        } else {
            throw new WiringFailedException(
                sprintf(
                    "Could not inject service factory into %s — the file does not contain a parseable trait declaration.\n" .
                    "If you edited the trait manually, ensure it still declares `trait %sDomainServices { }`.\n" .
                    "Alternatively, re-run with --no-wire and paste the snippet manually.",
                    basename($path),
                    $schema->domain
                ),
                $this->previewWiring($schema)
            );
        }
    }

    /**
     * The PHP source that goes inside the {Domain}DomainServices trait. Two
     * static factories: one for the response mapper, one for the service.
     */
    private function serviceFactorySnippet(ResourceSchema $schema): string
    {
        $resource = $schema->resource;
        $resourceLower = $schema->getResourceLower();
        $domain = $schema->domain;

        $mapperName = "{$resourceLower}ResponseMapper";
        $serviceName = "{$resourceLower}Service";

        $mapperIface = '\\' . ltrim($this->config->responseMapperInterface, '\\');
        $mapperImpl = '\\' . ltrim($this->config->responseMapperImplementation, '\\');
        $repoImpl = '\\' . ltrim($this->config->repositoryImplementation, '\\');
        $serviceIfaceFqcn = '\\' . $this->config->namespaceFor($this->config->paths->interfaces) . "\\{$domain}\\{$resource}ServiceInterface";
        $responseDtoFqcn = '\\' . $this->config->namespaceFor($this->config->paths->responseDtos) . "\\{$domain}\\{$resource}ResponseDTO";
        $serviceImplFqcn = '\\' . $this->config->namespaceFor($this->config->paths->services) . "\\{$domain}\\{$resource}Service";
        $modelFqcn = '\\' . $this->config->namespaceFor($this->config->paths->models) . "\\{$resource}Model";

        return "\n    public static function {$mapperName}(bool \$getShared = true): {$mapperIface}\n"
            . "    {\n"
            . "        if (\$getShared) {\n"
            . "            return static::getSharedInstance('{$mapperName}');\n"
            . "        }\n\n"
            . "        return new {$mapperImpl}(\n"
            . "            {$responseDtoFqcn}::class\n"
            . "        );\n"
            . "    }\n\n"
            . "    public static function {$serviceName}(bool \$getShared = true): {$serviceIfaceFqcn}\n"
            . "    {\n"
            . "        if (\$getShared) {\n"
            . "            return static::getSharedInstance('{$serviceName}');\n"
            . "        }\n\n"
            . "        return new {$serviceImplFqcn}(\n"
            . "            new {$repoImpl}(model({$modelFqcn}::class)),\n"
            . "            static::{$mapperName}()\n"
            . "        );\n"
            . "    }\n";
    }

    /**
     * The 2-line patch a consumer applies manually (with --no-wire) to their
     * `app/Config/Services.php` to pick up a new domain trait.
     */
    private function servicesRegisterSnippet(string $domain): string
    {
        return "// At the top of app/Config/Services.php (alongside other require_once lines):\n"
            . "require_once __DIR__ . '/{$domain}DomainServices.php';\n\n"
            . "// Inside the Services class body (alongside other 'use ...DomainServices;' lines):\n"
            . "    use {$domain}DomainServices;";
    }

    /**
     * Re-read Services.php and confirm both the require_once and use-trait
     * lines for the new domain are present.
     */
    private function verifyMainServicesRegistration(string $domain, ResourceSchema $schema): void
    {
        $servicesFile = $this->servicesFile();
        if (!file_exists($servicesFile)) {
            return;
        }

        $content     = (string) file_get_contents($servicesFile);
        $requireLine = "require_once __DIR__ . '/{$domain}DomainServices.php';";
        $useLine     = "use {$domain}DomainServices;";

        $missing = [];
        if (!str_contains($content, $requireLine)) {
            $missing[] = $requireLine;
        }
        if (!str_contains($content, $useLine)) {
            $missing[] = $useLine;
        }

        if ($missing !== []) {
            throw new WiringFailedException(
                sprintf(
                    "Could not auto-register the %s domain trait in Config/Services.php — the file's layout did not match the expected pattern. Missing lines:\n  %s",
                    $domain,
                    implode("\n  ", $missing)
                ),
                $this->previewWiring($schema)
            );
        }
    }

    /**
     * Re-read the domain trait file and confirm the new factory method was injected.
     */
    private function verifyServiceFactoryInjection(ResourceSchema $schema, string $traitFile): void
    {
        if (!file_exists($traitFile)) {
            throw new WiringFailedException(
                sprintf('Domain trait file vanished after writing: %s', $traitFile),
                $this->previewWiring($schema)
            );
        }

        $content     = (string) file_get_contents($traitFile);
        $serviceName = $schema->getResourceLower() . 'Service';

        if (!str_contains($content, "function {$serviceName}")) {
            throw new WiringFailedException(
                sprintf(
                    'Failed to inject %s() into %s — the trait file did not contain the expected closing brace pattern.',
                    $serviceName,
                    $traitFile
                ),
                $this->previewWiring($schema)
            );
        }
    }

    /**
     * Insert $newNode into a list of statements: after the last sibling require_once for
     * a ...Services.php file, or (fallback) immediately before `class Services`.
     *
     * @param array<mixed> $stmts Stmts array mutated in place (object property or top-level)
     */
    private function injectRequireIntoStmts(array &$stmts, Node\Stmt\Expression $newNode): void
    {
        $lastSiblingPos = -1;
        $pos            = 0;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt && $this->isServicesRequire($stmt)) {
                $lastSiblingPos = $pos;
            }
            $pos++;
        }

        if ($lastSiblingPos >= 0) {
            array_splice($stmts, $lastSiblingPos + 1, 0, [$newNode]);

            return;
        }

        // Fallback G1: no prior trait require — insert before `class Services`.
        $classPos = 0;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_ && $stmt->name?->name === 'Services') {
                array_splice($stmts, $classPos, 0, [$newNode]);

                return;
            }
            $classPos++;
        }
    }

    private function isServicesRequire(Node\Stmt $stmt): bool
    {
        if (!$stmt instanceof Node\Stmt\Expression) {
            return false;
        }

        if (!$stmt->expr instanceof Node\Expr\Include_) {
            return false;
        }

        $include = $stmt->expr;
        if (!$include->expr instanceof Node\Expr\BinaryOp\Concat) {
            return false;
        }

        $right = $include->expr->right;

        return $right instanceof Node\Scalar\String_
            && str_contains($right->value, 'Services.php');
    }

    private function buildRequireNode(string $domain): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Expr\BinaryOp\Concat(
                    new Node\Scalar\MagicConst\Dir(),
                    new Node\Scalar\String_("/{$domain}DomainServices.php")
                ),
                Node\Expr\Include_::TYPE_REQUIRE_ONCE
            )
        );
    }

    /**
     * @return list<Node\Stmt\ClassMethod>
     */
    private function extractMethodsFromSnippet(string $code): array
    {
        try {
            $parser = (new ParserFactory())->createForHostVersion();
            $stmts  = $parser->parse("<?php\nclass __Tmp {\n{$code}\n}") ?? [];
            $finder = new NodeFinder();
            $class  = $finder->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);

            if ($class === null) {
                return [];
            }

            return array_values(array_filter(
                $class->stmts,
                static fn ($s) => $s instanceof Node\Stmt\ClassMethod
            ));
        } catch (\PhpParser\Error) {
            return [];
        }
    }
}
