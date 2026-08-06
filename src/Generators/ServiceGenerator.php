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
        $entityNs = $this->config->namespaceFor($this->config->paths->entities);
        $entityFqcn = $entityNs . '\\' . $schema->resource . 'Entity';
        $repoFqcn = $this->config->repositoryInterface;
        $repoShort = Fqcn::shortName($repoFqcn);
        $mapperFqcn = $this->config->responseMapperInterface;
        $mapperShort = Fqcn::shortName($mapperFqcn);
        $serviceBaseFqcn = $this->config->serviceBaseClass;
        $serviceBaseShort = Fqcn::shortName($serviceBaseFqcn);

        return $this->renderer->render('service/Service', [
            'ns'                              => $ns,
            'entityFqcn'                      => $entityFqcn,
            'repoFqcn'                         => $repoFqcn,
            'repoShort'                        => $repoShort,
            'mapperFqcn'                       => $mapperFqcn,
            'mapperShort'                      => $mapperShort,
            'interfaceNs'                      => $interfaceNs,
            'serviceBaseFqcn'                  => $serviceBaseFqcn,
            'serviceBaseShort'                 => $serviceBaseShort,
            'resource'                         => $schema->resource,
            'resourceLower'                    => $resourceLower,
            'localizationUses'                 => $this->localizationUses($schema),
            'localizationTraits'               => $this->localizationTraits($schema),
            'localizationConstructorParams'    => $this->localizationConstructorParams($schema),
            'localizationConstructorAssignments' => $this->localizationConstructorAssignments($schema),
            'localizationOverrides'            => $this->localizationOverrides($schema),
        ]);
    }

    /**
     * Everything below composes HasLocalizedTranslations (and, when the
     * resource is also sluggable, HasPublicSlugs) exactly as documented in
     * ci4-api-core's docs/EXTENDING_LOCALIZATION.md — trait aliasing plus
     * the six lifecycle-hook overrides are only needed when BOTH traits are
     * combined; HasLocalizedTranslations alone needs no overrides at all,
     * since its own beforeStore/afterStore/... become the class's effective
     * hooks directly.
     */
    private function localizationUses(ResourceSchema $schema): string
    {
        if (!$schema->hasTranslatableFields()) {
            return '';
        }

        $lines = ['use dcardenasl\\Ci4ApiCore\\Localization\\LocalizedTranslationStore;'];
        if ($schema->isSluggable()) {
            $lines[] = 'use dcardenasl\\Ci4ApiCore\\Dto\\DataTransferObjectInterface;';
            $lines[] = 'use dcardenasl\\Ci4ApiCore\\Dto\\SecurityContext;';
            $lines[] = 'use dcardenasl\\Ci4ApiCore\\Localization\\PublicSlugStore;';
            $lines[] = 'use dcardenasl\\Ci4ApiCore\\Services\\HasLocalizedTranslations;';
            $lines[] = 'use dcardenasl\\Ci4ApiCore\\Services\\HasPublicSlugs;';
        } else {
            $lines[] = 'use dcardenasl\\Ci4ApiCore\\Services\\HasLocalizedTranslations;';
        }
        sort($lines);

        return implode("\n", $lines) . "\n";
    }

    private function localizationTraits(ResourceSchema $schema): string
    {
        if (!$schema->hasTranslatableFields()) {
            return '';
        }

        if (!$schema->isSluggable()) {
            return "    use HasLocalizedTranslations;\n\n";
        }

        return "    use HasLocalizedTranslations {\n"
            . "        beforeStore as private localizedBeforeStore;\n"
            . "        afterStore as private localizedAfterStore;\n"
            . "        beforeUpdate as private localizedBeforeUpdate;\n"
            . "        afterUpdate as private localizedAfterUpdate;\n"
            . "        enrichEntities as private localizedEnrichEntities;\n"
            . "        mapToResponse as private localizedMapToResponse;\n"
            . "    }\n"
            . "    use HasPublicSlugs;\n\n";
    }

    private function localizationConstructorParams(ResourceSchema $schema): string
    {
        if (!$schema->hasTranslatableFields()) {
            return '';
        }

        if (!$schema->isSluggable()) {
            return ",\n        LocalizedTranslationStore \$translationStore";
        }

        return ",\n        LocalizedTranslationStore \$translationStore,\n        PublicSlugStore \$slugStore";
    }

    private function localizationConstructorAssignments(ResourceSchema $schema): string
    {
        if (!$schema->hasTranslatableFields()) {
            return '';
        }

        $resourceType = $schema->localizationResourceType();
        $lines = [
            "        \$this->translationStore = \$translationStore;",
            "        \$this->localizedResourceType = '{$resourceType}';",
        ];

        if ($schema->isSluggable()) {
            $lines[] = "        \$this->slugStore = \$slugStore;";
            $lines[] = "        \$this->slugResourceType = '{$resourceType}';";
            $lines[] = "        \$this->slugSourceField = '{$schema->sluggableField}';";
        }

        return implode("\n", $lines) . "\n";
    }

    private function localizationOverrides(ResourceSchema $schema): string
    {
        if (!$schema->hasTranslatableFields() || !$schema->isSluggable()) {
            return '';
        }

        return "\n"
            . "    /** @param array<string, mixed> \$data */\n"
            . "    protected function beforeStore(array \$data, ?SecurityContext \$context): array\n"
            . "    {\n"
            . "        \$this->pendingManualSlugs = \$this->extractManualSlugs(\$data);\n\n"
            . "        return \$this->localizedBeforeStore(\$data, \$context);\n"
            . "    }\n\n"
            . "    protected function afterStore(object \$entity, ?SecurityContext \$context): void\n"
            . "    {\n"
            . "        \$this->localizedAfterStore(\$entity, \$context);\n"
            . "        \$this->syncPublicSlugs(\$entity);\n"
            . "    }\n\n"
            . "    /** @param array<string, mixed> \$data */\n"
            . "    protected function beforeUpdate(int \$id, array \$data, ?SecurityContext \$context): array\n"
            . "    {\n"
            . "        \$this->pendingManualSlugs = \$this->extractManualSlugs(\$data);\n\n"
            . "        return \$this->localizedBeforeUpdate(\$id, \$data, \$context);\n"
            . "    }\n\n"
            . "    protected function afterUpdate(object \$entity, ?SecurityContext \$context): void\n"
            . "    {\n"
            . "        \$this->localizedAfterUpdate(\$entity, \$context);\n"
            . "        \$this->syncPublicSlugs(\$entity);\n"
            . "    }\n\n"
            . "    /** @param array<int, object> \$entities */\n"
            . "    protected function enrichEntities(array \$entities): array\n"
            . "    {\n"
            . "        return \$this->attachSlugs(\$this->localizedEnrichEntities(\$entities));\n"
            . "    }\n\n"
            . "    protected function mapToResponse(object \$entity): DataTransferObjectInterface\n"
            . "    {\n"
            . "        \$this->attachSlugsToEntity(\$entity);\n\n"
            . "        return \$this->localizedMapToResponse(\$entity);\n"
            . "    }\n";
    }
}
