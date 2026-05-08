<?php

declare(strict_types=1);

namespace {ns};

use OpenApi\Attributes as OA;

/**
 * OpenAPI definitions for {resource} endpoints.
 *
 * @OA\Tag(name="{domain}", description="{domain} management")
 */
class {resource}Endpoints
{
    #[OA\Get(
        path: '/api/v1/{domainKebab}/{route}',
        tags: ['{domain}'],
        summary: 'List {plural}',
        responses: [
            new OA\Response(
                response: 200,
                description: 'List retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/{resource}Response')
                        ),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index() {}

    #[OA\Post(
        path: '/api/v1/{domainKebab}/{route}',
        tags: ['{domain}'],
        summary: 'Create new {resource}',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/{resource}CreateRequest')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created successfully'),
            new OA\Response(response: 422, description: 'Validation error')
        ]
    )]
    public function store() {}

    #[OA\Get(
        path: '/api/v1/{domainKebab}/{route}/{id}',
        tags: ['{domain}'],
        summary: 'Get {resource} by ID',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Found',
                content: new OA\JsonContent(ref: '#/components/schemas/{resource}Response')
            ),
            new OA\Response(response: 404, description: 'Not found')
        ]
    )]
    public function show() {}

    #[OA\Put(
        path: '/api/v1/{domainKebab}/{route}/{id}',
        tags: ['{domain}'],
        summary: 'Update existing {resource}',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/{resource}UpdateRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/{resource}Response')
            ),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error')
        ]
    )]
    public function update() {}

    #[OA\Delete(
        path: '/api/v1/{domainKebab}/{route}/{id}',
        tags: ['{domain}'],
        summary: 'Delete {resource} by ID',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted successfully'),
            new OA\Response(response: 404, description: 'Not found')
        ]
    )]
    public function delete() {}
}
