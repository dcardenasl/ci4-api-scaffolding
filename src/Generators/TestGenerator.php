<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Core\StringHelper;

/**
 * TestGenerator
 * Emits Unit/Integration/Feature test stubs for the new resource.
 *
 * Each stub includes at least one assertion that exercises the scaffolded code,
 * so the generated suite passes `vendor/bin/phpunit` immediately. Developers
 * extend these instead of deleting markTestIncomplete() calls.
 */
class TestGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'tests';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $domain = $schema->domain;
        $resource = $schema->resource;
        $unit = $this->config->paths->unitTests;
        $integration = $this->config->paths->integrationTests;
        $feature = $this->config->paths->featureTests;

        $tests = [
            ROOTPATH . "{$unit}/{$domain}/{$resource}ServiceTest.php" => $this->unitTestTemplate($schema),
            ROOTPATH . "{$integration}/{$resource}ModelTest.php" => $this->integrationTestTemplate($schema),
            ROOTPATH . "{$feature}/{$domain}/{$resource}ControllerTest.php" => $this->featureTestTemplate($schema),
        ];

        // Add architecture test placeholder (only once, idempotent).
        $archTest = ROOTPATH . 'tests/unit/Architecture/ArchitectureTest.php';
        if (!isset($tests[$archTest])) {
            $tests[$archTest] = $this->architectureTestTemplate();
        }

        return $tests;
    }

    private function unitTestTemplate(ResourceSchema $schema): string
    {
        $resource = $schema->resource;
        $resourceLower = $schema->getResourceLower();
        $interfaceNs = $this->config->namespaceFor($this->config->paths->interfaces) . '\\' . $schema->domain;
        $servicesFactoryFqcn = $this->config->servicesFactoryClass;
        $servicesFactoryShort = Fqcn::shortName($servicesFactoryFqcn);

        return $this->renderer->render('tests/UnitTest', [
            'domain'               => $schema->domain,
            'resource'             => $resource,
            'resourceLower'        => $resourceLower,
            'interfaceNs'          => $interfaceNs,
            'servicesFactoryFqcn'  => $servicesFactoryFqcn,
            'servicesFactoryShort' => $servicesFactoryShort,
        ]);
    }

    private function integrationTestTemplate(ResourceSchema $schema): string
    {
        $resource = $schema->resource;
        $modelNs = $this->config->namespaceFor($this->config->paths->models);

        return $this->renderer->render('tests/IntegrationTest', [
            'resource'     => $resource,
            'modelNs'      => $modelNs,
            'appNamespace' => $this->config->appNamespace,
            'tableName'    => $schema->getResourcePluralSnakeCase(),
        ]);
    }

    private function featureTestTemplate(ResourceSchema $schema): string
    {
        $resource = $schema->resource;
        // Routes are nested under the kebab-cased domain: /api/{apiVersion}/{domain-kebab}/{route}.
        // See RouteGenerator::baseTemplate().
        $fullPath = '/api/' . $schema->apiVersion . '/' . StringHelper::toKebab($schema->domain) . '/' . $schema->route;

        // Determine whether any auth filter gates the route group.
        // When gated: unauthenticated requests return 401 for all endpoints.
        // When open: the index endpoint returns 200 (empty list is still 200);
        // a request for a non-existent resource returns 404.
        $expectsAuth = false;
        foreach ($this->config->protectedRouteFilters as $filter) {
            if (
                str_starts_with($filter, 'jwtauth')
                || str_starts_with($filter, 'domainauth')
                || str_starts_with($filter, 'auth')
                || $filter === 'appKeyRequired'
            ) {
                $expectsAuth = true;
                break;
            }
        }
        $indexStatus = $expectsAuth ? 401 : 200;
        $showStatus  = $expectsAuth ? 401 : 404;
        $authReason  = $expectsAuth
            ? 'wraps every endpoint in an auth filter — an unauthenticated request returns 401'
            : 'is open — the index returns an empty list (200) and a non-existent resource returns 404';

        return $this->renderer->render('tests/FeatureTest', [
            'domain'       => $schema->domain,
            'resource'     => $resource,
            'authReason'   => $authReason,
            'appNamespace' => $this->config->appNamespace,
            'fullPath'     => $fullPath,
            'indexStatus'  => (string) $indexStatus,
            'showStatus'   => (string) $showStatus,
        ]);
    }

    private function architectureTestTemplate(): string
    {
        return $this->renderer->render('tests/ArchitectureTest', []);
    }
}
