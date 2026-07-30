<?php

declare(strict_types=1);

namespace dcardenasl\Ci4ApiScaffolding\Generators;

use dcardenasl\Ci4ApiScaffolding\Config\ScaffoldingConfig;
use dcardenasl\Ci4ApiScaffolding\Core\Field;
use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use dcardenasl\Ci4ApiScaffolding\Core\ResourceSchema;
use dcardenasl\Ci4ApiScaffolding\Core\TypeMapper;

/**
 * DtoGenerator
 * Generates all 4 DTOs: Index, Create, Update, and Response.
 */
class DtoGenerator implements CrudGeneratorInterface
{
    private readonly TemplateRenderer $renderer;

    public function __construct(private readonly ScaffoldingConfig $config)
    {
        $this->renderer = new TemplateRenderer();
    }

    public function name(): string
    {
        return 'dto';
    }

    /**
     * Emit an OA\Property attribute line for a request-DTO property.
     * Keeps the scaffolded DTO visually aligned with the hand-maintained gold standard
     * (e.g. UserCreateRequestDTO) without requiring manual edits.
     */
    private function buildPropertyAttribute(Field $field, bool $nullableOverride): string
    {
        $mapping = TypeMapper::get($field->type);
        $parts = ["description: '" . addslashes($field->name) . "'"];
        $parts[] = "type: '{$mapping['oa']}'";
        if (isset($mapping['oa_format'])) {
            $parts[] = "format: '{$mapping['oa_format']}'";
        }
        if ($nullableOverride || $field->nullable) {
            $parts[] = 'nullable: true';
        }

        return "    #[OA\\Property(" . implode(', ', $parts) . ")]\n";
    }

    /**
     * Build the right-hand expression that maps a raw array value to a strongly-typed property.
     * Handles int/float/bool/string consistently so the readonly property type matches the runtime value.
     */
    private function buildMapExpression(Field $field, bool $nullable = false): string
    {
        $access = "\$data['{$field->name}']";
        $phpType = TypeMapper::get($field->type)['php'];

        // The property is nullable when either:
        //  - the caller forced it (update DTO treats every field as nullable), or
        //  - the field itself was declared nullable in the schema.
        // Without this, a nullable Create DTO field would coerce `null` to `0`/`''` silently.
        if ($nullable || $field->nullable) {
            return match ($phpType) {
                'int'    => "isset({$access}) ? (int) {$access} : null",
                'float'  => "isset({$access}) ? (float) {$access} : null",
                'bool'   => "isset({$access}) ? (bool) {$access} : null",
                'array'  => "isset({$access}) ? (array) {$access} : null",
                default  => "{$access} ?? null",
            };
        }

        return match ($phpType) {
            'int'    => "(int) ({$access} ?? 0)",
            'float'  => "(float) ({$access} ?? 0)",
            'bool'   => "(bool) ({$access} ?? false)",
            'array'  => "(array) ({$access} ?? [])",
            default  => "(string) ({$access} ?? '')",
        };
    }

    /**
     * Update-DTO-only mapping expression. Unlike buildMapExpression() (shared
     * with Create, where every field is always present), an Update payload
     * can legitimately omit a field to mean "don't touch it" — but it must
     * still be possible to send the field as null to mean "clear it" when
     * the underlying column is nullable. isset($data[x]) can't tell those
     * apart: it reads false whether the key is absent or present-with-null.
     * array_key_exists() is required to distinguish them; the actual
     * "does an explicit null survive into toArray()" decision is made
     * separately in updateRequestDto(), which is why this method alone
     * still resolves to `null` for both cases — the resolved value is
     * identical, only whether it gets recorded into $mappedFields differs.
     *
     * Nullable fields additionally treat an empty string as "no value" (an
     * HTML form's blank text/number input), so it clears the same as null
     * rather than writing 0/'' into the column. NOT NULL fields skip that
     * shortcut — an empty string there is a validation concern (rules()),
     * not a silent "leave unchanged".
     */
    private function buildUpdateMapExpression(Field $field): string
    {
        $access = "\$data['{$field->name}']";
        $phpType = TypeMapper::get($field->type)['php'];
        $present = "array_key_exists('{$field->name}', \$data)";

        if ($phpType === 'array') {
            return "{$present} && is_array({$access}) ? (array) {$access} : null";
        }

        if ($phpType === 'bool') {
            return "{$present} && {$access} !== null ? (bool) {$access} : null";
        }

        $condition = "{$present} && {$access} !== null" . ($field->nullable ? " && {$access} !== ''" : '');
        $cast = match ($phpType) {
            'int'   => '(int)',
            'float' => '(float)',
            default => '(string)',
        };

        return "{$condition} ? {$cast} {$access} : null";
    }

