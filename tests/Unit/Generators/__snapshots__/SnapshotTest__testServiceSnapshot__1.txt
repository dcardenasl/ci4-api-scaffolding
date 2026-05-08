<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use dcardenasl\Ci4ApiCore\Repositories\RepositoryInterface;
use dcardenasl\Ci4ApiCore\Mappers\ResponseMapperInterface;
use App\Interfaces\Catalog\ProductServiceInterface;
use dcardenasl\Ci4ApiCore\Services\BaseCrudService;

class ProductService extends BaseCrudService implements ProductServiceInterface
{
    public function __construct(
        RepositoryInterface $productRepository,
        ResponseMapperInterface $responseMapper
    ) {
        parent::__construct($productRepository, $responseMapper);
    }

    /**
     * Domain Hooks
     *
     * Implement beforeStore, afterStore, beforeUpdate, etc.,
     * to add specific business logic while keeping the service layer clean.
     */
}
