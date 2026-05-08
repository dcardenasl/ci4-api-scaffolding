<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Smoke tests for ProductModel. Extend with persistence scenarios as
 * domain behavior solidifies.
 *
 * @internal
 */
final class ProductModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    public function testModelReportsCorrectTable(): void
    {
        $model = new ProductModel();

        $this->assertSame('products', $model->getTable());
    }
}