    /**
     * Whether a resolved Update DTO field value should be recorded into
     * $mappedFields (and therefore reach toArray() / the eventual UPDATE
     * payload). NOT NULL fields only record a real value — an explicit null
     * there is indistinguishable from "not provided" and must stay that way
     * to respect the DB constraint. Nullable fields record whenever the key
     * was present at all, including when the resolved value is null, so an
     * explicit clear actually reaches the database.
     */
    private function buildUpdateMappedFieldCondition(Field $field): string
    {
        if ($field->nullable) {
            return "array_key_exists('{$field->name}', \$data)";
        }

        return "\$this->{$field->name} !== null";
    }

    /** @return array<string,string> path => content */
    public function generate(ResourceSchema $schema): array
    {
        $domain = $schema->domain;
        $resource = $schema->resource;
        $reqPath = $this->config->paths->requestDtos;
        $resPath = $this->config->paths->responseDtos;

        return [
            APPPATH . "{$reqPath}/{$domain}/{$resource}IndexRequestDTO.php" => $this->indexRequestDto($schema),
            APPPATH . "{$reqPath}/{$domain}/{$resource}CreateRequestDTO.php" => $this->createRequestDto($schema),
            APPPATH . "{$reqPath}/{$domain}/{$resource}UpdateRequestDTO.php" => $this->updateRequestDto($schema),
            APPPATH . "{$resPath}/{$domain}/{$resource}ResponseDTO.php" => $this->responseDto($schema),
        ];
    }

    private function requestDtoNamespace(ResourceSchema $schema): string
    {
        return $this->config->namespaceFor($this->config->paths->requestDtos) . '\\' . $schema->domain;
    }

    private function responseDtoNamespace(ResourceSchema $schema): string
    {
        return $this->config->namespaceFor($this->config->paths->responseDtos) . '\\' . $schema->domain;
    }

    private function indexRequestDto(ResourceSchema $schema): string
    {
        $ns = $this->requestDtoNamespace($schema);
        $baseFqcn = $this->config->requestDtoBaseClass;
        $baseShort = Fqcn::shortName($baseFqcn);

        return $this->renderer->render('dto/IndexRequestDTO', [
            'ns'        => $ns,
            'baseFqcn'  => $baseFqcn,
            'baseShort' => $baseShort,
            'resource'  => $schema->resource,
        ]);
    }

    private function createRequestDto(ResourceSchema $schema): string
    {
        $properties = '';
        $rules = '';
        $mappings = '';
        $toArray = '';

        $table = $schema->getResourcePluralSnakeCase();

        foreach ($schema->fields as $field) {
            $phpType = TypeMapper::getPhpType($field->type, $field->nullable);
            // Create DTO validates uniqueness against the full table; Update DTO intentionally skips
            // it because it would reject the record's own value (needs id-in-context to do right).
            $validation = TypeMapper::getValidationRules($field, $table);

            $properties .= $this->buildPropertyAttribute($field, nullableOverride: $field->nullable);
            $properties .= "    public {$phpType} \${$field->name};\n";
            $rules .= "            '{$field->name}' => '{$validation}',\n";

            $mappings .= "        \$this->{$field->name} = " . $this->buildMapExpression($field) . ";\n";
            $toArray .= "            '{$field->name}' => \$this->{$field->name},\n";
        }

        $ns = $this->requestDtoNamespace($schema);
        $baseFqcn = $this->config->requestDtoBaseClass;
        $baseShort = Fqcn::shortName($baseFqcn);

        return $this->renderer->render('dto/CreateRequestDTO', [
            'ns'         => $ns,
            'baseFqcn'   => $baseFqcn,
            'baseShort'  => $baseShort,
            'resource'   => $schema->resource,
            'properties' => $properties,
            'rules'      => $rules,
            'mappings'   => $mappings,
            'toArray'    => $toArray,
        ]);
    }

