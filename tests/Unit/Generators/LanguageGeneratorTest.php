<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\LanguageGenerator;
use PHPUnit\Framework\TestCase;

final class LanguageGeneratorTest extends TestCase
{
    public function testGenerateIncludesLocalizedPlaceholderAndHelpEntries(): void
    {
        $generator = new LanguageGenerator(ScaffoldingConfig::defaults());
        $schema = new ResourceSchema(
            resource: 'Faq',
            domain: 'Support',
            route: 'faqs',
            fields: [
                new Field(name: 'question', type: 'string', required: true),
                new Field(name: 'category_id', type: 'relation', required: true),
                new Field(name: 'is_published', type: 'boolean', required: false),
            ],
        );

        $artifacts = $generator->generate($schema);
        $en = $this->findArtifact($artifacts, '/en/');
        $es = $this->findArtifact($artifacts, '/es/');

        $this->assertStringContainsString("'question_placeholder' => 'Enter Question'", $en);
        $this->assertStringContainsString("'question_help' => 'Enter Question.'", $en);
        $this->assertStringContainsString("'category_id_placeholder' => 'Select Category'", $en);
        $this->assertStringContainsString("'category_id_help' => 'Select Category.'", $en);
        $this->assertStringContainsString("'is_published_placeholder' => 'Toggle Is Published'", $en);
        $this->assertStringContainsString("'is_published_help' => 'Toggle Is Published.'", $en);

        $this->assertStringContainsString("'question_placeholder' => 'Ingresa Question'", $es);
        $this->assertStringContainsString("'question_help' => 'Ingresa Question.'", $es);
        $this->assertStringContainsString("'category_id_placeholder' => 'Selecciona Category'", $es);
        $this->assertStringContainsString("'category_id_help' => 'Selecciona Category.'", $es);
        $this->assertStringContainsString("'is_published_placeholder' => 'Activa o desactiva Is Published'", $es);
        $this->assertStringContainsString("'is_published_help' => 'Activa o desactiva Is Published.'", $es);
    }

    public function testCheckParityReportsRecursiveDriftAndParseErrors(): void
    {
        $generator = new LanguageGenerator(ScaffoldingConfig::defaults());
        $enPath = tempnam(sys_get_temp_dir(), 'lang-en-');
        $esPath = tempnam(sys_get_temp_dir(), 'lang-es-');

        $this->assertIsString($enPath);
        $this->assertIsString($esPath);

        try {
            file_put_contents($enPath, <<<'PHP'
<?php

return [
    'fields' => [
        'question' => 'Question',
        'question_placeholder' => 'Enter Question',
        'question_help' => 'Enter Question.',
    ],
];
PHP);

            file_put_contents($esPath, <<<'PHP'
<?php

return [
    'fields' => [
        'question' => 'Pregunta',
    ],
];
PHP);

            $parity = $generator->checkParity($enPath, $esPath);

            $this->assertSame(['fields.question_placeholder', 'fields.question_help'], $parity['missing_in_es']);
            $this->assertSame([], $parity['missing_in_en']);
            $this->assertSame([], $parity['parse_errors']);

            file_put_contents($esPath, <<<'PHP'
<?php

return [
    'fields' => [
        'question' => 'Pregunta',
    ]
PHP);

            $parity = $generator->checkParity($enPath, $esPath);

            $this->assertNotEmpty($parity['parse_errors']);
            $this->assertStringContainsString('Failed to parse es language file', $parity['parse_errors'][0]);
        } finally {
            @unlink($enPath);
            @unlink($esPath);
        }
    }

    /**
     * @param array<string, string> $artifacts
     */
    private function findArtifact(array $artifacts, string $needle): string
    {
        $matches = array_filter(
            $artifacts,
            static fn (string $path): bool => str_contains($path, $needle),
            ARRAY_FILTER_USE_KEY,
        );

        self::assertCount(1, $matches);

        return array_values($matches)[0];
    }
}
