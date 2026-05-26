<?php

declare(strict_types=1);

namespace {ns};

use {entityFqcn};
use {repoFqcn};
use {mapperFqcn};
use {interfaceNs}\{resource}ServiceInterface;
use {serviceBaseFqcn};

/**
 * @extends {serviceBaseShort}<{resource}Entity>
 */
class {resource}Service extends {serviceBaseShort} implements {resource}ServiceInterface
{
    /**
     * @param {repoShort}<{resource}Entity> ${resourceLower}Repository
     */
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

    // Custom methods declared in {resource}ServiceInterface must be implemented here.
    // Until fully implemented, throw to avoid silent incorrect behavior:
    //   throw new \BadMethodCallException(__METHOD__ . ' not implemented');
}
