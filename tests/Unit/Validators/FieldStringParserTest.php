<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use dcardenasl\Ci4ApiScaffolding\Validators\FieldStringParser;
use dcardenasl\Ci4ApiScaffolding\Validators\UnknownFieldTypeException;
use PHPUnit\Framework\TestCase;

final class FieldStringParserTest extends TestCase
{
    private FieldStringParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FieldStringParser();
    }

    public function testParsesThreeSegmentTypeWithPipeSeparatedOptions(): void
    {
        $fields = $this->parser->parse('name:string:required|searchable|unique');

        $this->assertCount(1, $fields);
        $field = $fields[0];

        $this->assertSame('name', $field->name);
        $this->assertSame('string', $field->type);
        $this->assertTrue($field->required);
        $this->assertTrue($field->searchable);
        $this->assertTrue($field->unique);
        $this->assertFalse($field->filterable);
        $this->assertNull($field->fkTable);
    }

    /**
     * Regression: the foreign-key-like types use a 4-segment form —
     * `name:fk:target_table:opts` or `name:relation:target_table:opts`.
     * The previous parser only read three segments and looked for an `fk:xxx` modifier
     * inside options, so a correctly documented input like `parent_id:fk:categories:nullable`
     * produced a field with fkTable=null and nullable=false, breaking both the migration's
     * FK constraint and the DTO's validation rule.
     */
    public function testParsesFkFieldWithTableAndModifiers(): void
    {
        $fields = $this->parser->parse('parent_id:fk:categories:required|filterable');

        $this->assertCount(1, $fields);
        $field = $fields[0];

        $this->assertSame('parent_id', $field->name);
        $this->assertSame('fk', $field->type);
        $this->assertSame('categories', $field->fkTable);
        $this->assertTrue($field->required);
        $this->assertTrue($field->filterable);
    }

    public function testParsesRelationFieldWithTableAndModifiers(): void
    {
        $fields = $this->parser->parse('parent_id:relation:categories:required|filterable');

        $this->assertCount(1, $fields);
        $field = $fields[0];

        $this->assertSame('parent_id', $field->name);
        $this->assertSame('relation', $field->type);
        $this->assertSame('categories', $field->fkTable);
        $this->assertTrue($field->required);
        $this->assertTrue($field->filterable);
    }

    public function testParsesFkFieldWithNullableModifier(): void
    {
        $fields = $this->parser->parse('category_id:fk:categories:nullable');

        $this->assertCount(1, $fields);
        $field = $fields[0];

        $this->assertSame('categories', $field->fkTable);
        $this->assertTrue($field->nullable);
        $this->assertFalse($field->required);
    }

    public function testParsesFkFieldWithoutModifiers(): void
    {
        $fields = $this->parser->parse('author_id:fk:users');

        $this->assertCount(1, $fields);
        $this->assertSame('users', $fields[0]->fkTable);
    }

    public function testParsesMultipleCommaSeparatedFields(): void
    {
        $fields = $this->parser->parse(
            'name:string:required|searchable,price:decimal:required|filterable,is_active:bool'
        );

        $this->assertCount(3, $fields);
        $this->assertSame('name', $fields[0]->name);
        $this->assertSame('price', $fields[1]->name);
        $this->assertSame('is_active', $fields[2]->name);
    }

    public function testReturnsEmptyArrayForEmptyString(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('   '));
    }

    public function testSkipsMalformedSegments(): void
    {
        $fields = $this->parser->parse('orphan,name:string:required');

        $this->assertCount(1, $fields);
        $this->assertSame('name', $fields[0]->name);
    }

    public function testFkDefaultsToCascade(): void
    {
        $fields = $this->parser->parse('user_id:fk:users:required');
        $this->assertCount(1, $fields);
        $this->assertSame('CASCADE', $fields[0]->fkOnDelete);
    }

    public function testFkRestrictModifierSetsRestrict(): void
    {
        $fields = $this->parser->parse('user_id:fk:users:required|restrict');
        $this->assertSame('RESTRICT', $fields[0]->fkOnDelete);
    }

    public function testRelationRestrictModifierSetsRestrict(): void
    {
        $fields = $this->parser->parse('user_id:relation:users:required|restrict');
        $this->assertSame('RESTRICT', $fields[0]->fkOnDelete);
    }

    public function testFkSetNullModifierSetsSetNull(): void
    {
        $fields = $this->parser->parse('user_id:fk:users:nullable|setnull');
        $this->assertSame('SET NULL', $fields[0]->fkOnDelete);
    }

    public function testNonFkFieldsIgnoreReferentialModifiers(): void
    {
        $fields = $this->parser->parse('name:string:required|restrict');
        $this->assertSame('CASCADE', $fields[0]->fkOnDelete, 'Non-FK fields should not honor restrict/setnull');
    }

    public function testUnknownTypeRaisesExplicitException(): void
    {
        // Without strict validation, TypeMapper::get() falls back to 'string'
        // for unknown type codes — meaning a typo like `intenger` would
        // silently produce a VARCHAR column with string validation rules.
        // The parser must catch this upfront with an actionable error.
        try {
            $this->parser->parse('age:intenger:required');
            $this->fail('Expected UnknownFieldTypeException for typo "intenger".');
        } catch (UnknownFieldTypeException $e) {
            $this->assertSame('age', $e->fieldName);
            $this->assertSame('intenger', $e->declaredType);
            $this->assertContains('int', $e->knownTypes);
            $this->assertStringContainsString('intenger', $e->getMessage());
            $this->assertStringContainsString('int', $e->getMessage());
        }
    }

    public function testUnknownTypeMessageListsAllKnownTypesSorted(): void
    {
        try {
            $this->parser->parse('foo:nope');
            $this->fail('Expected UnknownFieldTypeException.');
        } catch (UnknownFieldTypeException $e) {
            // Sanity-check that the suggested types include the expected vocabulary.
            $expected = ['bool', 'date', 'datetime', 'decimal', 'email', 'fk', 'int', 'integer', 'json', 'relation', 'string', 'text'];
            foreach ($expected as $known) {
                $this->assertContains($known, $e->knownTypes, "Missing known type: {$known}");
            }
            $this->assertSame($e->knownTypes, array_values(array_unique($e->knownTypes)));
        }
    }
}
