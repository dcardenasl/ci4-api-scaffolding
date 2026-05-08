<?php

declare(strict_types=1);

namespace Tests\Unit\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Generators\MigrationGenerator;
use PHPUnit\Framework\TestCase;

final class MigrationGeneratorTest extends TestCase
{
    private MigrationGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MigrationGenerator(ScaffoldingConfig::defaults());
    }

    private function generate(ResourceSchema $schema): string
    {
        return array_values($this->generator->generate($schema))[0];
    }

    public function test_down_without_fk_fields_emits_only_drop_table(): void
    {
        $schema = new ResourceSchema(
            resource: 'Article',
            domain: 'Blog',
            route: 'articles',
            fields: [new Field(name: 'title', type: 'string', required: true)],
        );

        $content = $this->generate($schema);

        $this->assertStringContainsString("\$this->forge->dropTable('articles')", $content);
        $this->assertStringNotContainsString(
            'dropForeignKey',
            $content,
            'down() must not emit dropForeignKey when the schema has no FK fields'
        );
    }

    public function test_down_with_one_fk_field_emits_drop_foreign_key_before_drop_table(): void
    {
        $schema = new ResourceSchema(
            resource: 'Post',
            domain: 'Blog',
            route: 'posts',
            fields: [
                new Field(name: 'title', type: 'string', required: true),
                new Field(name: 'category_id', type: 'int', fkTable: 'categories'),
            ],
        );

        $content = $this->generate($schema);

        $this->assertStringContainsString(
            "\$this->forge->dropForeignKey('posts', 'fk_posts_category_id')",
            $content,
            'down() must drop the FK constraint with the canonical name'
        );
        $dropFkPos = strpos($content, 'dropForeignKey');
        $dropTablePos = strpos($content, 'dropTable');
        $this->assertNotFalse($dropFkPos);
        $this->assertNotFalse($dropTablePos);
        $this->assertLessThan($dropTablePos, $dropFkPos, 'dropForeignKey must appear before dropTable in down()');
    }

    public function test_down_with_multiple_fk_fields_emits_all_drops_before_drop_table(): void
    {
        $schema = new ResourceSchema(
            resource: 'Comment',
            domain: 'Blog',
            route: 'comments',
            fields: [
                new Field(name: 'body', type: 'text', required: true),
                new Field(name: 'post_id', type: 'int', fkTable: 'posts'),
                new Field(name: 'author_id', type: 'int', fkTable: 'users'),
            ],
        );

        $content = $this->generate($schema);

        $this->assertStringContainsString(
            "\$this->forge->dropForeignKey('comments', 'fk_comments_post_id')",
            $content
        );
        $this->assertStringContainsString(
            "\$this->forge->dropForeignKey('comments', 'fk_comments_author_id')",
            $content
        );
        $dropTablePos = strpos($content, 'dropTable');
        $this->assertNotFalse($dropTablePos);
        $this->assertLessThan(
            $dropTablePos,
            strpos($content, 'fk_comments_post_id'),
            'fk_comments_post_id drop must precede dropTable'
        );
        $this->assertLessThan(
            $dropTablePos,
            strpos($content, 'fk_comments_author_id'),
            'fk_comments_author_id drop must precede dropTable'
        );
    }

    public function test_up_uses_explicit_fk_constraint_name(): void
    {
        $schema = new ResourceSchema(
            resource: 'Post',
            domain: 'Blog',
            route: 'posts',
            fields: [
                new Field(name: 'category_id', type: 'int', fkTable: 'categories'),
            ],
        );

        $content = $this->generate($schema);

        $this->assertStringContainsString(
            "'fk_posts_category_id'",
            $content,
            'up() must pass an explicit FK constraint name so down() can reference it by name'
        );
    }
}
