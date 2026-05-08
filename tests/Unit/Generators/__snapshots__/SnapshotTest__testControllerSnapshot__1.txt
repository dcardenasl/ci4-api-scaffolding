<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Catalog;

use dcardenasl\Ci4ApiCore\Http\ApiController;
use App\DTO\Request\Catalog\ProductCreateRequestDTO;
use App\DTO\Request\Catalog\ProductIndexRequestDTO;
use App\DTO\Request\Catalog\ProductUpdateRequestDTO;
use App\Interfaces\Catalog\ProductServiceInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class ProductController extends ApiController
{
    protected ProductServiceInterface $productService;

    protected function resolveDefaultService(): object
    {
        $this->productService = Services::productService();

        return $this->productService;
    }

    protected array $statusCodes = [
        'store' => 201,
    ];

    public function index(): ResponseInterface
    {
        return $this->handleRequest('index', ProductIndexRequestDTO::class);
    }

    public function create(): ResponseInterface
    {
        return $this->handleRequest('store', ProductCreateRequestDTO::class);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->handleRequest(
            fn ($dto, $context) => $this->productService->update($id, $dto, $context),
            ProductUpdateRequestDTO::class
        );
    }

    public function show(int $id): ResponseInterface
    {
        return $this->handleRequest(fn ($dto, $context) => $this->productService->show($id, $context));
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->handleRequest(fn ($dto, $context) => $this->productService->destroy($id, $context));
    }
}
