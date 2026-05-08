<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;

/**
 * LanguageGenerator
 * Generates translation files for English and Spanish.
 */
class LanguageGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'language';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $resourcePlural = $schema->getResourcePlural();

        return [
            APPPATH . $this->config->paths->languageEn . "/{$resourcePlural}.php" => $this->enTemplate($schema),
            APPPATH . $this->config->paths->languageEs . "/{$resourcePlural}.php" => $this->esTemplate($schema),
        ];
    }

    private function enTemplate(ResourceSchema $schema): string
    {
        return $this->renderer->render('language/en', [
            'resource' => $schema->resource,
            'fields'   => $this->generateFieldsArray($schema),
        ]);
    }

    private function esTemplate(ResourceSchema $schema): string
    {
        return $this->renderer->render('language/es', [
            'resource' => $schema->resource,
            'fields'   => $this->generateFieldsArray($schema),
        ]);
    }

    /**
     * Compare top-level keys between the en and es language files for a resource.
     *
     * @return array{missing_in_es: list<string>, missing_in_en: list<string>}
     */
    public function checkParity(string $enPath, string $esPath): array
    {
        if (!is_file($enPath) || !is_file($esPath)) {
            return ['missing_in_es' => [], 'missing_in_en' => []];
        }

        $en = include $enPath;
        $es = include $esPath;

        if (!is_array($en) || !is_array($es)) {
            return ['missing_in_es' => [], 'missing_in_en' => []];
        }

        return [
            'missing_in_es' => array_keys(array_diff_key($en, $es)),
            'missing_in_en' => array_keys(array_diff_key($es, $en)),
        ];
    }

    private function generateFieldsArray(ResourceSchema $schema): string
    {
        $content = '';
        foreach ($schema->fields as $field) {
            $label = ucfirst(str_replace('_', ' ', $field->name));
            $content .= "        '{$field->name}' => '{$label}',\n";
        }
        return $content;
    }
}
