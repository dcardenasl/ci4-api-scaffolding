<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Config;

/**
 * Directory layout where each generator drops its artifact, expressed
 * relative to the consumer app's APPPATH (or ROOTPATH for tests).
 *
 * Defaults match the convention shipped with `ci4-api-starter`. Consumers
 * that organize their app differently override individual paths from
 * `app/Config/Scaffolding.php`.
 *
 * Trailing slashes are intentionally absent — generators concatenate
 * `{path}/{ResourceName}.php`.
 */
final readonly class ScaffoldingPaths
{
    public function __construct(
        // Under APPPATH:
        public string $controllers = 'Controllers/Api/V1',
        public string $services = 'Services',
        public string $interfaces = 'Interfaces',
        public string $requestDtos = 'DTO/Request',
        public string $responseDtos = 'DTO/Response',
        public string $documentation = 'Documentation',
        public string $models = 'Models',
        public string $entities = 'Entities',
        public string $migrations = 'Database/Migrations',
        public string $routes = 'Config/Routes/v1',
        public string $languageEn = 'Language/en',
        public string $languageEs = 'Language/es',
        // Under ROOTPATH:
        public string $unitTests = 'tests/Unit/Services',
        public string $integrationTests = 'tests/Integration/Models',
        public string $featureTests = 'tests/Feature/Controllers',
    ) {
    }
}
