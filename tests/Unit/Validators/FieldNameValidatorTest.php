<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Validators\FieldNameValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FieldNameValidatorTest extends TestCase
{
    private FieldNameValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FieldNameValidator();
    }

    public function testAcceptsValidFieldNames(): void
    {
        $this->validator->validate([
            new Field(name: 'name', type: 'string'),
            new Field(name: 'price', type: 'decimal'),
            new Field(name: 'category_id', type: 'fk', fkTable: 'categories'),
            new Field(name: 'is_active', type: 'bool'),
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRejectsPhpReservedKeyword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'class' is a PHP reserved keyword");

        $this->validator->validate([
            new Field(name: 'class', type: 'string'),
        ]);
    }

    public function testRejectsEngineManagedColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('engine-managed column');

        $this->validator->validate([
            new Field(name: 'created_at', type: 'datetime'),
        ]);
    }

    public function testRejectsDuplicateFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate field');

        $this->validator->validate([
            new Field(name: 'name', type: 'string'),
            new Field(name: 'name', type: 'int'),
        ]);
    }

    public function testRejectsMysqlReservedWord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MySQL reserved word');

        $this->validator->validate([
            new Field(name: 'order', type: 'int'),
        ]);
    }

    public function testRejectsInvalidIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid identifier');

        $this->validator->validate([
            new Field(name: '2invalid', type: 'string'),
        ]);
    }

    public function testRejectsIdColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'id' collides");

        $this->validator->validate([
            new Field(name: 'id', type: 'int'),
        ]);
    }

    public function testCaseInsensitiveReservedDetection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate([
            new Field(name: 'Class', type: 'string'),
        ]);
    }

    public function testBoolWithoutExplicitModifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bool fields must be tagged/');

        $this->validator->validate([
            new Field(name: 'is_paid', type: 'bool', required: false, nullable: false),
        ]);
    }

    public function testBoolWithRequiredModifierIsAccepted(): void
    {
        $this->validator->validate([
            new Field(name: 'is_paid', type: 'bool', required: true, nullable: false),
        ]);
        $this->addToAssertionCount(1);
    }

    public function testBoolWithNullableModifierIsAccepted(): void
    {
        $this->validator->validate([
            new Field(name: 'is_paid', type: 'bool', required: false, nullable: true),
        ]);
        $this->addToAssertionCount(1);
    }
}
