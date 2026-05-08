<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\{domain};

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * HTTP smoke test for {resource}Controller. The configured route group
 * {authReason} — a sufficient signal that the route was registered and wired.
 *
 * Extend with authenticated 200 flows as business rules solidify.
 *
 * @internal
 */
final class {resource}ControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = '{appNamespace}';

    public function testIndexSmoke(): void
    {
        $result = $this->get('{fullPath}');

        $result->assertStatus({expectedStatus});
    }
}
