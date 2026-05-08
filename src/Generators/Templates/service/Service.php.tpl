<?php

declare(strict_types=1);

namespace {ns};

use {repoFqcn};
use {mapperFqcn};
use {interfaceNs}\{resource}ServiceInterface;
use {serviceBaseFqcn};

class {resource}Service extends {serviceBaseShort} implements {resource}ServiceInterface
{
    public function __construct(
        {repoShort} ${resourceLower}Repository,
        {mapperShort} $responseMapper
    ) {
        parent::__construct(${resourceLower}Repository, $responseMapper);
    }

    /**
     * Domain Hooks
     *
     * Implement beforeStore, afterStore, beforeUpdate, etc.,
     * to add specific business logic while keeping the service layer clean.
     */
}
