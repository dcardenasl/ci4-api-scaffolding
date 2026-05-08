<?php

declare(strict_types=1);

namespace Tests\Unit\Services\{domain};

use {interfaceNs}\{resource}ServiceInterface;
use CodeIgniter\Test\CIUnitTestCase;
use {servicesFactoryFqcn};

/**
 * Smoke tests for {resource}Service. Extend with domain-specific assertions
 * as business rules accumulate in the service.
 *
 * @internal
 */
final class {resource}ServiceTest extends CIUnitTestCase
{
    public function testServiceImplementsItsInterface(): void
    {
        $service = {servicesFactoryShort}::{resourceLower}Service(false);

        $this->assertInstanceOf({resource}ServiceInterface::class, $service);
    }
}
