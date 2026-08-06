<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use dcardenasl\Ci4ApiScaffolding\Config\BaseScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\StringHelper;
use dcardenasl\Ci4ApiScaffolding\Generators\LanguageGenerator;
use Throwable;

class ModuleCheck extends BaseCommand
{
    protected $group = 'Scaffold';
    protected $name = 'module:check';
    protected $description = 'Validate module bootstrap compliance for template architecture.';
    protected $usage = 'module:check <Resource> [--domain <Domain>]';
    protected $arguments = [
        'Resource' => 'Resource singular name (e.g. Product, InvoiceItem)',
    ];
    protected $options = [
        '--domain' => 'Domain folder (default: Catalog)',
    ];

    public function run(array $params)
    {
        $resourceInput = (string) ($params[0] ?? '');
        if ($resourceInput === '') {
            CLI::error('Resource argument is required. Example: php spark module:check Product --domain Catalog');
            return EXIT_ERROR;
        }

        $config = $this->loadConfig();

        $resource = StringHelper::studly($resourceInput);
        $resourceLower = StringHelper::toCamelCase($resource);
        $resourcePlural = StringHelper::pluralize($resource);
        $domain = StringHelper::studly((string) (CLI::getOption('domain') ?: 'Catalog'));
        $domainKebab = StringHelper::toKebab($domain);
        $p = $config->paths;

        $checks = [
            APPPATH . "{$p->controllers}/{$domain}/{$resource}Controller.php",
            APPPATH . "{$p->services}/{$domain}/{$resource}Service.php",
            APPPATH . "{$p->interfaces}/{$domain}/{$resource}ServiceInterface.php",
            APPPATH . "{$p->requestDtos}/{$domain}/{$resource}IndexRequestDTO.php",
            APPPATH . "{$p->requestDtos}/{$domain}/{$resource}CreateRequestDTO.php",
            APPPATH . "{$p->requestDtos}/{$domain}/{$resource}UpdateRequestDTO.php",
            APPPATH . "{$p->responseDtos}/{$domain}/{$resource}ResponseDTO.php",
            APPPATH . "{$p->documentation}/{$domain}/{$resource}Endpoints.php",
            APPPATH . "{$p->languageEn}/{$resourcePlural}.php",
            APPPATH . "{$p->languageEs}/{$resourcePlural}.php",
            ROOTPATH . "{$p->unitTests}/{$domain}/{$resource}ServiceTest.php",
            ROOTPATH . "{$p->integrationTests}/{$resource}ModelTest.php",
            ROOTPATH . "{$p->featureTests}/{$domain}/{$resource}ControllerTest.php",
        ];

        $missing = [];
        foreach ($checks as $path) {
            if (!file_exists($path)) {
                $missing[] = $path;
            }
        }

        $placeholderPatterns = ['markTestIncomplete', 'TODO', 'FIXME'];
        foreach ($checks as $path) {
            if (!is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            foreach ($placeholderPatterns as $pattern) {
                if (str_contains($source, $pattern)) {
                    $missing[] = "Placeholder `{$pattern}` found in {$path}";
                }
            }
        }

        // Check Domain Wiring in Services
        $domainServicesPath = APPPATH . "Config/{$domain}DomainServices.php";
        $servicesSource = is_file($domainServicesPath) ? (string) file_get_contents($domainServicesPath) : '';
        $serviceMethod = "{$resourceLower}Service";
        $mapperMethod = "{$resourceLower}ResponseMapper";
        if (!preg_match('/\bfunction\s+' . preg_quote($serviceMethod, '/') . '\s*\(/', $servicesSource)) {
            $missing[] = "Missing service registration in {$domain}DomainServices.php: function {$serviceMethod}(";
        }
        if (!preg_match('/\bfunction\s+' . preg_quote($mapperMethod, '/') . '\s*\(/', $servicesSource)) {
            $missing[] = "Missing mapper registration in {$domain}DomainServices.php: function {$mapperMethod}(";
        }

        // Check language parity
        $enPath = APPPATH . "{$p->languageEn}/{$resourcePlural}.php";
        $esPath = APPPATH . "{$p->languageEs}/{$resourcePlural}.php";
        $parity = (new LanguageGenerator($config))->checkParity($enPath, $esPath);
        foreach ($parity['parse_errors'] as $error) {
            $missing[] = $error;
        }
        foreach ($parity['missing_in_es'] as $key) {
            $missing[] = "Language key '{$key}' present in en but missing in es ({$resourcePlural}.php)";
        }
        foreach ($parity['missing_in_en'] as $key) {
            $missing[] = "Language key '{$key}' present in es but missing in en ({$resourcePlural}.php)";
        }

        // Check Routes
        $routesPath = APPPATH . "{$p->routes}/{$domainKebab}.php";
        $routesSource = is_file($routesPath) ? (string) file_get_contents($routesPath) : '';
        $controllerRef = "{$resource}Controller::";
        if (!preg_match('/' . preg_quote($controllerRef, '/') . '/', $routesSource)) {
            $missing[] = "Missing route reference in {$routesPath}: {$controllerRef}";
        }

        // Check content-localization prerequisites (LOC-007). The scaffold has no
        // separate schema-metadata file, so detect translatable/sluggable from the
        // generated Service's trait composition, then verify the app-level factories
        // these traits depend on actually exist — module:check runs inside the real
        // consumer app, so it's safe to check the loaded class directly rather than
        // parse files. Without this, the failure only surfaces as a cryptic
        // BadMethodCallException the first time the scaffolded service is resolved.
        $servicePath = APPPATH . "{$p->services}/{$domain}/{$resource}Service.php";
        $serviceSource = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
        if (str_contains($serviceSource, 'HasLocalizedTranslations')) {
            if (!method_exists(\Config\Services::class, 'localizedTranslationStore')) {
                $missing[] = "Config\\Services::localizedTranslationStore() not found — required by "
                    . "{$resource}Service's HasLocalizedTranslations. See "
                    . 'vendor/dcardenasl/ci4-api-core/docs/EXTENDING_LOCALIZATION.md § 4.';
            }
            if (!class_exists(\Config\Localization::class)) {
                $missing[] = 'app/Config/Localization.php not found — required to register '
                    . "{$resource}'s translatable fields. See "
                    . 'vendor/dcardenasl/ci4-api-core/docs/EXTENDING_LOCALIZATION.md § 1.';
            }
        }
        if (str_contains($serviceSource, 'HasPublicSlugs')) {
            if (!method_exists(\Config\Services::class, 'publicSlugStore')) {
                $missing[] = "Config\\Services::publicSlugStore() not found — required by "
                    . "{$resource}Service's HasPublicSlugs. See "
                    . 'vendor/dcardenasl/ci4-api-core/docs/EXTENDING_LOCALIZATION.md § 4.';
            }
        }

        if ($missing !== []) {
            CLI::error('Module bootstrap check failed.');
            foreach ($missing as $item) {
                CLI::write("- {$item}", 'red');
            }
            CLI::newLine();
            CLI::write('Possible next steps:', 'yellow');
            CLI::write("  - Re-run scaffold:  bash vendor/bin/make-crud.sh {$resource} {$domain} '<fields>' yes", 'yellow');
            CLI::write("  - Or remove leftover artifacts: php spark make:crud:remove {$resource} --domain {$domain}", 'yellow');
            return EXIT_ERROR;
        }

        CLI::write('Module bootstrap check passed.', 'green');
        return EXIT_SUCCESS;
    }

    private function loadConfig(): ScaffoldingConfig
    {
        try {
            $userConfig = config('Scaffolding');
        } catch (Throwable) {
            $userConfig = null;
        }

        if ($userConfig instanceof BaseScaffoldingConfig) {
            return $userConfig->build();
        }

        return ScaffoldingConfig::defaults();
    }
}
