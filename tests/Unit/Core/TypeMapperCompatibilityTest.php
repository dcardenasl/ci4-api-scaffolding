<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\TypeMapper;
use PHPUnit\Framework\TestCase;

final class TypeMapperCompatibilityTest extends TestCase
{
    public function testBoolFieldsGenerateBooleanLikeRuleForSupportedStarters(): void
    {
        $rules = TypeMapper::getValidationRules(new Field(name: 'is_active', type: 'bool', required: false));

        $this->assertSame('permit_empty|boolean_like', $rules);
    }

    public function testRelationFieldsShareFkValidationContract(): void
    {
        $rules = TypeMapper::getValidationRules(
            new Field(name: 'category_id', type: 'relation', required: true, fkTable: 'categories')
        );

        $this->assertSame('required|is_natural_no_zero|is_not_unique[categories.id]', $rules);
    }

    public function testSupportedStarterReposExposeBooleanLikeRule(): void
    {
        foreach (['ci4-api-starter', 'ci4-domain-starter'] as $repoName) {
            $rulesFile = $this->workspaceRoot() . "/{$repoName}/app/Validations/Rules/CustomRules.php";
            $validationConfig = $this->workspaceRoot() . "/{$repoName}/app/Config/Validation.php";

            if (!is_file($rulesFile) || !is_file($validationConfig)) {
                $this->markTestSkipped("Workspace repo {$repoName} not available for cross-repo compatibility check.");
            }

            $rulesSource = (string) file_get_contents($rulesFile);
            $validationSource = (string) file_get_contents($validationConfig);

            $this->assertStringContainsString(
                'function boolean_like',
                $rulesSource,
                "{$repoName} must implement boolean_like() because scaffolding emits that rule for bool fields."
            );
            $this->assertStringContainsString(
                'CustomRules::class',
                $validationSource,
                "{$repoName} must register CustomRules so generated boolean validations resolve at runtime."
            );
        }
    }

    private function workspaceRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
