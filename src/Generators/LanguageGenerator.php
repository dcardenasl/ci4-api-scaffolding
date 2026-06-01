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
            'fields'   => $this->generateFieldsArray($schema, 'en'),
        ]);
    }

    private function esTemplate(ResourceSchema $schema): string
    {
        return $this->renderer->render('language/es', [
            'resource' => $schema->resource,
            'fields'   => $this->generateFieldsArray($schema, 'es'),
        ]);
    }

    /**
     * Compare recursive keys between the en and es language files for a resource.
     *
     * @return array{
     *     missing_in_es: list<string>,
     *     missing_in_en: list<string>,
     *     parse_errors: list<string>
     * }
     */
    public function checkParity(string $enPath, string $esPath): array
    {
        $parseErrors = [];
        $en = $this->loadLanguageFile($enPath, 'en', $parseErrors);
        $es = $this->loadLanguageFile($esPath, 'es', $parseErrors);

        if ($en === null || $es === null) {
            return [
                'missing_in_es' => [],
                'missing_in_en' => [],
                'parse_errors'  => $parseErrors,
            ];
        }

        $enKeys = array_keys($this->flattenKeys($en));
        $esKeys = array_keys($this->flattenKeys($es));

        return [
            'missing_in_es' => array_values(array_diff($enKeys, $esKeys)),
            'missing_in_en' => array_values(array_diff($esKeys, $enKeys)),
            'parse_errors'  => $parseErrors,
        ];
    }

    private function generateFieldsArray(ResourceSchema $schema, string $locale): string
    {
        $content = '';
        foreach ($schema->fields as $field) {
            $label = $this->humanizeFieldLabel($field->name);
            $placeholder = $this->defaultPlaceholder($field->type, $label, $locale);
            $help = $this->defaultHelp($field->type, $label, $locale);

            $content .= "        '{$field->name}' => '{$label}',\n";
            $content .= "        '{$field->name}_placeholder' => '{$placeholder}',\n";
            $content .= "        '{$field->name}_help' => '{$help}',\n";
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                foreach ($this->flattenKeys($value, $path) as $nestedKey => $nestedValue) {
                    $out[$nestedKey] = $nestedValue;
                }

                continue;
            }

            $out[$path] = (string) $value;
        }

        return $out;
    }

    /**
     * @param list<string> $parseErrors
     * @return array<string, mixed>|null
     */
    private function loadLanguageFile(string $path, string $locale, array &$parseErrors): ?array
    {
        if (!is_file($path)) {
            $parseErrors[] = "Missing {$locale} language file: {$path}";
            return null;
        }

        try {
            $data = include $path;
        } catch (\Throwable $e) {
            $parseErrors[] = "Failed to parse {$locale} language file {$path}: {$e->getMessage()}";
            return null;
        }

        if (!is_array($data)) {
            $parseErrors[] = "Language file does not return array: {$path}";
            return null;
        }

        return $data;
    }

    private function humanizeFieldLabel(string $name): string
    {
        $base = preg_replace('/_id$/', '', $name) ?? $name;
        $base = str_replace('_', ' ', $base);
        $base = trim($base);

        if ($base === '') {
            return $name;
        }

        return preg_replace_callback(
            '/\b([a-z])/',
            static fn (array $match): string => strtoupper($match[1]),
            $base
        ) ?? $base;
    }

    private function defaultPlaceholder(string $type, string $label, string $locale): string
    {
        $prefix = $locale === 'es' ? 'Selecciona ' : 'Select ';
        if (in_array($type, ['string', 'text', 'longtext', 'int', 'bigint', 'decimal', 'float'], true)) {
            $prefix = $locale === 'es' ? 'Ingresa ' : 'Enter ';
        } elseif (in_array($type, ['boolean', 'bool'], true)) {
            $prefix = $locale === 'es' ? 'Activa o desactiva ' : 'Toggle ';
        } elseif (in_array($type, ['file', 'image'], true)) {
            $prefix = $locale === 'es' ? 'Elige ' : 'Choose ';
        }

        return $prefix . $label;
    }

    private function defaultHelp(string $type, string $label, string $locale): string
    {
        if (in_array($type, ['string', 'text', 'longtext', 'int', 'bigint', 'decimal', 'float'], true)) {
            $prefix = $locale === 'es' ? 'Ingresa ' : 'Enter ';
        } elseif (in_array($type, ['enum', 'relation', 'date', 'datetime'], true)) {
            $prefix = $locale === 'es' ? 'Selecciona ' : 'Select ';
        } elseif (in_array($type, ['file', 'image'], true)) {
            $prefix = $locale === 'es' ? 'Elige ' : 'Choose ';
        } elseif (in_array($type, ['boolean', 'bool'], true)) {
            $prefix = $locale === 'es' ? 'Activa o desactiva ' : 'Toggle ';
        } else {
            $prefix = $locale === 'es' ? 'Ingresa ' : 'Enter ';
        }

        return $prefix . $label . '.';
    }
}
