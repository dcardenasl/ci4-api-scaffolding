<?php

declare(strict_types=1);

namespace {ns};

use {controllerBaseFqcn};
use {reqDtoNs}\{resource}CreateRequestDTO;
use {reqDtoNs}\{resource}IndexRequestDTO;
use {reqDtoNs}\{resource}UpdateRequestDTO;
use {interfaceNs}\{resource}ServiceInterface;
use CodeIgniter\HTTP\ResponseInterface;
use dcardenasl\Ci4ApiCore\Dto\SecurityContext;
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
        return $this->handleRequest(
            function ({resource}IndexRequestDTO $dto, SecurityContext $context): mixed {
                if (!$context->hasPermission('{permissionResource}.read')) {
                    throw new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException(lang('Api.forbidden'));
                }
                return $this->{resourceLower}Service->index($dto, $context);
            },
            {resource}IndexRequestDTO::class
        );
    }

    public function create(): ResponseInterface
    {
        return $this->handleRequest(
            function ({resource}CreateRequestDTO $dto, SecurityContext $context): mixed {
                if (!$context->hasPermission('{permissionResource}.create')) {
                    throw new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException(lang('Api.forbidden'));
                }
                return $this->{resourceLower}Service->store($dto, $context);
            },
            {resource}CreateRequestDTO::class
        );
    }

    public function update(int $id): ResponseInterface
    {
        return $this->handleRequest(
            function ({resource}UpdateRequestDTO $dto, SecurityContext $context) use ($id): mixed {
                if (!$context->hasPermission('{permissionResource}.update')) {
                    throw new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException(lang('Api.forbidden'));
                }
                return $this->{resourceLower}Service->update($id, $dto, $context);
            },
            {resource}UpdateRequestDTO::class
        );
    }

    public function show(int $id): ResponseInterface
    {
        return $this->handleRequest(
            function (array $dto, SecurityContext $context) use ($id): mixed {
                if (!$context->hasPermission('{resourceLower}.read')) {
                    throw new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException(lang('Api.forbidden'));
                }
                return $this->{resourceLower}Service->show($id, $context);
            }
        );
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->handleRequest(
            function (array $dto, SecurityContext $context) use ($id): mixed {
                if (!$context->hasPermission('{permissionResource}.delete')) {
                    throw new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException(lang('Api.forbidden'));
                }
                return $this->{resourceLower}Service->destroy($id, $context);
            }
        );
    }
}
