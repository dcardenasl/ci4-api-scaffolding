<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Generate the consumer's `public/swagger.json` from `zircote/swagger-php`
 * annotations across:
 *
 *   - `app/Config/OpenApi.php`
 *   - `app/Controllers/`
 *   - `app/Documentation/`
 *   - `app/DTO/`
 *   - `vendor/dcardenasl/ci4-api-core/src/Dto/`
 *
 * Subclasses may override `scanPaths()` to add domain-specific directories.
 *
 * Requires `zircote/swagger-php` (^4.10 || ^5 || ^6) — listed as a
 * `suggest` of this package; install in the consumer's `require-dev`.
 */
class SwaggerGenerate extends BaseCommand
{
    protected $group       = 'ci4-api-scaffolding';
    protected $name        = 'swagger:generate';
    protected $description = 'Generate OpenAPI/Swagger documentation from annotations into public/swagger.json.';
    protected $usage       = 'swagger:generate';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        if (! class_exists(\OpenApi\Generator::class)) {
            CLI::error('zircote/swagger-php is not installed. Add it to require-dev:');
            CLI::write('  composer require --dev zircote/swagger-php');

            return EXIT_ERROR;
        }

        CLI::write('Generating OpenAPI documentation...', 'yellow');

        try {
            $outputPath = FCPATH . 'swagger.json';
            $openapi    = (new \OpenApi\Generator())->generate($this->scanPaths());

            if ($openapi === null) {
                CLI::error('OpenAPI generator returned no output (no annotations detected?).');

                return EXIT_ERROR;
            }

            $json = $openapi->toJson();
            if (file_put_contents($outputPath, $json) === false) {
                CLI::error('Failed to write ' . $outputPath);

                return EXIT_ERROR;
            }

            $endpointCount    = count((array) $openapi->paths);
            $schemaCount      = count((array) $openapi->components->schemas);
            $responseCount    = count((array) $openapi->components->responses);
            $requestBodyCount = count((array) $openapi->components->requestBodies);

            CLI::write('OpenAPI documentation generated successfully!', 'green');
            CLI::write('Location: ' . $outputPath, 'green');
            CLI::write('');
            CLI::write('Statistics:', 'cyan');
            CLI::write('  Endpoints: ' . $endpointCount);
            CLI::write('  Schemas: ' . $schemaCount);
            CLI::write('  Reusable Responses: ' . $responseCount);
            CLI::write('  Request Bodies: ' . $requestBodyCount);
        } catch (\Throwable $e) {
            CLI::error('Failed to generate OpenAPI documentation');
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    /**
     * Directories and files scanned for OpenAPI annotations. Override in
     * a subclass to inject extra paths (e.g. domain-specific modules).
     *
     * @return list<string>
     */
    protected function scanPaths(): array
    {
        return [
            APPPATH . 'Config/OpenApi.php',
            APPPATH . 'Controllers/',
            APPPATH . 'Documentation/',
            APPPATH . 'DTO/',
            ROOTPATH . 'vendor/dcardenasl/ci4-api-core/src/Dto/',
        ];
    }
}
