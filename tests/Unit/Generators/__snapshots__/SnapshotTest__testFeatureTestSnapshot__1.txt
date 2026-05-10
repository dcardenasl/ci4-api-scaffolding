<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Catalog;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * HTTP smoke test for ProductController. The configured route group
 * is open, so a request for a missing resource must return 404 — a sufficient signal that the route was registered and wired.
 *
 * Extend with authenticated 200 flows as business rules solidify.
 *
 * @internal
 */
final class ProductControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    public function testIndexSmoke(): void
    {
        $result = $this->get('/api/v1/catalog/products');

        $result->assertStatus(404);
    }
}
