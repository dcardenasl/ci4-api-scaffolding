<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;

/**
 * ServiceGenerator
 * Generates the Service Interface and the Service Implementation.
 */
class ServiceGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'service';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $domain = $schema->domain;
        $resource = $schema->resource;
        $servicesPath = $this->config->paths->services;
        $interfacesPath = $this->config->paths->interfaces;

        return [
            APPPATH . "{$interfacesPath}/{$domain}/{$resource}ServiceInterface.php" => $this->interfaceTemplate($schema),
            APPPATH . "{$servicesPath}/{$domain}/{$resource}Service.php" => $this->serviceTemplate($schema),
        ];
    }

    private function interfaceTemplate(ResourceSchema $schema): string
    {
        $ns = $this->config->namespaceFor($this->config->paths->interfaces) . '\\' . $schema->domain;
        $contractFqcn = $this->config->serviceContractInterface;
        $contractShort = Fqcn::shortName($contractFqcn);

        return $this->renderer->render('service/ServiceInterface', [
            'ns'            => $ns,
            'contractFqcn'  => $contractFqcn,
            'contractShort' => $contractShort,
            'resource'      => $schema->resource,
        ]);
    }

    private function serviceTemplate(ResourceSchema $schema): string
    {
        $resourceLower = $schema->getResourceLower();
        $ns = $this->config->namespaceFor($this->config->paths->services) . '\\' . $schema->domain;
        $interfaceNs = $this->config->namespaceFor($this->config->paths->interfaces) . '\\' . $schema->domain;
        $repoFqcn = $this->config->repositoryInterface;
        $repoShort = Fqcn::shortName($repoFqcn);
        $mapperFqcn = $this->config->responseMapperInterface;
        $mapperShort = Fqcn::shortName($mapperFqcn);
        $serviceBaseFqcn = $this->config->serviceBaseClass;
        $serviceBaseShort = Fqcn::shortName($serviceBaseFqcn);

        return $this->renderer->render('service/Service', [
            'ns'               => $ns,
            'repoFqcn'         => $repoFqcn,
            'repoShort'        => $repoShort,
            'mapperFqcn'       => $mapperFqcn,
            'mapperShort'      => $mapperShort,
            'interfaceNs'      => $interfaceNs,
            'serviceBaseFqcn'  => $serviceBaseFqcn,
            'serviceBaseShort' => $serviceBaseShort,
            'resource'         => $schema->resource,
            'resourceLower'    => $resourceLower,
        ]);
    }
}
