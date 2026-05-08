<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

/**
 * Loads generator template files and substitutes {key} placeholders with
 * pre-computed string values.
 *
 * Templates live under src/Generators/Templates/ and use the extension
 * `.php.tpl`. Variables are delimited with single curly braces: {varName}.
 * Standalone `{` and `}` used as PHP syntax are safe because str_replace
 * only replaces exact `{key}` tokens that exist in the $vars map.
 */
class TemplateRenderer
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? __DIR__ . '/Templates';
    }

    /**
     * Render the named template by replacing every `{key}` marker with its value.
     *
     * @param string               $template Relative path inside Templates/ without extension.
     *                                       E.g. 'dto/IndexRequestDTO' for Templates/dto/IndexRequestDTO.php.tpl
     * @param array<string,string> $vars     Map of marker name → replacement value.
     *
     * @throws \RuntimeException if the template file does not exist.
     */
    public function render(string $template, array $vars): string
    {
        $path = $this->baseDir . '/' . $template . '.php.tpl';

        if (!is_file($path)) {
            throw new \RuntimeException("Generator template not found: {$path}");
        }

        $content = (string) file_get_contents($path);

        foreach ($vars as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }

        return $content;
    }
}
