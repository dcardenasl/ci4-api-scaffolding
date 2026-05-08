<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Core\TypeMapper;

/**
 * ModelEntityGenerator
 * Generates the Entity and Model with full support for Searchable/Filterable traits.
 */
class ModelEntityGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'model';
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $resource = $schema->resource;
        $modelsPath = $this->config->paths->models;
        $entitiesPath = $this->config->paths->entities;

        return [
            APPPATH . "{$entitiesPath}/{$resource}Entity.php" => $this->entityTemplate($schema),
            APPPATH . "{$modelsPath}/{$resource}Model.php" => $this->modelTemplate($schema),
        ];
    }

    private function entityTemplate(ResourceSchema $schema): string
    {
        $casts = "        'id' => 'integer',\n";
        $hasDecimal = false;
        foreach ($schema->fields as $field) {
            $mapping = TypeMapper::get($field->type);
            $phpType = $mapping['php'];
            // CI4 Casts use specific names
            $castType = $phpType === 'float' ? 'decimal' : $phpType;
            if ($castType === 'array') {
                $castType = 'json';
            }
            if ($castType === 'decimal') {
                $hasDecimal = true;
            }

            $casts .= "        '{$field->name}' => '{$castType}',\n";
        }

        $dates = $schema->softDelete
            ? "['created_at', 'updated_at', 'deleted_at']"
            : "['created_at', 'updated_at']";

        $ns = $this->config->namespaceFor($this->config->paths->entities);
        $entityBaseFqcn = $this->config->entityBaseClass;
        $entityBaseShort = Fqcn::shortName($entityBaseFqcn);

        // CI4 doesn't ship a 'decimal' cast handler natively. When the resource
        // has a DECIMAL column we register Ci4ApiCore's DecimalCast (string-backed,
        // preserves monetary precision). Skipped otherwise to keep generated
        // Entities minimal.
        $castHandlersBlock = '';
        $decimalCastUse = '';
        if ($hasDecimal) {
            $decimalCastUse = "use dcardenasl\\Ci4ApiCore\\DataCasts\\DecimalCast;\n";
            $castHandlersBlock = "    protected \$castHandlers = [\n"
                . "        'decimal' => DecimalCast::class,\n"
                . "    ];\n\n";
        }

        return $this->renderer->render('model/Entity', [
            'ns'               => $ns,
            'entityBaseFqcn'   => $entityBaseFqcn,
            'entityBaseShort'  => $entityBaseShort,
            'resource'         => $schema->resource,
            'decimalCastUse'   => $decimalCastUse,
            'castHandlersBlock' => $castHandlersBlock,
            'casts'            => $casts,
            'dates'            => $dates,
        ]);
    }

    private function modelTemplate(ResourceSchema $schema): string
    {
        $table = $schema->getResourcePluralSnakeCase();
        $softDelete = $schema->softDelete ? 'true' : 'false';

        $allowedFields = [];
        $searchableFields = [];
        $filterableFields = ["'id'"];
        $sortableFields = ["'id'", "'created_at'"];
        $validationRules = "";

        foreach ($schema->fields as $field) {
            $allowedFields[] = "'{$field->name}'";
            if ($field->searchable) {
                $searchableFields[] = "'{$field->name}'";
                $sortableFields[] = "'{$field->name}'";
            }
            if ($field->filterable) {
                $filterableFields[] = "'{$field->name}'";
                $sortableFields[] = "'{$field->name}'";
            }

            // Pass the table name so TypeMapper can emit is_unique[table.col] for unique fields.
            $rules = TypeMapper::getValidationRules($field, $table);
            $validationRules .= "        '{$field->name}' => '{$rules}',\n";
        }

        $allowedFieldsStr = implode(", ", $allowedFields);
        $searchableFieldsStr = implode(", ", $searchableFields);
        $filterableFieldsStr = implode(", ", $filterableFields);
        $sortableFieldsStr = implode(", ", array_unique($sortableFields));

        $ns = $this->config->namespaceFor($this->config->paths->models);
        $entityNs = $this->config->namespaceFor($this->config->paths->entities);
        $modelBaseFqcn = $this->config->modelBaseClass;
        $modelBaseShort = Fqcn::shortName($modelBaseFqcn);
        $filterableFqcn = ltrim($this->config->filterableTraitFqcn, '\\');
        $searchableFqcn = ltrim($this->config->searchableTraitFqcn, '\\');
        $filterableShort = Fqcn::shortName($filterableFqcn);
        $searchableShort = Fqcn::shortName($searchableFqcn);

        return $this->renderer->render('model/Model', [
            'ns'                 => $ns,
            'entityNs'           => $entityNs,
            'modelBaseFqcn'      => $modelBaseFqcn,
            'modelBaseShort'     => $modelBaseShort,
            'filterableFqcn'     => $filterableFqcn,
            'searchableFqcn'     => $searchableFqcn,
            'filterableShort'    => $filterableShort,
            'searchableShort'    => $searchableShort,
            'resource'           => $schema->resource,
            'table'              => $table,
            'softDelete'         => $softDelete,
            'allowedFieldsStr'   => $allowedFieldsStr,
            'searchableFieldsStr' => $searchableFieldsStr,
            'filterableFieldsStr' => $filterableFieldsStr,
            'sortableFieldsStr'  => $sortableFieldsStr,
            'validationRules'    => $validationRules,
        ]);
    }
}
