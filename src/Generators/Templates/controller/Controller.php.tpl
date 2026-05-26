<?php

declare(strict_types=1);

namespace {ns};

use {controllerBaseFqcn};
use {reqDtoNs}\{resource}CreateRequestDTO;
use {reqDtoNs}\{resource}IndexRequestDTO;
use {reqDtoNs}\{resource}UpdateRequestDTO;
use {interfaceNs}\{resource}ServiceInterface;
use CodeIgniter\HTTP\ResponseInterface;
use {servicesFactoryFqcn};{traitImports}

class {resource}Controller extends {controllerBaseShort}
{{traitUseBlock}
    protected {resource}ServiceInterface ${resourceLower}Service;

    protected function resolveDefaultService(): {resource}ServiceInterface
    {
        $this->{resourceLower}Service = {servicesFactoryShort}::{resourceLower}Service();

        return $this->{resourceLower}Service;
    }

    protected array $statusCodes = [
        'store' => 201,
    ];

    public function index(): ResponseInterface
    {
        return $this->handleRequest('index', {resource}IndexRequestDTO::class);
    }

    public function create(): ResponseInterface
    {
        return $this->handleRequest('store', {resource}CreateRequestDTO::class);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->handleRequest(
            fn ($dto, $context) => $this->{resourceLower}Service->update($id, $dto, $context),
            {resource}UpdateRequestDTO::class
        );
    }

    public function show(int $id): ResponseInterface
    {
        return $this->handleRequest(fn ($dto, $context) => $this->{resourceLower}Service->show($id, $context));
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->handleRequest(fn ($dto, $context) => $this->{resourceLower}Service->destroy($id, $context));
    }
}
