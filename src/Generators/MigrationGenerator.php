<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Core\TypeMapper;

/**
 * MigrationGenerator
 * Generates CodeIgniter 4 migration files with automatic type mapping and FK support.
 */
class MigrationGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'migration';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        // CI4's MigrationRunner regex requires exactly YYYYMMDDHHMMSS_ClassName.
        // We can't encode sub-second precision in the filename, so collisions in
        // the same second are guarded by ScaffoldingOrchestrator::validateFilesDoNotExist()
        // which throws ScaffoldConflictException (never silent overwrite).
        $timestamp = date('Y-m-d-His');
        $resourcePlural = $schema->getResourcePlural();
        $fileName = "{$timestamp}_Create{$resourcePlural}Table.php";

        return [
            APPPATH . $this->config->paths->migrations . "/{$fileName}" => $this->template($schema),
        ];
    }

    private function template(ResourceSchema $schema): string
    {
        $resourcePlural = $schema->getResourcePlural();
        $table = $schema->getResourcePluralSnakeCase();
        $fieldsContent = $this->generateFields($schema);
        $indexes = $this->generateIndexes($schema);
        $foreignKeys = $this->generateForeignKeys($schema);
        $dropForeignKeys = $this->generateDropForeignKeys($schema);
        $deletedAtField = $schema->softDelete ? $this->getDeletedAtField() : '';

        $ns = $this->config->namespaceFor($this->config->paths->migrations);
        $migrationFqcn = $this->config->migrationBaseClass;
        $migrationShort = Fqcn::shortName($migrationFqcn);

        return $this->renderer->render('migration/Migration', [
            'ns'              => $ns,
            'migrationFqcn'   => $migrationFqcn,
            'migrationShort'  => $migrationShort,
            'resourcePlural'  => $resourcePlural,
            'table'           => $table,
            'fieldsContent'   => $fieldsContent,
            'indexes'         => $indexes,
            'foreignKeys'     => $foreignKeys,
            'dropForeignKeys' => $dropForeignKeys,
            'deletedAtField'  => $deletedAtField,
        ]);
    }

    /**
     * Emit `addUniqueKey` / `addKey` lines for fields flagged unique/index,
     * plus implicit indexes for searchable/filterable columns so common
     * `WHERE filter=? AND name LIKE ?` lookups don't degrade on large tables.
     */
    private function generateIndexes(ResourceSchema $schema): string
    {
        $output = '';
        $indexed = [];
        foreach ($schema->fields as $field) {
            if ($field->unique) {
                $output .= "        \$this->forge->addUniqueKey('{$field->name}');\n";
                $indexed[$field->name] = true;
                continue;
            }
            if ($field->index || $field->searchable || $field->filterable) {
                if (isset($indexed[$field->name])) {
                    continue;
                }
                $output .= "        \$this->forge->addKey('{$field->name}');\n";
                $indexed[$field->name] = true;
            }
        }

        return $output;
    }

    private function generateFields(ResourceSchema $schema): string
    {
        $output = "";
        foreach ($schema->fields as $field) {
            $mapping = TypeMapper::get($field->type);
            $dbType = $mapping['db'];
            $null = $field->nullable ? 'true' : 'false';

            $output .= "            '{$field->name}' => [\n";
            $output .= "                'type' => '{$dbType}',\n";

            if ($dbType === 'VARCHAR') {
                $constraint = $field->length ?? 255;
                $output .= "                'constraint' => {$constraint},\n";
            } elseif ($dbType === 'DECIMAL') {
                $precision = $field->precision ?? '10,2';
                $output .= "                'constraint' => '{$precision}',\n";
            } elseif ($dbType === 'INT') {
                $output .= "                'unsigned' => true,\n";
            }

            if ($field->defaultValue !== null) {
                $output .= "                'default' => '{$field->defaultValue}',\n";
            }

            $output .= "                'null' => {$null},\n";
            $output .= "            ],\n";
        }

        return $output;
    }

    private function generateForeignKeys(ResourceSchema $schema): string
    {
        $output = "";
        $table = $schema->getResourcePluralSnakeCase();
        foreach ($schema->fields as $field) {
            if (!$field->fkTable) {
                continue;
            }

            // Explicit override from `fk:table:setnull|restrict|cascade` wins;
            // otherwise the historical heuristic kicks in: nullable columns use
            // SET NULL (parent delete preserves child with null FK), required
            // columns use CASCADE (parent delete removes children).
            $onDelete = $field->fkOnDelete !== 'CASCADE'
                ? $field->fkOnDelete
                : ($field->nullable ? 'SET NULL' : 'CASCADE');

            $output .= "        \$this->forge->addForeignKey('{$field->name}', '{$field->fkTable}', 'id', '{$field->fkOnUpdate}', '{$onDelete}', 'fk_{$table}_{$field->name}');\n";
        }
        return $output;
    }

    private function generateDropForeignKeys(ResourceSchema $schema): string
    {
        $table = $schema->getResourcePluralSnakeCase();
        $lines = [];
        foreach ($schema->fields as $field) {
            if (!$field->fkTable) {
                continue;
            }
            $lines[] = "        \$this->forge->dropForeignKey('{$table}', 'fk_{$table}_{$field->name}');\n";
        }
        return $lines === [] ? '' : implode('', $lines) . "\n";
    }

    private function getDeletedAtField(): string
    {
        return <<<'PHP'
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

PHP;
    }
}
