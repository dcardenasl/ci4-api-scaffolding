<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Config;

/**
 * Single value object that captures every consumer-app convention the
 * scaffolding engine needs to know about: which base classes generated
 * code extends, where files are written, what filters protect routes,
 * and what namespace prefix the app uses.
 *
 * Each consumer publishes one of these (typically as a CI4 Config class
 * `App\Config\Scaffolding`) and the spark commands inject it into the
 * generators. Nothing in the engine is hardcoded to `\App\Controllers\…`
 * — every base class, path, and filter is configurable.
 *
 * **Permission default:** the shipped `defaults()` factory protects new
 * routes with `permission:iam.superadmin-access` (the most-restrictive
 * permission seeded by `ci4-api-starter`'s `RbacBootstrapSeeder`). New
 * resources are reachable only by superadmins until you explicitly loosen
 * the filter to something like `permission:my-resource.read` — secure by
 * default. Consumers with a different IAM model should override
 * `protectedRouteFilters` in their `App\Config\Scaffolding`.
 *
 * All FQCNs are written without leading backslash — generators add
 * the leading `\\` only where the rendered template needs it.
 */
final readonly class ScaffoldingConfig
{
    /**
     * @param list<string>          $protectedRouteFilters       Filters applied to the
     *   "protected" route group emitted by RouteGenerator. The first one
     *   is typically auth, the rest are permission/throttle/etc.
     * @param array<string, string> $conditionalControllerTraits Map of fieldName => traitFQCN.
     *   When a scaffolded resource contains a field whose name matches a key, the
     *   corresponding trait is imported and injected via `use` in the generated controller.
     *   Example: ['slug' => 'App\\Traits\\Controllers\\HasSlugActions']
     */
    public function __construct(
        // Base classes the generated code extends or implements.
        public string $controllerBaseClass,
        public string $serviceBaseClass,
        public string $serviceContractInterface,
        public string $modelBaseClass,
        public string $entityBaseClass,
        public string $migrationBaseClass,
        public string $requestDtoBaseClass,
        public string $responseDtoInterface,
        public string $repositoryInterface,
        public string $responseMapperInterface,
        // Concrete implementations the ConfigWireman wires into the trait factories.
        public string $repositoryImplementation,
        public string $responseMapperImplementation,
        public string $servicesFactoryClass,

        // Where artifacts land.
        public ScaffoldingPaths $paths,

        // Route group filters applied to the "protected" group emitted by RouteGenerator.
        // First filter is typically auth (e.g. 'jwtauth'), the rest gate authorization.
        // The default in defaults() locks new routes behind the most-restrictive
        // seeded permission ('iam.superadmin-access') so resources are unreachable
        // until you intentionally loosen the filter — see the docblock above.
        public array $protectedRouteFilters,

        // Top-level namespace of the consumer app (e.g. 'App').
        public string $appNamespace = 'App',

        // OpenAPI tag name strategy. When null, the generator uses $schema->domain.
        public ?string $openApiTagPrefix = null,

        // fieldName => traitFQCN map for conditional trait injection in generated controllers.
        public array $conditionalControllerTraits = [],

        // FQCNs of the Filterable / Searchable traits emitted by ModelEntityGenerator.
        // Default to the bundled traits in dcardenasl\Ci4ApiCore\Models\Traits\, but
        // consumers may override (e.g. the legacy ci4-api-starter copies under
        // App\Traits\ before CORE-004 lands).
        public string $filterableTraitFqcn = 'dcardenasl\\Ci4ApiCore\\Models\\Traits\\Filterable',
        public string $searchableTraitFqcn = 'dcardenasl\\Ci4ApiCore\\Models\\Traits\\Searchable',

        // API version used for prefixing routes and tests (e.g., 'v1', 'v2')
        public string $apiVersion = 'v1',
    ) {
    }

    /**
     * Build the namespace that corresponds to a directory under APPPATH.
     * Example: namespaceFor('DTO/Request') === 'App\\DTO\\Request' (when appNamespace='App').
     */
    public function namespaceFor(string $path): string
    {
        return $this->appNamespace . '\\' . str_replace('/', '\\', trim($path, '/'));
    }

    /**
     * Convenience factory that returns a config matching the defaults shipped
     * with ci4-api-starter — useful for tests and for consumers who haven't
     * customized anything yet.
     *
     * @param list<string> $protectedRouteFilters Filters for the "protected" route group.
     *   Defaults to [] (no auth) — safe for any CI4 project. Consumers running
     *   ci4-api-starter should pass ['jwtauth', 'permission:iam.superadmin-access', 'throttle']
     *   (or their own filter list) via App\Config\Scaffolding::build().
     */
    public static function defaults(array $protectedRouteFilters = []): self
    {
        return new self(
            controllerBaseClass: 'dcardenasl\\Ci4ApiCore\\Http\\ApiController',
            serviceBaseClass: 'dcardenasl\\Ci4ApiCore\\Services\\BaseCrudService',
            serviceContractInterface: 'dcardenasl\\Ci4ApiCore\\Services\\CrudServiceContract',
            modelBaseClass: 'dcardenasl\\Ci4ApiCore\\Models\\BaseAuditableModel',
            entityBaseClass: 'CodeIgniter\\Entity\\Entity',
            migrationBaseClass: 'CodeIgniter\\Database\\Migration',
            requestDtoBaseClass: 'dcardenasl\\Ci4ApiCore\\Dto\\BaseRequestDTO',
            responseDtoInterface: 'dcardenasl\\Ci4ApiCore\\Dto\\DataTransferObjectInterface',
            repositoryInterface: 'dcardenasl\\Ci4ApiCore\\Repositories\\RepositoryInterface',
            responseMapperInterface: 'dcardenasl\\Ci4ApiCore\\Mappers\\ResponseMapperInterface',
            repositoryImplementation: 'dcardenasl\\Ci4ApiCore\\Repositories\\GenericRepository',
            responseMapperImplementation: 'dcardenasl\\Ci4ApiCore\\Mappers\\DtoResponseMapper',
            servicesFactoryClass: 'Config\\Services',
            paths: new ScaffoldingPaths(),
            protectedRouteFilters: $protectedRouteFilters,
            appNamespace: 'App',
            apiVersion: 'v1'
        );
    }
}
