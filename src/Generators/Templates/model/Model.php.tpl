<?php

declare(strict_types=1);

namespace {ns};

use {entityNs}\{resource}Entity;
use {modelBaseFqcn};
use {filterableFqcn};
use {searchableFqcn};

class {resource}Model extends {modelBaseShort}
{
    use {filterableShort};
    use {searchableShort};

    protected $table = '{table}';
    protected $primaryKey = 'id';
    protected $returnType = {resource}Entity::class;
    protected $useSoftDeletes = {softDelete};
    protected $useTimestamps = true;

    protected $allowedFields = [{allowedFieldsStr}];

    /** @var array<int, string> */
    protected array $searchableFields = [{searchableFieldsStr}];

    /** @var array<int, string> */
    protected array $filterableFields = [{filterableFieldsStr}];

    /** @var array<int, string> */
    protected array $sortableFields = [{sortableFieldsStr}];

    protected $validationRules = [
{validationRules}    ];
}
