<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;

/**
 * ControllerGenerator
 * Generates the API Controller and its corresponding OpenAPI Documentation class.
 */
class ControllerGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'controller';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $domain = $schema->domain;
        $resource = $schema->resource;
        $controllersPath = $this->config->paths->controllers;
        $docsPath = $this->config->paths->documentation;

        return [
            APPPATH . "{$controllersPath}/{$domain}/{$resource}Controller.php" => $this->controllerTemplate($schema),
            APPPATH . "{$docsPath}/{$domain}/{$resource}Endpoints.php" => $this->docEndpointsTemplate($schema),
        ];
    }

    private function controllerTemplate(ResourceSchema $schema): string
    {
        $resource = $schema->resource;
        $resourceLower = $schema->getResourceLower();
        $domain = $schema->domain;

        $ns = $this->config->namespaceFor($this->config->paths->controllers) . '\\' . $domain;
        $reqDtoNs = $this->config->namespaceFor($this->config->paths->requestDtos) . '\\' . $domain;
        $interfaceNs = $this->config->namespaceFor($this->config->paths->interfaces) . '\\' . $domain;

        $controllerBaseFqcn = $this->config->controllerBaseClass;
        $controllerBaseShort = Fqcn::shortName($controllerBaseFqcn);
        $servicesFactoryFqcn = $this->config->servicesFactoryClass;
        $servicesFactoryShort = Fqcn::shortName($servicesFactoryFqcn);

        [$traitImports, $traitUseBlock] = $this->resolveConditionalTraits($schema);

        return $this->renderer->render('controller/Controller', [
            'ns'                   => $ns,
            'controllerBaseFqcn'   => $controllerBaseFqcn,
            'controllerBaseShort'  => $controllerBaseShort,
            'reqDtoNs'             => $reqDtoNs,
            'interfaceNs'          => $interfaceNs,
            'servicesFactoryFqcn'  => $servicesFactoryFqcn,
            'servicesFactoryShort' => $servicesFactoryShort,
            'resource'             => $resource,
            'resourceLower'        => $resourceLower,
            'traitImports'         => $traitImports,
            'traitUseBlock'        => $traitUseBlock,
        ]);
    }

    /**
     * @return array{string, string} [traitImports, traitUseBlock]
     *   traitImports:  "\nuse FqcnA;\nuse FqcnB;" (empty string when none)
     *   traitUseBlock: "\n    use ShortA;\n    use ShortB;\n" (empty string when none)
     */
    private function resolveConditionalTraits(ResourceSchema $schema): array
    {
        $fieldNames = array_map(fn ($f) => $f->name, $schema->fields);
        $imports = '';
        $uses = '';

        foreach ($this->config->conditionalControllerTraits as $fieldName => $traitFqcn) {
            if (in_array($fieldName, $fieldNames, true)) {
                $imports .= "\nuse {$traitFqcn};";
                $uses .= "\n    use " . Fqcn::shortName($traitFqcn) . ';';
            }
        }

        return [$imports, $uses !== '' ? $uses . "\n" : ''];
    }

    private function docEndpointsTemplate(ResourceSchema $schema): string
    {
        $resource = $schema->resource;
        $domain = $schema->domain;
        $route = $schema->route;
        $plural = $schema->getResourcePlural();
        $domainKebab = $schema->toKebab($domain);

        $ns = $this->config->namespaceFor($this->config->paths->documentation) . '\\' . $domain;

        return $this->renderer->render('controller/Endpoints', [
            'ns'          => $ns,
            'resource'    => $resource,
            'domain'      => $domain,
            'route'       => $route,
            'plural'      => $plural,
            'domainKebab' => $domainKebab,
            'apiVersion'  => $schema->apiVersion,
        ]);
    }
}
