<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use {modelNs}\{resource}Model;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Smoke tests for {resource}Model. Extend with persistence scenarios as
 * domain behavior solidifies.
 *
 * @internal
 */
final class {resource}ModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = '{appNamespace}';

    public function testModelReportsCorrectTable(): void
    {
        $model = new {resource}Model();

        $this->assertSame('{tableName}', $model->getTable());
    }
}