    private function updateRequestDto(ResourceSchema $schema): string
    {
        $properties = '';
        $rules = '';
        $mappings = '';
        $mappedFieldsBlock = '';

        foreach ($schema->fields as $field) {
            $phpType = TypeMapper::getPhpType($field->type, true); // Update fields are usually optional
            // Use word boundaries so compound rules like `required_if`, `required_with` are preserved.
            $validation = preg_replace(
                '/\brequired\b(?![_\-a-zA-Z])/',
                'permit_empty',
                TypeMapper::getValidationRules($field)
            ) ?? TypeMapper::getValidationRules($field);

            $properties .= $this->buildPropertyAttribute($field, nullableOverride: true);
            $properties .= "    public {$phpType} \${$field->name};\n";
            $rules .= "            '{$field->name}' => '{$validation}',\n";

            $mappings .= "        \$this->{$field->name} = " . $this->buildUpdateMapExpression($field) . ";\n";

            $condition = $this->buildUpdateMappedFieldCondition($field);
            $mappedFieldsBlock .= "        if ({$condition}) {\n";
            $mappedFieldsBlock .= "            \$mappedFields['{$field->name}'] = \$this->{$field->name};\n";
            $mappedFieldsBlock .= "        }\n";
        }

        $ns = $this->requestDtoNamespace($schema);
        $baseFqcn = $this->config->requestDtoBaseClass;
        $baseShort = Fqcn::shortName($baseFqcn);

        return $this->renderer->render('dto/UpdateRequestDTO', [
            'ns'                => $ns,
            'baseFqcn'          => $baseFqcn,
            'baseShort'         => $baseShort,
            'resource'          => $schema->resource,
            'properties'        => $properties,
            'rules'             => $rules,
            'mappings'          => $mappings,
            'mappedFieldsBlock' => $mappedFieldsBlock,
        ]);
    }

    private function responseDto(ResourceSchema $schema): string
    {
        $params = '';
        $toArray = '';
        $fromArrayMappings = '';
        $requiredFields = ['id'];

        foreach ($schema->fields as $field) {
            $mapping = TypeMapper::get($field->type);
            $phpType = TypeMapper::getPhpType($field->type, $field->nullable);
            $oaType = $mapping['oa'];
            $oaFormat = isset($mapping['oa_format']) ? ", format: '{$mapping['oa_format']}'" : "";
            $nullable = $field->nullable ? ", nullable: true" : "";

            if ($field->required) {
                $requiredFields[] = $field->name;
            }

            $params .= "\n        #[OA\\Property(description: '{$field->name}', type: '{$oaType}'{$oaFormat}{$nullable})]\n";
            $params .= "        public {$phpType} \${$field->name},";

            $toArray .= "            '{$field->name}' => \$this->{$field->name},\n";
            $fromArrayMappings .= "            {$field->name}: " . $this->buildMapExpression($field) . ",\n";
        }

        $requiredJson = json_encode($requiredFields);

        // Remove leading newline from $params to avoid blank line after public int $id,
        $params = ltrim($params, "\n");

        $ns = $this->responseDtoNamespace($schema);
        $ifaceFqcn = $this->config->responseDtoInterface;
        $ifaceShort = Fqcn::shortName($ifaceFqcn);

        return $this->renderer->render('dto/ResponseDTO', [
            'ns'                => $ns,
            'ifaceFqcn'         => $ifaceFqcn,
            'ifaceShort'        => $ifaceShort,
            'resource'          => $schema->resource,
            'requiredJson'      => (string) $requiredJson,
            'params'            => $params,
            'toArray'           => $toArray,
            'fromArrayMappings' => $fromArrayMappings,
        ]);
    }
}
