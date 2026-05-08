<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Catalog;

use App\Interfaces\Catalog\ProductServiceInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * Smoke tests for ProductService. Extend with domain-specific assertions
 * as business rules accumulate in the service.
 *
 * @internal
 */
final class ProductServiceTest extends CIUnitTestCase
{
    public function testServiceImplementsItsInterface(): void
    {
        $service = Services::productService(false);

        $this->assertInstanceOf(ProductServiceInterface::class, $service);
    }
}
